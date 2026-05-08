<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Loginlockout;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * Integration tests for Pramnos\Auth\Loginlockout against PostgreSQL 14 / TimescaleDB.
 *
 * Mirrors LoginlockoutMySQLTest but runs against the timescaledb container
 * (host: timescaledb, port: 5432). Because Database::getInstance() is a
 * singleton that defaults to MySQL, each test runs in a separate process
 * so the pg_settings.php fixture takes effect before any MySQL singleton
 * is created by sibling tests.
 *
 * The loginlockout table is a plain PostgreSQL table (no hypertable DDL),
 * so all tests are identical to the MySQL suite. This confirms that the
 * Unix-timestamp storage, LIMIT 1 queries, and prepareQuery() escaping all
 * work correctly on the PostgreSQL dialect.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
#[RunTestsInSeparateProcesses]
class LoginlockoutPostgreSQLTest extends TestCase
{
    protected Database $db;
    protected Loginlockout $lockout;
    protected string $migrationsBase;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        $pgSettingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        $this->migrationsBase = dirname(__DIR__, 3) . '/database/migrations/framework';
        $this->lockout        = new Loginlockout();

        $this->dropTable();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        $this->dropTable();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function dropTable(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS loginlockout CASCADE');
    }

    protected function createTable(): void
    {
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        $dir        = $this->migrationsBase . '/auth';
        $migrations = MigrationLoader::loadFromDirectory($dir, $app);
        usort($migrations, fn($a, $b) => $a->priority <=> $b->priority);

        foreach ($migrations as $m) {
            if (strpos(get_class($m), 'CreateLoginlockoutTable') !== false) {
                $m->up();
                return;
            }
        }
        $this->fail('CreateLoginlockoutTable migration not found');
    }

    // -------------------------------------------------------------------------
    // getLockoutStatus() — unknown key
    // -------------------------------------------------------------------------

    /**
     * getLockoutStatus() must return not-locked with zero remaining seconds when
     * no row exists for the requested scope+identifier pair.
     *
     * This is the baseline state: a brand-new user/IP that has never failed
     * a login must not be treated as locked.
     */
    public function testGetLockoutStatusUnknownKeyReturnsNotLocked(): void
    {
        // Act
        $status = $this->lockout->getLockoutStatus('identifier', 'nobody@example.com');

        // Assert
        $this->assertFalse($status['locked']);
        $this->assertSame(0, $status['remaining']);
    }

    // -------------------------------------------------------------------------
    // recordFailedAttempt() — row creation and incrementing
    // -------------------------------------------------------------------------

    /**
     * The first call to recordFailedAttempt() must create a row in the database.
     *
     * One failure is below every threshold, so no lockout is applied yet.
     * The row must exist and failedattempts must be 1.
     */
    public function testFirstFailedAttemptCreatesRow(): void
    {
        // Act
        $this->lockout->recordFailedAttempt('identifier', 'user@example.com');

        // Assert — row was created
        $result = $this->db->query("SELECT failedattempts, lockoutuntil FROM loginlockout WHERE locktype = 'identifier' AND lookupvalue = 'user@example.com'");
        $this->assertSame(1, $result->numRows, 'row must be created on first failed attempt');
        $this->assertSame(1, (int) $result->fields['failedattempts']);
        $this->assertSame(0, (int) $result->fields['lockoutuntil'], 'no lockout after 1 attempt');
    }

    /**
     * Each subsequent call within the sliding window must increment the counter.
     *
     * After 2 failures (still below the 3-attempt threshold) the row must exist
     * with failedattempts=2 and no active lockout.
     */
    public function testSecondFailedAttemptIncrementsCounter(): void
    {
        // Arrange
        $this->lockout->recordFailedAttempt('identifier', 'user2@example.com');

        // Act
        $this->lockout->recordFailedAttempt('identifier', 'user2@example.com');

        // Assert
        $result = $this->db->query("SELECT failedattempts, lockoutuntil FROM loginlockout WHERE locktype = 'identifier' AND lookupvalue = 'user2@example.com'");
        $this->assertSame(2, (int) $result->fields['failedattempts']);
        $this->assertSame(0, (int) $result->fields['lockoutuntil'], 'no lockout after 2 attempts');
    }

    // -------------------------------------------------------------------------
    // Progressive lockout — threshold tests
    // -------------------------------------------------------------------------

    /**
     * 3 failures must trigger a 60-second lockout.
     *
     * After 3 consecutive calls, getLockoutStatus() must return locked=true
     * with remaining ≈ 60 seconds (allowing a small delta for test execution time).
     */
    public function testThreeFailuresApply60SecondLockout(): void
    {
        // Arrange
        $scope = 'identifier';
        $id    = 'locktest3@example.com';

        // Act
        for ($i = 0; $i < 3; $i++) {
            $this->lockout->recordFailedAttempt($scope, $id);
        }

        // Assert
        $status = $this->lockout->getLockoutStatus($scope, $id);
        $this->assertTrue($status['locked'], 'must be locked after 3 failures');
        $this->assertGreaterThan(55, $status['remaining'], 'remaining must be close to 60 s');
        $this->assertLessThanOrEqual(60, $status['remaining'], 'remaining must not exceed 60 s');
    }

    /**
     * 5 failures must trigger a 300-second lockout.
     *
     * The progressive threshold escalates from 60 s to 300 s at 5 attempts.
     */
    public function testFiveFailuresApply300SecondLockout(): void
    {
        // Arrange
        $scope = 'ip';
        $id    = '192.168.1.1';

        // Act
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailedAttempt($scope, $id);
        }

        // Assert
        $status = $this->lockout->getLockoutStatus($scope, $id);
        $this->assertTrue($status['locked']);
        $this->assertGreaterThan(295, $status['remaining']);
        $this->assertLessThanOrEqual(300, $status['remaining']);
    }

    /**
     * 7 failures must trigger a 900-second lockout.
     */
    public function testSevenFailuresApply900SecondLockout(): void
    {
        // Arrange
        $scope = 'user';
        $id    = '42';

        // Act
        for ($i = 0; $i < 7; $i++) {
            $this->lockout->recordFailedAttempt($scope, $id);
        }

        // Assert
        $status = $this->lockout->getLockoutStatus($scope, $id);
        $this->assertTrue($status['locked']);
        $this->assertGreaterThan(895, $status['remaining']);
        $this->assertLessThanOrEqual(900, $status['remaining']);
    }

    /**
     * 10 failures must trigger the maximum 3600-second lockout.
     */
    public function testTenFailuresApplyMaxLockout(): void
    {
        // Arrange
        $scope = 'identifier';
        $id    = 'maxlock@example.com';

        // Act
        for ($i = 0; $i < 10; $i++) {
            $this->lockout->recordFailedAttempt($scope, $id);
        }

        // Assert
        $status = $this->lockout->getLockoutStatus($scope, $id);
        $this->assertTrue($status['locked']);
        $this->assertGreaterThan(3595, $status['remaining']);
        $this->assertLessThanOrEqual(3600, $status['remaining']);
    }

    // -------------------------------------------------------------------------
    // clearSuccessfulLoginState()
    // -------------------------------------------------------------------------

    /**
     * clearSuccessfulLoginState() must delete the row, resetting all lockout state.
     *
     * After a successful login, the identifier must no longer appear locked,
     * and a subsequent failed attempt must start the counter from 1 (not resume
     * from the previous attempt count).
     */
    public function testClearSuccessfulLoginStateRemovesRow(): void
    {
        // Arrange
        $scope = 'identifier';
        $id    = 'clearme@example.com';
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailedAttempt($scope, $id);
        }
        $before = $this->lockout->getLockoutStatus($scope, $id);
        $this->assertTrue($before['locked'], 'must be locked before clear');

        // Act
        $this->lockout->clearSuccessfulLoginState($scope, $id);

        // Assert — row is gone
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM loginlockout WHERE locktype = 'identifier' AND lookupvalue = 'clearme@example.com'");
        $this->assertSame(0, (int) $result->fields['cnt'], 'row must be deleted after clearSuccessfulLoginState()');

        $after = $this->lockout->getLockoutStatus($scope, $id);
        $this->assertFalse($after['locked']);
        $this->assertSame(0, $after['remaining']);
    }

    /**
     * After clear, a fresh failure must start at attempt count 1.
     *
     * This confirms that clearSuccessfulLoginState() fully resets state: the next
     * recordFailedAttempt() is treated as the first failure, not an increment.
     */
    public function testAfterClearNewFailureStartsFromOne(): void
    {
        // Arrange
        $scope = 'identifier';
        $id    = 'restart@example.com';
        for ($i = 0; $i < 10; $i++) {
            $this->lockout->recordFailedAttempt($scope, $id);
        }
        $this->lockout->clearSuccessfulLoginState($scope, $id);

        // Act
        $this->lockout->recordFailedAttempt($scope, $id);

        // Assert — counter is 1, no lockout
        $result = $this->db->query("SELECT failedattempts, lockoutuntil FROM loginlockout WHERE locktype = 'identifier' AND lookupvalue = 'restart@example.com'");
        $this->assertSame(1, (int) $result->fields['failedattempts']);
        $this->assertSame(0, (int) $result->fields['lockoutuntil']);
    }

    // -------------------------------------------------------------------------
    // Sliding window behaviour
    // -------------------------------------------------------------------------

    /**
     * Failures outside the sliding window must not count toward the lockout.
     *
     * When lastfailedat is older than DEFAULT_WINDOW_SECONDS, the counter resets
     * to 1. We simulate an expired window by directly backdating lastfailedat
     * via a raw INSERT.
     */
    public function testOldFailuresOutsideWindowAreDiscarded(): void
    {
        // Arrange — insert a row as if 9 failures happened long ago
        $scope   = 'identifier';
        $id      = 'oldwindow@example.com';
        $oldTime = time() - Loginlockout::DEFAULT_WINDOW_SECONDS - 60;

        $this->db->query(
            "INSERT INTO loginlockout
             (locktype, lookupvalue, failedattempts, firstfailedat, lastfailedat, lockoutuntil, createdat, updatedat)
             VALUES ('identifier', 'oldwindow@example.com', 9, {$oldTime}, {$oldTime}, 0, {$oldTime}, {$oldTime})"
        );

        // Act
        $this->lockout->recordFailedAttempt($scope, $id);

        // Assert
        $result = $this->db->query("SELECT failedattempts, lockoutuntil FROM loginlockout WHERE locktype = 'identifier' AND lookupvalue = 'oldwindow@example.com'");
        $this->assertSame(1, (int) $result->fields['failedattempts'],
            'counter must reset when last failure is outside the sliding window');
        $this->assertSame(0, (int) $result->fields['lockoutuntil'],
            'no lockout must be applied after window reset');
    }

    // -------------------------------------------------------------------------
    // Scope isolation
    // -------------------------------------------------------------------------

    /**
     * Lockout state is isolated per scope — failures on 'ip' must not affect 'user'.
     *
     * The unique key is (locktype, lookupvalue), so the same value can appear in
     * multiple scopes without collision.
     */
    public function testScopesAreIsolated(): void
    {
        // Arrange
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailedAttempt('ip', '10.0.0.1');
        }

        // Act
        $userStatus = $this->lockout->getLockoutStatus('user', '10.0.0.1');
        $ipStatus   = $this->lockout->getLockoutStatus('ip', '10.0.0.1');

        // Assert
        $this->assertFalse($userStatus['locked'], 'user scope must be unaffected by ip scope failures');
        $this->assertTrue($ipStatus['locked'], 'ip scope must be locked after 5 failures');
    }
}
