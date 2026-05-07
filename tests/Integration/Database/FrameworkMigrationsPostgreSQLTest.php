<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * Integration tests for all framework system migrations against TimescaleDB / PostgreSQL 14.
 *
 * Each test:
 *   (1) Runs the migration's up() method against a live TimescaleDB container.
 *   (2) Verifies the resulting schema via information_schema and pg_catalog: table and
 *       schema existence, column presence and data types, index presence, nullable
 *       and default constraints, JSONB types, PostgreSQL ENUM types, and TimescaleDB
 *       hypertable registration.
 *   (3) Runs the migration's down() method and verifies the artefacts are gone.
 *
 * Tests are deliberately independent — each test drops and re-creates all tables
 * in setUp/tearDown so that order does not matter.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class FrameworkMigrationsPostgreSQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;

    /** Base path for framework system migrations. */
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

        if (!$this->db->connect(false)) {
            $this->markTestSkipped('TimescaleDB container not reachable (timescaledb:5432)');
        }

        $this->migrationsBase = dirname(__DIR__, 3)
            . '/database/migrations/framework';

        $this->app = $this->makeApp();
        $this->dropAllTestTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTestTables();
    }

    // -------------------------------------------------------------------------
    // Core: sessions
    // -------------------------------------------------------------------------

    /**
     * sessions migration must create the sessions table with the correct columns
     * and rollback must drop it.
     *
     * The sessions table is the visitor session store: visitorid TEXT PK, time INT,
     * userid BIGINT nullable, sid VARCHAR, etc.
     */
    public function testCoreSessionsUpCreatesTableWithExpectedColumns(): void
    {
        // Arrange
        $m = $this->loadMigration('core', 'CreateSessionsTable');

        // Act
        $m->up();

        // Assert — table exists in public schema
        $this->assertTrue($this->tableExists('sessions'), 'sessions table must exist after up()');

        // Assert — critical columns present with expected PostgreSQL types
        $this->assertColumnType('sessions', 'visitorid', 'character varying');
        $this->assertColumnType('sessions', 'time', 'integer');
        $this->assertColumnNullable('sessions', 'userid', true);
        $this->assertColumnType('sessions', 'sid', 'character varying');
        $this->assertColumnType('sessions', 'agent', 'character varying');
        $this->assertColumnType('sessions', 'history', 'text');

        // Assert — rollback drops the table
        $m->down();
        $this->assertFalse($this->tableExists('sessions'), 'sessions table must be gone after down()');
    }

    // -------------------------------------------------------------------------
    // Core: settings
    // -------------------------------------------------------------------------

    /**
     * settings migration must create the settings key/value table.
     * delete column must default to 1 (stored as boolean TRUE on PostgreSQL
     * when boolean type is used, but the migration uses tinyInteger so it's SMALLINT).
     */
    public function testCoreSettingsUpCreatesTableWithDeleteDefaultOne(): void
    {
        // Arrange
        $m = $this->loadMigration('core', 'CreateSettingsTable');

        // Act
        $m->up();

        // Assert — table and columns
        $this->assertTrue($this->tableExists('settings'));
        $this->assertColumnType('settings', 'setting_id', 'integer');
        $this->assertColumnType('settings', 'setting', 'character varying');
        $this->assertColumnType('settings', 'value', 'text');

        // Assert — delete column has default value 1 (tinyInteger → SMALLINT on PostgreSQL).
        // PostgreSQL stores integer defaults without a cast, so column_default = '1'.
        $info = $this->getColumnInfo('settings', 'delete');
        $this->assertSame('1', (string) $info['column_default'], 'delete column must default to 1');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('settings'));
    }

    // -------------------------------------------------------------------------
    // Core: framework_policies
    // -------------------------------------------------------------------------

    /**
     * framework_policies must have all PolicyEngine columns and both
     * policy_type+enabled and next_run indexes.
     */
    public function testCorePoliciesUpCreatesTableWithIndexes(): void
    {
        // Arrange
        $m = $this->loadMigration('core', 'CreateFrameworkPoliciesTable');

        // Act
        $m->up();

        // Assert — columns (PostgreSQL uses json not json, jsonb is separate type)
        $this->assertTrue($this->tableExists('framework_policies'));
        $this->assertColumnType('framework_policies', 'policyid', 'integer');
        $this->assertColumnType('framework_policies', 'policy_type', 'character varying');
        $this->assertColumnType('framework_policies', 'target', 'character varying');
        $this->assertColumnType('framework_policies', 'config', 'json');
        $this->assertColumnNullable('framework_policies', 'last_run', true);
        $this->assertColumnNullable('framework_policies', 'next_run', true);
        $this->assertColumnNullable('framework_policies', 'last_result', true);
        $this->assertColumnNullable('framework_policies', 'last_error', true);

        // Assert — indexes
        $this->assertTrue($this->indexExists('framework_policies', 'idx_framework_policies_type_enabled'));
        $this->assertTrue($this->indexExists('framework_policies', 'idx_framework_policies_next_run'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('framework_policies'));
    }

    // -------------------------------------------------------------------------
    // Auth: users
    // -------------------------------------------------------------------------

    /**
     * users table must have the full UrbanWater schema: BIGINT userid PK,
     * username/email/password strings, all profile fields, and key indexes.
     * On PostgreSQL, BIGSERIAL becomes bigint in information_schema.
     */
    public function testAuthUsersUpCreatesFullUwSchema(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateUsersTable');

        // Act
        $m->up();

        // Assert — core identity fields with PostgreSQL type names
        $this->assertTrue($this->tableExists('users'));
        $this->assertColumnType('users', 'userid', 'bigint');
        $this->assertColumnType('users', 'username', 'character varying');
        $this->assertColumnType('users', 'email', 'character varying');
        $this->assertColumnType('users', 'password', 'character varying');

        // Assert — profile fields unique to UW schema
        $this->assertColumnType('users', 'lastname', 'character varying');
        $this->assertColumnType('users', 'firstname', 'character varying');
        $this->assertColumnType('users', 'regdate', 'integer');
        $this->assertColumnType('users', 'lastlogin', 'integer');
        $this->assertColumnNullable('users', 'locationid', true);
        $this->assertColumnNullable('users', 'fbauth', true);

        // Assert — indexes
        $this->assertTrue($this->indexExists('users', 'idx_users_username'));
        $this->assertTrue($this->indexExists('users', 'idx_users_email'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('users'));
    }

    // -------------------------------------------------------------------------
    // Auth: userdetails
    // -------------------------------------------------------------------------

    /**
     * userdetails is an EAV table with composite PK (userid, fieldname).
     * No auto-increment primary key.
     */
    public function testAuthUserdetailsCompositeKey(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUserdetailsTable');

        // Act
        $m->up();

        // Assert — columns with PostgreSQL type names
        $this->assertTrue($this->tableExists('userdetails'));
        $this->assertColumnType('userdetails', 'userid', 'bigint');
        $this->assertColumnType('userdetails', 'fieldname', 'character varying');
        $this->assertColumnType('userdetails', 'value', 'text');

        // Assert — both PK columns are NOT NULL
        $this->assertColumnNullable('userdetails', 'userid', false);
        $this->assertColumnNullable('userdetails', 'fieldname', false);

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('userdetails'));
    }

    // -------------------------------------------------------------------------
    // Auth: userlog
    // -------------------------------------------------------------------------

    /**
     * userlog must have logid auto-increment PK (SERIAL → integer in pg),
     * userid (bigint), date (integer), log (nullable text), logtype (smallint), details (text).
     */
    public function testAuthUserlogUpCreatesAuditTable(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUserlogTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('userlog'));
        $this->assertColumnType('userlog', 'logid', 'integer');
        $this->assertColumnType('userlog', 'userid', 'bigint');
        $this->assertColumnType('userlog', 'date', 'integer');
        $this->assertColumnNullable('userlog', 'log', true);
        $this->assertColumnType('userlog', 'logtype', 'smallint');
        $this->assertColumnType('userlog', 'details', 'text');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('userlog'));
    }

    // -------------------------------------------------------------------------
    // Auth: usernotes
    // -------------------------------------------------------------------------

    /**
     * usernotes must have userid (bigint), admin (bigint nullable), note (text), date (int).
     */
    public function testAuthUsernotesUpCreatesNotesTable(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUsernotesTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('usernotes'));
        $this->assertColumnType('usernotes', 'userid', 'bigint');
        $this->assertColumnNullable('usernotes', 'admin', true);
        $this->assertColumnType('usernotes', 'note', 'text');
        $this->assertColumnType('usernotes', 'date', 'integer');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('usernotes'));
    }

    // -------------------------------------------------------------------------
    // Auth: usertokens
    // -------------------------------------------------------------------------

    /**
     * usertokens must have TEXT token (critical for JWT support), PKCE columns
     * (code_challenge, code_challenge_method), and all legacy fields.
     * The token column is TEXT on PostgreSQL — no length restriction for JWTs.
     */
    public function testAuthUsertokensHasTextTokenAndPkceColumns(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUsertokensTable');

        // Act
        $m->up();

        // Assert — token must be TEXT (JWT support requirement)
        $this->assertTrue($this->tableExists('usertokens'));
        $tokenInfo = $this->getColumnInfo('usertokens', 'token');
        $this->assertSame('text', strtolower($tokenInfo['data_type']),
            'token column must be TEXT (not character varying) to accommodate JWTs of any length');

        // Assert — PKCE columns (RFC 7636)
        $this->assertTrue($this->columnExists('usertokens', 'code_challenge'));
        $this->assertTrue($this->columnExists('usertokens', 'code_challenge_method'));
        $this->assertColumnNullable('usertokens', 'code_challenge', true);
        $this->assertColumnNullable('usertokens', 'code_challenge_method', true);

        // Assert — legacy fields present with correct PostgreSQL types
        $this->assertColumnType('usertokens', 'tokentype', 'character varying');
        $this->assertColumnType('usertokens', 'status', 'smallint');
        $this->assertColumnNullable('usertokens', 'expires', true);
        $this->assertColumnNullable('usertokens', 'parentToken', true);
        $this->assertColumnNullable('usertokens', 'applicationid', true);

        // Assert — FK-lookup index exists
        $this->assertTrue($this->indexExists('usertokens', 'idx_usertokens_userid_status'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('usertokens'));
    }

    // -------------------------------------------------------------------------
    // Auth: urls
    // -------------------------------------------------------------------------

    /**
     * urls must have hash BIGINT (not INT) to store CRC32 values without overflow.
     */
    public function testAuthUrlsHasBigintHash(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateUrlsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('urls'));
        $this->assertColumnType('urls', 'urlid', 'integer');
        $this->assertColumnNullable('urls', 'url', true);
        $this->assertColumnType('urls', 'hash', 'bigint');
        $this->assertTrue($this->indexExists('urls', 'idx_urls_hash'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('urls'));
    }

    // -------------------------------------------------------------------------
    // Auth: tokenactions (TimescaleDB hypertable)
    // -------------------------------------------------------------------------

    /**
     * tokenactions on TimescaleDB must be converted to a hypertable partitioned
     * by action_time (14-day chunks). The return_data column must be JSONB
     * (not plain JSON) to allow GIN indexing. The composite PK (actionid, action_time)
     * is required by TimescaleDB for partition-key inclusion.
     *
     * This test proves that the migration calls create_hypertable() successfully
     * and that the TimescaleDB extension recognises the table as partitioned.
     */
    public function testAuthTokenactionsIsHypertableOnTimescaleDb(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $m = $this->loadMigration('auth', 'CreateTokenactionsTable');

        // Act
        $m->up();

        // Assert — table exists
        $this->assertTrue($this->tableExists('tokenactions'));

        // Assert — return_data is JSONB (not plain JSON — GIN indexable on PostgreSQL)
        $this->assertColumnType('tokenactions', 'return_data', 'jsonb', 'public',
            'return_data must be JSONB on PostgreSQL for indexability');

        // Assert — action_time is TIMESTAMPTZ (the partition dimension)
        $this->assertColumnType('tokenactions', 'action_time', 'timestamp with time zone', 'public',
            'action_time must be TIMESTAMPTZ to serve as the TimescaleDB partition key');

        // Assert — tokenactions is registered as a TimescaleDB hypertable
        $this->assertTrue($this->isHypertable('tokenactions'),
            'tokenactions must be a TimescaleDB hypertable (partitioned by action_time)');

        // Assert — time-based lookup index exists
        $this->assertTrue($this->indexExists('tokenactions', 'idx_tokenactions_time_tokenid'));

        // Assert — sync trigger exists (keeps servertime ↔ action_time bidirectional).
        // information_schema.triggers lists one row per event, so BEFORE INSERT OR UPDATE
        // produces 2 rows. We assert at least 1 row to confirm the trigger was created.
        $triggerRow = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.triggers"
            . " WHERE trigger_name = 'sync_tokenactions_time'"
            . "   AND event_object_table = 'tokenactions'"
        );
        $this->assertGreaterThan(0, (int)$triggerRow->fields['cnt'],
            'sync_tokenactions_time trigger must be created by the migration');

        // Assert — rollback drops the hypertable and the sync trigger
        $m->down();
        $this->assertFalse($this->tableExists('tokenactions'));
        $afterDrop = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.triggers"
            . " WHERE trigger_name = 'sync_tokenactions_time'"
            . "   AND event_object_table = 'tokenactions'"
        );
        $this->assertSame(0, (int)$afterDrop->fields['cnt'],
            'sync_tokenactions_time trigger must be removed by down()');
    }

    // -------------------------------------------------------------------------
    // Messaging: mails
    // -------------------------------------------------------------------------

    /**
     * mails table must have status (smallint), frommail/tomail/subject (character varying),
     * content (text), date (integer unix timestamp), and hash (char 32 for MD5).
     */
    public function testMessagingMailsUpCreatesEmailHistoryTable(): void
    {
        // Arrange
        $m = $this->loadMigration('messaging', 'CreateMailsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('mails'));
        $this->assertColumnType('mails', 'id', 'integer');
        $this->assertColumnType('mails', 'status', 'smallint');
        $this->assertColumnType('mails', 'frommail', 'character varying');
        $this->assertColumnType('mails', 'tomail', 'character varying');
        $this->assertColumnType('mails', 'subject', 'character varying');
        $this->assertColumnType('mails', 'content', 'text');
        $this->assertColumnType('mails', 'date', 'integer');
        $this->assertColumnType('mails', 'hash', 'character');

        // Assert — indexes
        $this->assertTrue($this->indexExists('mails', 'idx_mails_status'));
        $this->assertTrue($this->indexExists('mails', 'idx_mails_hash'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('mails'));
    }

    // -------------------------------------------------------------------------
    // Messaging: mailtemplates
    // -------------------------------------------------------------------------

    /**
     * mailtemplates must have the category+language+type lookup index
     * and the correct channel type column (smallint on PostgreSQL).
     */
    public function testMessagingMailtemplatesUpCreatesTemplateTable(): void
    {
        // Arrange
        $m = $this->loadMigration('messaging', 'CreateMailtemplatesTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('mailtemplates'));
        $this->assertColumnType('mailtemplates', 'templateid', 'bigint');
        $this->assertColumnType('mailtemplates', 'title', 'character varying');
        $this->assertColumnType('mailtemplates', 'defaulttext', 'text');
        $this->assertColumnType('mailtemplates', 'category', 'character varying');
        $this->assertColumnType('mailtemplates', 'language', 'character varying');
        $this->assertColumnType('mailtemplates', 'type', 'smallint');
        $this->assertColumnType('mailtemplates', 'sendmethod', 'smallint');

        // Assert — lookup index
        $this->assertTrue($this->indexExists('mailtemplates', 'idx_mailtemplates_lookup'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('mailtemplates'));
    }

    // -------------------------------------------------------------------------
    // Messaging: messages
    // -------------------------------------------------------------------------

    /**
     * messages table must have the UW type-based state machine columns:
     * type (smallint), fromuserid/touserid (bigint nullable), massid (integer nullable),
     * bbcode/html/smilies flags (smallint). NOT the old thread-based schema.
     */
    public function testMessagingMessagesUpCreatesUwMessageTable(): void
    {
        // Arrange
        $m = $this->loadMigration('messaging', 'CreateMessagesTable');

        // Act
        $m->up();

        // Assert — UW-specific columns (would not exist in old thread-based schema)
        $this->assertTrue($this->tableExists('messages'));
        $this->assertColumnType('messages', 'messageid', 'integer');
        $this->assertColumnType('messages', 'type', 'smallint');
        $this->assertColumnNullable('messages', 'massid', true);
        $this->assertColumnNullable('messages', 'fromuserid', true);
        $this->assertColumnNullable('messages', 'touserid', true);
        $this->assertColumnType('messages', 'text', 'text');
        $this->assertColumnType('messages', 'bbcode', 'smallint');
        $this->assertColumnType('messages', 'html', 'smallint');
        $this->assertColumnType('messages', 'smilies', 'smallint');
        $this->assertColumnType('messages', 'attachment', 'smallint');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('messages'));
    }

    // -------------------------------------------------------------------------
    // Messaging: massmessages + massmessagerecipients
    // -------------------------------------------------------------------------

    /**
     * massmessages must have the broadcast-specific fields: locationid (text for
     * JSON array), totalrecipients (integer), request (json nullable).
     */
    public function testMessagingMassmessagesUpCreatesBroadcastTable(): void
    {
        // Arrange
        $m = $this->loadMigration('messaging', 'CreateMassmessagesTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('massmessages'));
        $this->assertColumnType('massmessages', 'messageid', 'integer');
        $this->assertColumnType('massmessages', 'message', 'text');
        $this->assertColumnType('massmessages', 'type', 'integer');
        $this->assertColumnType('massmessages', 'locationid', 'text');
        $this->assertColumnType('massmessages', 'totalrecipients', 'integer');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('massmessages'));
    }

    /**
     * massmessagerecipients must FK to massmessages (cascade delete) and have
     * messageid typed as INTEGER — matching massmessages.messageid (SERIAL = INTEGER).
     */
    public function testMessagingMassmessagerecepientsUpCreatesDeliveryTable(): void
    {
        // Arrange
        $this->loadMigration('messaging', 'CreateMassmessagesTable')->up();
        $m = $this->loadMigration('messaging', 'CreateMassmessagerecepientsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('massmessagerecipients'));
        $this->assertColumnType('massmessagerecipients', 'recipientid', 'integer');
        $this->assertColumnType('massmessagerecipients', 'messageid', 'integer');
        $this->assertColumnType('massmessagerecipients', 'userid', 'bigint');
        $this->assertColumnType('massmessagerecipients', 'status', 'integer');

        // Assert — FK to massmessages (PostgreSQL: information_schema.referential_constraints)
        $fkResult = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.key_column_usage kcu
             JOIN information_schema.referential_constraints rc
               ON kcu.constraint_name = rc.constraint_name
             WHERE kcu.table_schema = 'public'
               AND kcu.table_name   = 'massmessagerecipients'
               AND kcu.column_name  = 'messageid'"
        );
        $this->assertGreaterThan(0, (int) $fkResult->fields['cnt'],
            'FK to massmessages.messageid must be defined');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('massmessagerecipients'));
    }

    // -------------------------------------------------------------------------
    // Queue: queueitems (PostgreSQL ENUM)
    // -------------------------------------------------------------------------

    /**
     * queueitems on PostgreSQL must create a queue_status ENUM type and use it
     * (via VARCHAR + CHECK constraint) for the status column — not TINYINT as on MySQL.
     * The payload column must be JSON. All worker-management columns must be present.
     */
    public function testQueueQueueitemsUsesEnumTypeOnPostgres(): void
    {
        // Arrange
        $m = $this->loadMigration('queue', 'CreateQueueitemsTable');

        // Act
        $m->up();

        // Assert — table and key columns
        $this->assertTrue($this->tableExists('queueitems'));
        $this->assertColumnType('queueitems', 'taskid', 'bigint');
        $this->assertColumnType('queueitems', 'type', 'character varying');
        $this->assertColumnType('queueitems', 'payload', 'json');

        // Assert — queue_status ENUM type was created in pg_type
        $this->assertTrue($this->typeExists('queue_status'),
            'queue_status ENUM type must be registered in pg_type');

        // Assert — status column is character varying (the migration uses VARCHAR for ENUM compatibility)
        $statusInfo = $this->getColumnInfo('queueitems', 'status');
        $this->assertSame('character varying', strtolower($statusInfo['data_type']),
            'On PostgreSQL, queueitems.status must be character varying (backed by queue_status ENUM)');

        // Assert — lock columns for atomic worker claims
        $this->assertColumnNullable('queueitems', 'lockedby', true);
        $this->assertColumnNullable('queueitems', 'lockexpires', true);

        // Assert — deduplication column
        $this->assertColumnNullable('queueitems', 'task_hash', true);
        $this->assertColumnType('queueitems', 'task_hash', 'character varying');

        // Assert — indexes
        $this->assertTrue($this->indexExists('queueitems', 'idx_queueitems_status_priority_created'));
        $this->assertTrue($this->indexExists('queueitems', 'idx_queueitems_task_hash'));
        $this->assertTrue($this->indexExists('queueitems', 'idx_queueitems_locked'));

        // Assert — rollback drops table AND the queue_status type
        $m->down();
        $this->assertFalse($this->tableExists('queueitems'));
        $this->assertFalse($this->typeExists('queue_status'),
            'queue_status ENUM type must be dropped by down()');
    }

    // -------------------------------------------------------------------------
    // Authserver: schema creation (PostgreSQL-only)
    // -------------------------------------------------------------------------

    /**
     * The create_authserver_schema migration must create the 'authserver' schema
     * in PostgreSQL. On MySQL this migration is a no-op.
     * The schema must survive idempotent re-runs (CREATE SCHEMA IF NOT EXISTS).
     */
    public function testAuthserverSchemaIsCreatedByMigration(): void
    {
        // Arrange
        $m = $this->loadMigration('authserver', 'CreateAuthserverSchema');

        // Act
        $m->up();

        // Assert — authserver schema exists in pg_catalog
        $this->assertTrue($this->schemaExists('authserver'),
            "'authserver' schema must be created by the migration");

        // Act — idempotent re-run must not throw
        $m->up();
        $this->assertTrue($this->schemaExists('authserver'));

        // Assert — rollback drops the schema (CASCADE removes all contained tables)
        $m->down();
        $this->assertFalse($this->schemaExists('authserver'),
            "'authserver' schema must be dropped by down()");
    }

    // -------------------------------------------------------------------------
    // Authserver: roles table
    // -------------------------------------------------------------------------

    /**
     * authserver.roles must be created inside the 'authserver' schema — not in public.
     * The table must have role_name (varchar), deyaid (nullable int for org scoping),
     * is_active (boolean), and the standard lookup indexes.
     */
    public function testAuthserverRolesTableIsInAuthserverSchema(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('authserver', 'CreateAuthserverRolesTable');

        // Act
        $m->up();

        // Assert — table exists in 'authserver' schema, NOT in 'public'
        $this->assertTrue($this->tableExists('roles', 'authserver'),
            'roles must be in the authserver schema');
        $this->assertFalse($this->tableExists('roles', 'public'),
            'roles must NOT be created in the public schema');

        // Assert — columns with PostgreSQL types
        $this->assertColumnType('roles', 'roleid', 'integer', 'authserver');
        $this->assertColumnType('roles', 'role_name', 'character varying', 'authserver');
        $this->assertColumnType('roles', 'is_active', 'boolean', 'authserver');
        $this->assertColumnNullable('roles', 'deyaid', true, 'authserver');

        // Assert — indexes
        $this->assertTrue($this->indexExists('roles', 'idx_authserver_roles_name', 'authserver'));
        $this->assertTrue($this->indexExists('roles', 'idx_authserver_roles_deyaid', 'authserver'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('roles', 'authserver'));
    }

    // -------------------------------------------------------------------------
    // Authserver: permissions table
    // -------------------------------------------------------------------------

    /**
     * authserver.permissions must have the deny/allow grant_type and the composite
     * subject+object lookup index for policy engine evaluation.
     */
    public function testAuthserverPermissionsHasDenyAllowGrants(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $m = $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable');

        // Act
        $m->up();

        // Assert — table in correct schema
        $this->assertTrue($this->tableExists('permissions', 'authserver'));

        // Assert — grant_type defaults to 'allow'. PostgreSQL stores the default as
        // "'allow'::character varying", so we assert the value starts with "'allow'".
        $info = $this->getColumnInfo('permissions', 'grant_type', 'authserver');
        $this->assertStringStartsWith("'allow'", (string) $info['column_default'],
            "grant_type must default to 'allow'");

        // Assert — subject and object columns present
        $this->assertColumnType('permissions', 'subject_type', 'character varying', 'authserver');
        $this->assertColumnType('permissions', 'subject_id', 'bigint', 'authserver');
        $this->assertColumnType('permissions', 'object_type', 'character varying', 'authserver');
        $this->assertColumnNullable('permissions', 'object_id', true, 'authserver');
        $this->assertColumnType('permissions', 'action', 'character varying', 'authserver');
        $this->assertColumnType('permissions', 'is_active', 'boolean', 'authserver');
        $this->assertColumnNullable('permissions', 'expires_at', true, 'authserver');

        // Assert — composite lookup index for efficient permission resolution
        $this->assertTrue($this->indexExists('permissions', 'idx_authserver_perms_lookup', 'authserver'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('permissions', 'authserver'));
    }

    // -------------------------------------------------------------------------
    // Authserver: user_roles table
    // -------------------------------------------------------------------------

    /**
     * authserver.user_roles must have a composite primary key (userid, roleid)
     * to prevent duplicate role assignments. No auto-increment PK.
     */
    public function testAuthserverUserRolesHasCompositePrimaryKey(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $m = $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable');

        // Act
        $m->up();

        // Assert — table in correct schema
        $this->assertTrue($this->tableExists('user_roles', 'authserver'));

        // Assert — the composite PK columns are NOT NULL (PK constraint implies NOT NULL)
        $this->assertColumnNullable('user_roles', 'userid', false, 'authserver');
        $this->assertColumnNullable('user_roles', 'roleid', false, 'authserver');

        // Assert — composite PK exists in pg_constraint
        $pkResult = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.table_constraints tc
             WHERE tc.table_schema = 'authserver'
               AND tc.table_name   = 'user_roles'
               AND tc.constraint_type = 'PRIMARY KEY'"
        );
        $this->assertGreaterThan(0, (int) $pkResult->fields['cnt'],
            'user_roles must have a PRIMARY KEY constraint');

        // Assert — audit columns present
        $this->assertColumnType('user_roles', 'is_active', 'boolean', 'authserver');
        $this->assertColumnNullable('user_roles', 'expires_at', true, 'authserver');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('user_roles', 'authserver'));
    }

    // -------------------------------------------------------------------------
    // Authserver: audit_log (JSONB columns)
    // -------------------------------------------------------------------------

    /**
     * authserver.audit_log must store before_state and after_state as JSONB columns
     * (not plain JSON) to allow GIN indexing of permission change history.
     * The created_at column must be TIMESTAMPTZ (timestamp with time zone) for
     * correct timezone-aware audit timestamps.
     */
    public function testAuthserverAuditLogHasJsonbColumns(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable');

        // Act
        $m->up();

        // Assert — table in correct schema
        $this->assertTrue($this->tableExists('audit_log', 'authserver'));

        // Assert — before_state and after_state are JSONB (not plain json)
        $this->assertColumnType('audit_log', 'before_state', 'jsonb', 'authserver',
            'before_state must be JSONB for GIN indexable permission history');
        $this->assertColumnType('audit_log', 'after_state', 'jsonb', 'authserver',
            'after_state must be JSONB for GIN indexable permission history');

        // Assert — both state columns are nullable (creation events have no before, deletion events have no after)
        $this->assertColumnNullable('audit_log', 'before_state', true, 'authserver');
        $this->assertColumnNullable('audit_log', 'after_state', true, 'authserver');

        // Assert — created_at is timezone-aware (immutable audit record)
        $this->assertColumnType('audit_log', 'created_at', 'timestamp with time zone', 'authserver',
            'created_at must be TIMESTAMPTZ for timezone-correct audit timestamps');

        // Assert — audit trail indexes
        $this->assertTrue($this->indexExists('audit_log', 'idx_authserver_audit_by', 'authserver'));
        $this->assertTrue($this->indexExists('audit_log', 'idx_authserver_audit_user', 'authserver'));
        $this->assertTrue($this->indexExists('audit_log', 'idx_authserver_audit_type', 'authserver'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('audit_log', 'authserver'));
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // AuthServer migrations (PostgreSQL — applications, device_authorizations, etc.)
    // -------------------------------------------------------------------------

    /**
     * CreateApplicationsTable must create the `applications` table in the
     * default (public) schema on PostgreSQL with the required OAuth2 columns.
     *
     * The applications table holds registered OAuth2 client applications.
     * Its apikey column is the OAuth2 client_id; the unique constraint on it
     * ensures no two clients can share the same public identifier.
     */
    public function testAuthserverApplicationsUpCreatesOauth2ClientTableOnPostgres(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('authserver', 'CreateApplicationsTable');

        // Act
        $m->up();

        // Assert — table exists in public schema
        $this->assertTrue($this->tableExists('applications'),
            'applications table must be created by the authserver migration on PostgreSQL');

        // Assert — key OAuth2 columns with expected PostgreSQL types
        $this->assertColumnType('applications', 'appid', 'integer');
        $this->assertColumnType('applications', 'name', 'character varying');
        $this->assertColumnType('applications', 'apikey', 'character varying');
        $this->assertColumnType('applications', 'callback', 'text');
        $this->assertColumnType('applications', 'public_key', 'text');

        // Assert — nullable optional fields
        $this->assertColumnNullable('applications', 'apikey', true);
        $this->assertColumnNullable('applications', 'callback', true);
        $this->assertColumnNullable('applications', 'owner', true);
        $this->assertColumnNullable('applications', 'public_key', true);
        $this->assertColumnNullable('applications', 'jwks_uri', true);

        // Assert — a unique constraint exists on the apikey column.
        // The PostgreSQL SchemaBuilder uses inline UNIQUE syntax without naming the
        // constraint, so PostgreSQL auto-generates the name (e.g. applications_apikey_key).
        // We verify the constraint via pg_constraint rather than looking for a fixed name.
        $uniqueCheck = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM pg_constraint c"
            . " JOIN pg_class t ON t.oid = c.conrelid"
            . " JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)"
            . " WHERE t.relname = 'applications' AND a.attname = 'apikey' AND c.contype = 'u'"
        );
        $this->assertGreaterThan(0, (int)$uniqueCheck->fields['cnt'],
            'apikey must have a unique constraint on PostgreSQL');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('applications'),
            'applications table must be dropped by down()');

        $this->loadMigration('authserver', 'CreateAuthserverSchema')->down();
    }

    /**
     * CreateDeviceAuthorizationsTable must create the table inside the
     * `authserver` schema on PostgreSQL with a CHECK constraint on status
     * (instead of MySQL's ENUM type) and unique constraints on both code columns.
     *
     * PostgreSQL does not support ENUM for new columns without CREATE TYPE;
     * the migration uses VARCHAR + CHECK to achieve the same constraint.
     */
    public function testAuthserverDeviceAuthorizationsCreatesTableInAuthserverSchemaOnPostgres(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('authserver', 'CreateDeviceAuthorizationsTable');

        // Act
        $m->up();

        // Assert — table exists in authserver schema (not public)
        $this->assertTrue($this->tableExists('device_authorizations', 'authserver'),
            'device_authorizations must be created in the authserver schema on PostgreSQL');

        // Assert — key columns have correct PostgreSQL types
        $this->assertColumnType('device_authorizations', 'device_code', 'character varying', 'authserver');
        $this->assertColumnType('device_authorizations', 'user_code', 'character varying', 'authserver');
        $this->assertColumnType('device_authorizations', 'verification_uri', 'character varying', 'authserver');
        $this->assertColumnType('device_authorizations', 'expires_at', 'timestamp without time zone', 'authserver');

        // Assert — status is VARCHAR (PostgreSQL uses CHECK instead of ENUM)
        $this->assertColumnType('device_authorizations', 'status', 'character varying', 'authserver');

        // Assert — rollback drops the table
        $m->down();
        $this->assertFalse($this->tableExists('device_authorizations', 'authserver'),
            'device_authorizations must be dropped by down() on PostgreSQL');

        $this->loadMigration('authserver', 'CreateAuthserverSchema')->down();
    }

    /**
     * CreateJwtReplayPreventionTable must create the table inside the
     * `authserver` schema on PostgreSQL with jti as the primary key and
     * an expires_at index.
     *
     * The table stores seen JWT IDs to prevent replay attacks. Placing it
     * in the authserver schema keeps it separate from application data.
     */
    public function testAuthserverJwtReplayPreventionCreatesTableInAuthserverSchemaOnPostgres(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('authserver', 'CreateJwtReplayPreventionTable');

        // Act
        $m->up();

        // Assert — table in authserver schema
        $this->assertTrue($this->tableExists('jwt_replay_prevention', 'authserver'),
            'jwt_replay_prevention must be created in the authserver schema');

        // Assert — jti and expires_at columns
        $this->assertColumnType('jwt_replay_prevention', 'jti', 'character varying', 'authserver');
        $this->assertColumnType('jwt_replay_prevention', 'expires_at', 'timestamp without time zone', 'authserver');

        // Assert — expires_at index for cleanup
        $this->assertTrue($this->indexExists('jwt_replay_prevention', 'idx_jrp_expires', 'authserver'),
            'expires_at index must exist in authserver.jwt_replay_prevention for cleanup queries');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('jwt_replay_prevention', 'authserver'),
            'jwt_replay_prevention must be dropped by down()');

        $this->loadMigration('authserver', 'CreateAuthserverSchema')->down();
    }

    /**
     * CreateOauth2ClientAuthMethodsTable must create the table inside the
     * `authserver` schema on PostgreSQL with a CHECK constraint on auth_method
     * and a unique constraint on (appid, auth_method).
     */
    public function testAuthserverOauth2ClientAuthMethodsCreatesTableInAuthserverSchemaOnPostgres(): void
    {
        // Arrange — applications table must exist for appid column integrity
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateOauth2ClientAuthMethodsTable');

        // Act
        $m->up();

        // Assert — table in authserver schema
        $this->assertTrue($this->tableExists('oauth2_client_auth_methods', 'authserver'),
            'oauth2_client_auth_methods must be created in the authserver schema');

        // Assert — auth_method uses VARCHAR + CHECK (not ENUM) on PostgreSQL
        $this->assertColumnType('oauth2_client_auth_methods', 'auth_method', 'character varying', 'authserver');

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('oauth2_client_auth_methods', 'authserver'));
        $this->loadMigration('authserver', 'CreateApplicationsTable')->down();
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->down();
    }

    /**
     * CreateOauth2WebhooksTables must create both webhook tables inside the
     * `authserver` schema on PostgreSQL with JSONB columns for events and payload.
     *
     * JSONB is the native PostgreSQL binary JSON type and enables efficient
     * JSON indexing and querying. The events table has a FK to endpoints.
     */
    public function testAuthserverOauth2WebhooksCreatesBothTablesWithJsonbOnPostgres(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateOauth2WebhooksTables');

        // Act
        $m->up();

        // Assert — both tables in authserver schema
        $this->assertTrue($this->tableExists('oauth2_webhook_endpoints', 'authserver'),
            'oauth2_webhook_endpoints must be created in the authserver schema');
        $this->assertTrue($this->tableExists('oauth2_webhook_events', 'authserver'),
            'oauth2_webhook_events must be created in the authserver schema');

        // Assert — events column is JSONB (binary JSON for efficient querying)
        $this->assertColumnType('oauth2_webhook_endpoints', 'events', 'jsonb', 'authserver',
            'events on endpoints must be JSONB for efficient JSON path queries on PostgreSQL');

        // Assert — payload column is JSONB
        $this->assertColumnType('oauth2_webhook_events', 'payload', 'jsonb', 'authserver',
            'payload on events must be JSONB on PostgreSQL');

        // Assert — delivery tracking columns
        $this->assertColumnType('oauth2_webhook_events', 'delivered', 'boolean', 'authserver');
        $this->assertColumnType('oauth2_webhook_events', 'attempts', 'smallint', 'authserver');

        // Assert — rollback drops both tables
        $m->down();
        $this->assertFalse($this->tableExists('oauth2_webhook_events', 'authserver'));
        $this->assertFalse($this->tableExists('oauth2_webhook_endpoints', 'authserver'));
        $this->loadMigration('authserver', 'CreateApplicationsTable')->down();
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->down();
    }

    /**
     * Running up() twice must be idempotent — the hasTable() guard prevents
     * duplicate-table errors on all framework migrations.
     */
    public function testAllCoreMigrationsAreIdempotent(): void
    {
        // Arrange — sessions as representative migration
        $m = $this->loadMigration('core', 'CreateSessionsTable');

        // Act — run twice
        $m->up();
        $m->up(); // Must not throw

        // Assert — table still exists and no exception was thrown
        $this->assertTrue($this->tableExists('sessions'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Loads a specific migration class from the framework migrations directory.
     *
     * @param string $feature Feature subdirectory (core/auth/messaging/queue/authserver)
     * @param string $class   Short class name (e.g. 'CreateSessionsTable')
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

    protected function tableExists(string $name, string $schema = 'public'): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                $schema,
                $name
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    protected function schemaExists(string $schema): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.schemata
                 WHERE schema_name = %s",
                $schema
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    protected function columnExists(string $table, string $column, string $schema = 'public'): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.columns
                 WHERE table_schema = %s AND table_name = %s AND column_name = %s",
                $schema,
                $table,
                $column
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    protected function getColumnInfo(string $table, string $column, string $schema = 'public'): array
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT data_type, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_schema = %s AND table_name = %s AND column_name = %s",
                $schema,
                $table,
                $column
            )
        );
        $this->assertNotNull($result->fields,
            "Column '{$column}' must exist in '{$schema}.{$table}'");
        return $result->fields;
    }

    /**
     * Checks whether a named index exists on a table in a given schema.
     * Uses pg_indexes which is authoritative in PostgreSQL.
     */
    protected function indexExists(string $table, string $indexName, string $schema = 'public'): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM pg_indexes
                 WHERE schemaname = %s AND tablename = %s AND indexname = %s",
                $schema,
                $table,
                $indexName
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    /**
     * Returns true if the table is registered as a TimescaleDB hypertable.
     * Queries timescaledb_information.hypertables which is available only when
     * the TimescaleDB extension is installed and active.
     */
    protected function isHypertable(string $table, string $schema = 'public'): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM timescaledb_information.hypertables
                 WHERE hypertable_schema = %s AND hypertable_name = %s",
                $schema,
                $table
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    /**
     * Returns true if a named type exists in pg_type (used for ENUM type verification).
     */
    protected function typeExists(string $typeName): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_type WHERE typname = %s",
                $typeName
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    /**
     * @param string $message Optional assertion failure message
     */
    protected function assertColumnType(
        string $table,
        string $column,
        string $expectedType,
        string $schema = 'public',
        string $message = ''
    ): void {
        $info = $this->getColumnInfo($table, $column, $schema);
        $msg = $message ?: "Column '{$schema}.{$table}.{$column}' must have type '{$expectedType}'";
        $this->assertSame(
            strtolower($expectedType),
            strtolower($info['data_type']),
            $msg
        );
    }

    protected function assertColumnNullable(
        string $table,
        string $column,
        bool   $nullable,
        string $schema = 'public'
    ): void {
        $info     = $this->getColumnInfo($table, $column, $schema);
        $expected = $nullable ? 'YES' : 'NO';
        $this->assertSame(
            $expected,
            $info['is_nullable'],
            "Column '{$schema}.{$table}.{$column}' nullable must be " . ($nullable ? 'YES' : 'NO')
        );
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
        // Drop the authserver schema with CASCADE first (removes all contained tables/sequences)
        $this->db->query('DROP SCHEMA IF EXISTS authserver CASCADE');

        // Drop public-schema tables with CASCADE (handles FK dependencies automatically)
        $tables = [
            'massmessagerecipients', 'massmessages',
            'mailtemplates', 'mails', 'messages',
            'tokenactions', 'urls',
            'usertokens', 'usernotes', 'userlog', 'userdetails',
            'users',
            'queueitems',
            'framework_policies', 'settings', 'sessions',
        ];

        foreach ($tables as $table) {
            $this->db->query("DROP TABLE IF EXISTS public.\"{$table}\" CASCADE");
        }

        // Drop the queue_status ENUM type if it was left behind by a failed test
        $this->db->query('DROP TYPE IF EXISTS queue_status');
    }
}
