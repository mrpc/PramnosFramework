<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * Integration tests for all framework system migrations against MySQL 8.0.
 *
 * Each test:
 *   (1) Runs the migration's up() method against a live MySQL database.
 *   (2) Verifies the resulting schema via information_schema: table existence,
 *       column presence and data types, index presence, NOT NULL and DEFAULT.
 *   (3) Runs the migration's down() method and verifies the table is gone.
 *
 * Tests are deliberately independent — each test drops and re-creates all
 * tables in setUp/tearDown so order does not matter.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class FrameworkMigrationsMySQLTest extends TestCase
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
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

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
     * userid BIGINT nullable, sid VARCHAR(32), etc.
     */
    public function testCoreSessionsUpCreatesTableWithExpectedColumns(): void
    {
        // Arrange
        $m = $this->loadMigration('core', 'CreateSessionsTable');

        // Act
        $m->up();

        // Assert – table exists
        $this->assertTrue($this->tableExists('sessions'), 'sessions table must exist after up()');

        // Assert – critical columns present with expected types
        $this->assertColumnType('sessions', 'visitorid', 'varchar');
        $this->assertColumnType('sessions', 'time', 'int');
        $this->assertColumnNullable('sessions', 'userid', true);
        $this->assertColumnType('sessions', 'sid', 'varchar');
        $this->assertColumnType('sessions', 'agent', 'varchar');
        $this->assertColumnType('sessions', 'history', 'text');

        // Assert – rollback drops the table
        $m->down();
        $this->assertFalse($this->tableExists('sessions'), 'sessions table must be gone after down()');
    }

    // -------------------------------------------------------------------------
    // Core: settings
    // -------------------------------------------------------------------------

    /**
     * settings migration must create the settings key/value table.
     * delete column must default to 1.
     */
    public function testCoreSettingsUpCreatesTableWithDeleteDefaultOne(): void
    {
        // Arrange
        $m = $this->loadMigration('core', 'CreateSettingsTable');

        // Act
        $m->up();

        // Assert – table and columns
        $this->assertTrue($this->tableExists('settings'));
        $this->assertColumnType('settings', 'setting_id', 'int');
        $this->assertColumnType('settings', 'setting', 'varchar');
        $this->assertColumnType('settings', 'value', 'text');
        $this->assertColumnType('settings', 'delete', 'tinyint');

        // Assert – delete column has default value 1
        $info = $this->getColumnInfo('settings', 'delete');
        $this->assertSame('1', (string) $info['COLUMN_DEFAULT'], 'delete column must default to 1');

        // Assert – rollback
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

        // Assert – columns
        $this->assertTrue($this->tableExists('framework_policies'));
        $this->assertColumnType('framework_policies', 'policyid', 'int');
        $this->assertColumnType('framework_policies', 'policy_type', 'varchar');
        $this->assertColumnType('framework_policies', 'target', 'varchar');
        $this->assertColumnType('framework_policies', 'config', 'json');
        $this->assertColumnType('framework_policies', 'enabled', 'tinyint');
        $this->assertColumnNullable('framework_policies', 'last_run', true);
        $this->assertColumnNullable('framework_policies', 'next_run', true);
        $this->assertColumnNullable('framework_policies', 'last_result', true);
        $this->assertColumnNullable('framework_policies', 'last_error', true);

        // Assert – indexes
        $this->assertTrue($this->indexExists('framework_policies', 'idx_framework_policies_type_enabled'));
        $this->assertTrue($this->indexExists('framework_policies', 'idx_framework_policies_next_run'));

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('framework_policies'));
    }

    // -------------------------------------------------------------------------
    // Auth: users
    // -------------------------------------------------------------------------

    /**
     * users table must have the full UrbanWater schema: BIGINT userid PK,
     * username/email/password strings, all profile fields, and key indexes.
     */
    public function testAuthUsersUpCreatesFullUwSchema(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateUsersTable');

        // Act
        $m->up();

        // Assert – core identity fields
        $this->assertTrue($this->tableExists('users'));
        $this->assertColumnType('users', 'userid', 'bigint');
        $this->assertColumnType('users', 'username', 'varchar');
        $this->assertColumnType('users', 'email', 'varchar');
        $this->assertColumnType('users', 'password', 'varchar');

        // Assert – profile fields unique to UW schema (NOT in the old fake schema)
        $this->assertColumnType('users', 'lastname', 'varchar');
        $this->assertColumnType('users', 'firstname', 'varchar');
        $this->assertColumnType('users', 'regdate', 'int');
        $this->assertColumnType('users', 'lastlogin', 'int');
        $this->assertColumnType('users', 'active', 'tinyint');
        $this->assertColumnType('users', 'validated', 'tinyint');
        $this->assertColumnType('users', 'usertype', 'tinyint');
        $this->assertColumnType('users', 'modified', 'int');
        $this->assertColumnNullable('users', 'locationid', true);
        $this->assertColumnNullable('users', 'fbauth', true);

        // Assert – indexes
        $this->assertTrue($this->indexExists('users', 'idx_users_username'));
        $this->assertTrue($this->indexExists('users', 'idx_users_email'));

        // Assert – rollback
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

        // Assert – columns
        $this->assertTrue($this->tableExists('userdetails'));
        $this->assertColumnType('userdetails', 'userid', 'bigint');
        $this->assertColumnType('userdetails', 'fieldname', 'varchar');
        $this->assertColumnType('userdetails', 'value', 'text');

        // Assert – both PK columns are NOT NULL
        $this->assertColumnNullable('userdetails', 'userid', false);
        $this->assertColumnNullable('userdetails', 'fieldname', false);

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('userdetails'));
    }

    // -------------------------------------------------------------------------
    // Auth: userlog
    // -------------------------------------------------------------------------

    /**
     * userlog must have logid auto-increment PK, userid, date (int), log (nullable),
     * logtype (tinyint), and details (text).
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
        $this->assertColumnType('userlog', 'logid', 'int');
        $this->assertColumnType('userlog', 'userid', 'bigint');
        $this->assertColumnType('userlog', 'date', 'int');
        $this->assertColumnNullable('userlog', 'log', true);
        $this->assertColumnType('userlog', 'logtype', 'tinyint');
        $this->assertColumnType('userlog', 'details', 'text');

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('userlog'));
    }

    // -------------------------------------------------------------------------
    // Auth: usernotes
    // -------------------------------------------------------------------------

    /**
     * usernotes must have userid (bigint), admin (bigint nullable), note (text),
     * date (int). No PK — notes are identified by userid+date.
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
        $this->assertColumnType('usernotes', 'date', 'int');

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('usernotes'));
    }

    // -------------------------------------------------------------------------
    // Auth: usertokens
    // -------------------------------------------------------------------------

    /**
     * usertokens must have TEXT token (not VARCHAR — critical for JWT support),
     * PKCE columns (code_challenge, code_challenge_method), and all legacy fields.
     */
    public function testAuthUsertokensHasTextTokenAndPkceColumns(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUsertokensTable');

        // Act
        $m->up();

        // Assert – token must be TEXT, not VARCHAR (JWT support requirement)
        $this->assertTrue($this->tableExists('usertokens'));
        $tokenInfo = $this->getColumnInfo('usertokens', 'token');
        $this->assertStringStartsWith(
            'text', strtolower($tokenInfo['DATA_TYPE']),
            'token column must be TEXT (not VARCHAR) to accommodate JWTs of any length'
        );

        // Assert – PKCE columns
        $this->assertTrue($this->columnExists('usertokens', 'code_challenge'),
            'code_challenge column must exist (PKCE RFC 7636)');
        $this->assertTrue($this->columnExists('usertokens', 'code_challenge_method'),
            'code_challenge_method column must exist (PKCE RFC 7636)');
        $this->assertColumnNullable('usertokens', 'code_challenge', true);
        $this->assertColumnNullable('usertokens', 'code_challenge_method', true);

        // Assert – legacy fields present
        $this->assertColumnType('usertokens', 'tokentype', 'varchar');
        $this->assertColumnType('usertokens', 'status', 'tinyint');
        $this->assertColumnNullable('usertokens', 'expires', true);
        $this->assertColumnNullable('usertokens', 'parentToken', true);
        $this->assertColumnNullable('usertokens', 'applicationid', true);

        // Assert – indexes (note: no idx_usertokens_token — MySQL cannot index TEXT without a prefix)
        $this->assertTrue($this->indexExists('usertokens', 'idx_usertokens_userid_status'));

        // Assert – rollback
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
        $this->assertColumnType('urls', 'urlid', 'int');
        $this->assertColumnNullable('urls', 'url', true);
        $this->assertColumnType('urls', 'hash', 'bigint');
        $this->assertTrue($this->indexExists('urls', 'idx_urls_hash'));

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('urls'));
    }

    // -------------------------------------------------------------------------
    // Auth: tokenactions
    // -------------------------------------------------------------------------

    /**
     * tokenactions must have the full schema including decimal execution_time_ms
     * and the composite PK (actionid, action_time). On MySQL it is a regular table
     * (no hypertable). JSON column used for return_data.
     */
    public function testAuthTokenactionsUpCreatesRegularTableOnMysql(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $m = $this->loadMigration('auth', 'CreateTokenactionsTable');

        // Act
        $m->up();

        // Assert – table and columns
        $this->assertTrue($this->tableExists('tokenactions'));
        $this->assertColumnType('tokenactions', 'actionid', 'int');
        $this->assertColumnType('tokenactions', 'tokenid', 'int');
        $this->assertColumnType('tokenactions', 'urlid', 'int');
        $this->assertColumnType('tokenactions', 'method', 'varchar');
        $this->assertColumnType('tokenactions', 'params', 'text');
        $this->assertColumnType('tokenactions', 'servertime', 'int');
        $this->assertColumnNullable('tokenactions', 'return_status', true);
        $this->assertColumnNullable('tokenactions', 'execution_time_ms', true);

        // Assert – action_time is the time-dimension column (DATETIME/TIMESTAMP on MySQL)
        $timeInfo = $this->getColumnInfo('tokenactions', 'action_time');
        $this->assertContains(
            strtolower($timeInfo['DATA_TYPE']),
            ['timestamp', 'datetime'],
            'action_time must be a timestamp type'
        );

        // Assert – indexes exist
        $this->assertTrue($this->indexExists('tokenactions', 'idx_tokenactions_time_tokenid'));

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('tokenactions'));
    }

    // -------------------------------------------------------------------------
    // Messaging: mails
    // -------------------------------------------------------------------------

    /**
     * mails table must have status (tinyint), frommail/tomail/subject, content (text),
     * date (int unix timestamp), and hash (char 32 for MD5).
     */
    public function testMessagingMailsUpCreatesEmailHistoryTable(): void
    {
        // Arrange
        $m = $this->loadMigration('messaging', 'CreateMailsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('mails'));
        $this->assertColumnType('mails', 'id', 'int');
        $this->assertColumnType('mails', 'status', 'tinyint');
        $this->assertColumnType('mails', 'frommail', 'varchar');
        $this->assertColumnType('mails', 'tomail', 'varchar');
        $this->assertColumnType('mails', 'subject', 'varchar');
        $this->assertColumnType('mails', 'content', 'text');
        $this->assertColumnType('mails', 'date', 'int');
        $this->assertColumnType('mails', 'hash', 'char');

        // Assert – indexes
        $this->assertTrue($this->indexExists('mails', 'idx_mails_status'));
        $this->assertTrue($this->indexExists('mails', 'idx_mails_hash'));

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('mails'));
    }

    // -------------------------------------------------------------------------
    // Messaging: mailtemplates
    // -------------------------------------------------------------------------

    /**
     * mailtemplates must have the category+language+type lookup index
     * and the correct channel type column.
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
        $this->assertColumnType('mailtemplates', 'title', 'varchar');
        $this->assertColumnType('mailtemplates', 'defaulttext', 'text');
        $this->assertColumnType('mailtemplates', 'category', 'varchar');
        $this->assertColumnType('mailtemplates', 'language', 'varchar');
        $this->assertColumnType('mailtemplates', 'type', 'tinyint');
        $this->assertColumnType('mailtemplates', 'sendmethod', 'tinyint');

        // Assert – lookup index
        $this->assertTrue($this->indexExists('mailtemplates', 'idx_mailtemplates_lookup'));

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('mailtemplates'));
    }

    // -------------------------------------------------------------------------
    // Messaging: messages
    // -------------------------------------------------------------------------

    /**
     * messages table must have the UW type-based state machine columns (type tinyint,
     * fromuserid/touserid bigint nullable, massid nullable, bbcode/html/smilies flags).
     * NOT the old thread-based schema.
     */
    public function testMessagingMessagesUpCreatesUwMessageTable(): void
    {
        // Arrange
        $m = $this->loadMigration('messaging', 'CreateMessagesTable');

        // Act
        $m->up();

        // Assert – UW-specific columns (would not exist in old thread-based schema)
        $this->assertTrue($this->tableExists('messages'));
        $this->assertColumnType('messages', 'messageid', 'int');
        $this->assertColumnType('messages', 'type', 'tinyint');
        $this->assertColumnNullable('messages', 'massid', true);
        $this->assertColumnNullable('messages', 'fromuserid', true);
        $this->assertColumnNullable('messages', 'touserid', true);
        $this->assertColumnType('messages', 'text', 'text');
        $this->assertColumnType('messages', 'bbcode', 'tinyint');
        $this->assertColumnType('messages', 'html', 'tinyint');
        $this->assertColumnType('messages', 'smilies', 'tinyint');
        $this->assertColumnType('messages', 'attachment', 'tinyint');

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('messages'));
    }

    // -------------------------------------------------------------------------
    // Messaging: massmessages + massmessagerecipients
    // -------------------------------------------------------------------------

    /**
     * massmessages must have the broadcast-specific fields: locationid (text for
     * JSON array), totalrecipients (int), request (json nullable).
     */
    public function testMessagingMassmessagesUpCreatesBroadcastTable(): void
    {
        // Arrange
        $m = $this->loadMigration('messaging', 'CreateMassmessagesTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('massmessages'));
        $this->assertColumnType('massmessages', 'messageid', 'int');
        $this->assertColumnType('massmessages', 'message', 'text');
        $this->assertColumnType('massmessages', 'type', 'int');
        $this->assertColumnType('massmessages', 'locationid', 'text');
        $this->assertColumnType('massmessages', 'totalrecipients', 'int');

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('massmessages'));
    }

    /**
     * massmessagerecipients must FK to massmessages and have status column.
     * Cascade delete must be defined so recipient rows disappear with the broadcast.
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
        $this->assertColumnType('massmessagerecipients', 'recipientid', 'int');
        $this->assertColumnType('massmessagerecipients', 'messageid', 'int');
        $this->assertColumnType('massmessagerecipients', 'userid', 'bigint');
        $this->assertColumnType('massmessagerecipients', 'status', 'int');

        // Assert – FK constraint exists (MySQL: information_schema.KEY_COLUMN_USAGE)
        $fkResult = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = %s
                   AND TABLE_NAME = %s
                   AND REFERENCED_TABLE_NAME = %s
                   AND REFERENCED_COLUMN_NAME = %s",
                'pramnos_test',
                'massmessagerecipients',
                'massmessages',
                'messageid'
            )
        );
        $this->assertGreaterThan(0, (int) $fkResult->fields['cnt'],
            'FK to massmessages.messageid must be defined');

        // Assert – rollback (drop recipients first, then massmessages)
        $m->down();
        $this->assertFalse($this->tableExists('massmessagerecipients'));
    }

    // -------------------------------------------------------------------------
    // Queue: queueitems
    // -------------------------------------------------------------------------

    /**
     * queueitems on MySQL must use VARCHAR(20) for status so that QueueManager's
     * string-based queries ('pending', 'processing', etc.) work correctly.
     *
     * A TINYINT column would silently mis-coerce string comparisons on MySQL
     * (e.g. WHERE status = 'pending' would evaluate as WHERE status = 0,
     * matching ALL non-numeric strings — including 'processing' and 'completed').
     * VARCHAR is the safe cross-DB choice that keeps QueueManager simple.
     */
    public function testQueueQueueitemsUpCreatesMysqlQueueTable(): void
    {
        // Arrange
        $m = $this->loadMigration('queue', 'CreateQueueitemsTable');

        // Act
        $m->up();

        // Assert – table and key columns
        $this->assertTrue($this->tableExists('queueitems'));
        $this->assertColumnType('queueitems', 'taskid', 'bigint');
        $this->assertColumnType('queueitems', 'type', 'varchar');
        $this->assertColumnType('queueitems', 'payload', 'json');

        // Assert – status is VARCHAR(20) with string default 'pending'
        // QueueManager uses string comparisons everywhere; a numeric type would
        // silently break all WHERE status = 'xxx' queries on MySQL.
        $statusInfo = $this->getColumnInfo('queueitems', 'status');
        $this->assertSame('varchar', strtolower($statusInfo['DATA_TYPE']),
            'queueitems.status must be VARCHAR so QueueManager string queries work on MySQL');
        $this->assertSame('pending', (string) $statusInfo['COLUMN_DEFAULT'],
            "status must default to 'pending'");

        // Assert – lock columns for atomic worker claims
        $this->assertColumnNullable('queueitems', 'lockedby', true);
        $this->assertColumnNullable('queueitems', 'lockexpires', true);

        // Assert – deduplication column
        $this->assertColumnNullable('queueitems', 'task_hash', true);
        $this->assertColumnType('queueitems', 'task_hash', 'varchar');

        // Assert – execution metadata
        $this->assertColumnNullable('queueitems', 'execution_time', true);
        $this->assertColumnNullable('queueitems', 'success_message', true);

        // Assert – indexes
        $this->assertTrue($this->indexExists('queueitems', 'idx_queueitems_status_priority_created'));
        $this->assertTrue($this->indexExists('queueitems', 'idx_queueitems_task_hash'));
        $this->assertTrue($this->indexExists('queueitems', 'idx_queueitems_locked'));

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('queueitems'));
    }

    /**
     * Running up() twice must be idempotent — the hasTable() guard prevents
     * duplicate-table errors on all framework migrations.
     */
    public function testAllCoreMigrationsAreIdempotent(): void
    {
        // Arrange – sessions as representative migration
        $m = $this->loadMigration('core', 'CreateSessionsTable');

        // Act – run twice
        $m->up();
        $m->up(); // Must not throw

        // Assert – table still exists and no exception was thrown
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
        $dir         = $this->migrationsBase . '/' . $feature;
        $migrations  = MigrationLoader::loadFromDirectory($dir, $this->app);

        foreach ($migrations as $m) {
            if ((new \ReflectionClass($m))->getShortName() === $class) {
                return $m;
            }
        }

        $this->fail("Migration class '{$class}' not found in feature '{$feature}'");
    }

    protected function tableExists(string $name): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                $name
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    protected function columnExists(string $table, string $column): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                'pramnos_test',
                $table,
                $column
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    protected function getColumnInfo(string $table, string $column): array
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                'pramnos_test',
                $table,
                $column
            )
        );
        $this->assertNotNull($result->fields,
            "Column '{$column}' must exist in '{$table}'");
        return $result->fields;
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) as cnt FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                'pramnos_test',
                $table,
                $indexName
            )
        );
        return (int) $result->fields['cnt'] > 0;
    }

    protected function assertColumnType(string $table, string $column, string $expectedType): void
    {
        $info = $this->getColumnInfo($table, $column);
        $this->assertSame(
            strtolower($expectedType),
            strtolower($info['DATA_TYPE']),
            "Column '{$table}.{$column}' must have type '{$expectedType}'"
        );
    }

    protected function assertColumnNullable(string $table, string $column, bool $nullable): void
    {
        $info = $this->getColumnInfo($table, $column);
        $expected = $nullable ? 'YES' : 'NO';
        $this->assertSame(
            $expected,
            $info['IS_NULLABLE'],
            "Column '{$table}.{$column}' nullable must be " . ($nullable ? 'YES' : 'NO')
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
        // Drop in dependency order (children first)
        $tables = [
            'massmessagerecipients', 'massmessages',
            'mailtemplates', 'mails', 'messages',
            'tokenactions', 'urls',
            'usertokens', 'usernotes', 'userlog', 'userdetails',
            'users',
            'queueitems',
            'framework_policies', 'settings', 'sessions',
            'authserver_user_roles', 'authserver_audit_log',
            'authserver_permissions', 'authserver_roles',
        ];

        foreach ($tables as $table) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }
}
