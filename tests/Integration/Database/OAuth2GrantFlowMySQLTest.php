<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Integration tests for OAuth2 grant flows against MySQL 9.x.
 *
 * These tests verify that the DB schema and SQL patterns used by the OAuth2
 * controllers (Oauth.php, Device.php, Dashboard.php) actually work against a
 * live MySQL database.  No controller or League-server code is exercised here;
 * the queries are replicated inline to keep the tests independent of the HTTP
 * dispatch layer and to isolate DB correctness.
 *
 * Isolation strategy:
 *   - The `users`, `applications`, and `usertokens` tables are shared framework
 *     tables.  This test creates them with CREATE TABLE IF NOT EXISTS so it works
 *     both in isolation and when run after FrameworkMigrationsMySQLTest.  It
 *     never drops them; instead it DELETEs only the rows it inserted.
 *   - The `oauth2_device_codes` and `oauth2_user_consents` tables are new and
 *     owned by this test suite — they are dropped and recreated each setUp.
 *
 * Covered scenarios:
 *   - Device authorization flow (RFC 8628): INSERT device code, SELECT by
 *     user_code, approve and deny status transitions, expiry filtering.
 *   - User consent recording: INSERT, scope-merge UPDATE, SELECT check.
 *   - PKCE auth_code tokens: INSERT with code_challenge, SELECT for exchange,
 *     auth_code consumption after exchange.
 *   - Token revocation: UPDATE status to revoked.
 *   - Token introspection: JOIN usertokens + applications for the active flag.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class OAuth2GrantFlowMySQLTest extends TestCase
{
    protected Database $db;

    /** @var int[] userids inserted during this test — deleted in tearDown */
    private array $testUserIds     = [];
    /** @var int[] appids inserted during this test — deleted in tearDown */
    private array $testAppIds      = [];
    /** @var int[] tokenids inserted during this test — deleted in tearDown */
    private array $testTokenIds    = [];
    /** @var string[] device_codes inserted — deleted in tearDown */
    private array $testDeviceCodes = [];

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

        $this->testUserIds     = [];
        $this->testAppIds      = [];
        $this->testTokenIds    = [];
        $this->testDeviceCodes = [];

        $this->dropOwnedTables();
        $this->ensureSharedTables();
        $this->createOwnedTables();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestRows();
        $this->dropOwnedTables();
    }

    // -------------------------------------------------------------------------
    // Table management
    // -------------------------------------------------------------------------

    /**
     * Drop tables managed by this test class.
     *
     * oauth2_device_codes and oauth2_user_consents are fully owned by this test.
     * applications is also dropped and recreated so its schema is always the
     * full-compatible version — nothing in this test creates a FK to applications,
     * so the drop is safe.  users and usertokens are NOT dropped because
     * userstogroups has a FK to users that MySQL 9.x enforces even with
     * FOREIGN_KEY_CHECKS = 0 during CREATE.
     */
    protected function dropOwnedTables(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `authserver_oauth2_user_consents`');
        $this->db->query('DROP TABLE IF EXISTS `authserver_oauth2_device_codes`');
        $this->db->query('DROP TABLE IF EXISTS `applications`');
    }

    /**
     * Ensure the shared framework tables that we cannot safely drop exist.
     *
     * Only users and usertokens are created IF NOT EXISTS here.  applications
     * is dropped and recreated (with the full schema) in createOwnedTables(),
     * so it is always in the correct state.
     *
     * Signed BIGINT is used for userid to match the existing userstogroups.userid
     * FK reference type (MySQL 9.x enforces strict FK type compatibility even for
     * dangling references created by other tests).
     */
    protected function ensureSharedTables(): void
    {
        // Full schema must match User::setupDb() exactly so that User::save()
        // can write all its fields if this test creates the table first.
        $this->db->query("CREATE TABLE IF NOT EXISTS `users` (
            `userid`          bigint(20)            NOT NULL AUTO_INCREMENT,
            `username`        varchar(50)           NOT NULL DEFAULT '',
            `password`        varchar(100)          NOT NULL DEFAULT '',
            `email`           varchar(150)          NOT NULL DEFAULT '',
            `lastname`        varchar(128)          NOT NULL DEFAULT '',
            `firstname`       varchar(128)          NOT NULL DEFAULT '',
            `regdate`         int(11)               NOT NULL DEFAULT '0',
            `regcompletion`   int(10) UNSIGNED      DEFAULT NULL,
            `lasttermsagreed` int(10) UNSIGNED      DEFAULT NULL,
            `lastlogin`       int(11)               NOT NULL DEFAULT '0',
            `active`          tinyint(1)            NOT NULL DEFAULT '1',
            `validated`       tinyint(4)            NOT NULL DEFAULT '1',
            `language`        varchar(50)           NOT NULL DEFAULT '',
            `timezone`        char(3)               NOT NULL DEFAULT '',
            `dateformat`      varchar(15)           NOT NULL DEFAULT 'd/m/Y H:i',
            `usertype`        tinyint(4)            NOT NULL DEFAULT '0',
            `sex`             tinyint(3) UNSIGNED   NOT NULL DEFAULT '0',
            `birthdate`       bigint(20)            NOT NULL DEFAULT '0',
            `photo`           int(11)               DEFAULT NULL,
            `phone`           varchar(50)           NOT NULL DEFAULT '',
            `mobile`          varchar(50)           NOT NULL DEFAULT '',
            `fax`             varchar(50)           NOT NULL DEFAULT '',
            `website`         varchar(255)          NOT NULL DEFAULT '',
            `modified`        int(11)               NOT NULL DEFAULT '0',
            PRIMARY KEY (`userid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $this->db->query("CREATE TABLE IF NOT EXISTS `usertokens` (
            `tokenid`               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `userid`                BIGINT NOT NULL,
            `tokentype`             VARCHAR(20) NOT NULL,
            `token`                 TEXT NOT NULL,
            `created`               INT NOT NULL DEFAULT 0,
            `notes`                 VARCHAR(255) NOT NULL DEFAULT '',
            `lastused`              INT NOT NULL DEFAULT 0,
            `status`                TINYINT NOT NULL DEFAULT 1,
            `parentToken`           INT NULL,
            `applicationid`         INT NULL,
            `actions`               INT NOT NULL DEFAULT 0,
            `removedate`            INT NOT NULL DEFAULT 0,
            `deviceinfo`            TEXT NULL,
            `scope`                 TEXT NULL,
            `expires`               INT NULL,
            `ipaddress`             VARCHAR(45) NULL,
            `code_challenge`        VARCHAR(128) NULL,
            `code_challenge_method` VARCHAR(10)  NULL,
            KEY `idx_usertokens_userid_status`  (`userid`, `status`),
            KEY `idx_usertokens_type_status`    (`tokentype`, `status`),
            KEY `idx_usertokens_applicationid`  (`applicationid`),
            KEY `idx_usertokens_code_challenge` (`code_challenge`(128)),
            CONSTRAINT `chk_code_challenge_method`
                CHECK (`code_challenge_method` IS NULL
                    OR `code_challenge_method` IN ('plain', 'S256'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Create the tables owned by this test (applications, oauth2_device_codes, oauth2_user_consents).
     * These are dropped and recreated each test run so the schema is always
     * the full-compatible version.  The applications schema must exactly match
     * ApikeyCharacterizationTest::ensureApplicationsTableExists() so that if
     * either test class runs first, the other finds a compatible table.
     */
    protected function createOwnedTables(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `applications` (
            `appid`           INT AUTO_INCREMENT PRIMARY KEY,
            `name`            VARCHAR(191) NOT NULL,
            `apikey`          VARCHAR(191) NOT NULL,
            `apisecret`       VARCHAR(191) NOT NULL,
            `status`          INT NOT NULL DEFAULT 0,
            `added`           INT NOT NULL DEFAULT 0,
            `description`     TEXT NULL,
            `organization`    VARCHAR(191) NULL,
            `organizationurl` VARCHAR(255) NULL,
            `url`             VARCHAR(255) NULL,
            `apptype`         INT NOT NULL DEFAULT 0,
            `accesstype`      INT NOT NULL DEFAULT 0,
            `apiversion`      VARCHAR(50) NULL,
            `scope`           TEXT NULL,
            `public`          INT NOT NULL DEFAULT 0,
            `callback`        VARCHAR(255) NULL,
            `owner`           INT NULL,
            `public_key`      TEXT NULL,
            `jwks_uri`        VARCHAR(500) NULL,
            `systemuser`      BIGINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `authserver_oauth2_device_codes` (
            `id`            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `device_code`   VARCHAR(64)  NOT NULL,
            `user_code`     VARCHAR(9)   NOT NULL,
            `client_id`     VARCHAR(255) NOT NULL,
            `scope`         TEXT NULL,
            `expires_at`    INT NOT NULL,
            `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending',
            `user_id`       BIGINT NULL,
            `authorized_at` INT NULL,
            UNIQUE KEY `uq_oauth2_dc_device_code` (`device_code`),
            UNIQUE KEY `uq_oauth2_dc_user_code`   (`user_code`),
            KEY `idx_oauth2_dc_expires_status` (`expires_at`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `authserver_oauth2_user_consents` (
            `id`            BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `userid`        BIGINT NOT NULL,
            `applicationid` INT    NOT NULL,
            `scope`         TEXT NULL,
            `created_at`    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_oauth2_consents_user_app` (`userid`, `applicationid`),
            KEY `idx_oauth2_consents_userid` (`userid`),
            KEY `idx_oauth2_consents_appid`  (`applicationid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Delete rows inserted by this test from the shared framework tables.
     * This leaves the tables intact for other test classes.
     */
    protected function cleanupTestRows(): void
    {
        if ($this->testTokenIds) {
            $ids = implode(',', $this->testTokenIds);
            $this->db->query("DELETE FROM `usertokens` WHERE `tokenid` IN ({$ids})");
        }
        if ($this->testAppIds) {
            $ids = implode(',', $this->testAppIds);
            $this->db->query("DELETE FROM `applications` WHERE `appid` IN ({$ids})");
        }
        if ($this->testUserIds) {
            $ids = implode(',', $this->testUserIds);
            $this->db->query("DELETE FROM `users` WHERE `userid` IN ({$ids})");
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Insert a test user and return the userid.
     * The userid is tracked so tearDown can DELETE it.
     */
    protected function insertUser(string $username = 'testuser'): int
    {
        // Use a unique suffix to avoid collisions across parallel test runs
        $unique   = bin2hex(random_bytes(4));
        $username = $username . '_' . $unique;
        $sql = $this->db->prepareQuery(
            "INSERT INTO users (username, email, active) VALUES (%s, %s, 1)",
            $username,
            "{$username}@example.com"
        );
        $this->db->query($sql);
        $id                  = (int) $this->db->getInsertId();
        $this->testUserIds[] = $id;
        return $id;
    }

    /**
     * Insert a test application and return the appid.
     * The appid is tracked so tearDown can DELETE it.
     */
    protected function insertApp(string $apikey = 'test-client'): int
    {
        $unique = bin2hex(random_bytes(4));
        $apikey = $apikey . '_' . $unique;
        $sql = $this->db->prepareQuery(
            "INSERT INTO applications (name, apikey, apisecret, status) VALUES (%s, %s, %s, 1)",
            'Test Application',
            $apikey,
            'test-secret'
        );
        $this->db->query($sql);
        $id                 = (int) $this->db->getInsertId();
        $this->testAppIds[] = $id;
        return $id;
    }

    /**
     * Insert a token row and track its id for tearDown cleanup.
     */
    protected function trackToken(int $tokenId): void
    {
        $this->testTokenIds[] = $tokenId;
    }

    // =========================================================================
    // Device code flow (RFC 8628)
    // =========================================================================

    /**
     * A device code row must be insertable and retrievable by user_code.
     *
     * The controller's deviceauthorization() inserts a row with status='pending'
     * and the device's verify endpoint selects it by user_code.  This test
     * verifies the INSERT → SELECT path works and returns the expected row.
     */
    public function testDeviceCodeInsertAndRetrieve(): void
    {
        // Arrange
        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = 'BCDF-GHJK';
        $clientId   = 'test-client';
        $expiresAt  = time() + 600;

        // Act — replicate Oauth::deviceauthorization() INSERT
        $sql = $this->db->prepareQuery(
            "INSERT INTO authserver_oauth2_device_codes
                (device_code, user_code, client_id, scope, expires_at, status)
             VALUES (%s, %s, %s, %s, %d, 'pending')",
            $deviceCode, $userCode, $clientId, 'openid profile', $expiresAt
        );
        $this->db->query($sql);
        $this->testDeviceCodes[] = $deviceCode;

        // Act — replicate Device::handleVerification() SELECT
        $sql = $this->db->prepareQuery(
            "SELECT * FROM authserver_oauth2_device_codes
              WHERE user_code = %s AND status = 'pending' AND expires_at > %d",
            $userCode,
            time()
        );
        $result = $this->db->query($sql);

        // Assert
        $this->assertSame(1, $result->numRows, 'Device code must be retrievable by user_code');
        $this->assertSame($deviceCode, $result->fields['device_code']);
        $this->assertSame('openid profile', $result->fields['scope']);
        $this->assertSame('pending', $result->fields['status']);
    }

    /**
     * An expired device code must not be returned by the verification query.
     *
     * The Device controller filters with `expires_at > time()`. Codes whose
     * expiry has passed must be invisible to the filter, preventing use of
     * stale codes.
     */
    public function testExpiredDeviceCodeIsExcluded(): void
    {
        // Arrange — insert a device code that expired 10 seconds ago
        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = 'LMNP-QRST';
        $sql = $this->db->prepareQuery(
            "INSERT INTO authserver_oauth2_device_codes
                (device_code, user_code, client_id, scope, expires_at, status)
             VALUES (%s, %s, %s, %s, %d, 'pending')",
            $deviceCode, $userCode, 'test-client', 'openid', time() - 10
        );
        $this->db->query($sql);
        $this->testDeviceCodes[] = $deviceCode;

        // Act — the verification query must filter out expired codes
        $sql = $this->db->prepareQuery(
            "SELECT * FROM authserver_oauth2_device_codes
              WHERE user_code = %s AND status = 'pending' AND expires_at > %d",
            $userCode,
            time()
        );
        $result = $this->db->query($sql);

        // Assert — no rows returned
        $this->assertSame(0, $result->numRows, 'Expired device codes must not be returned');
    }

    /**
     * Approving a device code must set status='authorized', user_id, and authorized_at.
     *
     * The Device::approveDevice() method sets all three columns atomically.
     * The subsequent token-polling by the device client will check status='authorized'
     * to know when to exchange the device_code for an access token.
     */
    public function testDeviceCodeApproval(): void
    {
        // Arrange
        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = 'VWXZ-BCDF';
        $userId     = $this->insertUser();
        $sql = $this->db->prepareQuery(
            "INSERT INTO authserver_oauth2_device_codes
                (device_code, user_code, client_id, scope, expires_at, status)
             VALUES (%s, %s, %s, %s, %d, 'pending')",
            $deviceCode, $userCode, 'test-client', 'openid', time() + 600
        );
        $this->db->query($sql);
        $this->testDeviceCodes[] = $deviceCode;

        // Act — replicate Device::approveDevice()
        $now = time();
        $sql = $this->db->prepareQuery(
            "UPDATE authserver_oauth2_device_codes
                SET status = 'authorized', user_id = %d, authorized_at = %d
              WHERE user_code = %s",
            $userId, $now, $userCode
        );
        $this->db->query($sql);

        // Assert
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT status, user_id, authorized_at FROM authserver_oauth2_device_codes WHERE user_code = %s",
                $userCode
            )
        );
        $this->assertSame('authorized', $result->fields['status']);
        $this->assertSame((string) $userId, (string) $result->fields['user_id']);
        $this->assertGreaterThan(0, (int) $result->fields['authorized_at']);
    }

    /**
     * Denying a device code must set status='denied' and authorized_at (denial timestamp).
     *
     * Denied codes must still have an authorized_at to record when the denial occurred.
     * The device client polls for any non-pending status; 'denied' causes it to stop.
     */
    public function testDeviceCodeDenial(): void
    {
        // Arrange
        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = 'GHJK-LMNP';
        $sql = $this->db->prepareQuery(
            "INSERT INTO authserver_oauth2_device_codes
                (device_code, user_code, client_id, scope, expires_at, status)
             VALUES (%s, %s, %s, %s, %d, 'pending')",
            $deviceCode, $userCode, 'test-client', 'openid', time() + 600
        );
        $this->db->query($sql);
        $this->testDeviceCodes[] = $deviceCode;

        // Act — replicate Device::denyDevice()
        $sql = $this->db->prepareQuery(
            "UPDATE authserver_oauth2_device_codes
                SET status = 'denied', authorized_at = %d
              WHERE user_code = %s",
            time(), $userCode
        );
        $this->db->query($sql);

        // Assert
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT status, user_id FROM authserver_oauth2_device_codes WHERE user_code = %s",
                $userCode
            )
        );
        $this->assertSame('denied', $result->fields['status']);
        $this->assertNull($result->fields['user_id'], 'Denied codes must have NULL user_id');
    }

    // =========================================================================
    // User consent recording
    // =========================================================================

    /**
     * Recording consent for the first time must insert a row with the granted scope.
     *
     * Oauth::recordConsent() checks for an existing row first. When none exists it
     * performs an INSERT. The unique constraint on (userid, applicationid) prevents
     * duplicate entries.
     */
    public function testUserConsentInsert(): void
    {
        // Arrange
        $userId = $this->insertUser('alice');
        $appId  = $this->insertApp('client-a');

        // Act — replicate Oauth::recordConsent() INSERT path
        $sql = $this->db->prepareQuery(
            "INSERT INTO authserver_oauth2_user_consents (userid, applicationid, scope, created_at, updated_at)
             VALUES (%d, %d, %s, NOW(), NOW())",
            $userId, $appId, 'openid profile'
        );
        $this->db->query($sql);

        // Assert — consent row was persisted
        $sql    = $this->db->prepareQuery(
            "SELECT scope FROM authserver_oauth2_user_consents WHERE userid = %d AND applicationid = %d",
            $userId, $appId
        );
        $result = $this->db->query($sql);
        $this->assertSame(1, $result->numRows, 'Consent row must be inserted');
        $this->assertSame('openid profile', $result->fields['scope']);
    }

    /**
     * Re-authorizing with additional scopes must expand the stored scope, not replace it.
     *
     * Oauth::recordConsent() reads the existing scope, merges with the new scope
     * (array_unique), and issues an UPDATE.  This test verifies that previously
     * granted scopes are never lost.
     */
    public function testUserConsentScopeMergeExpands(): void
    {
        // Arrange — initial consent with two scopes
        $userId = $this->insertUser('bob');
        $appId  = $this->insertApp('client-b');
        $sql = $this->db->prepareQuery(
            "INSERT INTO authserver_oauth2_user_consents (userid, applicationid, scope, created_at, updated_at)
             VALUES (%d, %d, %s, NOW(), NOW())",
            $userId, $appId, 'openid profile'
        );
        $this->db->query($sql);

        // Act — add email scope (replicate Oauth::recordConsent() UPDATE path)
        $existing = 'openid profile';
        $new      = 'openid profile email';
        $merged   = implode(' ', array_unique(array_filter(array_merge(
            explode(' ', $existing),
            explode(' ', $new)
        ))));
        $sql = $this->db->prepareQuery(
            "UPDATE authserver_oauth2_user_consents SET scope = %s, updated_at = NOW()
              WHERE userid = %d AND applicationid = %d",
            $merged, $userId, $appId
        );
        $this->db->query($sql);

        // Assert — all three scopes are present
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT scope FROM authserver_oauth2_user_consents WHERE userid = %d AND applicationid = %d",
                $userId, $appId
            )
        );
        $stored = (string) ($result->fields['scope'] ?? '');
        $this->assertStringContainsString('openid',  $stored, 'openid scope must be retained');
        $this->assertStringContainsString('profile', $stored, 'profile scope must be retained');
        $this->assertStringContainsString('email',   $stored, 'email scope must be added');
    }

    /**
     * hasUserAuthorizedApp() must return true when all requested scopes are in the consent.
     *
     * The controller checks the granted scope string and verifies every requested
     * scope is present. This test exercises that exact query + check logic.
     */
    public function testUserConsentCheckGrantsAccess(): void
    {
        // Arrange — user has granted openid, profile, email
        $userId = $this->insertUser('carol');
        $appId  = $this->insertApp('client-c');
        $sql = $this->db->prepareQuery(
            "INSERT INTO authserver_oauth2_user_consents (userid, applicationid, scope, created_at, updated_at)
             VALUES (%d, %d, %s, NOW(), NOW())",
            $userId, $appId, 'openid profile email'
        );
        $this->db->query($sql);

        // Act — replicate hasUserAuthorizedApp() query + check
        $requestedScopes = ['openid', 'profile'];
        $sql    = $this->db->prepareQuery(
            "SELECT scope FROM authserver_oauth2_user_consents WHERE userid = %d AND applicationid = %d",
            $userId, $appId
        );
        $result = $this->db->query($sql);
        $grantedScopes = array_filter(explode(' ', (string) ($result->fields['scope'] ?? '')));
        $allGranted = true;
        foreach ($requestedScopes as $scope) {
            if (!in_array($scope, $grantedScopes, true)) {
                $allGranted = false;
                break;
            }
        }

        // Assert
        $this->assertTrue($allGranted, 'All requested scopes must be found in the consent record');
    }

    /**
     * hasUserAuthorizedApp() must return false when a requested scope was not granted.
     *
     * If the stored consent does not cover all requested scopes, the consent screen
     * must be shown again so the user can approve the additional scope.
     */
    public function testUserConsentCheckDeniesWhenScopeMissing(): void
    {
        // Arrange — user only granted openid (not profile)
        $userId = $this->insertUser('dave');
        $appId  = $this->insertApp('client-d');
        $sql = $this->db->prepareQuery(
            "INSERT INTO authserver_oauth2_user_consents (userid, applicationid, scope, created_at, updated_at)
             VALUES (%d, %d, %s, NOW(), NOW())",
            $userId, $appId, 'openid'
        );
        $this->db->query($sql);

        // Act — request openid + profile; profile is missing
        $requestedScopes = ['openid', 'profile'];
        $sql    = $this->db->prepareQuery(
            "SELECT scope FROM authserver_oauth2_user_consents WHERE userid = %d AND applicationid = %d",
            $userId, $appId
        );
        $result = $this->db->query($sql);
        $grantedScopes = array_filter(explode(' ', (string) ($result->fields['scope'] ?? '')));
        $allGranted = true;
        foreach ($requestedScopes as $scope) {
            if (!in_array($scope, $grantedScopes, true)) {
                $allGranted = false;
                break;
            }
        }

        // Assert
        $this->assertFalse($allGranted, 'Missing scope must cause consent check to fail');
    }

    // =========================================================================
    // PKCE token lifecycle (RFC 7636)
    // =========================================================================

    /**
     * An auth_code token with an S256 code_challenge must be persisted and retrievable.
     *
     * The authorization code flow (PKCE variant) stores the code_challenge at
     * authorization time and validates the code_verifier at token-exchange time.
     * This test verifies the INSERT → SELECT path.
     */
    public function testPkceAuthCodeTokenInsertAndRetrieve(): void
    {
        // Arrange
        $userId    = $this->insertUser('eve');
        $appId     = $this->insertApp('pkce-client');
        $authCode  = bin2hex(random_bytes(16));
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // Act — INSERT auth_code token (replicate Oauth::issueCodeAndRedirect())
        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope, expires,
                 notes, deviceinfo, code_challenge, code_challenge_method)
             VALUES (%d, 'auth_code', %s, %d, 1, %d, %s, %d, %s, %s, %s, 'S256')",
            $userId, $authCode, time(), $appId, 'openid profile',
            time() + 300, 'pkce-client', '{}', $challenge
        );
        $this->db->query($sql);
        $this->trackToken((int) $this->db->getInsertId());

        // Act — SELECT to exchange the auth code (replicate token endpoint lookup)
        $sql = $this->db->prepareQuery(
            "SELECT tokenid, userid, scope, code_challenge, code_challenge_method
               FROM usertokens
              WHERE token = %s AND tokentype = 'auth_code' AND status = 1 AND expires > %d",
            $authCode, time()
        );
        $result = $this->db->query($sql);

        // Assert
        $this->assertSame(1, $result->numRows, 'auth_code token must be stored and retrievable');
        $this->assertSame($challenge, $result->fields['code_challenge']);
        $this->assertSame('S256', $result->fields['code_challenge_method']);
        $this->assertSame('openid profile', $result->fields['scope']);
    }

    /**
     * After token exchange, the auth_code must be consumed (status set to 2).
     *
     * The authorization code must be single-use: once exchanged for an access token
     * the auth_code row must be deactivated so it cannot be replayed.
     */
    public function testAuthCodeConsumptionOnExchange(): void
    {
        // Arrange — insert an auth_code token
        $userId   = $this->insertUser('frank');
        $appId    = $this->insertApp('consume-client');
        $authCode = bin2hex(random_bytes(16));
        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope,
                 expires, notes, deviceinfo)
             VALUES (%d, 'auth_code', %s, %d, 1, %d, %s, %d, %s, %s)",
            $userId, $authCode, time(), $appId, 'openid', time() + 300, 'consume-client', '{}'
        );
        $this->db->query($sql);
        $tokenId = (int) $this->db->getInsertId();
        $this->trackToken($tokenId);

        // Act — consume (deactivate) the auth code after exchange
        $sql = $this->db->prepareQuery(
            "UPDATE usertokens SET status = 2 WHERE tokenid = %d",
            $tokenId
        );
        $this->db->query($sql);

        // Assert — the code can no longer be found by the active query
        $sql = $this->db->prepareQuery(
            "SELECT tokenid FROM usertokens
              WHERE token = %s AND tokentype = 'auth_code' AND status = 1",
            $authCode
        );
        $result = $this->db->query($sql);
        $this->assertSame(0, $result->numRows, 'Consumed auth_code must not be findable as active');
    }

    // =========================================================================
    // Token revocation (RFC 7009)
    // =========================================================================

    /**
     * Revoking an access token must set status = 0 (inactive).
     *
     * The Oauth::revoke() endpoint issues an UPDATE without verifying ownership
     * (the spec permits this). The token must not be findable as active afterwards.
     */
    public function testTokenRevocation(): void
    {
        // Arrange — insert an active access token
        $userId      = $this->insertUser('grace');
        $appId       = $this->insertApp('revoke-client');
        $accessToken = bin2hex(random_bytes(32));
        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope,
                 expires, notes, deviceinfo)
             VALUES (%d, 'access_token', %s, %d, 1, %d, %s, %d, %s, %s)",
            $userId, $accessToken, time(), $appId, 'openid', time() + 3600, 'revoke-client', '{}'
        );
        $this->db->query($sql);
        $this->trackToken((int) $this->db->getInsertId());

        // Act — replicate Oauth::revoke() UPDATE
        $sql = $this->db->prepareQuery(
            "UPDATE usertokens SET status = 0 WHERE token = %s",
            $accessToken
        );
        $this->db->query($sql);

        // Assert — token is no longer active
        $sql = $this->db->prepareQuery(
            "SELECT tokenid FROM usertokens WHERE token = %s AND status = 1",
            $accessToken
        );
        $result = $this->db->query($sql);
        $this->assertSame(0, $result->numRows, 'Revoked token must not appear as active');
    }

    // =========================================================================
    // Token introspection (RFC 7662)
    // =========================================================================

    /**
     * The introspection query must return an active flag and token metadata.
     *
     * Oauth::introspect() joins usertokens and applications to build the response
     * payload. The `active` field is TRUE when status=1 and expires > now().
     */
    public function testTokenIntrospectionReturnsActiveToken(): void
    {
        // Arrange
        $userId      = $this->insertUser('helen');
        $appId       = $this->insertApp('intro-client');
        $accessToken = bin2hex(random_bytes(32));
        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope,
                 expires, notes, deviceinfo)
             VALUES (%d, 'access_token', %s, %d, 1, %d, %s, %d, %s, %s)",
            $userId, $accessToken, time(), $appId, 'openid profile',
            time() + 3600, 'intro-client', '{}'
        );
        $this->db->query($sql);
        $this->trackToken((int) $this->db->getInsertId());

        // Act — replicate Oauth::introspect() SELECT JOIN
        $sql = $this->db->prepareQuery(
            "SELECT t.userid, t.scope, t.expires, t.status, a.apikey AS client_id
               FROM usertokens t
               JOIN applications a ON a.appid = t.applicationid
              WHERE t.token = %s
                AND t.status = 1
                AND (t.expires IS NULL OR t.expires > %d)",
            $accessToken,
            time()
        );
        $result = $this->db->query($sql);

        // Assert — active = true (row returned), metadata present
        $this->assertSame(1, $result->numRows, 'Active token must be found by introspection query');
        $this->assertSame((string) $userId, (string) $result->fields['userid']);
        $this->assertSame('openid profile', $result->fields['scope']);
    }

    /**
     * Introspecting a revoked token must return zero rows (active = false).
     *
     * A token with status=0 is considered inactive per RFC 7662. The introspection
     * response must have `active: false` in this case.
     */
    public function testTokenIntrospectionRevokedTokenIsInactive(): void
    {
        // Arrange — insert and immediately revoke a token (status=0)
        $userId      = $this->insertUser('ivan');
        $appId       = $this->insertApp('intro-revoked-client');
        $accessToken = bin2hex(random_bytes(32));
        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope,
                 expires, notes, deviceinfo)
             VALUES (%d, 'access_token', %s, %d, 0, %d, %s, %d, %s, %s)",
            $userId, $accessToken, time(), $appId, 'openid', time() + 3600, 'intro-revoked-client', '{}'
        );
        $this->db->query($sql);
        $this->trackToken((int) $this->db->getInsertId());

        // Act — same introspection query
        $sql = $this->db->prepareQuery(
            "SELECT t.tokenid FROM usertokens t
               JOIN applications a ON a.appid = t.applicationid
              WHERE t.token = %s
                AND t.status = 1
                AND (t.expires IS NULL OR t.expires > %d)",
            $accessToken, time()
        );
        $result = $this->db->query($sql);

        // Assert — no rows = inactive
        $this->assertSame(0, $result->numRows, 'Revoked token must be treated as inactive (active=false)');
    }

    // =========================================================================
    // JWT client credentials — system user deduplication (regression UW-461)
    // =========================================================================

    /**
     * Verifies that the applications.systemuser column exists and can be written
     * and read back.  This column is added by migration 000043.
     *
     * The regression (UW-461) was that handleJwtClientCredentials() created a new
     * sys_* user on every token request because it did not SELECT the existing
     * systemuser before inserting a new User row.  The fix adds a SELECT after
     * JWT validation so subsequent requests reuse the already-assigned account.
     */
    public function testSystemuserColumnExistsOnApplications(): void
    {
        // Arrange — insert an application (systemuser defaults to NULL)
        $appId = $this->insertApp('jwt-cc-client');

        // Act — write a synthetic system user id to the column
        $fakeSystemUserId = PHP_INT_MAX - random_int(1, 10000);
        $sql = $this->db->prepareQuery(
            "UPDATE applications SET systemuser = %d WHERE appid = %d",
            $fakeSystemUserId,
            $appId
        );
        $this->db->query($sql);

        // Assert — read it back
        $readSql = $this->db->prepareQuery(
            "SELECT systemuser FROM applications WHERE appid = %d",
            $appId
        );
        $result = $this->db->query($readSql);
        $this->assertSame(1, $result->numRows, 'Application row must be found');
        $this->assertSame(
            (string) $fakeSystemUserId,
            (string) $result->fields['systemuser'],
            'systemuser column must persist the written value'
        );
    }

    /**
     * The system user lookup logic must return the existing systemuser and
     * skip INSERT when the column is already set.
     *
     * Replicates the DB-side contract of handleJwtClientCredentials(): after
     * the first call sets systemuser, a subsequent SELECT must return that same
     * value so no second User INSERT is triggered.
     *
     * This is the core invariant that prevents duplicate sys_* users.
     */
    public function testSystemuserIsReusedOnSubsequentRequests(): void
    {
        // Arrange — application with a pre-assigned systemuser
        $appId      = $this->insertApp('jwt-cc-reuse-client');
        $sysUserId  = $this->insertUser('sys_reuse_test');

        $sql = $this->db->prepareQuery(
            "UPDATE applications SET systemuser = %d WHERE appid = %d",
            $sysUserId,
            $appId
        );
        $this->db->query($sql);

        // Act — replicate the SELECT in handleJwtClientCredentials() (first)
        $lookupSql = $this->db->prepareQuery(
            "SELECT systemuser FROM applications WHERE appid = %d AND status = 1",
            $appId
        );
        $result1 = $this->db->query($lookupSql);

        // Act — replicate again (second call, same application)
        $result2 = $this->db->query($lookupSql);

        // Assert — both lookups return the same userid, no new user was needed
        $this->assertSame(
            (string) $sysUserId,
            (string) $result1->fields['systemuser'],
            'First lookup must return the existing system user'
        );
        $this->assertSame(
            (string) $sysUserId,
            (string) $result2->fields['systemuser'],
            'Second lookup must return the same system user — no duplicate created'
        );
    }
}
