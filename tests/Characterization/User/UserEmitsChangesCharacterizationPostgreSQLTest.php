<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Mirrors UserEmitsChangesCharacterizationTest (MySQL) exactly, on PostgreSQL 14 /
 * TimescaleDB. See that class for what this locks in and why.
 *
 * The connection is this class's own: the suite's isolation extension drops the shared
 * one before every class, and setUp() builds it from the PostgreSQL settings.
 */
#[CoversClass(User::class)]
class UserEmitsChangesCharacterizationPostgreSQLTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
        if (!is_dir(LOG_PATH . DS . 'logs')) {
            @mkdir(LOG_PATH . DS . 'logs', 0777, true);
        }

        $pgSettingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        User::setupDb();
        Event::forget(ChangeFeed::EVENT);
        $this->flushAllListeners();
    }

    protected function tearDown(): void
    {
        Event::forget(ChangeFeed::EVENT);
        $this->flushAllListeners();
    }

    private function flushAllListeners(): void
    {
        User::flushEventListeners();
        $this->configurableUser()::flushEventListeners();
    }

    public function testSaveEmitsNothingByDefault(): void
    {
        // Arrange
        $fired = false;
        Event::listen(ChangeFeed::EVENT, function () use (&$fired) {
            $fired = true;
        });
        $user = $this->newUser();

        // Act
        $user->save();

        // Assert
        $this->assertFalse($fired, 'a User with $emitChanges left at its default must emit nothing');
    }

    public function testCreatingAUserEmitsCreatedWithFullData(): void
    {
        // Arrange
        $captured = null;
        Event::listen(ChangeFeed::EVENT, function (ModelChange $change) use (&$captured) {
            $captured = $change;
        });
        $user = $this->newUser();
        $user->configure(['emitChanges' => true]);

        // Act
        $user->save();

        // Assert
        $this->assertNotNull($captured, 'save() of a new user must emit a change');
        $this->assertSame(ModelChange::CREATED, $captured->op);
        $this->assertSame('user', $captured->entity);
        $this->assertSame((int) $user->userid, (int) $captured->key);
        $this->assertSame($user->username, $captured->data['username'] ?? null);
    }

    public function testUpdatingAUserEmitsOnlyTheChangedField(): void
    {
        // Arrange
        $user = $this->newUser();
        $user->configure(['emitChanges' => true]);
        $user->save();

        $loaded = $this->loadUser((int) $user->userid);
        $loaded->configure(['emitChanges' => true]);
        $captured = null;
        Event::listen(ChangeFeed::EVENT, function (ModelChange $change) use (&$captured) {
            $captured = $change;
        });
        $loaded->firstname = 'Changed';

        // Act
        $loaded->save();

        // Assert
        $this->assertNotNull($captured, 'save() of an updated user must emit a change');
        $this->assertSame(ModelChange::UPDATED, $captured->op);
        $this->assertTrue($captured->has('firstname'), 'the changed field must be reported');
        $this->assertSame('Changed', $captured->changes['firstname']['new'] ?? null);
        $this->assertFalse(
            $captured->has('username'),
            'a field that did not change must not be reported as changed'
        );
    }

    public function testSavingWithNoActualChangeEmitsNothing(): void
    {
        // Arrange
        $user = $this->newUser();
        $user->configure(['emitChanges' => true]);
        $user->save();

        $loaded = $this->loadUser((int) $user->userid);
        $loaded->configure(['emitChanges' => true]);
        $fired = false;
        Event::listen(ChangeFeed::EVENT, function () use (&$fired) {
            $fired = true;
        });

        // Act — nothing on $loaded was changed before saving again
        $loaded->save();

        // Assert
        $this->assertFalse($fired, 'a save with no actual field change must not emit');
    }

    public function testUpdatingListenerReturningFalseCancelsTheSave(): void
    {
        // Arrange
        $user = $this->newUser();
        $user->save();

        $loaded = new User((int) $user->userid);
        User::on('updating', fn () => false);
        $loaded->firstname = 'Should Not Persist';

        // Act
        $loaded->save();

        // Assert
        $reread = new User((int) $user->userid);
        $this->assertNotSame('Should Not Persist', $reread->firstname);
    }

    public function testCreatedEventFiresAfterTheRowIsWritten(): void
    {
        // Arrange
        $seenUserid = null;
        $user = $this->newUser();
        $user::on('created', function (User $u) use (&$seenUserid) {
            $seenUserid = $u->userid;
        });

        // Act
        $user->save();

        // Assert
        $this->assertGreaterThan(0, (int) $seenUserid, 'created must fire after the insert, once userid is known');
        $this->assertSame((int) $user->userid, (int) $seenUserid);
    }

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

    private function newUser(): User
    {
        $username = 'emitchg_char_pg_' . bin2hex(random_bytes(4));
        $user = $this->configurableUser();
        $user->username = $username;
        $user->email    = $username . '@example.com';
        $user->setPassword('secret123');

        return $user;
    }

    private function loadUser(int $userid): User
    {
        $user = $this->configurableUser();
        $user->load($userid);

        return $user;
    }
}
