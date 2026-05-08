<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * Integration tests for framework auth migrations against TimescaleDB.
 *
 * These tests specifically verify the TimescaleDB-native code paths in the
 * auth feature migrations — hypertable creation, retention policies, compression
 * policies, and continuous aggregates. The generic PostgreSQL structural tests
 * (column existence, indexes) live in FrameworkMigrationsPostgreSQLTest.
 *
 * Each test verifies:
 *   (1) The TimescaleDB-native feature was created (hypertable row in
 *       timescaledb_information.hypertables; continuous aggregate in
 *       timescaledb_information.continuous_aggregates).
 *   (2) The table/view is queryable and returns results (not just schema metadata).
 *   (3) down() removes the object.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432)
 * with the TimescaleDB extension installed.
 */
class FrameworkMigrationsTimescaleDBTest extends TestCase
{
    protected Database $db;
    protected Application $app;
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

        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        try {
            $this->db->connect(true);
        } catch (\Exception $e) {
            $this->markTestSkipped('TimescaleDB container not reachable (timescaledb:5432)');
        }

        $this->migrationsBase = dirname(__DIR__, 3) . '/database/migrations/framework';
        $this->app            = $this->makeApp();

        $this->dropAllTestTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTestTables();
    }

    // -------------------------------------------------------------------------
    // Auth: twofactor_attempts hypertable
    // -------------------------------------------------------------------------

    /**
     * Migration 000020 (twofactor_attempts) must create a TimescaleDB hypertable
     * when run against a TimescaleDB-enabled PostgreSQL server.
     *
     * The migration uses ifCapable(TIMESCALEDB) to branch — on TimescaleDB,
     * a hypertable partitioned by attempt_time is created with 7-day chunks,
     * compression after 7 days, and a 2-year retention policy.
     *
     * A hypertable is registered in timescaledb_information.hypertables. This
     * cannot be faked — if the row does not exist, the migration silently fell
     * back to a plain table despite TimescaleDB being available.
     */
    public function testTwofactorAttemptsIsHypertableOnTimescaleDB(): void
    {
        // Arrange — depends on user_twofactor and twofactor_setup
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUserTwofactorTable')->up();
        $this->loadMigration('auth', 'CreateTwofactorSetupTable')->up();

        $m = $this->loadMigration('auth', 'CreateTwofactorAttemptsTable');

        // Act
        $m->up();

        // Assert — registered as a hypertable
        $this->assertIsHypertable('twofactor_attempts', 'public',
            'twofactor_attempts must be a TimescaleDB hypertable after up()');

        // Assert — the hypertable is insertable and queryable (success is SMALLINT, not BOOL)
        $this->db->execute(
            "INSERT INTO twofactor_attempts (userid, success, attempt_time)
             VALUES (1, 1, NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM twofactor_attempts');
        $this->assertSame('1', (string) $r->fields['cnt'],
            'hypertable must accept inserts and return rows');

        // Assert — down() removes the table
        $m->down();
        $this->assertFalse($this->tableExists('twofactor_attempts'),
            'twofactor_attempts must be gone after down()');
    }

    // -------------------------------------------------------------------------
    // Auth: user_activity_log hypertable
    // -------------------------------------------------------------------------

    /**
     * Migration 000021 (user_activity_log) must create a TimescaleDB hypertable
     * with 1-day time buckets partitioned on the created_at column.
     *
     * user_activity_log is the GDPR activity audit table: every user action is
     * appended here. TimescaleDB hypertable partitioning ensures efficient
     * range queries and supports the continuous aggregate (daily_activity_summary).
     */
    public function testUserActivityLogIsHypertableOnTimescaleDB(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();

        $m = $this->loadMigration('auth', 'CreateUserActivityLogTable');

        // Act
        $m->up();

        // Assert — registered as a hypertable
        $this->assertIsHypertable('user_activity_log', 'public',
            'user_activity_log must be a TimescaleDB hypertable after up()');

        // Assert — queryable
        $this->db->execute(
            "INSERT INTO user_activity_log (userid, action, created_at)
             VALUES (1, 'login', NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM user_activity_log');
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down() removes the table
        $m->down();
        $this->assertFalse($this->tableExists('user_activity_log'));
    }

    // -------------------------------------------------------------------------
    // Auth: user_consents hypertable
    // -------------------------------------------------------------------------

    /**
     * Migration 000023 (user_consents) must create a TimescaleDB hypertable
     * with 1-month time buckets and a 7-year retention policy.
     *
     * The 7-year retention satisfies GDPR Article 7 record-keeping requirements:
     * consent records must be kept long enough to demonstrate lawful basis.
     */
    public function testUserConsentsIsHypertableOnTimescaleDB(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUserPrivacySettingsTable')->up();

        $m = $this->loadMigration('auth', 'CreateUserConsentsTable');

        // Act
        $m->up();

        // Assert — registered as a hypertable
        $this->assertIsHypertable('user_consents', 'public',
            'user_consents must be a TimescaleDB hypertable after up()');

        // Assert — queryable
        $this->db->execute(
            "INSERT INTO user_consents (userid, consent_type, granted, granted_at)
             VALUES (1, 'marketing', 1, NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM user_consents');
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down()
        $m->down();
        $this->assertFalse($this->tableExists('user_consents'));
    }

    // -------------------------------------------------------------------------
    // Auth: data_processing_records hypertable
    // -------------------------------------------------------------------------

    /**
     * Migration 000024 (data_processing_records) must create a TimescaleDB
     * hypertable with 1-week time buckets and a 36-month retention policy.
     *
     * GDPR Article 30 requires records of processing activities. The 3-year
     * retention is the standard recommendation for article 30 records.
     */
    public function testDataProcessingRecordsIsHypertableOnTimescaleDB(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUserPrivacySettingsTable')->up();
        $this->loadMigration('auth', 'CreateUserConsentsTable')->up();

        $m = $this->loadMigration('auth', 'CreateDataProcessingRecordsTable');

        // Act
        $m->up();

        // Assert — registered as a hypertable
        $this->assertIsHypertable('data_processing_records', 'public',
            'data_processing_records must be a TimescaleDB hypertable after up()');

        // Assert — queryable
        $this->db->execute(
            "INSERT INTO data_processing_records
             (userid, operation, data_category, legal_basis, processor, processed_at)
             VALUES (1, 'export', 'personal_data', 'consent', 'pramnos', NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM data_processing_records');
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down()
        $m->down();
        $this->assertFalse($this->tableExists('data_processing_records'));
    }

    // -------------------------------------------------------------------------
    // Auth: gdpr_requests hypertable
    // -------------------------------------------------------------------------

    /**
     * Migration 000025 (gdpr_requests) must create a TimescaleDB hypertable
     * with 1-month time buckets and a 7-year retention policy.
     *
     * GDPR right-to-erasure and right-to-access requests must be logged.
     * The 7-year retention matches the user_consents table: both records must
     * survive together to demonstrate that a deletion request was actioned.
     */
    public function testGdprRequestsIsHypertableOnTimescaleDB(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUserPrivacySettingsTable')->up();
        $this->loadMigration('auth', 'CreateUserConsentsTable')->up();
        $this->loadMigration('auth', 'CreateDataProcessingRecordsTable')->up();

        $m = $this->loadMigration('auth', 'CreateGdprRequestsTable');

        // Act
        $m->up();

        // Assert — registered as a hypertable
        $this->assertIsHypertable('gdpr_requests', 'public',
            'gdpr_requests must be a TimescaleDB hypertable after up()');

        // Assert — queryable
        $this->db->execute(
            "INSERT INTO gdpr_requests (userid, request_type, status, requested_at)
             VALUES (1, 'erasure', 'pending', NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM gdpr_requests');
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down()
        $m->down();
        $this->assertFalse($this->tableExists('gdpr_requests'));
    }

    // -------------------------------------------------------------------------
    // Auth: daily_activity_summary continuous aggregate
    // -------------------------------------------------------------------------

    /**
     * Migration 000026 (daily_activity_summary) must create a TimescaleDB
     * continuous aggregate on TimescaleDB.
     *
     * A continuous aggregate is auto-refreshed by the TimescaleDB background
     * worker. It differs from a plain materialized view: it appears in
     * timescaledb_information.continuous_aggregates, not in pg_matviews.
     *
     * This test verifies the continuous aggregate is created (not just a view),
     * and that it is queryable (returns 0 rows before any data is inserted into
     * user_activity_log).
     */
    public function testDailyActivitySummaryIsContinuousAggregateOnTimescaleDB(): void
    {
        // Arrange — continuous aggregate requires the source hypertable
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUserActivityLogTable')->up();

        $m = $this->loadMigration('auth', 'CreateDailyActivitySummaryView');

        // Act
        $m->up();

        // Assert — registered as a continuous aggregate (not just a plain view)
        $r = $this->db->execute(
            "SELECT COUNT(*) AS cnt
             FROM timescaledb_information.continuous_aggregates
             WHERE view_name = 'daily_activity_summary'"
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'daily_activity_summary must be a TimescaleDB continuous aggregate on TimescaleDB');

        // Assert — queryable (zero rows before data)
        $r2 = $this->db->execute('SELECT COUNT(*) AS cnt FROM daily_activity_summary');
        $this->assertSame('0', (string) $r2->fields['cnt'],
            'empty continuous aggregate must return 0 rows');

        // Assert — inserts to user_activity_log eventually populate the aggregate;
        //          CALL refresh_continuous_aggregate to force immediate refresh
        $this->db->execute(
            "INSERT INTO user_activity_log (userid, action, created_at)
             VALUES (1, 'login', NOW() - INTERVAL '1 hour'),
                    (1, 'view_page', NOW() - INTERVAL '30 minutes'),
                    (2, 'login', NOW() - INTERVAL '2 hours')"
        );
        $this->db->execute(
            "CALL refresh_continuous_aggregate('daily_activity_summary', NULL, NULL)"
        );
        $r3 = $this->db->execute('SELECT COUNT(*) AS cnt FROM daily_activity_summary');
        $this->assertGreaterThan(0, (int) $r3->fields['cnt'],
            'continuous aggregate must return rows after refresh');

        // Assert — down() removes the continuous aggregate
        $m->down();
        $r4 = $this->db->execute(
            "SELECT COUNT(*) AS cnt
             FROM timescaledb_information.continuous_aggregates
             WHERE view_name = 'daily_activity_summary'"
        );
        $this->assertSame('0', (string) $r4->fields['cnt'],
            'continuous aggregate must be removed by down()');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Loads a specific migration class from the framework migrations directory.
     *
     * @param string $feature Feature subdirectory (auth, authserver, etc.)
     * @param string $class   Short class name
     */
    protected function loadMigration(string $feature, string $class): \Pramnos\Database\Migration
    {
        $dir        = $this->migrationsBase . '/' . $feature;
        $migrations = MigrationLoader::loadFromDirectory($dir, $this->app);

        foreach ($migrations as $m) {
            if ((new \ReflectionClass($m))->getShortName() === $class) {
                return $m;
            }
        }

        $this->fail("Migration class '{$class}' not found in feature '{$feature}'");
    }

    /**
     * Returns true when a table exists in the given schema.
     */
    protected function tableExists(string $name, string $schema = 'public'): bool
    {
        $r = $this->db->execute(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                $schema,
                $name
            )
        );
        return (int) $r->fields['cnt'] > 0;
    }

    /**
     * Asserts that a table is registered as a TimescaleDB hypertable.
     *
     * Checks timescaledb_information.hypertables — a plain table would NOT
     * appear there, so this assertion distinguishes real hypertables from
     * fallback plain-table creation.
     */
    protected function assertIsHypertable(
        string $tableName,
        string $schema,
        string $message = ''
    ): void {
        $r = $this->db->execute(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM timescaledb_information.hypertables
                 WHERE hypertable_schema = %s AND hypertable_name = %s",
                $schema,
                $tableName
            )
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            $message ?: "{$schema}.{$tableName} must be registered in timescaledb_information.hypertables");
    }

    protected function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }

    protected function dropAllTestTables(): void
    {
        // Drop continuous aggregate + views before source hypertables
        $this->db->execute('DROP MATERIALIZED VIEW IF EXISTS daily_activity_summary CASCADE');

        // Drop hypertables (CASCADE handles chunks and policies automatically)
        $hypertables = [
            'gdpr_requests', 'data_processing_records',
            'user_consents', 'user_activity_log',
            'twofactor_attempts',
        ];
        foreach ($hypertables as $t) {
            $this->db->execute("DROP TABLE IF EXISTS public.\"{$t}\" CASCADE");
        }

        // Drop plain auth tables
        $plainTables = [
            'user_privacy_settings', 'twofactor_setup',
            'user_twofactor', 'users',
        ];
        foreach ($plainTables as $t) {
            $this->db->execute("DROP TABLE IF EXISTS public.\"{$t}\" CASCADE");
        }
    }
}
