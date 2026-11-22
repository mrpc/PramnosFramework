<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Changelog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Changelog\ChangelogWriter;
use Pramnos\Database\WriteSpool;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * FW-577/FW-575: `User::restoreFromChangelog()` — the concrete case the feature was asked for.
 * `User` predates `Model` and wires {@see \Pramnos\Application\Concerns\EmitsModelChanges}
 * itself (see its own docblock), so this is the proof that the trait's generic
 * `restoreFromChangelog()` also works through `User::_restoreSave()` (`save()`, not `_save()`)
 * — not only through `Model`, which {@see RestoreFromChangelogTest} already covers end to end.
 *
 * One happy-path run: the edge cases (stale logid, `$ifLogid`, wrong record, non-`updated` op)
 * are the trait's own logic, already exercised there — this only has to show the other host
 * class reaches the same save path.
 */
#[CoversClass(User::class)]
class RestoreUserFromChangelogTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        User::setupDb();
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Changelog\CreateChangelogTables::class,
            \Pramnos\Framework\Migrations\Changelog\AddScopeToChangelogEvents::class,
        ], $this->db);

        Event::forget(ChangeFeed::EVENT);
        WriteSpool::reset();
        WriteSpool::setDriver(WriteSpool::DRIVER_SYNC);
        ChangelogWriter::registerEncoders();
        ChangelogWriter::listen();
    }

    protected function tearDown(): void
    {
        Event::forget(ChangeFeed::EVENT);
        WriteSpool::reset();
    }

    /** A user whose own saves announce themselves — {@see UserEmitsChangesCharacterizationTest}'s own fixture shape. */
    private function configurableUser(): User
    {
        return new class extends User {
            /** @param array<string, mixed> $config */
            public function configure(array $config): void
            {
                foreach ($config as $key => $value) {
                    $this->$key = $value;
                }
            }
        };
    }

    private function newestLogid(string $entity, string $itemid): int
    {
        $row = $this->db->queryBuilder()->table(ChangelogWriter::TABLE)
            ->where('entity', $entity)->where('itemid', $itemid)
            ->orderBy('logid', 'desc')->first();

        return (int) ($row->fields['logid'] ?? 0);
    }

    /**
     * Updating `firstname` twice through `save()` writes two real `pramnos.changelog` rows
     * (the real {@see ChangeFeed}/{@see ChangelogWriter} pipeline, not a mock); restoring from
     * the newest one reverses the second edit, through `save()` — not a raw UPDATE — so the
     * restore itself becomes a third, fresh `updated` row.
     */
    public function testRestoringAUserGoesThroughSave(): void
    {
        // Arrange
        $username = 'restore_user_' . bin2hex(random_bytes(4));
        $user = $this->configurableUser();
        $user->username  = $username;
        $user->email     = $username . '@example.com';
        $user->firstname = 'Original';
        $user->setPassword('secret123');
        $user->configure(['emitChanges' => true]);
        $user->save();
        $userid = (int) $user->userid;

        $loaded = $this->configurableUser();
        $loaded->load($userid);
        $loaded->configure(['emitChanges' => true]);
        $loaded->firstname = 'Changed by mistake';
        $loaded->save();
        $mistakeLogid = $this->newestLogid('user', (string) $userid);

        // Act
        $target = $this->configurableUser();
        $target->load($userid);
        $target->configure(['emitChanges' => true]);
        $restored = $target->restoreFromChangelog($mistakeLogid);

        // Assert
        $this->assertTrue($restored, 'the newest row for this user must restore');
        $this->assertSame('Original', $target->firstname, 'save() wrote the reversed field back');
        $reread = new User($userid);
        $this->assertSame('Original', $reread->firstname, 'the restore reached the database, not only the in-memory object');
        $afterRestoreLogid = $this->newestLogid('user', (string) $userid);
        $this->assertNotSame($mistakeLogid, $afterRestoreLogid, 'the restore is its own audited save, through save() like any other update');

        // Cleanup
        $this->db->queryBuilder()->table(ChangelogWriter::TABLE)->where('entity', 'user')->where('itemid', (string) $userid)->delete();
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $userid)->delete();
    }
}
