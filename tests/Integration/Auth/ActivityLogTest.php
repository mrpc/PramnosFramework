<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\Settings;
use Pramnos\Auth\ActivityLog;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Integration tests for {@see \Pramnos\Auth\ActivityLog} against a real MySQL
 * database.
 *
 * ActivityLog is the single writer for authserver.user_activity_log. These
 * tests verify the observable end-to-end behaviour — a row is actually written
 * with the expected columns — as well as every guard/failure branch that must
 * NEVER throw into (and so never break) a login/logout:
 *   - input guards (empty user id / action),
 *   - the feature gate (auth feature off ⇒ silent no-op),
 *   - the table-existence probe (table absent ⇒ silent no-op) and its memoization,
 *   - a database failure during insert (swallowed, never rethrown).
 *
 * The table is built from the canonical migration
 * ({@see \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable}) so the
 * test exercises the production schema, not a hand-rolled copy.
 *
 * Runs on MySQL only (schema-qualified authserver.* maps to the authserver_
 * prefix there); the QueryBuilder abstracts the driver, so the logic under test
 * is identical on PostgreSQL.
 */
#[CoversClass(ActivityLog::class)]
class ActivityLogTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;

    /** Physical table name (authserver schema → authserver_ prefix on MySQL). */
    private string $table;

    /** Snapshot of FeatureRegistry::$enabled to restore after each test. */
    private array $enabledSnapshot = [];

    protected function setUp(): void
    {
        // Own bootstrap (MySQL) — deliberately NOT parent::setUp(): we only need
        // BaseTestCase for its runMigrations() helper, not a full app init.
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('ActivityLogTest runs on MySQL only.');
        }

        $this->table = $this->db->prefix . 'authserver_user_activity_log';

        // Snapshot + enable the 'auth' feature (ActivityLog::record gates on it).
        $prop = new \ReflectionProperty(FeatureRegistry::class, 'enabled');
        $this->enabledSnapshot = $prop->getValue();
        FeatureRegistry::loadFromConfig(['auth']);

        // Fresh table from the real migration; reset the memoized probe.
        $this->db->query("DROP TABLE IF EXISTS `{$this->table}`");
        $this->runMigrations(
            [\Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class],
            $this->db
        );
        ActivityLog::resetTableCache();

        $_SERVER['REMOTE_ADDR']     = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/ActivityLog';
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `{$this->table}`");
        // Restore FeatureRegistry so we don't leak 'auth' into other tests.
        $prop = new \ReflectionProperty(FeatureRegistry::class, 'enabled');
        $prop->setValue(null, $this->enabledSnapshot);
        ActivityLog::resetTableCache();
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    /** Count rows currently in the activity-log table. */
    private function rowCount(): int
    {
        return (int) $this->db->queryBuilder()->table('authserver.user_activity_log')->count();
    }

    /**
     * The happy path writes exactly one row carrying the action, JSON details
     * and the request's IP / user-agent.
     */
    public function testRecordInsertsRowWithAllColumns(): void
    {
        // Act
        ActivityLog::record(42, 'login', ['method' => 'password']);

        // Assert — a single row with the expected column values.
        $row = $this->db->queryBuilder()
            ->table('authserver.user_activity_log')
            ->where('userid', 42)
            ->first();
        $this->assertSame(1, $this->rowCount(), 'Exactly one row must be written');
        $this->assertSame('login', $row->fields['action']);
        $this->assertSame(42, (int) $row->fields['userid']);
        $this->assertSame('203.0.113.7', $row->fields['ip_address']);
        $this->assertSame('PHPUnit/ActivityLog', $row->fields['user_agent']);
        $this->assertSame(['method' => 'password'], json_decode((string) $row->fields['details'], true),
            'details must be stored as the JSON-encoded context array');
    }

    /**
     * An action longer than the column (100 chars) is truncated, and empty
     * details are stored as SQL NULL rather than an empty JSON string.
     */
    public function testRecordTruncatesActionAndNullsEmptyDetails(): void
    {
        // Act
        ActivityLog::record(7, str_repeat('x', 150));

        // Assert
        $row = $this->db->queryBuilder()->table('authserver.user_activity_log')->where('userid', 7)->first();
        $this->assertSame(100, strlen((string) $row->fields['action']), 'action must be truncated to 100 chars');
        $this->assertNull($row->fields['details'], 'empty details must be stored as NULL');
    }

    /**
     * Missing REMOTE_ADDR / HTTP_USER_AGENT are stored as NULL (not "").
     */
    public function testRecordWithoutServerVarsStoresNulls(): void
    {
        // Arrange
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

        // Act
        ActivityLog::record(9, 'logout');

        // Assert
        $row = $this->db->queryBuilder()->table('authserver.user_activity_log')->where('userid', 9)->first();
        $this->assertNull($row->fields['ip_address']);
        $this->assertNull($row->fields['user_agent']);
    }

    /**
     * Input guards: a non-positive user id or an empty action is a no-op that
     * never touches the database.
     */
    public function testInputGuardsAreNoOps(): void
    {
        // Act
        ActivityLog::record(0, 'login');
        ActivityLog::record(-5, 'login');
        ActivityLog::record(42, '');

        // Assert
        $this->assertSame(0, $this->rowCount(), 'Guarded calls must write nothing');
    }

    /**
     * When the 'auth' feature is disabled the writer short-circuits before
     * touching the database.
     */
    public function testDisabledFeatureIsNoOp(): void
    {
        // Arrange — clear enabled features so isEnabled('auth') is false.
        $prop = new \ReflectionProperty(FeatureRegistry::class, 'enabled');
        $prop->setValue(null, []);

        // Act
        ActivityLog::record(42, 'login');

        // Assert
        $this->assertSame(0, $this->rowCount(), 'A disabled auth feature must skip the write');
    }

    /**
     * When the table does not exist the writer is a silent no-op and never
     * throws (a missing table = a legacy install past the migration cutoff).
     */
    public function testMissingTableIsSilentNoOp(): void
    {
        // Arrange — remove the table and clear the memoized probe.
        $this->db->query("DROP TABLE IF EXISTS `{$this->table}`");
        ActivityLog::resetTableCache();

        // Act + Assert — must not throw.
        ActivityLog::record(42, 'login');
        $this->assertTrue(true, 'record() must not throw when the table is absent');
    }

    /**
     * A database failure during the insert is swallowed (logged, never
     * rethrown) so a logging failure can never break the caller. We force the
     * failure by dropping the table but leaving the memoized probe reporting
     * "available", so record() proceeds to an insert that fails.
     */
    public function testInsertFailureIsSwallowed(): void
    {
        // Arrange — probe says the table exists, but it does not.
        ActivityLog::resetTableCache();
        $this->rowCount(); // touch connection
        $this->db->query("DROP TABLE IF EXISTS `{$this->table}`");
        $probe = new \ReflectionProperty(ActivityLog::class, 'tableAvailable');
        $probe->setValue(null, true);

        // Act + Assert — the insert fails internally but record() returns quietly.
        ActivityLog::record(42, 'login');
        $this->assertTrue(true, 'record() must swallow a database failure');

        // Recreate for tearDown symmetry.
        $this->runMigrations(
            [\Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class],
            $this->db
        );
    }

    /**
     * If the table-existence probe itself throws, it is treated as "unavailable"
     * and record() is a silent no-op — the probe exception is swallowed.
     */
    public function testTableProbeExceptionIsSwallowed(): void
    {
        // Arrange — swap in a DB whose tableExists() throws.
        $throwingDb = $this->getMockBuilder(\Pramnos\Database\Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['tableExists'])
            ->getMock();
        $throwingDb->method('tableExists')
            ->willThrowException(new \RuntimeException('probe boom'));

        $ref  = &\Pramnos\Database\Database::getInstance();
        $orig = $ref;
        $ref  = $throwingDb;
        ActivityLog::resetTableCache();

        try {
            // Act + Assert — must not throw despite the probe failure.
            ActivityLog::record(42, 'login');
            $this->assertTrue(true, 'a throwing table probe must be swallowed');
        } finally {
            $ref = $orig; // restore the real singleton
            ActivityLog::resetTableCache();
        }
    }

    /**
     * resetTableCache() forces the next call to re-probe: after a reset the
     * writer sees a freshly-created table and writes again.
     */
    public function testResetTableCacheReprobes(): void
    {
        // Arrange — first call memoizes "available" and writes.
        ActivityLog::record(1, 'login');
        $this->assertSame(1, $this->rowCount());

        // Reset the probe and record again — still works (re-probes true).
        ActivityLog::resetTableCache();
        ActivityLog::record(2, 'logout');
        $this->assertSame(2, $this->rowCount(), 'A second write after a cache reset must still land');
    }
}
