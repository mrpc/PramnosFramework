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
        $this->assertTrue($this->tableExists('pramnos_framework_policies'));
        $this->assertColumnType('pramnos_framework_policies', 'policyid', 'int');
        $this->assertColumnType('pramnos_framework_policies', 'policy_type', 'varchar');
        $this->assertColumnType('pramnos_framework_policies', 'target', 'varchar');
        $this->assertColumnType('pramnos_framework_policies', 'config', 'json');
        $this->assertColumnType('pramnos_framework_policies', 'enabled', 'tinyint');
        $this->assertColumnNullable('pramnos_framework_policies', 'last_run', true);
        $this->assertColumnNullable('pramnos_framework_policies', 'next_run', true);
        $this->assertColumnNullable('pramnos_framework_policies', 'last_result', true);
        $this->assertColumnNullable('pramnos_framework_policies', 'last_error', true);

        // Assert – indexes
        $this->assertTrue($this->indexExists('pramnos_framework_policies', 'idx_framework_policies_type_enabled'));
        $this->assertTrue($this->indexExists('pramnos_framework_policies', 'idx_framework_policies_next_run'));

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('pramnos_framework_policies'));
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

    /**
     * CreateUsertokensTable must add a plain index on code_challenge and a
     * CHECK constraint on code_challenge_method on MySQL.
     *
     * MySQL does not support partial (WHERE clause) indexes, so the implementation
     * falls back to a full index on the code_challenge(128) prefix.
     * The CHECK constraint on method values ('plain' | 'S256') is supported
     * by MySQL 8.0.16+ and must reject invalid values.
     */
    public function testUsertokensPkceIndexAndConstraintOnMySQL(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUsertokensTable');

        // Act
        $m->up();

        // Assert — code_challenge index exists (MySQL STATISTICS catalog)
        $idxCnt = (int) $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = %s",
                'usertokens',
                'idx_usertokens_code_challenge'
            )
        )->fields['cnt'];
        $this->assertSame(1, $idxCnt,
            'idx_usertokens_code_challenge index must exist on MySQL');

        // Assert — CHECK constraint on method values is enforced
        $rejected = false;
        try {
            $this->db->query(
                "INSERT INTO `usertokens`
                 (userid, tokentype, token, created, status, deviceinfo, scope,
                  code_challenge, code_challenge_method)
                 VALUES (1, 'auth_code', 'tok', 0, 1, '{}', '',
                  'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'MD5')"
            );
        } catch (\Exception $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected,
            'chk_code_challenge_method must reject method values other than plain/S256 on MySQL 8.0+');
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
        $this->assertColumnType('massmessages', 'messageid', 'int');
        $this->assertColumnType('massmessages', 'message', 'text');
        $this->assertColumnType('massmessages', 'type', 'int');
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

    // =========================================================================
    // AuthServer migrations (MySQL — applications, device_authorizations, etc.)
    // =========================================================================

    /**
     * CreateApplicationsTable must create the `applications` table on MySQL with
     * the columns needed by the OAuth2 server: apikey (unique), apisecret, callback,
     * owner (FK-like), public_key (for JWT client auth), and jwks_uri.
     *
     * This table is the prerequisite for all OAuth2 grant flows because every
     * usertokens.applicationid column references it.
     */
    public function testAuthserverApplicationsUpCreatesOauth2ClientTable(): void
    {
        // Arrange
        $m = $this->loadMigration('authserver', 'CreateApplicationsTable');

        // Act
        $m->up();

        // Assert – table exists
        $this->assertTrue($this->tableExists('applications'),
            'applications table must be created by the authserver migration');

        // Assert – key OAuth2 columns
        $this->assertColumnType('applications', 'appid', 'int');
        $this->assertColumnType('applications', 'name', 'varchar');
        $this->assertColumnType('applications', 'apikey', 'varchar');
        $this->assertColumnType('applications', 'apisecret', 'varchar');
        $this->assertColumnType('applications', 'callback', 'text');
        $this->assertColumnType('applications', 'public_key', 'text');
        $this->assertColumnType('applications', 'scope', 'text');

        // Assert – nullable columns for optional OAuth2 metadata
        $this->assertColumnNullable('applications', 'apikey', true);
        $this->assertColumnNullable('applications', 'callback', true);
        $this->assertColumnNullable('applications', 'owner', true);
        $this->assertColumnNullable('applications', 'public_key', true);
        $this->assertColumnNullable('applications', 'jwks_uri', true);

        // Assert – unique constraint on apikey (prevents duplicate client_id)
        $this->assertTrue($this->indexExists('applications', 'uq_applications_apikey'),
            'apikey must have a unique index so two clients cannot share the same client_id');

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('applications'),
            'applications table must be dropped by down()');
    }

    /**
     * CreateDeviceAuthorizationsTable must create the `device_authorizations` table
     * on MySQL with the RFC 8628 columns: device_code (unique), user_code (unique),
     * verification_uri, expires_at, and status ENUM.
     *
     * The device authorization flow requires storing the polling state (status) and
     * expiry so the resource server can reject expired or already-consumed codes.
     */
    public function testAuthserverDeviceAuthorizationsUpCreatesRfc8628Table(): void
    {
        // Arrange
        $m = $this->loadMigration('authserver', 'CreateDeviceAuthorizationsTable');

        // Act
        $m->up();

        // Assert – table exists
        $this->assertTrue($this->tableExists('authserver_device_authorizations'),
            'device_authorizations table must be created by the authserver migration');

        // Assert – key columns
        $this->assertColumnType('authserver_device_authorizations', 'device_code', 'varchar');
        $this->assertColumnType('authserver_device_authorizations', 'user_code', 'varchar');
        $this->assertColumnType('authserver_device_authorizations', 'verification_uri', 'varchar');
        $this->assertColumnType('authserver_device_authorizations', 'expires_at', 'datetime');

        // Assert – status is ENUM (MySQL-specific type) to constrain valid states
        $statusInfo = $this->getColumnInfo('authserver_device_authorizations', 'status');
        $this->assertSame('enum', strtolower($statusInfo['DATA_TYPE']),
            'device_authorizations.status must be ENUM on MySQL to enforce valid states');
        $this->assertSame('pending', (string)$statusInfo['COLUMN_DEFAULT'],
            "status must default to 'pending' (device waiting for user approval)");

        // Assert – uniqueness on both code columns
        $this->assertTrue($this->indexExists('authserver_device_authorizations', 'uq_devauth_device_code'),
            'device_code must be unique to prevent collisions across clients');
        $this->assertTrue($this->indexExists('authserver_device_authorizations', 'uq_devauth_user_code'),
            'user_code must be unique so users can identify the correct device');

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('authserver_device_authorizations'),
            'device_authorizations table must be dropped by down()');
    }

    /**
     * CreateJwtReplayPreventionTable must create the `jwt_replay_prevention` table
     * on MySQL with a jti PRIMARY KEY and an expires_at index.
     *
     * JWT replay prevention works by recording every jti (JWT ID) on first use
     * and rejecting subsequent requests with the same jti before its expiry.
     * The expires_at index enables efficient cleanup of expired entries.
     */
    public function testAuthserverJwtReplayPreventionUpCreatesLookupTable(): void
    {
        // Arrange
        $m = $this->loadMigration('authserver', 'CreateJwtReplayPreventionTable');

        // Act
        $m->up();

        // Assert – table exists
        $this->assertTrue($this->tableExists('authserver_jwt_replay_prevention'),
            'jwt_replay_prevention table must be created');

        // Assert – jti is the primary key (fast lookup for token validation)
        $this->assertColumnType('authserver_jwt_replay_prevention', 'jti', 'varchar');
        $this->assertColumnType('authserver_jwt_replay_prevention', 'expires_at', 'datetime');

        // Assert – expires_at index for efficient cleanup queries
        $this->assertTrue($this->indexExists('authserver_jwt_replay_prevention', 'idx_jrp_expires'),
            'expires_at index must exist to allow efficient cleanup of expired jti records');

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('authserver_jwt_replay_prevention'),
            'jwt_replay_prevention table must be dropped by down()');
    }

    /**
     * CreateOauth2ClientAuthMethodsTable must create the table in the applications_
     * prefix on MySQL and enforce a valid auth_method ENUM.
     *
     * Per RFC 7591, clients may authenticate using different methods
     * (client_secret_basic, client_secret_post, private_key_jwt, none).
     * This table records which methods each application supports.
     */
    public function testAuthserverOauth2ClientAuthMethodsUpCreatesTable(): void
    {
        // Arrange — applications table must exist (FK dependency in appid column)
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateOauth2ClientAuthMethodsTable');

        // Act
        $m->up();

        // Assert – table exists with applications_ prefix
        $this->assertTrue($this->tableExists('applications_oauth2_client_auth_methods'),
            'oauth2_client_auth_methods must be created with applications_ prefix on MySQL');

        // Assert – auth_method is ENUM (MySQL-specific type)
        $methodInfo = $this->getColumnInfo('applications_oauth2_client_auth_methods', 'auth_method');
        $this->assertSame('enum', strtolower($methodInfo['DATA_TYPE']),
            'auth_method must be ENUM on MySQL to prevent invalid values');

        // Assert – unique constraint prevents duplicate method registrations per app
        $this->assertTrue($this->indexExists('applications_oauth2_client_auth_methods', 'uq_ocam_appid_method'),
            'unique(appid, auth_method) must prevent duplicate method entries per application');

        // Assert – rollback
        $m->down();
        $this->assertFalse($this->tableExists('applications_oauth2_client_auth_methods'));
        $this->loadMigration('authserver', 'CreateApplicationsTable')->down();
    }

    /**
     * CreateOauth2WebhooksTables must create both the endpoints and events tables
     * with the applications_ prefix on MySQL, using the correct column schema
     * (webhook_id, endpoint_url, webhook_type, secret_key) from the Auth Server.
     */
    public function testAuthserverOauth2WebhooksUpCreatesBothWebhookTables(): void
    {
        // Arrange — applications must exist because endpoints.appid references it
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();
        $m = $this->loadMigration('authserver', 'CreateOauth2WebhooksTables');

        // Act
        $m->up();

        // Assert – both tables exist with applications_ prefix
        $this->assertTrue($this->tableExists('applications_oauth2_webhook_endpoints'),
            'oauth2_webhook_endpoints must be created with applications_ prefix on MySQL');
        $this->assertTrue($this->tableExists('applications_oauth2_webhook_events'),
            'oauth2_webhook_events must be created with applications_ prefix on MySQL');

        // Assert – endpoints has the correct Auth Server column schema
        $this->assertColumnType('applications_oauth2_webhook_endpoints', 'endpoint_url', 'varchar');
        $this->assertColumnType('applications_oauth2_webhook_endpoints', 'webhook_type', 'varchar');
        $this->assertColumnType('applications_oauth2_webhook_endpoints', 'secret_key', 'varchar');

        // Assert – events has delivery tracking columns
        $this->assertColumnType('applications_oauth2_webhook_events', 'event_type', 'varchar');
        $this->assertColumnType('applications_oauth2_webhook_events', 'payload', 'json');
        $this->assertColumnType('applications_oauth2_webhook_events', 'status', 'varchar');
        $this->assertColumnType('applications_oauth2_webhook_events', 'attempts', 'int');

        // Assert – rollback (events before endpoints due to FK)
        $m->down();
        $this->assertFalse($this->tableExists('applications_oauth2_webhook_events'));
        $this->assertFalse($this->tableExists('applications_oauth2_webhook_endpoints'));
        $this->loadMigration('authserver', 'CreateApplicationsTable')->down();
    }

    /**
     * CreateOrganizationsTable must create the organizations table in the default
     * MySQL database with an auto-increment organization_id PK, name, and is_active.
     *
     * The organizations table is the FK target for authserver_user_organizations.organization_id.
     */
    public function testOrganizationsTableCreatedOnMySQL(): void
    {
        // Arrange
        $m = $this->loadMigration('authserver', 'CreateOrganizationsTable');

        // Act
        $m->up();

        // Assert — table exists
        $this->assertTrue($this->tableExists('organizations'),
            'organizations table must be created on MySQL');

        // Assert — essential columns
        $this->assertColumnType('organizations', 'organization_id', 'int');
        $this->assertColumnType('organizations', 'name', 'varchar');
        $this->assertColumnType('organizations', 'is_active', 'tinyint');
        $this->assertColumnNullable('organizations', 'description', true);
        $this->assertColumnNullable('organizations', 'org_type', true);

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('organizations'));
    }

    // -------------------------------------------------------------------------
    // AuthServer: oauth2_application_grants + views
    // -------------------------------------------------------------------------

    /**
     * CreateOauth2ApplicationGrantsTable must create on MySQL:
     *   - applications_oauth2_application_grants (table)
     *   - applications_oauth2_application_permissions (VIEW with GROUP_CONCAT)
     *   - applications_oauth2_active_tokens (VIEW)
     *
     * MySQL names are schema-as-prefix (applications_*) because MySQL does not
     * support schemas as namespaces. GROUP_CONCAT replaces array_agg in the
     * permissions view. The cleanup function is PostgreSQL-only and is skipped.
     */
    public function testOauth2ApplicationGrantsTableAndViewsOnMySQL(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();

        $m = $this->loadMigration('authserver', 'CreateOauth2ApplicationGrantsTable');

        // Act
        $m->up();

        // Assert — grants table exists with MySQL prefix convention
        $this->assertTrue(
            $this->tableExists('applications_oauth2_application_grants'),
            'applications_oauth2_application_grants table must exist on MySQL'
        );

        // Assert — essential columns on the grants table
        $this->assertTrue(
            $this->columnExists('applications_oauth2_application_grants', 'grant_id'),
            'grant_id (serial PK) must exist'
        );
        $this->assertTrue(
            $this->columnExists('applications_oauth2_application_grants', 'grant_type'),
            'grant_type must exist'
        );
        $this->assertTrue(
            $this->columnExists('applications_oauth2_application_grants', 'is_enabled'),
            'is_enabled flag must exist'
        );

        // Assert — grant_type CHECK constraint rejects invalid values
        $rejected = false;
        try {
            $this->db->query(
                "INSERT INTO `applications_oauth2_application_grants`
                 (appid, grant_type) VALUES (999, 'invalid_grant_type')"
            );
        } catch (\Exception $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected,
            'CHECK constraint on grant_type must reject unknown grant types');

        // Assert — oauth2_application_permissions VIEW exists
        $this->assertTrue(
            $this->viewExists('applications_oauth2_application_permissions'),
            'applications_oauth2_application_permissions VIEW must exist on MySQL'
        );

        $r = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM `applications_oauth2_application_permissions`'
        );
        $this->assertSame('0', (string) $r->fields['cnt'],
            'Empty permissions view must return 0 rows');

        // Assert — oauth2_active_tokens VIEW exists and is queryable
        $this->assertTrue(
            $this->viewExists('applications_oauth2_active_tokens'),
            'applications_oauth2_active_tokens VIEW must exist on MySQL'
        );

        $r2 = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM `applications_oauth2_active_tokens`'
        );
        $this->assertSame('0', (string) $r2->fields['cnt'],
            'Empty active tokens view must return 0 rows');

        // Assert — rollback removes table and views
        $m->down();
        $this->assertFalse(
            $this->tableExists('applications_oauth2_application_grants'),
            'Table must be removed by down()'
        );
        $this->assertFalse(
            $this->viewExists('applications_oauth2_application_permissions'),
            'Permissions view must be removed by down()'
        );
        $this->assertFalse(
            $this->viewExists('applications_oauth2_active_tokens'),
            'Active tokens view must be removed by down()'
        );
    }

    // -------------------------------------------------------------------------
    // AuthServer: slow_api_calls view
    // -------------------------------------------------------------------------

    /**
     * CreateSlowApiCallsView must create the authserver_slow_api_calls view on MySQL.
     *
     * The view joins tokenactions + usertokens + applications to surface slow API
     * calls (> 5 000 ms) from the last 7 days. On MySQL the view is named
     * authserver_slow_api_calls (schema-as-prefix convention).
     */
    public function testAuthserverSlowApiCallsViewCreatedOnMySQL(): void
    {
        // Arrange — all prerequisite tables must exist before the view can be created
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateUsertokensTable')->up();
        $this->loadMigration('auth', 'CreateUrlsTable')->up();
        $this->loadMigration('auth', 'CreateTokenactionsTable')->up();
        $this->loadMigration('authserver', 'CreateApplicationsTable')->up();

        $m = $this->loadMigration('authserver', 'CreateSlowApiCallsView');

        // Act
        $m->up();

        // Assert — view must exist in information_schema.VIEWS
        $this->assertTrue(
            $this->viewExists('authserver_slow_api_calls'),
            'authserver_slow_api_calls view must exist on MySQL after up()'
        );

        // Assert — the view must be queryable (zero rows — no tokenactions data yet)
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_slow_api_calls`");
        $this->assertSame('0', (string) $r->fields['cnt'], 'empty view must return 0 rows');

        // Assert — rollback removes the view
        $m->down();
        $this->assertFalse(
            $this->viewExists('authserver_slow_api_calls'),
            'view must be gone after down()'
        );
    }

    // -------------------------------------------------------------------------
    // AuthServer: RBAC tables (user_organizations, permission_templates, role_templates,
    //             permission_inheritance, effective_permissions VIEW)
    // -------------------------------------------------------------------------

    /**
     * CreateAuthserverUserOrganizationsTable must create authserver_user_organizations
     * with the (userid, organization_id) composite PK and the expected columns.
     *
     * user_organizations is the organisation membership table — a user must be a
     * member of an organisation before they can be assigned any org-scoped role.
     * The table name and column are configurable via Settings; this test verifies
     * the framework defaults (user_organizations / organization_id).
     */
    public function testAuthserverUserOrganizationsUpCreatesTable(): void
    {
        // Arrange — depends on user_roles (which depends on roles) and organizations (FK target)
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateOrganizationsTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverUserOrganizationsTable');

        // Act
        $m->up();

        // Assert — table created with default name
        $this->assertTrue(
            $this->tableExists('authserver_user_organizations'),
            'authserver_user_organizations table must exist after up() with default Settings'
        );

        // Assert — critical columns present
        $this->assertTrue($this->columnExists('authserver_user_organizations', 'userid'));
        // organization_id is the generic default (override via authserver_organization_column)
        $this->assertTrue($this->columnExists('authserver_user_organizations', 'organization_id'));
        $this->assertTrue($this->columnExists('authserver_user_organizations', 'granted_by'));
        $this->assertTrue($this->columnExists('authserver_user_organizations', 'granted_at'));
        $this->assertTrue($this->columnExists('authserver_user_organizations', 'expires_at'));
        $this->assertTrue($this->columnExists('authserver_user_organizations', 'is_active'));

        // Assert — granted_by and expires_at are nullable (optional metadata)
        $this->assertColumnNullable('authserver_user_organizations', 'granted_by', true);
        $this->assertColumnNullable('authserver_user_organizations', 'expires_at', true);

        // Assert — indexes for membership lookup
        $this->assertTrue($this->indexExists('authserver_user_organizations', 'idx_authserver_ud_userid'));
        $this->assertTrue($this->indexExists('authserver_user_organizations', 'idx_authserver_ud_org'));

        // Assert — rollback removes the table
        $m->down();
        $this->assertFalse($this->tableExists('authserver_user_organizations'));
    }

    /**
     * CreateAuthserverPermissionTemplatesTable must create authserver_permission_templates
     * with the expected columns for reusable permission blueprints.
     */
    public function testAuthserverPermissionTemplatesUpCreatesTable(): void
    {
        // Arrange — depends on audit_log which depends on permissions → roles
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverPermissionTemplatesTable');

        // Act
        $m->up();

        // Assert — table created
        $this->assertTrue(
            $this->tableExists('authserver_permission_templates'),
            'authserver_permission_templates must exist after up()'
        );

        // Assert — blueprint-specific columns
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'templateid'));
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'template_name'));
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'template_type'));
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'object_type'));
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'object_id_pattern'));
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'action'));
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'grant_type'));
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'priority'));
        $this->assertTrue($this->columnExists('authserver_permission_templates', 'is_active'));

        // Assert — template_name index for fast lookup
        $this->assertTrue($this->indexExists('authserver_permission_templates', 'idx_authserver_pt_name'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('authserver_permission_templates'));
    }

    /**
     * CreateAuthserverRoleTemplatesTable must create authserver_role_templates
     * with a JSON-compatible permission_templateids TEXT column.
     *
     * role_templates bundle permission templates so a complete access profile
     * can be applied in a single call. The permission_templateids column stores
     * a JSON array of integer IDs (TEXT type for cross-DB compatibility).
     */
    public function testAuthserverRoleTemplatesUpCreatesTable(): void
    {
        // Arrange — depends on permission_templates
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionTemplatesTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverRoleTemplatesTable');

        // Act
        $m->up();

        // Assert — table created
        $this->assertTrue(
            $this->tableExists('authserver_role_templates'),
            'authserver_role_templates must exist after up()'
        );

        // Assert — key columns
        $this->assertTrue($this->columnExists('authserver_role_templates', 'role_templateid'));
        $this->assertTrue($this->columnExists('authserver_role_templates', 'template_name'));
        $this->assertTrue($this->columnExists('authserver_role_templates', 'permission_templateids'));
        $this->assertTrue($this->columnExists('authserver_role_templates', 'is_system_template'));

        // permission_templateids is TEXT (cross-DB JSON array)
        $this->assertColumnType('authserver_role_templates', 'permission_templateids', 'text');
        $this->assertColumnNullable('authserver_role_templates', 'permission_templateids', true);

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('authserver_role_templates'));
    }

    /**
     * CreateAuthserverPermissionInheritanceTable must create authserver_permission_inheritance
     * with child/parent object columns and indexes for fast hierarchy traversal.
     *
     * This table defines hierarchical relationships between resource objects so
     * that permissions cascade from parent to child (zone → location pattern).
     */
    public function testAuthserverPermissionInheritanceUpCreatesTable(): void
    {
        // Arrange — depends on role_templates
        $this->loadMigration('authserver', 'CreateAuthserverRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionsTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverUserRolesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverAuditLogTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverPermissionTemplatesTable')->up();
        $this->loadMigration('authserver', 'CreateAuthserverRoleTemplatesTable')->up();

        $m = $this->loadMigration('authserver', 'CreateAuthserverPermissionInheritanceTable');

        // Act
        $m->up();

        // Assert — table created
        $this->assertTrue(
            $this->tableExists('authserver_permission_inheritance'),
            'authserver_permission_inheritance must exist after up()'
        );

        // Assert — hierarchy columns
        $this->assertTrue($this->columnExists('authserver_permission_inheritance', 'inheritanceid'));
        $this->assertTrue($this->columnExists('authserver_permission_inheritance', 'child_object_type'));
        $this->assertTrue($this->columnExists('authserver_permission_inheritance', 'child_object_id'));
        $this->assertTrue($this->columnExists('authserver_permission_inheritance', 'parent_object_type'));
        $this->assertTrue($this->columnExists('authserver_permission_inheritance', 'parent_object_id'));
        $this->assertTrue($this->columnExists('authserver_permission_inheritance', 'inheritance_type'));

        // Assert — traversal indexes
        $this->assertTrue($this->indexExists('authserver_permission_inheritance', 'idx_authserver_pi_child'));
        $this->assertTrue($this->indexExists('authserver_permission_inheritance', 'idx_authserver_pi_parent'));

        // Assert — rollback
        $m->down();
        $this->assertFalse($this->tableExists('authserver_permission_inheritance'));
    }

    /**
     * CreateAuthserverEffectivePermissionsView must create the
     * authserver_effective_permissions view on MySQL.
     *
     * The view aggregates authserver_permissions rows and resolves the effective
     * grant (allow/deny) using deny-takes-priority logic. On MySQL the view is
     * named authserver_effective_permissions (schema-as-prefix convention).
     */
    public function testAuthserverEffectivePermissionsViewCreatedOnMySQL(): void
    {
        // Arrange — all dependency tables must exist
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

        // Assert — view present in information_schema
        $this->assertTrue(
            $this->viewExists('authserver_effective_permissions'),
            'authserver_effective_permissions view must exist on MySQL after up()'
        );

        // Assert — the view is queryable (returns 0 rows on empty permissions table)
        $r = $this->db->query('SELECT COUNT(*) AS cnt FROM `authserver_effective_permissions`');
        $this->assertSame('0', (string) $r->fields['cnt'],
            'empty permissions table must yield 0 rows from effective_permissions view');

        // Assert — deny-takes-priority: insert allow and deny for same subject+object+action;
        //          the view must resolve to 'deny' because the deny has higher priority (0+1000)
        $this->db->query(
            "INSERT INTO `authserver_permissions`
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 1, 'report', '42', 'read', 'allow', 10)"
        );
        $this->db->query(
            "INSERT INTO `authserver_permissions`
             (subject_type, subject_id, object_type, object_id, action, grant_type, priority)
             VALUES ('user', 1, 'report', '42', 'read', 'deny', 1010)"
        );
        $r = $this->db->query(
            "SELECT effective_grant FROM `authserver_effective_permissions`
             WHERE subject_type='user' AND subject_id=1
               AND object_type='report' AND object_id='42' AND action='read'"
        );
        $this->assertSame('deny', $r->fields['effective_grant'],
            'deny with higher priority must dominate allow in effective_permissions');

        // Assert — rollback removes the view
        $m->down();
        $this->assertFalse(
            $this->viewExists('authserver_effective_permissions'),
            'view must be gone after down()'
        );
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
    // Auth 000017-000026: authserver.* tables on MySQL
    // On MySQL, schema.table notation becomes schema_table (no separate schema).
    // =========================================================================

    /**
     * Migration 000017 (loginlockout) creates authserver.loginlockouts.
     * On MySQL this produces the table authserver_loginlockouts with the
     * composite unique index uq_loginlockout_type_value for upsert-by-lookup.
     */
    public function testLoginlockoutsCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateLoginlockoutTable');

        // Act
        $m->up();

        // Assert — table exists with MySQL authserver_ prefix
        $this->assertTrue($this->tableExists('authserver_loginlockouts'),
            'authserver_loginlockouts must exist after up()');

        // Assert — insertable and queryable
        $this->db->query(
            "INSERT INTO `authserver_loginlockouts`
             (locktype, lookupvalue, failedattempts, firstfailedat, lastfailedat, lockoutuntil, createdat, updatedat)
             VALUES ('ip', '127.0.0.1', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_loginlockouts`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        // Assert — down() removes the table
        $m->down();
        $this->assertFalse($this->tableExists('authserver_loginlockouts'),
            'authserver_loginlockouts must be gone after down()');
    }

    /**
     * Migration 000018 (user_twofactor) creates authserver.user_twofactor.
     * On MySQL this produces authserver_user_twofactor with userid as PK.
     */
    public function testUserTwofactorCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $m = $this->loadMigration('auth', 'CreateUserTwofactorTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('authserver_user_twofactor'),
            'authserver_user_twofactor must exist after up()');

        $this->db->query(
            "INSERT INTO `authserver_user_twofactor` (userid, enabled, created_at, updated_at)
             VALUES (999, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_user_twofactor`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('authserver_user_twofactor'));
    }

    /**
     * Migration 000019 (twofactor_setup) creates authserver.twofactor_setup.
     * On MySQL: authserver_twofactor_setup. Setup sessions expire after 15 min.
     */
    public function testTwofactorSetupCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateTwofactorSetupTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('authserver_twofactor_setup'),
            'authserver_twofactor_setup must exist after up()');

        $this->db->query(
            "INSERT INTO `authserver_twofactor_setup` (userid, temp_secret, used, expires_at, created_at)
             VALUES (999, 'JBSWY3DPEHPK3PXP', 0, UNIX_TIMESTAMP() + 900, UNIX_TIMESTAMP())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_twofactor_setup`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('authserver_twofactor_setup'));
    }

    /**
     * Migration 000020 (twofactor_attempts) creates authserver.twofactor_attempts.
     * On MySQL: plain table (ifCapable(TIMESCALEDB) is a no-op on MySQL).
     */
    public function testTwofactorAttemptsCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateTwofactorAttemptsTable');

        // Act
        $m->up();

        // Assert — plain table on MySQL (no hypertable)
        $this->assertTrue($this->tableExists('authserver_twofactor_attempts'),
            'authserver_twofactor_attempts must exist after up()');

        $this->db->query(
            "INSERT INTO `authserver_twofactor_attempts` (userid, success, ip_address, attempt_time)
             VALUES (999, 1, '127.0.0.1', NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_twofactor_attempts`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('authserver_twofactor_attempts'));
    }

    /**
     * Migration 000021 (user_activity_log) creates authserver.user_activity_log.
     * On MySQL: plain table. The continuous aggregate migration (000026)
     * creates a VIEW over this table.
     */
    public function testUserActivityLogCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateUserActivityLogTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('authserver_user_activity_log'),
            'authserver_user_activity_log must exist after up()');

        $this->db->query(
            "INSERT INTO `authserver_user_activity_log` (userid, action, created_at)
             VALUES (999, 'login', NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_user_activity_log`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('authserver_user_activity_log'));
    }

    /**
     * Migration 000022 (user_privacy_settings) creates authserver.user_privacy_settings.
     * On MySQL: authserver_user_privacy_settings with userid as PK.
     */
    public function testUserPrivacySettingsCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateUserPrivacySettingsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('authserver_user_privacy_settings'),
            'authserver_user_privacy_settings must exist after up()');

        $this->db->query(
            "INSERT INTO `authserver_user_privacy_settings` (userid, updated_at)
             VALUES (999, NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_user_privacy_settings`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('authserver_user_privacy_settings'));
    }

    /**
     * Migration 000023 (user_consents) creates authserver.user_consents.
     * On MySQL: plain table (ifCapable(TIMESCALEDB) is a no-op).
     */
    public function testUserConsentsCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $this->loadMigration('auth', 'CreateUserPrivacySettingsTable')->up();
        $m = $this->loadMigration('auth', 'CreateUserConsentsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('authserver_user_consents'),
            'authserver_user_consents must exist after up()');

        $this->db->query(
            "INSERT INTO `authserver_user_consents` (userid, consent_type, granted, granted_at)
             VALUES (999, 'marketing', 1, NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_user_consents`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('authserver_user_consents'));
    }

    /**
     * Migration 000024 (data_processing_records) creates authserver.data_processing_records.
     * On MySQL: plain table (ifCapable(TIMESCALEDB) is a no-op).
     */
    public function testDataProcessingRecordsCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateDataProcessingRecordsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('authserver_data_processing_records'),
            'authserver_data_processing_records must exist after up()');

        $this->db->query(
            "INSERT INTO `authserver_data_processing_records`
             (userid, operation, data_category, legal_basis, processed_at)
             VALUES (999, 'export', 'profile', 'consent', NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_data_processing_records`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('authserver_data_processing_records'));
    }

    /**
     * Migration 000025 (gdpr_requests) creates authserver.gdpr_requests.
     * On MySQL: plain table (ifCapable(TIMESCALEDB) is a no-op).
     * 7-year retention policy is enforced at the application level on MySQL.
     */
    public function testGdprRequestsCreatedInAuthserverPrefixOnMySQL(): void
    {
        // Arrange
        $m = $this->loadMigration('auth', 'CreateGdprRequestsTable');

        // Act
        $m->up();

        // Assert
        $this->assertTrue($this->tableExists('authserver_gdpr_requests'),
            'authserver_gdpr_requests must exist after up()');

        $this->db->query(
            "INSERT INTO `authserver_gdpr_requests` (userid, request_type, status, requested_at)
             VALUES (999, 'erasure', 'pending', NOW())"
        );
        $r = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_gdpr_requests`");
        $this->assertSame('1', (string) $r->fields['cnt']);

        $m->down();
        $this->assertFalse($this->tableExists('authserver_gdpr_requests'));
    }

    /**
     * Migration 000026 (daily_activity_summary) creates a plain VIEW on MySQL
     * over authserver_user_activity_log. On MySQL ifCapable(TIMESCALEDB) is
     * a no-op, so the fallback (materialized/plain view) path runs instead.
     */
    public function testDailyActivitySummaryCreatesViewOnMySQL(): void
    {
        // Arrange — source table must exist
        $this->loadMigration('auth', 'CreateUserActivityLogTable')->up();

        $m = $this->loadMigration('auth', 'CreateDailyActivitySummaryView');

        // Act
        $m->up();

        // Assert — view exists (information_schema.VIEWS for MySQL)
        $r = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.VIEWS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                'authserver_daily_activity_summary'
            )
        );
        $this->assertGreaterThan(0, (int) $r->fields['cnt'],
            'authserver_daily_activity_summary VIEW must exist after up()');

        // Assert — view is queryable (returns 0 rows before any data)
        $r2 = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_daily_activity_summary`");
        $this->assertSame('0', (string) $r2->fields['cnt']);

        // Assert — inserts to the source table are reflected in the view
        $this->db->query(
            "INSERT INTO `authserver_user_activity_log` (userid, action, created_at)
             VALUES (999, 'login', NOW()), (999, 'view_page', NOW())"
        );
        $r3 = $this->db->query("SELECT COUNT(*) AS cnt FROM `authserver_daily_activity_summary`");
        $this->assertGreaterThan(0, (int) $r3->fields['cnt'],
            'daily_activity_summary view must reflect inserts into user_activity_log');

        // Assert — down() removes the view
        $m->down();
        $r4 = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.VIEWS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                'authserver_daily_activity_summary'
            )
        );
        $this->assertSame('0', (string) $r4->fields['cnt'],
            'authserver_daily_activity_summary VIEW must be gone after down()');
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

    protected function viewExists(string $name): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.VIEWS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                'pramnos_test',
                $name
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
        // Drop views first (before the underlying tables are removed)
        $this->db->query("DROP VIEW IF EXISTS `authserver_slow_api_calls`");
        $this->db->query("DROP VIEW IF EXISTS `authserver_effective_permissions`");
        $this->db->query("DROP VIEW IF EXISTS `authserver_daily_activity_summary`");
        $this->db->query("DROP VIEW IF EXISTS `applications_oauth2_application_permissions`");
        $this->db->query("DROP VIEW IF EXISTS `applications_oauth2_active_tokens`");

        $tables = [
            // applications schema tables (drop before applications table)
            'applications_oauth2_webhook_events', 'applications_oauth2_webhook_endpoints',
            'applications_oauth2_client_auth_methods',
            'applications_oauth2_application_grants',
            // authserver RBAC extension tables (drop before base RBAC tables)
            'authserver_permission_inheritance',
            'authserver_role_templates',
            'authserver_permission_templates',
            'authserver_user_organizations',
            'authserver_jwt_replay_prevention',
            'authserver_device_authorizations',
            'oauth2_access_tokens', 'oauth2_refresh_tokens',
            'oauth2_auth_codes',
            'applications',
            'organizations',
            'authserver_user_roles', 'authserver_audit_log',
            'authserver_permissions', 'authserver_roles',
            'authserver_schema',
            // auth 000017-000026 (authserver-prefixed on MySQL)
            'authserver_gdpr_requests',
            'authserver_data_processing_records',
            'authserver_user_consents',
            'authserver_user_privacy_settings',
            'authserver_user_activity_log',
            'authserver_twofactor_attempts',
            'authserver_twofactor_setup',
            'authserver_user_twofactor',
            'authserver_loginlockouts',
            // messaging
            'massmessagerecipients', 'massmessages',
            'mailtemplates', 'mails', 'messages',
            // auth
            'tokenactions', 'urls',
            'usertokens', 'usernotes', 'userlog', 'userdetails',
            'users',
            // queue
            'queueitems',
            // core
            'pramnos_framework_policies', 'settings', 'sessions',
        ];

        foreach ($tables as $table) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }
}
