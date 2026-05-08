<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Loginlockout;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * Integration tests for Pramnos\Auth\Loginlockout against MySQL 8.0.
 *
 * Verifies that the progressive brute-force lockout state machine correctly
 * persists to and reads from the loginlockout table via real SQL — not mocks.
 *
 * Coverage:
 * - recordFailedAttempt() inserts a new row on first call per scope+identifier
 * - Subsequent calls within the window increment the attempt counter
 * - The correct lockout duration is applied at each threshold (3/5/7/10+)
 * - getLockoutStatus() returns locked=false before the first threshold
 * - getLockoutStatus() returns locked=true + correct remaining seconds after threshold
 * - getLockoutStatus() returns ['locked'=>false,'remaining'=>0] for unknown keys
 * - clearSuccessfulLoginState() removes the row, resetting all state
 * - Failures outside the sliding window restart the counter from 1
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class LoginlockoutMySQLTest extends TestCase
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

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
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
        $this->db->query('DROP TABLE IF EXISTS loginlockout');
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

        // Act — simulate 3 failures
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
     * Remaining seconds must be within the expected window.
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
        // Arrange — build up a lockout
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

        // Assert — status is clean
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
        // Arrange — build up to a lockout, then clear
        $scope = 'identifier';
        $id    = 'restart@example.com';
        for ($i = 0; $i < 10; $i++) {
            $this->lockout->recordFailedAttempt($scope, $id);
        }
        $this->lockout->clearSuccessfulLoginState($scope, $id);

        // Act — one new failure after clear
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
     * to 1. This prevents indefinite accumulation of old failures.
     *
     * We simulate an expired window by directly backdating the lastfailedat
     * column in the database to a timestamp older than the window.
     */
    public function testOldFailuresOutsideWindowAreDiscarded(): void
    {
        // Arrange — insert a row as if 9 failures happened long ago
        $scope    = 'identifier';
        $id       = 'oldwindow@example.com';
        $oldTime  = time() - Loginlockout::DEFAULT_WINDOW_SECONDS - 60; // well outside window

        $this->db->query(
            "INSERT INTO loginlockout
             (locktype, lookupvalue, failedattempts, firstfailedat, lastfailedat, lockoutuntil, createdat, updatedat)
             VALUES ('identifier', 'oldwindow@example.com', 9, {$oldTime}, {$oldTime}, 0, {$oldTime}, {$oldTime})"
        );

        // Act — one new failure; window has expired so counter should reset to 1
        $this->lockout->recordFailedAttempt($scope, $id);

        // Assert — counter is 1, not 10
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
     * The unique key is (locktype, lookupvalue), so the same value can exist in
     * multiple scopes without collision.
     */
    public function testScopesAreIsolated(): void
    {
        // Arrange — 5 failures on 'ip' scope
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailedAttempt('ip', '10.0.0.1');
        }

        // Act — check 'user' scope with the same string value
        $userStatus = $this->lockout->getLockoutStatus('user', '10.0.0.1');
        $ipStatus   = $this->lockout->getLockoutStatus('ip', '10.0.0.1');

        // Assert — ip is locked, user is not
        $this->assertFalse($userStatus['locked'], 'user scope must be unaffected by ip scope failures');
        $this->assertTrue($ipStatus['locked'], 'ip scope must be locked after 5 failures');
    }
}
