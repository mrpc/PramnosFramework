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

        // Ensure the authserver schema exists before any migration runs.
        // Auth migrations 000017-000026 now create their tables in authserver.*;
        // the schema creation migration must run first.
        $this->db->execute('CREATE SCHEMA IF NOT EXISTS authserver');
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

        // Assert — registered as a hypertable in the authserver schema
        $this->assertIsHypertable('twofactor_attempts', 'authserver',
            'twofactor_attempts must be a TimescaleDB hypertable after up()');

        // Assert — the hypertable is insertable and queryable
        $this->db->execute(
            "INSERT INTO authserver.twofactor_attempts (userid, success, attempt_time)
             VALUES (1, TRUE, NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.twofactor_attempts');
        $this->assertSame('1', (string) $r->fields['cnt'],
            'hypertable must accept inserts and return rows');

        // Assert — down() removes the table
        $m->down();
        $this->assertFalse($this->tableExists('twofactor_attempts', 'authserver'),
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

        // Assert — registered as a hypertable in the authserver schema
        $this->assertIsHypertable('user_activity_log', 'authserver',
            'user_activity_log must be a TimescaleDB hypertable after up()');

        // Assert — queryable
        $this->db->execute(
            "INSERT INTO authserver.user_activity_log (userid, action, created_at)
             VALUES (1, 'login', NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.user_activity_log');
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down() removes the table
        $m->down();
        $this->assertFalse($this->tableExists('user_activity_log', 'authserver'));
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

        // Assert — registered as a hypertable in the authserver schema
        $this->assertIsHypertable('user_consents', 'authserver',
            'user_consents must be a TimescaleDB hypertable after up()');

        // Assert — queryable
        $this->db->execute(
            "INSERT INTO authserver.user_consents (userid, consent_type, granted, granted_at)
             VALUES (1, 'marketing', 1, NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.user_consents');
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down()
        $m->down();
        $this->assertFalse($this->tableExists('user_consents', 'authserver'));
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

        // Assert — registered as a hypertable in the authserver schema
        $this->assertIsHypertable('data_processing_records', 'authserver',
            'data_processing_records must be a TimescaleDB hypertable after up()');

        // Assert — queryable
        $this->db->execute(
            "INSERT INTO authserver.data_processing_records
             (userid, operation, data_category, legal_basis, processor, processed_at)
             VALUES (1, 'export', 'personal_data', 'consent', 'pramnos', NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.data_processing_records');
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down()
        $m->down();
        $this->assertFalse($this->tableExists('data_processing_records', 'authserver'));
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

        // Assert — registered as a hypertable in the authserver schema
        $this->assertIsHypertable('gdpr_requests', 'authserver',
            'gdpr_requests must be a TimescaleDB hypertable after up()');

        // Assert — queryable
        $this->db->execute(
            "INSERT INTO authserver.gdpr_requests (userid, request_type, status, requested_at)
             VALUES (1, 'erasure', 'pending', NOW())"
        );
        $r = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.gdpr_requests');
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down()
        $m->down();
        $this->assertFalse($this->tableExists('gdpr_requests', 'authserver'));
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

        // Assert — registered as a continuous aggregate in the authserver schema
        $r = $this->db->execute(
            "SELECT COUNT(*) AS cnt
             FROM timescaledb_information.continuous_aggregates
             WHERE view_schema = 'authserver' AND view_name = 'daily_activity_summary'"
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'authserver.daily_activity_summary must be a TimescaleDB continuous aggregate');

        // Assert — queryable (zero rows before data)
        $r2 = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.daily_activity_summary');
        $this->assertSame('0', (string) $r2->fields['cnt'],
            'empty continuous aggregate must return 0 rows');

        // Assert — inserts to authserver.user_activity_log eventually populate the aggregate;
        //          CALL refresh_continuous_aggregate to force immediate refresh
        $this->db->execute(
            "INSERT INTO authserver.user_activity_log (userid, action, created_at)
             VALUES (1, 'login', NOW() - INTERVAL '1 hour'),
                    (1, 'view_page', NOW() - INTERVAL '30 minutes'),
                    (2, 'login', NOW() - INTERVAL '2 hours')"
        );
        $this->db->execute(
            "CALL refresh_continuous_aggregate('authserver.daily_activity_summary', NULL, NULL)"
        );
        $r3 = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.daily_activity_summary');
        $this->assertGreaterThan(0, (int) $r3->fields['cnt'],
            'continuous aggregate must return rows after refresh');

        // Assert — down() removes the continuous aggregate
        $m->down();
        $r4 = $this->db->execute(
            "SELECT COUNT(*) AS cnt
             FROM timescaledb_information.continuous_aggregates
             WHERE view_schema = 'authserver' AND view_name = 'daily_activity_summary'"
        );
        $this->assertSame('0', (string) $r4->fields['cnt'],
            'continuous aggregate must be removed by down()');
    }

    // =========================================================================
    // AuthServer: daily_2fa_stats continuous aggregate (000046)
    // =========================================================================

    /**
     * CreateAuthserverViews must create authserver.daily_2fa_stats as a
     * TimescaleDB continuous aggregate (not a plain materialized view) when
     * the database supports TimescaleDB.
     *
     * A continuous aggregate is auto-refreshed by the TimescaleDB background
     * worker using time_bucket() over the twofactor_attempts hypertable.
     * It appears in timescaledb_information.continuous_aggregates and can be
     * force-refreshed with CALL refresh_continuous_aggregate().
     *
     * This test verifies:
     *   1. The aggregate is registered as a continuous aggregate (not a plain matview).
     *   2. It is queryable and returns 0 rows before data is inserted.
     *   3. After inserting rows and force-refreshing, it returns aggregated data.
     *   4. down() removes the continuous aggregate.
     */
    public function testDailyTwoFaStatsIsContinuousAggregateOnTimescaleDB(): void
    {
        // Arrange — source hypertable (twofactor_attempts) and all view dependencies must exist
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('auth', 'CreateLoginlockoutTable')->up();
        $this->loadMigration('auth', 'CreateUserTwofactorTable')->up();
        $this->loadMigration('auth', 'CreateTwofactorSetupTable')->up();
        $this->loadMigration('auth', 'CreateTwofactorAttemptsTable')->up();
        $this->loadMigration('auth', 'CreateUserActivityLogTable')->up();
        $this->loadMigration('auth', 'CreateUserConsentsTable')->up();
        $this->loadMigration('auth', 'CreateUserPrivacySettingsTable')->up();
        $this->loadMigration('auth', 'CreateGdprRequestsTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateAuthserverViews');

        // Act
        $m->up();

        // Assert — registered as a continuous aggregate, not a plain materialized view
        $r = $this->db->execute(
            "SELECT COUNT(*) AS cnt
             FROM timescaledb_information.continuous_aggregates
             WHERE view_schema = 'authserver' AND view_name = 'daily_2fa_stats'"
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'authserver.daily_2fa_stats must be a TimescaleDB continuous aggregate');

        // Assert — queryable before any data
        $r2 = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.daily_2fa_stats');
        $this->assertSame('0', (string) $r2->fields['cnt'],
            'empty continuous aggregate must return 0 rows before data');

        // Assert — insert data and force-refresh; the aggregate must populate
        $this->db->execute(
            "INSERT INTO authserver.twofactor_attempts
                (userid, ip_address, code_used, user_agent, attempt_time, success)
             VALUES
                (1, '1.2.3.4', 'totp', 'browser', NOW() - INTERVAL '2 hours', TRUE),
                (2, '5.6.7.8', 'totp', 'browser', NOW() - INTERVAL '1 hour',  FALSE)"
        );
        $this->db->execute(
            "CALL refresh_continuous_aggregate('authserver.daily_2fa_stats', NULL, NULL)"
        );
        $r3 = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.daily_2fa_stats');
        $this->assertGreaterThan(0, (int) $r3->fields['cnt'],
            'continuous aggregate must return rows after refresh');

        // Assert — down() removes the continuous aggregate
        $m->down();
        $r4 = $this->db->execute(
            "SELECT COUNT(*) AS cnt
             FROM timescaledb_information.continuous_aggregates
             WHERE view_schema = 'authserver' AND view_name = 'daily_2fa_stats'"
        );
        $this->assertSame('0', (string) $r4->fields['cnt'],
            'authserver.daily_2fa_stats must be removed by down()');
    }

    // =========================================================================
    // Applications schema — application_stats hypertable (000045)
    // =========================================================================

    /**
     * CreateApplicationStatsTable must register applications.application_stats
     * as a TimescaleDB hypertable when the database supports it.
     *
     * The hypertable uses 14-day chunks on the `time` column.  A compression
     * policy is set so chunks older than 30 days are compressed automatically.
     * This test verifies both the hypertable registration and that the table
     * is queryable after creation.
     */
    public function testApplicationStatsIsHypertableOnTimescaleDB(): void
    {
        // Arrange — schema + FK parents must exist first
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $this->loadMigration('applications', 'CreateApplicationSettingsTable')->up();
        $m = $this->loadMigration('applications', 'CreateApplicationStatsTable');

        // Act
        $m->up();

        // Assert — table exists in applications schema
        $r = $this->db->execute(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'applications',
                'application_stats'
            )
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'applications.application_stats must exist after up()');

        // Assert — registered as a hypertable on TimescaleDB
        $this->assertIsHypertable('application_stats', 'applications',
            'applications.application_stats must be a TimescaleDB hypertable');

        // Assert — queryable (no rows before data is inserted)
        $r2 = $this->db->execute('SELECT COUNT(*) AS cnt FROM applications.application_stats');
        $this->assertSame('0', (string) $r2->fields['cnt'],
            'empty hypertable must return 0 rows');

        // Assert — down() removes the table (and TimescaleDB drops associated metadata)
        $m->down();
        $r3 = $this->db->execute(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'applications',
                'application_stats'
            )
        );
        $this->assertSame('0', (string) $r3->fields['cnt'],
            'applications.application_stats must be gone after down()');
    }

    // =========================================================================
    // Applications schema — application_stats_daily/hourly continuous aggregates (000046)
    // =========================================================================

    /**
     * CreateApplicationsViews must create application_stats_daily and
     * application_stats_hourly as TimescaleDB continuous aggregates when the
     * database supports TimescaleDB.
     *
     * Both views aggregate applications.application_stats (a hypertable) with
     * time_bucket() — daily and hourly respectively.  They must appear in
     * timescaledb_information.continuous_aggregates and be queryable before and
     * after a force-refresh.
     */
    public function testApplicationStatsAggregatesAreContinuousOnTimescaleDB(): void
    {
        // Arrange — source hypertable, schema, and FK parents must exist;
        // webhook tables are also required because CreateApplicationsViews
        // creates the oauth2_webhook_status view that joins them.
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $this->loadMigration('applications', 'CreateApplicationSettingsTable')->up();
        $this->loadMigration('applications', 'CreateApplicationStatsTable')->up();
        $this->loadMigration('authserver', 'CreateOauth2WebhooksTables')->up();
        $this->loadMigration('authserver', 'CreateOauth2ApplicationGrantsTable')->up();
        $m = $this->loadMigration('applications', 'CreateApplicationsViews');

        // Act
        $m->up();

        // Assert — daily aggregate is a continuous aggregate
        foreach (['application_stats_daily', 'application_stats_hourly'] as $view) {
            $r = $this->db->execute(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt
                     FROM timescaledb_information.continuous_aggregates
                     WHERE view_schema = %s AND view_name = %s",
                    'applications', $view
                )
            );
            $this->assertGreaterThan(0, (int) $r->fields['cnt'],
                "applications.{$view} must be a TimescaleDB continuous aggregate");

            // Assert — queryable with 0 rows before data
            $r2 = $this->db->execute("SELECT COUNT(*) AS cnt FROM applications.\"{$view}\"");
            $this->assertSame('0', (string) $r2->fields['cnt'],
                "applications.{$view} must return 0 rows before data is inserted");
        }

        // Assert — down() removes both continuous aggregates
        $m->down();
        foreach (['application_stats_daily', 'application_stats_hourly'] as $view) {
            $r = $this->db->execute(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt
                     FROM timescaledb_information.continuous_aggregates
                     WHERE view_schema = %s AND view_name = %s",
                    'applications', $view
                )
            );
            $this->assertSame('0', (string) $r->fields['cnt'],
                "applications.{$view} must be gone after down()");
        }
    }

    // =========================================================================
    // Applications: tokenactions_hourly continuous aggregate (000049)
    // =========================================================================

    /**
     * CreateTokenactionsHourlyView must create applications.tokenactions_hourly as
     * a TimescaleDB continuous aggregate (not a plain view or materialized view).
     *
     * The aggregate queries public.tokenactions (a hypertable partitioned on
     * action_time) and groups into 1-hour buckets per tokenid/urlid/method/status.
     * It includes percentile approximations (p50, p95) that are not available on
     * plain PostgreSQL, where NULL placeholders are used instead.
     *
     * This test verifies:
     *   1. The aggregate is registered in timescaledb_information.continuous_aggregates.
     *   2. It is queryable before and after inserting rows.
     *   3. A force-refresh populates rows from tokenactions data.
     *   4. down() removes the continuous aggregate.
     */
    public function testTokenactionsHourlyIsContinuousAggregateOnTimescaleDB(): void
    {
        // Arrange — public.tokenactions (hypertable) + applications schema must exist
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('auth', 'CreateTokenactionsTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();

        $m = $this->loadMigration('applications', 'CreateTokenactionsHourlyView');

        // Act
        $m->up();

        // Assert — registered as a continuous aggregate in the applications schema
        $r = $this->db->execute(
            "SELECT COUNT(*) AS cnt
             FROM timescaledb_information.continuous_aggregates
             WHERE view_schema = 'applications' AND view_name = 'tokenactions_hourly'"
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'applications.tokenactions_hourly must be a TimescaleDB continuous aggregate');

        // Assert — queryable before any data
        $r2 = $this->db->execute('SELECT COUNT(*) AS cnt FROM applications.tokenactions_hourly');
        $this->assertSame('0', (string) $r2->fields['cnt'],
            'empty continuous aggregate must return 0 rows before data');

        // Assert — inserts to tokenactions and force-refresh must populate the aggregate
        $this->db->execute(
            "INSERT INTO public.tokenactions
                (tokenid, urlid, method, params, servertime, return_status, execution_time_ms, action_time)
             VALUES
                (1, 1, 'GET', '', EXTRACT(EPOCH FROM NOW() - INTERVAL '2 hours')::INT,
                 200, 45.5, NOW() - INTERVAL '2 hours'),
                (1, 2, 'POST', '', EXTRACT(EPOCH FROM NOW() - INTERVAL '1 hour')::INT,
                 500, 1200.0, NOW() - INTERVAL '1 hour')"
        );
        $this->db->execute(
            "CALL refresh_continuous_aggregate('applications.tokenactions_hourly', NULL, NULL)"
        );
        $r3 = $this->db->execute('SELECT COUNT(*) AS cnt FROM applications.tokenactions_hourly');
        $this->assertGreaterThan(0, (int) $r3->fields['cnt'],
            'continuous aggregate must return rows after refresh');

        // Assert — down() removes the continuous aggregate
        $m->down();
        $r4 = $this->db->execute(
            "SELECT COUNT(*) AS cnt
             FROM timescaledb_information.continuous_aggregates
             WHERE view_schema = 'applications' AND view_name = 'tokenactions_hourly'"
        );
        $this->assertSame('0', (string) $r4->fields['cnt'],
            'applications.tokenactions_hourly must be removed by down()');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Loads a specific migration class from the framework migrations directory.
     *
     * @param string $feature Feature subdirectory (auth, authserver, applications, etc.)
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
        // Drop applications schema (CASCADE drops all tables, views, and aggregates
        // including application_stats, application_settings, oauth2_webhook_endpoints,
        // oauth2_webhook_events, tokenactions_hourly, application_stats_daily/hourly)
        $this->db->execute('DROP SCHEMA IF EXISTS applications CASCADE');

        // Drop continuous aggregate + views before source hypertables
        $this->db->execute('DROP MATERIALIZED VIEW IF EXISTS authserver.daily_activity_summary CASCADE');

        // Drop hypertables from authserver schema (CASCADE handles chunks and policies)
        $hypertables = [
            'gdpr_requests', 'data_processing_records',
            'user_consents', 'user_activity_log',
            'twofactor_attempts',
        ];
        foreach ($hypertables as $t) {
            $this->db->execute("DROP TABLE IF EXISTS authserver.\"{$t}\" CASCADE");
        }

        // Drop plain auth tables from authserver schema
        $authserverTables = [
            'user_privacy_settings', 'twofactor_setup', 'user_twofactor',
            'loginlockouts',
        ];
        foreach ($authserverTables as $t) {
            $this->db->execute("DROP TABLE IF EXISTS authserver.\"{$t}\" CASCADE");
        }

        // Drop public-schema tables (tokenactions depends on usertokens + urls)
        $this->db->execute('DROP TABLE IF EXISTS public."tokenactions" CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS public."usertokens" CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS public."urls" CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS public."users" CASCADE');
    }
}
