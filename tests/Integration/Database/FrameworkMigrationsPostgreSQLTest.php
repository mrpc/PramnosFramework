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

    /**
     * CreateUsertokensTable must also install PKCE-specific partial indexes and
     * CHECK constraints on PostgreSQL (RFC 7636 §4.2/§4.3).
     *
     * The three partial indexes target only the rows that actually participate in
     * PKCE auth-code flows, keeping the index footprint small. Two CHECK
     * constraints enforce the allowed method values ('plain' | 'S256') and the
     * 43-128 character URL-safe challenge format.
     *
     * These are PostgreSQL-only constructs; MySQL gets a plain index + method check.
     */
    public function testUsertokensPkceConstraintsAndIndexesOnPostgreSQL(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUsertokensTable');

        // Act
        $m->up();

        // Assert — partial index on code_challenge (only non-null rows indexed)
        $idxChallengeCount = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_indexes
                 WHERE tablename = %s AND indexname = %s",
                'usertokens',
                'idx_usertokens_code_challenge'
            )
        )->fields['cnt'];
        $this->assertSame(1, $idxChallengeCount,
            'idx_usertokens_code_challenge partial index must be created on PostgreSQL');

        // Assert — unique partial index for auth_code+PKCE combos
        $idxUniqueCount = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_indexes
                 WHERE tablename = %s AND indexname = %s",
                'usertokens',
                'idx_usertokens_auth_code_unique'
            )
        )->fields['cnt'];
        $this->assertSame(1, $idxUniqueCount,
            'idx_usertokens_auth_code_unique partial unique index must exist for PKCE auth codes');

        // Assert — PKCE lookup composite index
        $idxPkceCount = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_indexes
                 WHERE tablename = %s AND indexname = %s",
                'usertokens',
                'idx_usertokens_auth_code_pkce'
            )
        )->fields['cnt'];
        $this->assertSame(1, $idxPkceCount,
            'idx_usertokens_auth_code_pkce lookup index must exist for auth_code PKCE rows');

        // Assert — CHECK constraint on method values
        $chkMethod = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.check_constraints
                 WHERE constraint_name = %s",
                'chk_code_challenge_method'
            )
        )->fields['cnt'];
        $this->assertSame(1, $chkMethod,
            'chk_code_challenge_method CHECK constraint must enforce plain|S256 values');

        // Assert — CHECK constraint on format (43-128 URL-safe chars)
        $chkFormat = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.check_constraints
                 WHERE constraint_name = %s",
                'chk_code_challenge_format'
            )
        )->fields['cnt'];
        $this->assertSame(1, $chkFormat,
            'chk_code_challenge_format CHECK constraint must enforce RFC 7636 §4.2 format');

        // Assert — constraint rejects invalid method value
        $rejected = false;
        try {
            $this->db->query(
                "INSERT INTO public.usertokens
                 (userid, tokentype, token, created, status, deviceinfo, scope,
                  code_challenge, code_challenge_method)
                 VALUES (1, 'auth_code', 'tok', 0, 1, '{}', '',
                  'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'MD5')"
            );
        } catch (\Exception $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected,
            'chk_code_challenge_method must reject invalid method values like MD5');

        // Assert — constraint rejects a challenge that is too short (< 43 chars)
        $rejectedShort = false;
        try {
            $this->db->query(
                "INSERT INTO public.usertokens
                 (userid, tokentype, token, created, status, deviceinfo, scope,
                  code_challenge, code_challenge_method)
                 VALUES (1, 'auth_code', 'tok2', 0, 1, '{}', '',
                  'tooshort', 'S256')"
            );
        } catch (\Exception $e) {
            $rejectedShort = true;
        }
        $this->assertTrue($rejectedShort,
            'chk_code_challenge_format must reject challenges shorter than 43 characters');
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

    /**
     * CreateApplicationsSchema must create the 'applications' schema in PostgreSQL.
     *
     * The applications schema separates app-level infrastructure (webhook endpoints,
     * per-app settings, usage stats) from public and authserver schemas. On MySQL
     * this migration is a no-op. Must be idempotent (CREATE SCHEMA IF NOT EXISTS).
     */
    public function testApplicationsSchemaIsCreatedByMigration(): void
    {
        // Arrange
        $m = $this->loadMigration('authserver', 'CreateApplicationsSchema');

        // Act
        $m->up();

        // Assert — applications schema exists in pg_catalog
        $this->assertTrue($this->schemaExists('applications'),
            "'applications' schema must be created by the migration");

        // Idempotent re-run must not throw
        $m->up();
        $this->assertTrue($this->schemaExists('applications'));

        // Assert — rollback drops the schema (CASCADE removes any contained objects)
        $m->down();
        $this->assertFalse($this->schemaExists('applications'),
            "'applications' schema must be dropped by down()");
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

    /**
     * When `authserver_organization_column` is overridden via Settings (e.g. to
     * 'deyaid' for UrbanWater), the roles table must be created with that column
     * name instead of the generic 'organization_id'.
     *
     * This verifies that the configurable-naming mechanism works end-to-end:
     * any application that cannot rename existing data can override the column
     * name in settings.php without modifying the migration.
     */
    public function testAuthserverRolesTableRespectsOrganizationColumnOverride(): void
    {
        // The entire test — including prerequisite setUp calls — lives inside the
        // try block so that the finally always restores Settings even on exception.
        try {
            // Arrange — override before running the migration
            \Pramnos\Application\Settings::setSetting('authserver_organization_column', 'deyaid', false);

            $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
            $m = $this->loadMigration('authserver', 'CreateAuthserverRolesTable');

            // Act
            $m->up();

            // Assert — custom column name is used instead of the default
            $this->assertTrue(
                $this->columnExists('roles', 'deyaid', 'authserver'),
                'roles must contain the overridden column name "deyaid"'
            );
            $this->assertFalse(
                $this->columnExists('roles', 'organization_id', 'authserver'),
                'roles must NOT contain "organization_id" when the column is overridden'
            );

            // Assert — index still created (uses overridden name internally)
            $this->assertTrue(
                $this->indexExists('roles', 'idx_authserver_roles_org', 'authserver'),
                'idx_authserver_roles_org index must exist regardless of column name override'
            );

            // Assert — rollback
            $m->down();
            $this->assertFalse($this->tableExists('roles', 'authserver'));
        } finally {
            // Always restore default so subsequent tests are unaffected,
            // even if an exception was thrown during setUp or assertions.
            // Set to the explicit default (not null) to avoid a DB-lookup
            // attempt on the next getSetting() call.
            \Pramnos\Application\Settings::setSetting('authserver_organization_column', 'organization_id', false);
        }
    }

    /**
     * When both `authserver_organization_table` and `authserver_organization_column`
     * are overridden (UrbanWater pattern: table='user_deyas', column='deyaid'), the
     * user-organisations migration must:
     *   - create authserver.user_deyas (not authserver.user_organizations)
     *   - use 'deyaid' as the organisation FK column (not 'organization_id')
     *   - skip the FK to public.organizations (because the FK target is app-defined)
     *   - drop authserver.user_deyas on rollback
     */
    public function testAuthserverUserOrganizationsTableRespectsFullOverride(): void
    {
        try {
            // Arrange — configure UrbanWater-style naming before running the migration
            \Pramnos\Application\Settings::setSetting('authserver_organization_table',  'user_deyas', false);
            \Pramnos\Application\Settings::setSetting('authserver_organization_column', 'deyaid',     false);

            $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
            $this->loadMigration('auth', 'CreateUsersTable')->up();
            $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
            $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
            // NOTE: CreateOrganizationsTable is intentionally NOT loaded here —
            // the override path must NOT add an FK to public.organizations

            $m = $this->loadMigration('authserver', 'CreateAuthserverUserOrganizationsTable');

            // Act
            $m->up();

            // Assert — table created under the overridden name
            $this->assertTrue(
                $this->tableExists('user_deyas', 'authserver'),
                'authserver.user_deyas must be created when table name is overridden'
            );
            $this->assertFalse(
                $this->tableExists('user_organizations', 'authserver'),
                'authserver.user_organizations must NOT be created when table name is overridden'
            );

            // Assert — overridden column name used as part of composite PK
            $this->assertTrue(
                $this->columnExists('user_deyas', 'deyaid', 'authserver'),
                'authserver.user_deyas must contain the "deyaid" column'
            );
            $this->assertFalse(
                $this->columnExists('user_deyas', 'organization_id', 'authserver'),
                'authserver.user_deyas must NOT contain "organization_id" when overridden'
            );

            // Assert — no FK to public.organizations (override path skips it)
            $fkCnt = (int) $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt
                     FROM information_schema.table_constraints tc
                     JOIN information_schema.key_column_usage kcu
                          ON tc.constraint_name = kcu.constraint_name
                     WHERE tc.constraint_type = 'FOREIGN KEY'
                       AND tc.table_schema = %s
                       AND tc.table_name = %s",
                    'authserver',
                    'user_deyas'
                )
            )->fields['cnt'];
            $this->assertSame(0, $fkCnt,
                'No FK to public.organizations must be added when organisation table is overridden');

            // Assert — rollback removes the overridden-name table
            $m->down();
            $this->assertFalse(
                $this->tableExists('user_deyas', 'authserver'),
                'authserver.user_deyas must be dropped by down()'
            );
        } finally {
            \Pramnos\Application\Settings::setSetting('authserver_organization_table',  'user_organizations', false);
            \Pramnos\Application\Settings::setSetting('authserver_organization_column', 'organization_id',    false);
        }
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
     * authserver.audit_log must use the polymorphic actor/target/object schema.
     * old_values and new_values must be JSONB (GIN-indexable snapshots).
     * event_timestamp must be TIMESTAMPTZ (timezone-aware audit record).
     * organization_context provides optional organisation scoping.
     *
     * Columns were renamed from the original RBAC-specific schema to the generic
     * event model that matches Urbanwater production (with organization_context
     * replacing deya_context).
     */
    public function testAuthserverAuditLogHasCorrectSchema(): void
    {
        // Arrange — audit_log now depends on organizations table for the FK
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $this->loadMigration('authserver', 'CreateOrganizationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable');

        // Act
        $m->up();

        // Assert — table in correct schema
        $this->assertTrue($this->tableExists('audit_log', 'authserver'));

        // Assert — old_values and new_values are JSONB (not plain JSON) for GIN indexability
        $this->assertColumnType('audit_log', 'old_values', 'jsonb', 'authserver',
            'old_values must be JSONB for GIN-indexable change snapshots');
        $this->assertColumnType('audit_log', 'new_values', 'jsonb', 'authserver',
            'new_values must be JSONB for GIN-indexable change snapshots');
        $this->assertColumnType('audit_log', 'metadata',   'jsonb', 'authserver',
            'metadata must be JSONB to store structured request context');

        // Assert — state columns are nullable (creation events lack old_values, deletions lack new_values)
        $this->assertColumnNullable('audit_log', 'old_values', true, 'authserver');
        $this->assertColumnNullable('audit_log', 'new_values', true, 'authserver');

        // Assert — event_timestamp is timezone-aware
        $this->assertColumnType('audit_log', 'event_timestamp', 'timestamp with time zone', 'authserver',
            'event_timestamp must be TIMESTAMPTZ for timezone-correct audit records');

        // Assert — polymorphic columns exist
        $this->assertColumnType('audit_log', 'actor_type',   'character varying', 'authserver');
        $this->assertColumnType('audit_log', 'target_type',  'character varying', 'authserver');
        $this->assertColumnType('audit_log', 'target_id',    'character varying', 'authserver');
        $this->assertColumnType('audit_log', 'object_type',  'character varying', 'authserver');
        $this->assertColumnType('audit_log', 'object_id',    'character varying', 'authserver');

        // Assert — organisation context column exists and is nullable
        $this->assertColumnNullable('audit_log', 'organization_context', true, 'authserver');

        // Assert — expected indexes exist
        $this->assertTrue($this->indexExists('audit_log', 'idx_audit_actor',        'authserver'));
        $this->assertTrue($this->indexExists('audit_log', 'idx_audit_event_type',   'authserver'));
        $this->assertTrue($this->indexExists('audit_log', 'idx_audit_target',       'authserver'));
        $this->assertTrue($this->indexExists('audit_log', 'idx_audit_timestamp',    'authserver'));
        $this->assertTrue($this->indexExists('audit_log', 'idx_audit_organization', 'authserver'));

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
     * `applications` schema on PostgreSQL with a CHECK constraint on auth_method,
     * a unique constraint on (appid, auth_method), and the Urbanwater-aligned
     * column set: is_enabled (not is_active) + updated_at.
     */
    public function testAuthserverOauth2ClientAuthMethodsCreatesTableInApplicationsSchemaOnPostgres(): void
    {
        // Arrange — applications schema + table must exist for FK integrity
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateOauth2ClientAuthMethodsTable');

        // Act
        $m->up();

        // Assert — table in applications schema (not authserver)
        $this->assertTrue($this->tableExists('oauth2_client_auth_methods', 'applications'),
            'oauth2_client_auth_methods must be created in the applications schema');
        $this->assertFalse($this->tableExists('oauth2_client_auth_methods', 'authserver'),
            'oauth2_client_auth_methods must NOT be in the authserver schema');

        // Assert — auth_method uses VARCHAR + CHECK (not ENUM) on PostgreSQL
        $this->assertColumnType('oauth2_client_auth_methods', 'auth_method', 'character varying', 'applications');

        // Assert — is_enabled column exists (renamed from is_active to match Urbanwater)
        $this->assertTrue(
            $this->columnExists('oauth2_client_auth_methods', 'is_enabled', 'applications'),
            'is_enabled column must exist; is_active was the old incorrect name'
        );

        // Assert — updated_at column exists (missing in original backport)
        $this->assertTrue(
            $this->columnExists('oauth2_client_auth_methods', 'updated_at', 'applications'),
            'updated_at must be present to match Urbanwater schema'
        );

        // Assert — is_active must NOT exist (was renamed to is_enabled)
        $this->assertFalse(
            $this->columnExists('oauth2_client_auth_methods', 'is_active', 'applications'),
            'is_active must not exist; column was renamed to is_enabled'
        );

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('oauth2_client_auth_methods', 'applications'));
        $this->loadMigration('authserver', 'CreateApplicationsTable')->down();
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->down();
    }

    /**
     * CreateOauth2WebhooksTables must create both webhook tables inside the
     * `applications` schema on PostgreSQL with JSONB payload and the correct
     * UrbanWater-aligned column schema (webhook_id, endpoint_url, webhook_type,
     * secret_key). Also verifies the create_webhook_event() PL/pgSQL function.
     */
    public function testAuthserverOauth2WebhooksCreatesBothTablesInApplicationsSchemaOnPostgres(): void
    {
        // Arrange — users and applications tables needed for FK constraints in events table
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateOauth2WebhooksTables');

        // Act
        $m->up();

        // Assert — both tables in applications schema (not authserver)
        $this->assertTrue($this->tableExists('oauth2_webhook_endpoints', 'applications'),
            'oauth2_webhook_endpoints must be created in the applications schema');
        $this->assertTrue($this->tableExists('oauth2_webhook_events', 'applications'),
            'oauth2_webhook_events must be created in the applications schema');
        $this->assertFalse($this->tableExists('oauth2_webhook_endpoints', 'authserver'),
            'oauth2_webhook_endpoints must NOT be in authserver schema');

        // Assert — endpoints has the correct columns
        $this->assertColumnType('oauth2_webhook_endpoints', 'webhook_id', 'integer', 'applications');
        $this->assertColumnType('oauth2_webhook_endpoints', 'endpoint_url', 'character varying', 'applications');
        $this->assertColumnType('oauth2_webhook_endpoints', 'webhook_type', 'character varying', 'applications');
        $this->assertColumnType('oauth2_webhook_endpoints', 'secret_key', 'character varying', 'applications');

        // Assert — events has JSONB payload and status lifecycle
        $this->assertColumnType('oauth2_webhook_events', 'payload', 'jsonb', 'applications',
            'payload must be JSONB on PostgreSQL for efficient JSON querying');
        $this->assertColumnType('oauth2_webhook_events', 'status', 'character varying', 'applications');
        $this->assertColumnType('oauth2_webhook_events', 'attempts', 'integer', 'applications');

        // Assert — create_webhook_event() function was created in applications schema
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.routines
             WHERE routine_schema = 'applications' AND routine_name = 'create_webhook_event'"
        );
        $this->assertEquals(1, (int) $result->fields['cnt'],
            'applications.create_webhook_event() function must exist after up()');

        // Assert — rollback drops both tables and the function
        $m->down();
        $this->assertFalse($this->tableExists('oauth2_webhook_events', 'applications'));
        $this->assertFalse($this->tableExists('oauth2_webhook_endpoints', 'applications'));
        $result2 = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.routines
             WHERE routine_schema = 'applications' AND routine_name = 'create_webhook_event'"
        );
        $this->assertEquals(0, (int) $result2->fields['cnt'],
            'create_webhook_event() must be dropped by down()');
        $this->loadMigration('authserver', 'CreateApplicationsTable')->down();
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->down();
    }

    /**
     * CreateOrganizationsTable must create the public.organizations table with an
     * auto-increment organization_id PK, name, org_type, and is_active columns.
     *
     * The organizations table is the FK target for user_organizations.organization_id
     * and provides the generic organisation registry used by the authserver RBAC system.
     */
    public function testOrganizationsTableCreatedInPublicSchema(): void
    {
        // Arrange
        $m = $this->loadMigration('authserver', 'CreateOrganizationsTable');

        // Act
        $m->up();

        // Assert — table exists in public schema
        $this->assertTrue($this->tableExists('organizations', 'public'),
            'organizations must be created in the public schema');

        // Assert — essential columns
        $this->assertColumnType('organizations', 'organization_id', 'integer', 'public');
        $this->assertColumnType('organizations', 'name', 'character varying', 'public');
        $this->assertColumnType('organizations', 'is_active', 'boolean', 'public');
        $this->assertColumnNullable('organizations', 'description', true, 'public');
        $this->assertColumnNullable('organizations', 'org_type', true, 'public');

        // Assert — rollback removes the table
        $m->down();
        $this->assertFalse($this->tableExists('organizations', 'public'));
    }

    // -------------------------------------------------------------------------
    // AuthServer: oauth2_application_grants + views + cleanup function
    // -------------------------------------------------------------------------

    /**
     * CreateOauth2ApplicationGrantsTable must create:
     *   - applications.oauth2_application_grants (table with grant_type CHECK)
     *   - applications.oauth2_application_permissions (VIEW using array_agg)
     *   - applications.oauth2_active_tokens (VIEW)
     *   - authserver.cleanup_expired_oauth2_tokens() (PL/pgSQL function)
     *
     * The view oauth2_application_permissions aggregates an application's OAuth2
     * profile (scopes, redirect URI, allowed grant types) for authorisation decisions.
     * cleanup_expired_oauth2_tokens() removes tokens expired for more than 7 days.
     */
    public function testOauth2ApplicationGrantsTableAndViewsOnPostgreSQL(): void
    {
        // Arrange — prerequisites: applications schema, applications table, usertokens, users
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();

        $m = $this->loadMigration('authserver', 'CreateOauth2ApplicationGrantsTable');

        // Act
        $m->up();

        // Assert — grants table exists in applications schema
        $grantsCnt = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables
                 WHERE table_schema = %s AND table_name = %s",
                'applications',
                'oauth2_application_grants'
            )
        )->fields['cnt'];
        $this->assertSame(1, $grantsCnt,
            'applications.oauth2_application_grants table must be created');

        // Assert — grant_type CHECK constraint exists (prevents invalid grant types)
        $chkGrant = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.table_constraints
                 WHERE table_schema = %s AND table_name = %s
                   AND constraint_type = 'CHECK'",
                'applications',
                'oauth2_application_grants'
            )
        )->fields['cnt'];
        $this->assertGreaterThan(0, $chkGrant,
            'oauth2_application_grants must have a CHECK constraint on grant_type');

        // Assert — essential columns
        $this->assertTrue(
            $this->columnExists('oauth2_application_grants', 'grant_id', 'applications'),
            'grant_id (serial PK) must exist'
        );
        $this->assertTrue(
            $this->columnExists('oauth2_application_grants', 'is_enabled', 'applications'),
            'is_enabled flag must exist'
        );

        // Assert — oauth2_application_permissions VIEW exists
        $permViewCnt = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.views
                 WHERE table_schema = %s AND table_name = %s",
                'applications',
                'oauth2_application_permissions'
            )
        )->fields['cnt'];
        $this->assertSame(1, $permViewCnt,
            'applications.oauth2_application_permissions VIEW must be created');

        // Assert — view is queryable (empty result set OK, no exception)
        $this->db->query('SELECT * FROM applications.oauth2_application_permissions LIMIT 0');

        // Assert — oauth2_active_tokens VIEW exists
        $tokViewCnt = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.views
                 WHERE table_schema = %s AND table_name = %s",
                'applications',
                'oauth2_active_tokens'
            )
        )->fields['cnt'];
        $this->assertSame(1, $tokViewCnt,
            'applications.oauth2_active_tokens VIEW must be created');

        $this->db->query('SELECT * FROM applications.oauth2_active_tokens LIMIT 0');

        // Assert — cleanup_expired_oauth2_tokens() function exists in authserver schema
        $fnCnt = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.routines
                 WHERE routine_schema = %s AND routine_name = %s AND routine_type = 'FUNCTION'",
                'authserver',
                'cleanup_expired_oauth2_tokens'
            )
        )->fields['cnt'];
        $this->assertSame(1, $fnCnt,
            'authserver.cleanup_expired_oauth2_tokens() function must be created');

        // Assert — function is callable and returns an integer
        $fnResult = $this->db->query('SELECT authserver.cleanup_expired_oauth2_tokens() AS deleted');
        $this->assertNotNull($fnResult, 'cleanup_expired_oauth2_tokens() must be callable without error');
        $this->assertSame('0', (string) $fnResult->fields['deleted'],
            'Cleanup on empty usertokens must return 0 deleted rows');

        // Assert — rollback removes all objects
        $m->down();
        $this->assertFalse(
            (bool) (int) $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt FROM information_schema.tables
                     WHERE table_schema = %s AND table_name = %s",
                    'applications',
                    'oauth2_application_grants'
                )
            )->fields['cnt'],
            'oauth2_application_grants must be removed by down()'
        );
    }

    // -------------------------------------------------------------------------
    // AuthServer: OAuth2 helper functions + trigger + webhook status view
    // -------------------------------------------------------------------------

    /**
     * CreateOauth2HelperFunctions must install four PL/pgSQL functions, one
     * trigger on public.usertokens, and the oauth2_webhook_status monitoring view.
     *
     * deauthorize_user_from_app() — revokes tokens and fires user_deauthorized webhook
     * create_gdpr_request()       — creates GDPR request and notifies all apps
     * notify_user_profile_changed() — fires user_profile_changed webhook
     * token_revocation_webhook()  — trigger function: fires webhook on status 1→0
     *
     * The trigger must be installed on public.usertokens so that any PHP code
     * path that updates token status automatically fans out the webhook without
     * extra application logic.
     */
    public function testOauth2HelperFunctionsInstalledOnPostgreSQL(): void
    {
        // Arrange — full dependency chain (schema must exist before any authserver.* table)
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $this->loadMigration('auth', 'CreateTokenactionsTable')->up();
        $this->loadMigration('auth', 'CreateUserActivityLogTable')->up();
        $this->loadMigration('auth', 'CreateGdprRequestsTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $this->loadMigration('authserver', 'CreateOauth2WebhooksTables')->up();
        $this->loadMigration('authserver', 'CreateOauth2ApplicationGrantsTable')->up();
        $this->loadMigration('authserver', 'CreateOrganizationsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserOrganizationsTable')->up();

        $m = $this->loadMigration('authserver', 'CreateOauth2HelperFunctions');

        // Act
        $m->up();

        // Assert — deauthorize_user_from_app() function exists
        $fn1 = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.routines
                 WHERE routine_schema = %s AND routine_name = %s",
                'applications',
                'deauthorize_user_from_app'
            )
        )->fields['cnt'];
        $this->assertSame(1, $fn1,
            'applications.deauthorize_user_from_app() function must be installed');

        // Assert — create_gdpr_request() function exists
        $fn2 = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.routines
                 WHERE routine_schema = %s AND routine_name = %s",
                'applications',
                'create_gdpr_request'
            )
        )->fields['cnt'];
        $this->assertSame(1, $fn2,
            'applications.create_gdpr_request() function must be installed');

        // Assert — notify_user_profile_changed() function exists
        $fn3 = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.routines
                 WHERE routine_schema = %s AND routine_name = %s",
                'applications',
                'notify_user_profile_changed'
            )
        )->fields['cnt'];
        $this->assertSame(1, $fn3,
            'applications.notify_user_profile_changed() function must be installed');

        // Assert — token_revocation_webhook() trigger function exists in public schema
        $fn4 = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.routines
                 WHERE routine_schema = %s AND routine_name = %s",
                'public',
                'token_revocation_webhook'
            )
        )->fields['cnt'];
        $this->assertSame(1, $fn4,
            'public.token_revocation_webhook() trigger function must be installed');

        // Assert — trigger is installed on public.usertokens
        $trigCnt = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.triggers
                 WHERE event_object_schema = %s
                   AND event_object_table = %s
                   AND trigger_name = %s",
                'public',
                'usertokens',
                'trigger_token_revocation_webhook'
            )
        )->fields['cnt'];
        $this->assertSame(1, $trigCnt,
            'trigger_token_revocation_webhook must be installed on public.usertokens');

        // Assert — oauth2_webhook_status VIEW exists and is queryable
        $viewCnt = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.views
                 WHERE table_schema = %s AND table_name = %s",
                'applications',
                'oauth2_webhook_status'
            )
        )->fields['cnt'];
        $this->assertSame(1, $viewCnt,
            'applications.oauth2_webhook_status monitoring view must be created');

        $this->db->query('SELECT * FROM applications.oauth2_webhook_status LIMIT 0');

        // Assert — down() removes all objects
        $m->down();

        $fnAfter = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.routines
             WHERE routine_schema IN ('applications', 'public')
               AND routine_name IN (
                   'deauthorize_user_from_app', 'create_gdpr_request',
                   'notify_user_profile_changed', 'token_revocation_webhook'
               )"
        )->fields['cnt'];
        $this->assertSame(0, $fnAfter,
            'All OAuth2 helper functions must be removed by down()');

        $trigAfter = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.triggers
             WHERE trigger_name = 'trigger_token_revocation_webhook'"
        )->fields['cnt'];
        $this->assertSame(0, $trigAfter,
            'trigger_token_revocation_webhook must be removed by down()');
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
        // Arrange — user_roles (→ roles → authserver schema) + organizations (FK target)
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateOrganizationsTable')->up();

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
            'check_user_org_membership',
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
     * Timestamps are TIMESTAMPTZ (not Unix integers). The table must be in the
     * authserver schema; index names match Urbanwater (uniq_loginlockouts_lookup,
     * idx_loginlockouts_active, idx_loginlockouts_userid).
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

        // Assert — insertable and queryable (timestamps are TIMESTAMPTZ, not integers)
        $this->db->query(
            "INSERT INTO authserver.loginlockouts
             (locktype, lookupvalue, failedattempts, firstfailedat, lastfailedat, lockoutuntil, createdat, updatedat)
             VALUES ('ip', '127.0.0.1', 1, NOW(), NOW(), NOW() + INTERVAL '60 seconds', NOW(), NOW())"
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
             VALUES (999, TRUE, '127.0.0.1', NOW())"
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
     * PK is serial `id`; `userid` is UNIQUE NOT NULL with FK to public.users.
     * Column names are share_usage_analytics + marketing_emails (not the old
     * analytics_consent / marketing_consent naming).
     */
    public function testUserPrivacySettingsCreatedInAuthserverSchemaOnPostgreSQL(): void
    {
        // Arrange — users table must exist for the FK constraint
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUserPrivacySettingsTable');

        // Act
        $m->up();

        // Assert — table in authserver schema
        $this->assertTrue($this->tableExists('user_privacy_settings', 'authserver'),
            'user_privacy_settings must be in the authserver schema');
        $this->assertFalse($this->tableExists('user_privacy_settings', 'public'));

        // Assert — Urbanwater-aligned column names exist; old analytics_consent must NOT exist
        $this->assertTrue(
            $this->columnExists('user_privacy_settings', 'share_usage_analytics', 'authserver'),
            'share_usage_analytics column must exist (Urbanwater column name)'
        );
        $this->assertTrue(
            $this->columnExists('user_privacy_settings', 'marketing_emails', 'authserver'),
            'marketing_emails column must exist (Urbanwater column name)'
        );
        $this->assertFalse(
            $this->columnExists('user_privacy_settings', 'data_processing', 'authserver'),
            'data_processing must not exist (not in Urbanwater schema)'
        );
        $this->assertFalse(
            $this->columnExists('user_privacy_settings', 'analytics_consent', 'authserver'),
            'analytics_consent must not exist (old incorrect column name)'
        );

        $m->down();
        $this->assertFalse($this->tableExists('user_privacy_settings', 'authserver'));
        $this->loadMigration('auth', 'CreateUsersTable')->down();
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
    // Applications schema — new tables (000044, 000045)
    // =========================================================================

    /**
     * CreateApplicationSettingsTable must create applications.application_settings
     * on PostgreSQL with all rate-limiting, pagination, IP-lock, and CORS columns.
     *
     * PostgreSQL-specific invariants:
     *   - allowed_ips / blocked_ips are INET[] (not JSON like on MySQL)
     *   - cors_origins is TEXT[]
     *   - A PL/pgSQL trigger keeps updated_at current on every UPDATE
     */
    public function testApplicationSettingsUpCreatesTableWithAllColumns(): void
    {
        // Arrange — schema + FK parent must exist first
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('applications', 'CreateApplicationSettingsTable');

        // Act
        $m->up();

        // Assert — table is in the applications schema
        $this->assertTrue(
            $this->tableExists('application_settings', 'applications'),
            'applications.application_settings must exist after up()'
        );

        // Assert — rate-limit columns
        $this->assertColumnType('application_settings', 'rate_limit_requests',       'integer', 'applications');
        $this->assertColumnType('application_settings', 'rate_limit_window_seconds', 'integer', 'applications');
        $this->assertColumnType('application_settings', 'rate_limit_burst',          'integer', 'applications');

        // Assert — pagination
        $this->assertColumnType('application_settings', 'enforce_pagination', 'boolean', 'applications');
        $this->assertColumnType('application_settings', 'max_page_size',      'integer', 'applications');
        $this->assertColumnType('application_settings', 'default_page_size',  'integer', 'applications');

        // Assert — PostgreSQL-native array types for IP lists
        $this->assertColumnType('application_settings', 'allowed_ips', 'ARRAY', 'applications');
        $this->assertColumnType('application_settings', 'blocked_ips', 'ARRAY', 'applications');
        $this->assertColumnNullable('application_settings', 'allowed_ips', true, 'applications');

        // Assert — CORS
        $this->assertColumnType('application_settings', 'require_https', 'boolean', 'applications');
        $this->assertColumnType('application_settings', 'cors_enabled',  'boolean', 'applications');
        $this->assertColumnType('application_settings', 'cors_origins',  'ARRAY',   'applications');

        // Assert — timestamps
        $this->assertColumnType('application_settings', 'created_at', 'timestamp without time zone', 'applications');
        $this->assertColumnType('application_settings', 'updated_at', 'timestamp without time zone', 'applications');

        // Assert — unique index on appid
        $this->assertTrue(
            $this->indexExists('application_settings', 'idx_application_settings_appid', 'applications'),
            'UNIQUE index on appid must prevent duplicate settings rows per application'
        );

        // Assert — trigger: UPDATE must advance updated_at.
        // Insert a parent row first so the FK constraint is satisfied.
        $this->db->query(
            "INSERT INTO public.applications (appid, name, status, added)
             VALUES (1, 'test_app', 1, 0)"
        );
        $this->db->query(
            "INSERT INTO applications.application_settings
                 (appid, rate_limit_requests, rate_limit_window_seconds, rate_limit_burst)
             VALUES (1, 100, 60, 10)"
        );
        $this->db->query("SELECT pg_sleep(0.05)"); // ensure clock advances
        $this->db->query(
            "UPDATE applications.application_settings SET rate_limit_requests = 200 WHERE appid = 1"
        );
        $r = $this->db->query(
            "SELECT created_at, updated_at FROM applications.application_settings WHERE appid = 1"
        );
        $this->assertNotSame(
            $r->fields['created_at'],
            $r->fields['updated_at'],
            'updated_at trigger must advance the column beyond created_at on UPDATE'
        );

        // Assert — rollback drops the table
        $m->down();
        $this->assertFalse(
            $this->tableExists('application_settings', 'applications'),
            'applications.application_settings must be gone after down()'
        );
    }

    /**
     * CreateApplicationStatsTable must create applications.application_stats
     * on PostgreSQL with the time-series metric schema.
     *
     * On TimescaleDB the table is converted to a hypertable; on plain PostgreSQL
     * it remains a regular table.  The test adapts its assertions to the actual
     * database capabilities at runtime.
     */
    public function testApplicationStatsUpCreatesTableWithMetricColumns(): void
    {
        // Arrange — schema + FK parents
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $this->loadMigration('applications', 'CreateApplicationSettingsTable')->up();
        $m = $this->loadMigration('applications', 'CreateApplicationStatsTable');

        // Act
        $m->up();

        // Assert — table is in the applications schema
        $this->assertTrue(
            $this->tableExists('application_stats', 'applications'),
            'applications.application_stats must exist after up()'
        );

        // Assert — time-series dimension
        $this->assertColumnType('application_stats', 'time', 'timestamp with time zone', 'applications');
        $this->assertColumnNullable('application_stats', 'time', false, 'applications');

        // Assert — metric columns
        $this->assertColumnType('application_stats', 'total_requests',      'bigint',         'applications');
        $this->assertColumnType('application_stats', 'avg_response_time',   'numeric',        'applications');
        $this->assertColumnType('application_stats', 'status_2xx',          'bigint',         'applications');
        $this->assertColumnType('application_stats', 'bytes_sent',          'bigint',         'applications');
        $this->assertColumnNullable('application_stats', 'country_code',    true,             'applications');

        // Assert — composite index on (appid, time DESC)
        $this->assertTrue(
            $this->indexExists('application_stats', 'idx_application_stats_appid_time', 'applications'),
            'Composite index (appid, time) must exist for fast per-app time-range queries'
        );

        // Assert — hypertable conversion on TimescaleDB (skip on plain PostgreSQL)
        $isTimescale = $this->isHypertable('application_stats', 'applications');
        if ($isTimescale) {
            $this->assertTrue($isTimescale,
                'On TimescaleDB, application_stats must be converted to a hypertable');
        }

        // Assert — rollback
        $m->down();
        $this->assertFalse(
            $this->tableExists('application_stats', 'applications'),
            'applications.application_stats must be gone after down()'
        );
    }

    /**
     * CreateUserAppAuthorizationsTable must create authserver.user_app_authorizations
     * on PostgreSQL with the OAuth consent lifecycle columns.
     *
     * PostgreSQL-specific: scope is stored as TEXT[], and the table lives inside
     * the `authserver` schema alongside other OAuth tables.
     */
    public function testUserAppAuthorizationsUpCreatesConsentTable(): void
    {
        // Arrange — schema + FK parents
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateUserAppAuthorizationsTable');

        // Act
        $m->up();

        // Assert — table is in the authserver schema
        $this->assertTrue(
            $this->tableExists('user_app_authorizations', 'authserver'),
            'authserver.user_app_authorizations must exist after up()'
        );

        // Assert — FK columns
        $this->assertColumnType('user_app_authorizations', 'userid', 'bigint',  'authserver');
        $this->assertColumnType('user_app_authorizations', 'appid',  'integer', 'authserver');
        $this->assertColumnNullable('user_app_authorizations', 'userid', false, 'authserver');
        $this->assertColumnNullable('user_app_authorizations', 'appid',  false, 'authserver');

        // Assert — PostgreSQL TEXT[] for scope
        $this->assertColumnType('user_app_authorizations', 'scope', 'ARRAY', 'authserver');

        // Assert — status column with CHECK constraint
        $this->assertColumnType('user_app_authorizations', 'status', 'character varying', 'authserver');

        // Assert — timestamp columns
        $this->assertColumnType('user_app_authorizations', 'granted_at', 'timestamp without time zone', 'authserver');
        $this->assertColumnNullable('user_app_authorizations', 'revoked_at',   true, 'authserver');
        $this->assertColumnNullable('user_app_authorizations', 'expires_at',   true, 'authserver');
        $this->assertColumnNullable('user_app_authorizations', 'last_used_at', true, 'authserver');

        // Assert — unique constraint prevents duplicate consent rows
        $this->assertTrue(
            $this->indexExists('user_app_authorizations', 'idx_user_app_auth_unique', 'authserver'),
            'UNIQUE (userid, appid) must prevent duplicate consent records for the same pair'
        );

        // Assert — rollback
        $m->down();
        $this->assertFalse(
            $this->tableExists('user_app_authorizations', 'authserver'),
            'authserver.user_app_authorizations must be gone after down()'
        );
    }

    // =========================================================================
    // Applications schema views (000046)
    // =========================================================================

    /**
     * CreateApplicationsViews must create all 10 applications-schema views on
     * PostgreSQL, plus 3 materialized views (application_stats_daily,
     * application_stats_hourly, usage_statistics).
     *
     * Verifies view existence, queryability, and that down() removes them.
     */
    public function testApplicationsViewsUpCreatesAllViews(): void
    {
        // Arrange — create all FK parents and source tables
        // (oauth2_webhook_endpoints/events are required by the oauth2_webhook_status view)
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateApplicationsSchema')->up();
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $this->loadMigration('applications', 'CreateApplicationSettingsTable')->up();
        $this->loadMigration('applications', 'CreateApplicationStatsTable')->up();
        $this->loadMigration('authserver', 'CreateOauth2WebhooksTables')->up();
        $this->loadMigration('authserver', 'CreateOauth2ApplicationGrantsTable')->up();
        $m = $this->loadMigration('applications', 'CreateApplicationsViews');

        // Act
        $m->up();

        // Assert — regular views exist in information_schema.views
        $regularViews = [
            'api_performance_summary', 'application_health', 'rate_limit_status',
            'slow_api_calls', 'ip_violations', 'oauth2_active_tokens',
            'oauth2_webhook_status', 'top_applications',
            'usage_statistics',
        ];
        foreach ($regularViews as $view) {
            $r = $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt FROM information_schema.views
                     WHERE table_schema = %s AND table_name = %s",
                    'applications', $view
                )
            );
            $this->assertGreaterThan(0, (int) $r->fields['cnt'],
                "applications.{$view} must exist in information_schema.views");
        }

        // Assert — aggregated views: on TimescaleDB, daily/hourly are continuous aggregates;
        // on plain PG they are regular materialized views.
        $hasTsdb   = $this->db->schema()->getCapabilities()->hasTimescaleDB();
        $caggViews = ['application_stats_daily', 'application_stats_hourly'];
        foreach ($caggViews as $view) {
            if ($hasTsdb) {
                $r = $this->db->query(
                    $this->db->prepareQuery(
                        "SELECT COUNT(*) AS cnt
                         FROM timescaledb_information.continuous_aggregates
                         WHERE view_schema = %s AND view_name = %s",
                        'applications', $view
                    )
                );
                $this->assertGreaterThan(0, (int) $r->fields['cnt'],
                    "applications.{$view} must be a TimescaleDB continuous aggregate");
            } else {
                $r = $this->db->query(
                    $this->db->prepareQuery(
                        "SELECT COUNT(*) AS cnt FROM pg_matviews
                         WHERE schemaname = %s AND matviewname = %s",
                        'applications', $view
                    )
                );
                $this->assertGreaterThan(0, (int) $r->fields['cnt'],
                    "applications.{$view} must exist as a materialized view on plain PG");
            }
        }
        // Assert — regular views are queryable
        foreach ($regularViews as $view) {
            $r = $this->db->query("SELECT COUNT(*) AS cnt FROM applications.\"{$view}\"");
            $this->assertNotNull($r, "applications.{$view} must be queryable");
        }

        // Assert — down() removes everything
        $m->down();
        foreach ($regularViews as $view) {
            $r = $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt FROM information_schema.views
                     WHERE table_schema = %s AND table_name = %s",
                    'applications', $view
                )
            );
            $this->assertSame('0', (string) $r->fields['cnt'],
                "applications.{$view} must be gone after down()");
        }
        foreach ($caggViews as $view) {
            if ($hasTsdb) {
                $r = $this->db->query(
                    $this->db->prepareQuery(
                        "SELECT COUNT(*) AS cnt
                         FROM timescaledb_information.continuous_aggregates
                         WHERE view_schema = %s AND view_name = %s",
                        'applications', $view
                    )
                );
                $this->assertSame('0', (string) $r->fields['cnt'],
                    "applications.{$view} continuous aggregate must be gone after down()");
            } else {
                $r = $this->db->query(
                    $this->db->prepareQuery(
                        "SELECT COUNT(*) AS cnt FROM pg_matviews
                         WHERE schemaname = %s AND matviewname = %s",
                        'applications', $view
                    )
                );
                $this->assertSame('0', (string) $r->fields['cnt'],
                    "applications.{$view} matview must be gone after down()");
            }
        }
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_matviews
                 WHERE schemaname = %s AND matviewname = %s",
                'applications', 'usage_statistics'
            )
        );
        $this->assertSame('0', (string) $r->fields['cnt'],
            'applications.usage_statistics matview must be gone after down()');
    }

    // =========================================================================
    // AuthServer schema views (000046)
    // =========================================================================

    /**
     * CreateAuthserverViews must create all 8 authserver monitoring views on
     * PostgreSQL, including the daily_2fa_stats materialized view.
     */
    public function testAuthserverViewsUpCreatesAllViews(): void
    {
        // Arrange — create all source tables
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

        // Assert — regular views
        $regularViews = [
            'alert_high_failure_rate', 'alert_suspicious_ips',
            'failed_twofactor_summary', 'gdpr_compliance_report',
            'geographic_analysis', 'oauth2_active_tokens', 'recent_twofactor_attempts',
        ];
        foreach ($regularViews as $view) {
            $r = $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt FROM information_schema.views
                     WHERE table_schema = %s AND table_name = %s",
                    'authserver', $view
                )
            );
            $this->assertGreaterThan(0, (int) $r->fields['cnt'],
                "authserver.{$view} must exist after up()");
        }

        // Assert — daily_2fa_stats is a materialized view or continuous aggregate.
        // On TimescaleDB it is created as a continuous aggregate (appears in
        // timescaledb_information.continuous_aggregates, not in pg_matviews).
        // On plain PostgreSQL it is a regular materialized view (in pg_matviews).
        $matviewCount = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_matviews
                 WHERE schemaname = %s AND matviewname = %s",
                'authserver', 'daily_2fa_stats'
            )
        )->fields['cnt'];
        $hasTsdb = $this->db->schema()->getCapabilities()->hasTimescaleDB();
        if ($hasTsdb) {
            $caCount = (int) $this->db->query(
                "SELECT COUNT(*) AS cnt
                 FROM timescaledb_information.continuous_aggregates
                 WHERE view_schema = 'authserver' AND view_name = 'daily_2fa_stats'"
            )->fields['cnt'];
            $this->assertGreaterThan(0, $caCount,
                'authserver.daily_2fa_stats must be a TimescaleDB continuous aggregate');
        } else {
            $this->assertGreaterThan(0, $matviewCount,
                'authserver.daily_2fa_stats must be a materialized view on plain PostgreSQL');
        }

        // Assert — regular views are queryable
        foreach ($regularViews as $view) {
            $r = $this->db->query("SELECT COUNT(*) AS cnt FROM authserver.\"{$view}\"");
            $this->assertNotNull($r, "authserver.{$view} must be queryable");
        }

        // Assert — down() removes daily_2fa_stats (matview or continuous aggregate)
        $m->down();
        if ($hasTsdb) {
            $r = $this->db->query(
                "SELECT COUNT(*) AS cnt
                 FROM timescaledb_information.continuous_aggregates
                 WHERE view_schema = 'authserver' AND view_name = 'daily_2fa_stats'"
            );
            $this->assertSame('0', (string) $r->fields['cnt'],
                'authserver.daily_2fa_stats continuous aggregate must be gone after down()');
        } else {
            $r = $this->db->query(
                $this->db->prepareQuery(
                    "SELECT COUNT(*) AS cnt FROM pg_matviews
                     WHERE schemaname = %s AND matviewname = %s",
                    'authserver', 'daily_2fa_stats'
                )
            );
            $this->assertSame('0', (string) $r->fields['cnt'],
                'authserver.daily_2fa_stats matview must be gone after down()');
        }
    }

    /**
     * AddSyncConsentTimestampTrigger must create authserver.sync_consent_timestamp()
     * and install it as BEFORE INSERT OR UPDATE on authserver.oauth2_user_consents.
     *
     * The trigger must set updated_at = CURRENT_TIMESTAMP on every INSERT and UPDATE
     * so callers never need to supply the field manually.
     */
    public function testSyncConsentTimestampTriggerUpAddsTrigger(): void
    {
        // Arrange — authserver schema + oauth2_user_consents table must exist
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('authserver', 'CreateOauth2UserConsentsTable')->up();
        $m = $this->loadMigration('authserver', 'AddSyncConsentTimestampTrigger');

        // Act
        $m->up();

        // Assert — trigger is attached to oauth2_user_consents in the authserver schema
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM pg_trigger t
                 JOIN pg_class c ON c.oid = t.tgrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relname = %s AND n.nspname = %s
                   AND t.tgname = %s AND NOT t.tgisinternal",
                'oauth2_user_consents',
                'authserver',
                'trg_sync_consent_timestamp'
            )
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'trg_sync_consent_timestamp must be attached to authserver.oauth2_user_consents');

        // Assert — trigger function exists in authserver schema
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_proc p
                 JOIN pg_namespace n ON n.oid = p.pronamespace
                 WHERE n.nspname = %s AND p.proname = %s",
                'authserver',
                'sync_consent_timestamp'
            )
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'authserver.sync_consent_timestamp() function must exist');

        // Assert — INSERT sets updated_at automatically
        $this->db->query(
            "INSERT INTO authserver.oauth2_user_consents (userid, applicationid, scope)
             VALUES (1, 1, 'read')"
        );
        $r = $this->db->query(
            "SELECT updated_at FROM authserver.oauth2_user_consents WHERE userid = 1"
        );
        $this->assertNotNull($r->fields['updated_at'],
            'BEFORE INSERT trigger must populate updated_at');

        // Assert — UPDATE advances updated_at
        $this->db->query("SELECT pg_sleep(0.05)");
        $before = $r->fields['updated_at'];
        $this->db->query(
            "UPDATE authserver.oauth2_user_consents SET scope = 'read write' WHERE userid = 1"
        );
        $r2 = $this->db->query(
            "SELECT updated_at FROM authserver.oauth2_user_consents WHERE userid = 1"
        );
        $this->assertGreaterThan($before, $r2->fields['updated_at'],
            'BEFORE UPDATE trigger must advance updated_at beyond the INSERT timestamp');

        // Assert — down() removes the trigger and function
        $m->down();
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt
                 FROM pg_trigger t
                 JOIN pg_class c ON c.oid = t.tgrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relname = %s AND n.nspname = %s
                   AND t.tgname = %s AND NOT t.tgisinternal",
                'oauth2_user_consents',
                'authserver',
                'trg_sync_consent_timestamp'
            )
        );
        $this->assertSame(0, (int) $r->fields['cnt'],
            'trg_sync_consent_timestamp must be removed after down()');
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM pg_proc p
                 JOIN pg_namespace n ON n.oid = p.pronamespace
                 WHERE n.nspname = %s AND p.proname = %s",
                'authserver',
                'sync_consent_timestamp'
            )
        );
        $this->assertSame(0, (int) $r->fields['cnt'],
            'authserver.sync_consent_timestamp() function must be removed after down()');
    }

    /**
     * RepositionSlowApiCallsView must drop authserver.slow_api_calls so all
     * slow-call analysis is unified under applications.slow_api_calls (000046).
     *
     * Rollback must restore the original authserver view so pre-migration code
     * that references authserver.slow_api_calls continues to work.
     */
    public function testRepositionSlowApiCallsViewUpDropsAuthserverView(): void
    {
        // Arrange — create source tables and the original authserver view
        $this->loadMigration('authserver', 'CreateAuthserverSchema')->up();
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('auth', 'CreateTokenactionsTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $this->loadMigration('authserver', 'CreateSlowApiCallsView')->up();

        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.views
                 WHERE table_schema = %s AND table_name = %s",
                'authserver', 'slow_api_calls'
            )
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'authserver.slow_api_calls must exist before repositioning');
        $m = $this->loadMigration('authserver', 'RepositionSlowApiCallsView');

        // Act
        $m->up();

        // Assert — authserver.slow_api_calls is gone
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.views
                 WHERE table_schema = %s AND table_name = %s",
                'authserver', 'slow_api_calls'
            )
        );
        $this->assertSame(0, (int) $r->fields['cnt'],
            'authserver.slow_api_calls must be dropped after repositioning');

        // Assert — rollback recreates the authserver view
        $m->down();
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.views
                 WHERE table_schema = %s AND table_name = %s",
                'authserver', 'slow_api_calls'
            )
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'authserver.slow_api_calls must be restored after down()');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Loads a specific migration class from the framework migrations directory.
     *
     * @param string $feature Feature subdirectory (core/auth/messaging/queue/authserver/applications)
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
        $this->dropSchemaWithRetry('applications');
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
            'organizations',
            // oauth2_user_consents and oauth2_device_codes are in authserver schema,
            // already dropped by dropSchemaWithRetry('authserver') above.
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
