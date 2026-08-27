<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\PasswordHash;
use Pramnos\Auth\PasswordHistory;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * Refusing a password the account has already used.
 *
 * The behaviour is small; the two properties worth pinning are the ones that decide whether
 * it is safe to switch on. It must **not** refuse when it cannot answer — a reuse check
 * that fails closed blocks somebody who is changing a password for a reason — and it must
 * compare with the same verifier the login uses, or an account could be refused a password
 * that the login would not have accepted anyway.
 *
 * Runs on MySQL only, like the other tests that touch `authserver.*`.
 */
#[CoversClass(PasswordHistory::class)]
class PasswordHistoryTest extends BaseTestCase
{
    private $db;
    private int $uid = 0;
    private ?array $savedInstances = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('PasswordHistoryTest runs on MySQL only.');
        }

        User::setupDb();
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefix . 'authserver_password_history`');
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreatePasswordHistoryTable::class,
        ], $this->db);

        $user = new User();
        $user->username = 'pwhistory_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->save();
        $this->uid = (int) $user->userid;
    }

    protected function tearDown(): void
    {
        try {
            (new PasswordHistory($this->db))->forget($this->uid);
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->uid)->delete();
        } catch (\Throwable $exception) {
            // Nothing to undo.
        }

        $this->restoreApplication();
        parent::tearDown();
    }

    /** Declare how many previous passwords to remember. */
    private function withHistory(int $keep): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['auth' => ['security' => ['password_history' => $keep]]];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $reflection->getValue() ?? [];
        $instances = $this->savedInstances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    private function restoreApplication(): void
    {
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }
    }

    /**
     * Off by default: nothing is remembered and nothing is refused.
     */
    public function testItDoesNothingUntilAskedFor(): void
    {
        // Arrange — no `password_history` declared
        $this->withHistory(0);
        $history = new PasswordHistory($this->db);

        // Act
        $history->remember($this->uid, PasswordHash::make('oldpass', $this->uid));

        // Assert
        $this->assertFalse($history->wasUsedBefore($this->uid, 'oldpass'));
    }

    /**
     * A remembered password is refused; a fresh one is not.
     */
    public function testARememberedPasswordIsRefused(): void
    {
        // Arrange
        $this->withHistory(3);
        $history = new PasswordHistory($this->db);
        $history->remember($this->uid, PasswordHash::make('oldpass', $this->uid));

        // Act & Assert
        $this->assertTrue($history->wasUsedBefore($this->uid, 'oldpass'));
        $this->assertFalse($history->wasUsedBefore($this->uid, 'somethingelse'));
    }

    /**
     * A hash belonging to one account does not match another.
     *
     * The verifier keys on the user id, so this is what stops a shared password producing
     * a false "you have used that before" across accounts.
     */
    public function testHistoryIsBoundToTheAccount(): void
    {
        // Arrange
        $this->withHistory(3);
        $history = new PasswordHistory($this->db);

        $other = new User();
        $other->username = 'pwhistory_other_' . bin2hex(random_bytes(4));
        $other->email    = $other->username . '@example.com';
        $other->save();
        $otherId = (int) $other->userid;

        try {
            // The *other* account's hash, filed under this account
            $this->db->queryBuilder()->table('authserver.password_history')->insert([
                'userid'        => $this->uid,
                'password_hash' => PasswordHash::make('shared', $otherId),
                'created_at'    => time(),
            ]);

            // Act & Assert
            $this->assertFalse($history->wasUsedBefore($this->uid, 'shared'));
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $otherId)->delete();
        }
    }

    /**
     * Only the configured number are kept.
     */
    public function testOnlyTheConfiguredNumberIsRemembered(): void
    {
        // Arrange
        $this->withHistory(2);
        $history = new PasswordHistory($this->db);

        foreach (['first', 'second', 'third'] as $index => $password) {
            $this->db->queryBuilder()->table('authserver.password_history')->insert([
                'userid'        => $this->uid,
                'password_hash' => PasswordHash::make($password, $this->uid),
                'created_at'    => time() - (10 - $index),
            ]);
        }

        // Act — a write prunes
        $history->remember($this->uid, PasswordHash::make('fourth', $this->uid));

        // Assert — the two newest are refused, the oldest is forgotten
        $this->assertTrue($history->wasUsedBefore($this->uid, 'fourth'));
        $this->assertFalse(
            $history->wasUsedBefore($this->uid, 'first'),
            'the oldest must be forgotten once the limit is passed'
        );
    }

    /**
     * With no table at all, nothing is refused.
     *
     * The case that decides whether this is safe to deploy: an installation whose migration
     * has not run must be able to change passwords. Failing closed here would block the one
     * action somebody takes *because* something has gone wrong.
     */
    public function testWithNoTableNothingIsRefused(): void
    {
        // Arrange
        $this->withHistory(3);
        $history = new PasswordHistory($this->db);
        $history->remember($this->uid, PasswordHash::make('oldpass', $this->uid));
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefix . 'authserver_password_history`');
        $this->db->cacheflush();

        try {
            // Act & Assert
            $this->assertFalse($history->wasUsedBefore($this->uid, 'oldpass'));
        } finally {
            $this->runMigrations([
                \Pramnos\Framework\Migrations\AuthServer\CreatePasswordHistoryTable::class,
            ], $this->db);
        }
    }
}
