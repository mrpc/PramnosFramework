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
    // Core: pramnos schema
    // -------------------------------------------------------------------------

    /**
     * CreatePramnosSchema must create the `pramnos` PostgreSQL schema and
     * drop it cleanly on rollback.
     */
    public function testCorePramnosSchemaUpCreatesSchema(): void
    {
        // Arrange
        $m = $this->loadMigration('core', 'CreatePramnosSchema');

        // Act
        $m->up();

        // Assert — schema exists
        $this->assertTrue($this->schemaExists('pramnos'), 'pramnos schema must exist after up()');

        // Assert — rollback removes it
        $m->down();
        $this->assertFalse($this->schemaExists('pramnos'), 'pramnos schema must be gone after down()');
    }

    // -------------------------------------------------------------------------
    // Core: framework_policies
    // -------------------------------------------------------------------------

    /**
     * framework_policies must live in the `pramnos` schema on PostgreSQL and
     * have all PolicyEngine columns and both indexes.
     */
    public function testCorePoliciesUpCreatesTableWithIndexes(): void
    {
        // Arrange — schema must exist first (migration dependency)
        $schema = $this->loadMigration('core', 'CreatePramnosSchema');
        $schema->up();
        $m = $this->loadMigration('core', 'CreateFrameworkPoliciesTable');

        // Act
        $m->up();

        // Assert — table is in the pramnos schema, NOT in public
        $this->assertTrue($this->tableExists('framework_policies', 'pramnos'),
            'framework_policies must be in the pramnos schema');
        $this->assertFalse($this->tableExists('framework_policies', 'public'),
            'framework_policies must NOT exist in the public schema');

        // Assert — columns
        $this->assertColumnType('framework_policies', 'policyid', 'integer', 'pramnos');
        $this->assertColumnType('framework_policies', 'policy_type', 'character varying', 'pramnos');
        $this->assertColumnType('framework_policies', 'target', 'character varying', 'pramnos');
        $this->assertColumnType('framework_policies', 'config', 'json', 'pramnos');
        $this->assertColumnNullable('framework_policies', 'last_run', true, 'pramnos');
        $this->assertColumnNullable('framework_policies', 'next_run', true, 'pramnos');
        $this->assertColumnNullable('framework_policies', 'last_result', true, 'pramnos');
        $this->assertColumnNullable('framework_policies', 'last_error', true, 'pramnos');

        // Assert — indexes
        $this->assertTrue($this->indexExists('framework_policies', 'idx_framework_policies_type_enabled', 'pramnos'));
        $this->assertTrue($this->indexExists('framework_policies', 'idx_framework_policies_next_run', 'pramnos'));

        // Assert — rollback (drops table only, not the schema)
        $m->down();
        $this->assertFalse($this->tableExists('framework_policies', 'pramnos'),
            'framework_policies must be gone after down()');
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
     * massmessages must have the generic broadcast fields: subject, message, type,
     * sender, status, totalrecipients, request. Domain-specific targeting fields
     * (locationid, deyaid, zoneid) belong to the application, not the framework.
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
     * The table must have role_name (varchar), organization_id (nullable int for org
     * scoping), is_active (boolean), and the standard lookup indexes.
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
        // organization_id is the generic column name (override via authserver_organization_column setting)
        $this->assertColumnNullable('roles', 'organization_id', true, 'authserver');

        // Assert — indexes
        $this->assertTrue($this->indexExists('roles', 'idx_authserver_roles_name', 'authserver'));
        $this->assertTrue($this->indexExists('roles', 'idx_authserver_roles_org', 'authserver'));

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

    // -------------------------------------------------------------------------
    // AuthServer: slow_api_calls view (PostgreSQL / TimescaleDB)
    // -------------------------------------------------------------------------

    /**
     * CreateSlowApiCallsView must create the authserver.slow_api_calls view on PostgreSQL.
     *
     * The view lives in the `authserver` schema and joins public.tokenactions +
     * public.usertokens + public.applications to surface API calls that took
     * longer than 5 000 ms (5 seconds) in the last 7 days.
     *
     * On TimescaleDB the view works equally well — tokenactions may be a hypertable
     * partitioned by action_time, but the view query is standard SQL.
     */
    public function testAuthserverSlowApiCallsViewCreatedOnPostgres(): void
    {
        // Arrange — all prerequisite tables must exist before the view can be created
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $this->loadMigration('auth', 'CreateTokenactionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();

        $m = $this->loadMigration('authserver', 'CreateSlowApiCallsView');

        // Act
        $m->up();

        // Assert — view must exist in the authserver schema
        $r = $this->db->execute(
            "SELECT 1 FROM information_schema.views
              WHERE table_schema = 'authserver' AND table_name = 'slow_api_calls'"
        );
        $this->assertTrue(
            $r && $r->numRows > 0,
            'authserver.slow_api_calls view must exist after up()'
        );

        // Assert — the view must be queryable (returns 0 rows since no tokenactions data)
        $r2 = $this->db->execute(
            'SELECT COUNT(*) AS cnt FROM "authserver"."slow_api_calls"'
        );
        $this->assertSame(0, (int) $r2->fields['cnt'], 'empty view must return 0 rows');

        // Assert — rollback removes the view
        $m->down();
        $r3 = $this->db->execute(
            "SELECT 1 FROM information_schema.views
              WHERE table_schema = 'authserver' AND table_name = 'slow_api_calls'"
        );
        $this->assertFalse(
            $r3 && $r3->numRows > 0,
            'view must be gone from authserver schema after down()'
        );
    }

    // -------------------------------------------------------------------------
    // AuthServer: RBAC tables (user_organizations, permission_templates, role_templates,
    //             permission_inheritance, effective_permissions VIEW, PL/pgSQL fns)
    // -------------------------------------------------------------------------

    /**
     * CreateAuthserverUserOrganizationsTable must create authserver.user_organizations
     * with the (userid, organization_id) composite PK and the expected columns.
     *
     * The table name and org column are configurable via Settings; this test verifies
     * the framework defaults (user_organizations / organization_id).
     */
    public function testAuthserverUserOrganizationsUpCreatesTable(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverUserOrganizationsTable');

        // Act
        $m->up();

        // Assert — table exists in authserver schema with default name
        $this->assertTrue(
            $this->tableExists('user_organizations', 'authserver'),
            'authserver.user_organizations must exist after up() with default Settings'
        );
        $this->assertTrue($this->columnExists('user_organizations', 'userid', 'authserver'));
        // organization_id is the generic default (override via authserver_organization_column)
        $this->assertTrue($this->columnExists('user_organizations', 'organization_id', 'authserver'));
        $this->assertTrue($this->columnExists('user_organizations', 'is_active', 'authserver'));
        $this->assertTrue($this->columnExists('user_organizations', 'expires_at', 'authserver'));

        // Assert — rollback removes the table
        $m->down();
        $this->assertFalse($this->tableExists('user_organizations', 'authserver'));
    }

    /**
     * CreateAuthserverPermissionTemplatesTable must create authserver.permission_templates.
     */
    public function testAuthserverPermissionTemplatesUpCreatesTable(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverPermissionTemplatesTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue(
            $this->tableExists('permission_templates', 'authserver'),
            'authserver.permission_templates must exist after up()'
        );
        $this->assertTrue($this->columnExists('permission_templates', 'template_name', 'authserver'));
        $this->assertTrue($this->columnExists('permission_templates', 'template_type', 'authserver'));
        $this->assertTrue($this->columnExists('permission_templates', 'object_type', 'authserver'));
        $this->assertTrue($this->columnExists('permission_templates', 'action', 'authserver'));
        $this->assertTrue($this->columnExists('permission_templates', 'grant_type', 'authserver'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('permission_templates', 'authserver'));
    }

    /**
     * CreateAuthserverRoleTemplatesTable must create authserver.role_templates with
     * a TEXT permission_templateids column (JSON array, cross-DB compatible).
     */
    public function testAuthserverRoleTemplatesUpCreatesTable(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionTemplatesTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverRoleTemplatesTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue(
            $this->tableExists('role_templates', 'authserver'),
            'authserver.role_templates must exist after up()'
        );
        $this->assertTrue($this->columnExists('role_templates', 'role_templateid', 'authserver'));
        $this->assertTrue($this->columnExists('role_templates', 'permission_templateids', 'authserver'));
        $this->assertTrue($this->columnExists('role_templates', 'is_system_template', 'authserver'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('role_templates', 'authserver'));
    }

    /**
     * CreateAuthserverPermissionInheritanceTable must create authserver.permission_inheritance
     * with child/parent object columns and traversal indexes.
     */
    public function testAuthserverPermissionInheritanceUpCreatesTable(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionTemplatesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRoleTemplatesTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverPermissionInheritanceTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue(
            $this->tableExists('permission_inheritance', 'authserver'),
            'authserver.permission_inheritance must exist after up()'
        );
        $this->assertTrue($this->columnExists('permission_inheritance', 'child_object_type', 'authserver'));
        $this->assertTrue($this->columnExists('permission_inheritance', 'child_object_id', 'authserver'));
        $this->assertTrue($this->columnExists('permission_inheritance', 'parent_object_type', 'authserver'));
        $this->assertTrue($this->columnExists('permission_inheritance', 'parent_object_id', 'authserver'));
        $this->assertTrue($this->columnExists('permission_inheritance', 'inheritance_type', 'authserver'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('permission_inheritance', 'authserver'));
    }

    /**
     * CreateAuthserverEffectivePermissionsView must create authserver.effective_permissions
     * as a queryable view that applies deny-takes-priority logic.
     *
     * Verify: (1) view exists, (2) returns 0 rows on empty table, (3) deny with
     * higher priority dominates an allow for the same subject+object+action.
     */
    public function testAuthserverEffectivePermissionsViewCreatedOnPostgreSQL(): void
    {
        // Arrange — all dependency tables must exist
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionTemplatesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRoleTemplatesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionInheritanceTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverEffectivePermissionsView');

        // Act
        $m->up();

        // Assert — view exists in information_schema
        $r = $this->db->execute(
            "SELECT 1 FROM information_schema.views
              WHERE table_schema = 'authserver'
                AND table_name = 'effective_permissions'"
        );
        $this->assertTrue(
            $r && $r->numRows > 0,
            'authserver.effective_permissions view must exist on PostgreSQL after up()'
        );

        // Assert — queryable, returns 0 rows on empty permissions table
        $r = $this->db->execute(
            'SELECT COUNT(*) AS cnt FROM authserver.effective_permissions'
        );
        $this->assertSame('0', (string) $r->fields['cnt']);

        // Assert — deny-takes-priority: insert allow(priority=10) and deny(priority=1010)
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 1, 'report', '42', 'read', 'allow', 10)"
        );
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 1, 'report', '42', 'read', 'deny', 1010)"
        );
        $r = $this->db->execute(
            "SELECT effective_grant FROM authserver.effective_permissions
              WHERE subject_type='user' AND subject_id=1
                AND object_type='report' AND object_id='42' AND action='read'"
        );
        $this->assertSame('deny', $r->fields['effective_grant'],
            'deny with higher priority must dominate allow in effective_permissions');

        // Assert — rollback removes the view
        $m->down();
        $r2 = $this->db->execute(
            "SELECT 1 FROM information_schema.views
              WHERE table_schema = 'authserver'
                AND table_name = 'effective_permissions'"
        );
        $this->assertFalse(
            $r2 && $r2->numRows > 0,
            'effective_permissions view must be gone after down()'
        );
    }

    /**
     * CreateAuthserverRbacFunctions must install 7 PL/pgSQL functions and 2 triggers
     * in the authserver schema on PostgreSQL.
     *
     * Verified: functions exist in pg_proc; triggers exist on permissions and
     * user_roles; the priority trigger auto-increments deny permissions by 1000;
     * apply_permission_template() creates a real permission row.
     */
    public function testAuthserverRbacFunctionsInstalledOnPostgreSQL(): void
    {
        // Arrange — all dependency tables/views must exist
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionTemplatesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRoleTemplatesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionInheritanceTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverEffectivePermissionsView')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverRbacFunctions');

        // Act
        $m->up();

        // Assert — all 7 functions exist in pg_proc
        $fns = [
            'set_permission_priority',
            'check_user_deya_membership',
            'apply_permission_template',
            'apply_role_template',
            'log_audit_event',
            'check_permission_with_inheritance',
            'get_user_effective_permissions',
        ];
        foreach ($fns as $fn) {
            $r = $this->db->execute(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt FROM pg_proc p
                     JOIN pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname = 'authserver' AND p.proname = %s",
                    $fn
                )
            );
            $this->assertGreaterThan(0, (int) $r->fields['cnt'],
                "PL/pgSQL function authserver.{$fn}() must exist after up()");
        }

        // Assert — trigger_set_permission_priority is attached to authserver.permissions
        $r = $this->db->execute(
            "SELECT COUNT(*) AS cnt FROM information_schema.triggers
              WHERE trigger_schema = 'authserver'
                AND event_object_table = 'permissions'
                AND trigger_name = 'trigger_set_permission_priority'"
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'trigger_set_permission_priority must be attached to authserver.permissions');

        // Assert — trigger auto-increments deny priority by 1000
        $this->db->execute(
            "INSERT INTO authserver.permissions
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 99, 'zone', '7', 'delete', 'deny', 0)"
        );
        $r = $this->db->execute(
            "SELECT priority FROM authserver.permissions
              WHERE subject_type='user' AND subject_id=99 AND action='delete'"
        );
        $this->assertSame('1000', (string) $r->fields['priority'],
            'set_permission_priority trigger must add 1000 to deny priority');

        // Assert — apply_permission_template() creates a permission row
        $this->db->execute(
            "INSERT INTO authserver.permission_templates
             (template_name, template_type, object_type, object_id_pattern, action, grant_type, priority)
             VALUES ('test_read_all', 'role_template', 'report', '*', 'read', 'allow', 5)"
        );
        $r = $this->db->execute(
            "SELECT authserver.apply_permission_template(
                 (SELECT templateid FROM authserver.permission_templates WHERE template_name='test_read_all'),
                 'role', 1, NULL, NULL
             ) AS cnt"
        );
        $this->assertSame('1', (string) $r->fields['cnt'],
            'apply_permission_template() must return 1 for a newly created permission');

        // Verify the permission was actually created
        $r2 = $this->db->execute(
            "SELECT COUNT(*) AS cnt FROM authserver.permissions
              WHERE subject_type='role' AND subject_id=1
                AND object_type='report' AND action='read'"
        );
        $this->assertGreaterThan(0, (int) $r2->fields['cnt'],
            'apply_permission_template() must insert a real permission row');

        // Assert — rollback removes triggers and functions
        $m->down();
        $r3 = $this->db->execute(
            "SELECT COUNT(*) AS cnt FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'authserver' AND p.proname = 'set_permission_priority'"
        );
        $this->assertSame('0', (string) $r3->fields['cnt'],
            'set_permission_priority function must be gone after down()');
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
    // Auth 000017-000026: authserver.* tables on plain PostgreSQL (no TimescaleDB)
    // =========================================================================

    /**
     * Migration 000017 (loginlockout) creates authserver.loginlockouts on PostgreSQL.
     * The table must be in the authserver schema (not public) for compatibility
     * with the urbanwater codebase which references authserver.loginlockouts.
     */
    public function testLoginlockoutsCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange — authserver schema must exist first
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('auth', 'CreateLoginlockoutTable');

        // Act
        $m->up();

        // Assert — table is in the authserver schema
        $this->assertTrue($this->tableExists('loginlockouts', 'authserver'),
            'loginlockouts must be in the authserver schema after up()');
        $this->assertFalse($this->tableExists('loginlockouts', 'public'),
            'loginlockouts must NOT be in the public schema');

        // Assert — insertable and queryable
        $this->db->query(
            "INSERT INTO authserver.loginlockouts
             (locktype, lookupvalue, failedattempts, firstfailedat, lastfailedat, lockoutuntil, createdat, updatedat)
             VALUES ('ip', '127.0.0.1', 1, EXTRACT(EPOCH FROM NOW())::INT,
                     EXTRACT(EPOCH FROM NOW())::INT, 0,
                     EXTRACT(EPOCH FROM NOW())::INT, EXTRACT(EPOCH FROM NOW())::INT)"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.loginlockouts");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('loginlockouts', 'authserver'),
            'loginlockouts must be gone after down()');
    }

    /**
     * Migration 000018 (user_twofactor) creates authserver.user_twofactor.
     * userid is the PK — no auto-increment.
     */
    public function testUserTwofactorCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUserTwofactorTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('user_twofactor', 'authserver'),
            'user_twofactor must be in the authserver schema');
        $this->assertFalse($this->tableExists('user_twofactor', 'public'));

        $this->db->query(
            "INSERT INTO authserver.user_twofactor (userid, enabled, created_at, updated_at)
             VALUES (999, 0, EXTRACT(EPOCH FROM NOW())::INT, EXTRACT(EPOCH FROM NOW())::INT)"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.user_twofactor");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('user_twofactor', 'authserver'));
    }

    /**
     * Migration 000019 (twofactor_setup) creates authserver.twofactor_setup.
     */
    public function testTwofactorSetupCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('auth', 'CreateTwofactorSetupTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('twofactor_setup', 'authserver'),
            'twofactor_setup must be in the authserver schema');
        $this->assertFalse($this->tableExists('twofactor_setup', 'public'));

        $this->db->query(
            "INSERT INTO authserver.twofactor_setup (userid, temp_secret, used, expires_at, created_at)
             VALUES (999, 'JBSWY3DPEHPK3PXP', 0,
                     EXTRACT(EPOCH FROM NOW())::INT + 900,
                     EXTRACT(EPOCH FROM NOW())::INT)"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.twofactor_setup");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('twofactor_setup', 'authserver'));
    }

    /**
     * Migration 000020 (twofactor_attempts) creates authserver.twofactor_attempts.
     * On plain PostgreSQL (no TimescaleDB) ifCapable(TIMESCALEDB) is a no-op —
     * a plain table is created, not a hypertable.
     */
    public function testTwofactorAttemptsCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('auth', 'CreateTwofactorAttemptsTable');

        // Act
        $m->up();

        // Assert — plain table in authserver schema
        $this->assertTrue($this->tableExists('twofactor_attempts', 'authserver'),
            'twofactor_attempts must be in the authserver schema');
        $this->assertFalse($this->tableExists('twofactor_attempts', 'public'));

        $this->db->query(
            "INSERT INTO authserver.twofactor_attempts (userid, success, ip_address, attempt_time)
             VALUES (999, 1, '127.0.0.1', NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.twofactor_attempts");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('twofactor_attempts', 'authserver'));
    }

    /**
     * Migration 000021 (user_activity_log) creates authserver.user_activity_log.
     * On plain PostgreSQL: plain table (no TimescaleDB hypertable).
     */
    public function testUserActivityLogCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('auth', 'CreateUserActivityLogTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('user_activity_log', 'authserver'),
            'user_activity_log must be in the authserver schema');
        $this->assertFalse($this->tableExists('user_activity_log', 'public'));

        $this->db->query(
            "INSERT INTO authserver.user_activity_log (userid, action, created_at)
             VALUES (999, 'login', NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.user_activity_log");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('user_activity_log', 'authserver'));
    }

    /**
     * Migration 000022 (user_privacy_settings) creates authserver.user_privacy_settings.
     */
    public function testUserPrivacySettingsCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('auth', 'CreateUserPrivacySettingsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('user_privacy_settings', 'authserver'),
            'user_privacy_settings must be in the authserver schema');
        $this->assertFalse($this->tableExists('user_privacy_settings', 'public'));

        $this->db->query(
            "INSERT INTO authserver.user_privacy_settings (userid, updated_at)
             VALUES (999, NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.user_privacy_settings");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('user_privacy_settings', 'authserver'));
    }

    /**
     * Migration 000023 (user_consents) creates authserver.user_consents.
     * On plain PostgreSQL: plain table (no TimescaleDB hypertable).
     */
    public function testUserConsentsCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('auth', 'CreateUserPrivacySettingsTable')->up();
        $m = $this->loadMigration('auth', 'CreateUserConsentsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('user_consents', 'authserver'),
            'user_consents must be in the authserver schema');
        $this->assertFalse($this->tableExists('user_consents', 'public'));

        $this->db->query(
            "INSERT INTO authserver.user_consents (userid, consent_type, granted, granted_at)
             VALUES (999, 'marketing', 1, NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.user_consents");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('user_consents', 'authserver'));
    }

    /**
     * Migration 000024 (data_processing_records) creates authserver.data_processing_records.
     * On plain PostgreSQL: plain table (no TimescaleDB hypertable).
     */
    public function testDataProcessingRecordsCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('auth', 'CreateDataProcessingRecordsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('data_processing_records', 'authserver'),
            'data_processing_records must be in the authserver schema');
        $this->assertFalse($this->tableExists('data_processing_records', 'public'));

        $this->db->query(
            "INSERT INTO authserver.data_processing_records
             (userid, operation, data_category, legal_basis, processed_at)
             VALUES (999, 'export', 'profile', 'consent', NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.data_processing_records");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('data_processing_records', 'authserver'));
    }

    /**
     * Migration 000025 (gdpr_requests) creates authserver.gdpr_requests.
     * On plain PostgreSQL: plain table (no TimescaleDB hypertable).
     */
    public function testGdprRequestsCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $m = $this->loadMigration('auth', 'CreateGdprRequestsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('gdpr_requests', 'authserver'),
            'gdpr_requests must be in the authserver schema');
        $this->assertFalse($this->tableExists('gdpr_requests', 'public'));

        $this->db->query(
            "INSERT INTO authserver.gdpr_requests (userid, request_type, status, requested_at)
             VALUES (999, 'erasure', 'pending', NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.gdpr_requests");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('gdpr_requests', 'authserver'));
    }

    /**
     * Migration 000026 (daily_activity_summary) creates a materialized view or
     * continuous aggregate in the authserver schema.
     *
     * On plain PostgreSQL (no TimescaleDB) the migration creates a MATERIALIZED
     * VIEW (checked via pg_matviews). On the TimescaleDB host (which this test
     * suite connects to) TimescaleDB is detected and a continuous aggregate is
     * created instead — both cases must produce an authserver-scoped object that
     * is NOT in the public schema and is queryable.
     *
     * After down(), the object must be removed.
     */
    public function testDailyActivitySummaryMaterializedViewOnPostgreSQL(): void
    {
        // Arrange — source table must exist
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('auth', 'CreateUserActivityLogTable')->up();

        $m = $this->loadMigration('auth', 'CreateDailyActivitySummaryView');

        // Act
        $m->up();

        // Assert — object exists in authserver schema: either pg_matviews OR
        // timescaledb_information.continuous_aggregates (TimescaleDB host)
        $matviewCnt = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_matviews
                 WHERE schemaname = %s AND matviewname = %s",
                'authserver',
                'daily_activity_summary'
            )
        )->fields['cnt'];

        $caggCnt = 0;
        $caggResult = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables
             WHERE table_schema = 'timescaledb_information'
               AND table_name = 'continuous_aggregates'"
        );
        if ((int) $caggResult->fields['cnt'] > 0) {
            $caggCnt = (int) $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt
                     FROM timescaledb_information.continuous_aggregates
                     WHERE view_schema = %s AND view_name = %s",
                    'authserver',
                    'daily_activity_summary'
                )
            )->fields['cnt'];
        }

        $this->assertGreaterThan(0, $matviewCnt + $caggCnt,
            'authserver.daily_activity_summary must be a materialized view or continuous aggregate');

        // Assert — NOT in public schema
        $rPub = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_matviews
                 WHERE schemaname = %s AND matviewname = %s",
                'public',
                'daily_activity_summary'
            )
        );
        $this->assertSame('0', (string) $rPub->fields['cnt'],
            'daily_activity_summary must NOT be in the public schema');

        // Assert — queryable
        $r2 = $this->db->query('SELECT COUNT(*) AS cnt FROM authserver.daily_activity_summary');
        $this->assertNotNull($r2, 'authserver.daily_activity_summary must be queryable');

        // Assert — down() removes the object
        $m->down();
        $matviewAfter = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_matviews
                 WHERE schemaname = %s AND matviewname = %s",
                'authserver',
                'daily_activity_summary'
            )
        )->fields['cnt'];

        $caggAfter = 0;
        if ((int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables
             WHERE table_schema = 'timescaledb_information'
               AND table_name = 'continuous_aggregates'"
        )->fields['cnt'] > 0) {
            $caggAfter = (int) $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt
                     FROM timescaledb_information.continuous_aggregates
                     WHERE view_schema = %s AND view_name = %s",
                    'authserver',
                    'daily_activity_summary'
                )
            )->fields['cnt'];
        }

        $this->assertSame(0, $matviewAfter + $caggAfter,
            'authserver.daily_activity_summary must be gone after down()');
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
        // DROP SCHEMA CASCADE can deadlock or get a lock conflict when another
        // test process (e.g. TwoFactorAuthServicePostgreSQLTest.setUp) holds DDL
        // locks inside authserver at the same moment.  We retry with backoff so
        // a transient lock wait does not fail the test.
        $this->dropSchemaWithRetry('authserver');
        $this->dropSchemaWithRetry('pramnos');

        // Drop public-schema tables with CASCADE (handles FK dependencies automatically)
        $tables = [
            'massmessagerecipients', 'massmessages',
            'mailtemplates', 'mails', 'messages',
            'tokenactions', 'urls',
            'usertokens', 'usernotes', 'userlog', 'userdetails',
            'users',
            'queueitems',
            'settings', 'sessions',
        ];

        foreach ($tables as $table) {
            $this->db->query("DROP TABLE IF EXISTS public.\"{$table}\" CASCADE");
        }

        // Drop the queue_status ENUM type if it was left behind by a failed test
        $this->db->query('DROP TYPE IF EXISTS queue_status');
    }

    /**
     * Drop a schema with retry-on-lock-conflict.
     *
     * PostgreSQL's DROP SCHEMA CASCADE requires AccessExclusiveLock on all
     * contained objects.  If another session holds a DDL lock inside the schema
     * (e.g. an in-progress CREATE TABLE from a concurrent setUp()), the DROP
     * blocks and can cause a deadlock.  We set a short lock_timeout so the
     * server aborts the DROP quickly, then retry after a brief sleep.
     */
    private function dropSchemaWithRetry(string $schema, int $maxAttempts = 5): void
    {
        $sql = "DROP SCHEMA IF EXISTS {$schema} CASCADE";
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Short timeout so we fail fast instead of blocking indefinitely
                $this->db->query('SET lock_timeout = \'2s\'');
                $this->db->query($sql);
                $this->db->query('SET lock_timeout = 0'); // restore default
                return;
            } catch (\Exception $e) {
                if ($attempt === $maxAttempts) {
                    throw $e;
                }
                // Wait before retrying: 200ms, 400ms, 600ms, 800ms
                usleep(200000 * $attempt);
                // Re-connect if the connection was broken by the aborted transaction
                if (!$this->db->connected) {
                    $this->db->connect();
                }
            }
        }
    }
}
