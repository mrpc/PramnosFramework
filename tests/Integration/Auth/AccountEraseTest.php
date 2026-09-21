<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Event\Event;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;
use Pramnos\User\User;

/**
 * `account.data_erase` — the extension point the deletion half of GDPR was missing.
 *
 * The export half has always been extensible: `buildExportData()` fires
 * `account.data_export` and merges what listeners return, which
 * {@see AccountExportTest} covers. The deletion half was a hard-coded list of six
 * framework tables plus `users`, so an account deleted under Article 17 left an
 * application's own rows behind **and orphaned them** — every `created_by` pointing at a
 * user id that no longer exists.
 *
 * Three things are asserted here, and the second is the one that is easy to get wrong:
 *
 *  - the event fires, carrying the user id;
 *  - it fires **before** the framework's own deletes, because an application's rows
 *    usually carry a foreign key to `users` and deleting the user first makes the
 *    framework's own `DELETE` fail on them — an erase that half happened;
 *  - a listener returning `false` stops the erase with nothing deleted.
 *
 * MySQL only, for the same reason as the export test: `authserver.*` maps to a prefix
 * here, and the QueryBuilder makes the logic identical on the other engine.
 */
#[CoversClass(Account::class)]
class AccountEraseTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;
    private int $uid = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        // Not Factory::getDatabase(): that hands back whichever settings were loaded
        // first in the run. See Testing\Connection.
        $this->db = Connection::fresh();
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('AccountEraseTest runs on MySQL only.');
        }

        User::setupDb();
        $this->seed();
    }

    protected function tearDown(): void
    {
        Event::forget('account.data_erase');

        if ($this->uid > 0) {
            foreach (['users', 'usertokens'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)
                        ->where('userid', $this->uid)->delete();
                } catch (\Throwable) {
                    // The test under examination may already have removed it.
                }
            }
        }

        parent::tearDown();
    }

    /** A user and one framework-owned row that the erase is expected to take with it. */
    private function seed(): void
    {
        $user = new User();
        $user->username = 'erase_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->setPassword('Secr3t!pass');
        $user->save();
        $this->uid = (int) $user->userid;

        $this->assertGreaterThan(0, $this->uid, 'the fixture user was not created');

        $now = time();
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid'  => $this->uid, 'tokentype' => 'oauth', 'token' => 'erase-me',
            'applicationid' => 1, 'status' => 1,
            'created' => $now, 'lastused' => $now, 'expires' => 0,
        ]);
    }

    /** Does a row for the fixture user still exist in this table? */
    private function rowExists(string $table): bool
    {
        $result = $this->db->queryBuilder()->table($table)
            ->where('userid', $this->uid)->first();

        return $result !== false && ($result->numRows ?? 0) > 0;
    }

    /** `eraseUserData()` is protected: it is the seam an application overrides. */
    private function erase(): void
    {
        (new \ReflectionMethod(Account::class, 'eraseUserData'))
            ->invoke(new Account(), $this->uid);
    }

    /**
     * The event fires, with the user id, and the framework's own deletes still happen.
     *
     * The control for the two below: a hook that fired but stopped the erase, or an erase
     * that ran but never fired, would each pass one of them.
     */
    public function testTheEventFiresWithTheUserIdAndTheEraseStillRuns(): void
    {
        // Arrange
        $seen = [];
        Event::listen('account.data_erase', function (int $userId) use (&$seen) {
            $seen[] = $userId;

            return true;
        });

        // Act
        $this->erase();

        // Assert
        $this->assertSame([$this->uid], $seen, 'the event did not fire with the user id');
        $this->assertFalse($this->rowExists('users'), 'the user row survived the erase');
        $this->assertFalse($this->rowExists('usertokens'), 'a framework table was not erased');
    }

    /**
     * It fires **before** anything is deleted.
     *
     * WHAT: at the moment the listener runs, the `users` row is still there.
     *
     * WHY:  this is the whole of the ordering requirement, and it is invisible in the
     *       diff. An application's rows almost always carry a foreign key to `users`, so
     *       a hook fired *after* the framework's deletes would find the parent gone — the
     *       framework's own `DELETE FROM users` would have failed on the child rows first,
     *       and what is left is an account half erased, which is worse than one that was
     *       never started.
     *
     *       Asserted by reading the database from inside the listener, because "before" is
     *       a fact about the database at that instant and nothing else can observe it.
     */
    public function testItFiresBeforeTheFrameworkDeletesAnything(): void
    {
        // Arrange
        $userRowWasStillThere  = null;
        $tokenRowWasStillThere = null;
        Event::listen('account.data_erase', function (int $userId) use (&$userRowWasStillThere, &$tokenRowWasStillThere) {
            $userRowWasStillThere  = $this->rowExists('users');
            $tokenRowWasStillThere = $this->rowExists('usertokens');

            return true;
        });

        // Act
        $this->erase();

        // Assert
        $this->assertTrue(
            $userRowWasStillThere,
            'the listener ran after the user was deleted, so its foreign keys would fail'
        );
        $this->assertTrue($tokenRowWasStillThere);
    }

    /**
     * A listener returning `false` refuses the erase, and nothing is deleted.
     *
     * The refusal has to be total rather than advisory. An application that cannot erase —
     * a retention obligation, an open invoice, a tenant it will not orphan — needs the
     * framework to stop, not to note the objection and carry on; and `deleteaccount()`
     * turns the exception into an error on the page rather than a silent half-deletion.
     */
    public function testAListenerCanRefuseAndNothingIsDeleted(): void
    {
        // Arrange
        Event::listen('account.data_erase', fn(int $userId) => false);

        // Act
        $raised = null;
        try {
            $this->erase();
        } catch (\RuntimeException $e) {
            $raised = $e->getMessage();
        }

        // Assert
        $this->assertNotNull($raised, 'a refusal did not stop the erase');
        $this->assertStringContainsString('refused the erase', $raised);
        $this->assertStringContainsString('Nothing has been deleted', $raised);
        $this->assertTrue($this->rowExists('users'), 'the user was deleted despite the refusal');
        $this->assertTrue($this->rowExists('usertokens'), 'a framework table was erased anyway');
    }

    /**
     * A refusal stops the listeners after it, too.
     *
     * Two listeners where the first refuses and the second has already deleted its rows is
     * the half-erase this ordering exists to avoid — so the short-circuit in
     * `Event::fire()` is part of the contract rather than an implementation detail.
     */
    public function testARefusalStopsTheListenersAfterIt(): void
    {
        // Arrange
        $secondRan = false;
        Event::listen('account.data_erase', fn(int $userId) => false);
        Event::listen('account.data_erase', function (int $userId) use (&$secondRan) {
            $secondRan = true;

            return true;
        });

        // Act
        try {
            $this->erase();
        } catch (\RuntimeException) {
            // expected
        }

        // Assert
        $this->assertFalse($secondRan, 'a listener ran after the erase had been refused');
    }

    /**
     * A table this installation does not have is skipped, not fatal.
     *
     * WHAT: with an `authserver.*` table absent, the erase completes and the user is gone.
     *
     * WHY:  five of the six tables the erase clears are `authserver.*`, and `authserver`
     *       is a **feature**. An installation that never enabled it has never had them,
     *       so the delete raised — and `deleteaccount()` caught it and answered "An error
     *       occurred while deleting your account. Please try again." Every user, every
     *       time, on every such installation: an Article 17 erasure that could not be
     *       performed, reported as something transient.
     *
     *       Found by this class rather than reasoned about: a full-suite run happened to
     *       reach it with the table absent, where running the file alone did not.
     */
    public function testATableThisInstallationDoesNotHaveIsSkipped(): void
    {
        // Arrange — the table exists first, so this test drops it rather than depending
        // on whatever an earlier test in the run happened to leave behind. Without this
        // the branch is exercised only by luck, and a green result means nothing.
        $migration = \Pramnos\Framework\Migrations\Auth\CreateUserPrivacySettingsTable::class;
        $this->runMigrations([$migration], $this->db);
        $this->assertTrue(
            $this->db->schema()->hasTable('authserver.user_privacy_settings'),
            'the fixture could not create the table it is about to drop'
        );

        $this->db->query(
            'DROP TABLE IF EXISTS `' . $this->db->prefix . 'authserver_user_privacy_settings`'
        );
        $this->assertFalse(
            $this->db->schema()->hasTable('authserver.user_privacy_settings'),
            'the table is still there, so the skip branch is not being reached'
        );

        try {
            // Act
            $this->erase();

            // Assert — the erase completed rather than raising
            $this->assertFalse($this->rowExists('users'), 'the erase did not finish');
            $this->assertFalse($this->rowExists('usertokens'));
        } finally {
            // Put it back for the rest of the run, which assumes the framework's tables.
            $this->runMigrations([$migration], $this->db);
        }
    }

    /**
     * With no listener at all, the erase behaves exactly as it did.
     *
     * The hook must not be a new way for the framework's own deletion to fail. Every
     * installation that never registers a listener gets `Event::fire()` returning `[]`,
     * the loop running zero times, and the same six tables plus `users` going.
     */
    public function testAnInstallationWithNoListenerIsUnaffected(): void
    {
        // Act
        $this->erase();

        // Assert
        $this->assertFalse($this->rowExists('users'));
        $this->assertFalse($this->rowExists('usertokens'));
    }
}
