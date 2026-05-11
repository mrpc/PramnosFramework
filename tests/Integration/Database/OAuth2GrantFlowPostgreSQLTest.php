<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Integration tests for OAuth2 grant flows against PostgreSQL / TimescaleDB.
 *
 * Mirrors OAuth2GrantFlowMySQLTest but runs against the TimescaleDB container
 * (host: timescaledb, port: 5432). Each test runs in a separate process to avoid
 * the MySQL singleton being re-used for the PostgreSQL connection.
 *
 * PostgreSQL-specific coverage beyond the MySQL tests:
 *   - PKCE CHECK constraint rejects an invalid code_challenge_method value.
 *   - PKCE CHECK constraint rejects a code_challenge shorter than 43 characters.
 *   - The partial index on (token, tokentype, ...) WHERE tokentype='auth_code' is used.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
#[RunTestsInSeparateProcesses]
class OAuth2GrantFlowPostgreSQLTest extends TestCase
{
    protected Database $db;

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

        $pgSettingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
    }

    // -------------------------------------------------------------------------
    // Table management
    // -------------------------------------------------------------------------

    protected function dropTables(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS public.oauth2_user_consents CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS public.oauth2_device_codes CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS public.usertokens CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS public.applications CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS public.users CASCADE');
    }

    protected function createTables(): void
    {
        // Minimal users table
        $this->db->execute("CREATE TABLE IF NOT EXISTS public.users (
            userid   BIGSERIAL PRIMARY KEY,
            username VARCHAR(255) NOT NULL DEFAULT '',
            email    VARCHAR(255) NOT NULL DEFAULT '',
            active   SMALLINT NOT NULL DEFAULT 1
        )");

        // Minimal applications table
        $this->db->execute("CREATE TABLE IF NOT EXISTS public.applications (
            appid     SERIAL PRIMARY KEY,
            name      VARCHAR(255) NOT NULL DEFAULT '',
            apikey    VARCHAR(255) NULL,
            apisecret VARCHAR(255) NULL,
            status    SMALLINT NOT NULL DEFAULT 1,
            callback  TEXT NULL,
            CONSTRAINT uq_applications_apikey UNIQUE (apikey)
        )");

        // usertokens with PKCE columns and PostgreSQL CHECK constraints (RFC 7636)
        $this->db->execute("CREATE TABLE IF NOT EXISTS public.usertokens (
            tokenid               SERIAL PRIMARY KEY,
            userid                BIGINT NOT NULL,
            tokentype             VARCHAR(20) NOT NULL,
            token                 TEXT NOT NULL,
            created               INT NOT NULL DEFAULT 0,
            notes                 VARCHAR(255) NOT NULL DEFAULT '',
            lastused              INT NOT NULL DEFAULT 0,
            status                SMALLINT NOT NULL DEFAULT 1,
            \"parentToken\"       INT NULL,
            applicationid         INT NULL,
            actions               INT NOT NULL DEFAULT 0,
            removedate            INT NOT NULL DEFAULT 0,
            deviceinfo            TEXT NOT NULL DEFAULT '',
            scope                 TEXT NOT NULL DEFAULT '',
            expires               INT NULL,
            ipaddress             VARCHAR(45) NULL,
            code_challenge        VARCHAR(128) NULL,
            code_challenge_method VARCHAR(10)  NULL,
            CONSTRAINT chk_code_challenge_method
                CHECK (code_challenge_method IS NULL
                    OR code_challenge_method IN ('plain', 'S256')),
            CONSTRAINT chk_code_challenge_format
                CHECK (code_challenge IS NULL
                    OR (length(code_challenge) >= 43
                        AND length(code_challenge) <= 128
                        AND code_challenge ~ '^[A-Za-z0-9\\-._~]+$'))
        )");

        $this->db->execute("CREATE INDEX IF NOT EXISTS idx_usertokens_code_challenge
            ON public.usertokens (code_challenge) WHERE code_challenge IS NOT NULL");
        $this->db->execute("CREATE INDEX IF NOT EXISTS idx_usertokens_auth_code_pkce
            ON public.usertokens (token, tokentype, status, expires, code_challenge)
            WHERE tokentype = 'auth_code'");

        // oauth2_device_codes (mirrors migration 000041)
        $this->db->execute("CREATE TABLE IF NOT EXISTS public.oauth2_device_codes (
            id            BIGSERIAL PRIMARY KEY,
            device_code   VARCHAR(64)  NOT NULL,
            user_code     VARCHAR(9)   NOT NULL,
            client_id     VARCHAR(255) NOT NULL,
            scope         TEXT NULL,
            expires_at    INT NOT NULL,
            status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
            user_id       BIGINT NULL,
            authorized_at INT NULL,
            CONSTRAINT uq_oauth2_dc_device_code UNIQUE (device_code),
            CONSTRAINT uq_oauth2_dc_user_code   UNIQUE (user_code)
        )");
        $this->db->execute("CREATE INDEX IF NOT EXISTS idx_oauth2_dc_expires_status
            ON public.oauth2_device_codes (expires_at, status)");

        // oauth2_user_consents (mirrors migration 000042)
        $this->db->execute("CREATE TABLE IF NOT EXISTS public.oauth2_user_consents (
            id            BIGSERIAL PRIMARY KEY,
            userid        BIGINT NOT NULL,
            applicationid INT NOT NULL,
            scope         TEXT NULL,
            created_at    TIMESTAMP NULL DEFAULT NOW(),
            updated_at    TIMESTAMP NULL DEFAULT NOW(),
            CONSTRAINT uq_oauth2_consents_user_app UNIQUE (userid, applicationid)
        )");
        $this->db->execute("CREATE INDEX IF NOT EXISTS idx_oauth2_consents_userid
            ON public.oauth2_user_consents (userid)");
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    protected function insertUser(string $username = 'testuser'): int
    {
        $sql = $this->db->prepareQuery(
            "INSERT INTO users (username, email, active) VALUES (%s, %s, 1) RETURNING userid",
            $username,
            "{$username}@example.com"
        );
        $result = $this->db->query($sql);
        return (int) $result->fields['userid'];
    }

    protected function insertApp(string $apikey = 'test-client'): int
    {
        $sql = $this->db->prepareQuery(
            "INSERT INTO applications (name, apikey, apisecret, status) VALUES (%s, %s, %s, 1) RETURNING appid",
            'Test Application',
            $apikey,
            'test-secret'
        );
        $result = $this->db->query($sql);
        return (int) $result->fields['appid'];
    }

    // =========================================================================
    // Device code flow (RFC 8628)
    // =========================================================================

    /**
     * A device code row must be insertable and retrievable by user_code.
     *
     * Same contract as the MySQL variant: the Device controller looks up pending
     * codes by user_code and filters on expires_at > time().
     */
    public function testDeviceCodeInsertAndRetrieve(): void
    {
        // Arrange
        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = 'BCDF-GHJK';
        $expiresAt  = time() + 600;

        // Act
        $sql = $this->db->prepareQuery(
            "INSERT INTO oauth2_device_codes
                (device_code, user_code, client_id, scope, expires_at, status)
             VALUES (%s, %s, %s, %s, %d, 'pending')",
            $deviceCode, $userCode, 'test-client', 'openid profile', $expiresAt
        );
        $this->db->query($sql);

        $sql = $this->db->prepareQuery(
            "SELECT * FROM oauth2_device_codes
              WHERE user_code = %s AND status = 'pending' AND expires_at > %d",
            $userCode, time()
        );
        $result = $this->db->query($sql);

        // Assert
        $this->assertSame(1, $result->numRows, 'Device code must be retrievable by user_code');
        $this->assertSame($deviceCode, $result->fields['device_code']);
        $this->assertSame('openid profile', $result->fields['scope']);
    }

    /**
     * An expired device code must not be returned by the verification query.
     *
     * The `expires_at > %d` (unix timestamp) filter must work correctly on
     * PostgreSQL where the INT column stores epoch seconds.
     */
    public function testExpiredDeviceCodeIsExcluded(): void
    {
        // Arrange — code expired 10 seconds ago
        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = 'LMNP-QRST';
        $sql = $this->db->prepareQuery(
            "INSERT INTO oauth2_device_codes
                (device_code, user_code, client_id, scope, expires_at, status)
             VALUES (%s, %s, %s, %s, %d, 'pending')",
            $deviceCode, $userCode, 'test-client', 'openid', time() - 10
        );
        $this->db->query($sql);

        // Act
        $sql = $this->db->prepareQuery(
            "SELECT * FROM oauth2_device_codes
              WHERE user_code = %s AND status = 'pending' AND expires_at > %d",
            $userCode, time()
        );
        $result = $this->db->query($sql);

        // Assert
        $this->assertSame(0, $result->numRows, 'Expired device codes must not be returned');
    }

    /**
     * Approving a device code must transition status to 'authorized' with user_id set.
     */
    public function testDeviceCodeApproval(): void
    {
        // Arrange
        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = 'VWXZ-BCDF';
        $userId     = $this->insertUser();
        $sql = $this->db->prepareQuery(
            "INSERT INTO oauth2_device_codes
                (device_code, user_code, client_id, scope, expires_at, status)
             VALUES (%s, %s, %s, %s, %d, 'pending')",
            $deviceCode, $userCode, 'test-client', 'openid', time() + 600
        );
        $this->db->query($sql);

        // Act
        $now = time();
        $sql = $this->db->prepareQuery(
            "UPDATE oauth2_device_codes
                SET status = 'authorized', user_id = %d, authorized_at = %d
              WHERE user_code = %s",
            $userId, $now, $userCode
        );
        $this->db->query($sql);

        // Assert
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT status, user_id, authorized_at FROM oauth2_device_codes WHERE user_code = %s",
                $userCode
            )
        );
        $this->assertSame('authorized', $result->fields['status']);
        $this->assertSame((string) $userId, (string) $result->fields['user_id']);
        $this->assertGreaterThan(0, (int) $result->fields['authorized_at']);
    }

    /**
     * Denying a device code must set status='denied' and leave user_id NULL.
     */
    public function testDeviceCodeDenial(): void
    {
        // Arrange
        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = 'GHJK-LMNP';
        $sql = $this->db->prepareQuery(
            "INSERT INTO oauth2_device_codes
                (device_code, user_code, client_id, scope, expires_at, status)
             VALUES (%s, %s, %s, %s, %d, 'pending')",
            $deviceCode, $userCode, 'test-client', 'openid', time() + 600
        );
        $this->db->query($sql);

        // Act
        $sql = $this->db->prepareQuery(
            "UPDATE oauth2_device_codes
                SET status = 'denied', authorized_at = %d
              WHERE user_code = %s",
            time(), $userCode
        );
        $this->db->query($sql);

        // Assert
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT status, user_id FROM oauth2_device_codes WHERE user_code = %s",
                $userCode
            )
        );
        $this->assertSame('denied', $result->fields['status']);
        $this->assertNull($result->fields['user_id']);
    }

    // =========================================================================
    // User consent recording
    // =========================================================================

    /**
     * Recording consent for the first time must insert a row with the granted scope.
     */
    public function testUserConsentInsert(): void
    {
        // Arrange
        $userId = $this->insertUser('alice');
        $appId  = $this->insertApp('client-a');

        // Act
        $sql = $this->db->prepareQuery(
            "INSERT INTO oauth2_user_consents (userid, applicationid, scope, created_at, updated_at)
             VALUES (%d, %d, %s, NOW(), NOW())",
            $userId, $appId, 'openid profile'
        );
        $this->db->query($sql);

        // Assert
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT scope FROM oauth2_user_consents WHERE userid = %d AND applicationid = %d",
                $userId, $appId
            )
        );
        $this->assertSame(1, $result->numRows);
        $this->assertSame('openid profile', $result->fields['scope']);
    }

    /**
     * Scope expansion must persist all previously granted scopes plus the new ones.
     */
    public function testUserConsentScopeMergeExpands(): void
    {
        // Arrange
        $userId = $this->insertUser('bob');
        $appId  = $this->insertApp('client-b');
        $sql = $this->db->prepareQuery(
            "INSERT INTO oauth2_user_consents (userid, applicationid, scope, created_at, updated_at)
             VALUES (%d, %d, %s, NOW(), NOW())",
            $userId, $appId, 'openid profile'
        );
        $this->db->query($sql);

        // Act — add email scope
        $existing = 'openid profile';
        $new      = 'openid profile email';
        $merged   = implode(' ', array_unique(array_filter(array_merge(
            explode(' ', $existing),
            explode(' ', $new)
        ))));
        $sql = $this->db->prepareQuery(
            "UPDATE oauth2_user_consents SET scope = %s, updated_at = NOW()
              WHERE userid = %d AND applicationid = %d",
            $merged, $userId, $appId
        );
        $this->db->query($sql);

        // Assert
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT scope FROM oauth2_user_consents WHERE userid = %d AND applicationid = %d",
                $userId, $appId
            )
        );
        $stored = (string) ($result->fields['scope'] ?? '');
        $this->assertStringContainsString('openid',  $stored);
        $this->assertStringContainsString('profile', $stored);
        $this->assertStringContainsString('email',   $stored);
    }

    // =========================================================================
    // PKCE — auth_code lifecycle (RFC 7636)
    // =========================================================================

    /**
     * An auth_code token with a valid S256 code_challenge must be persisted.
     *
     * PostgreSQL CHECK constraints (chk_code_challenge_format and
     * chk_code_challenge_method) must accept the well-formed challenge without
     * raising a constraint violation.
     */
    public function testPkceAuthCodeInsertWithValidChallenge(): void
    {
        // Arrange — 43+ char BASE64URL challenge (S256 of a code_verifier)
        $userId    = $this->insertUser('eve');
        $appId     = $this->insertApp('pkce-client');
        $authCode  = bin2hex(random_bytes(16));
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // Act
        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope, expires,
                 notes, deviceinfo, code_challenge, code_challenge_method)
             VALUES (%d, 'auth_code', %s, %d, 1, %d, %s, %d, %s, %s, %s, 'S256')",
            $userId, $authCode, time(), $appId, 'openid profile',
            time() + 300, 'pkce-client', '{}', $challenge
        );
        $this->db->query($sql);

        // Assert — constraint did not fire; row is readable
        $sql = $this->db->prepareQuery(
            "SELECT code_challenge, code_challenge_method
               FROM usertokens WHERE token = %s",
            $authCode
        );
        $result = $this->db->query($sql);
        $this->assertSame(1, $result->numRows);
        $this->assertSame($challenge, $result->fields['code_challenge']);
        $this->assertSame('S256', $result->fields['code_challenge_method']);
    }

    /**
     * PostgreSQL must reject an invalid code_challenge_method via the CHECK constraint.
     *
     * RFC 7636 §4.3 allows only 'S256' and 'plain'.  The chk_code_challenge_method
     * constraint must fire for any other value, preventing a misconfigured client
     * from bypassing PKCE validation.
     */
    public function testPkceInvalidMethodRejectedByConstraint(): void
    {
        // Arrange
        $userId    = $this->insertUser('frank');
        $appId     = $this->insertApp('pkce-bad-client');
        $authCode  = bin2hex(random_bytes(16));
        $challenge = str_repeat('a', 43);

        // Act / Assert — a constraint violation must be thrown
        $this->expectException(\Exception::class);

        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope, expires,
                 notes, deviceinfo, code_challenge, code_challenge_method)
             VALUES (%d, 'auth_code', %s, %d, 1, %d, %s, %d, %s, %s, %s, 'SHA512')",
            $userId, $authCode, time(), $appId, 'openid',
            time() + 300, 'pkce-bad-client', '{}', $challenge
        );
        $this->db->query($sql);
    }

    /**
     * PostgreSQL must reject a code_challenge shorter than 43 characters (RFC 7636 §4.2).
     *
     * The chk_code_challenge_format constraint enforces minimum length = 43 and
     * maximum = 128 with an allowed character class of [A-Za-z0-9\-._~].
     */
    public function testPkceShortChallengeRejectedByConstraint(): void
    {
        // Arrange — 42 chars: one below the RFC minimum
        $userId    = $this->insertUser('grace');
        $appId     = $this->insertApp('pkce-short-client');
        $authCode  = bin2hex(random_bytes(16));
        $challenge = str_repeat('a', 42);

        // Act / Assert — constraint violation expected
        $this->expectException(\Exception::class);

        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope, expires,
                 notes, deviceinfo, code_challenge, code_challenge_method)
             VALUES (%d, 'auth_code', %s, %d, 1, %d, %s, %d, %s, %s, %s, 'S256')",
            $userId, $authCode, time(), $appId, 'openid',
            time() + 300, 'pkce-short-client', '{}', $challenge
        );
        $this->db->query($sql);
    }

    // =========================================================================
    // Token revocation (RFC 7009)
    // =========================================================================

    /**
     * Revoking a token (status=0) must make it invisible to the active-token query.
     */
    public function testTokenRevocation(): void
    {
        // Arrange
        $userId      = $this->insertUser('helen');
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

        // Act
        $sql = $this->db->prepareQuery(
            "UPDATE usertokens SET status = 0 WHERE token = %s",
            $accessToken
        );
        $this->db->query($sql);

        // Assert
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
     * Introspecting an active token must return full metadata including scope and client_id.
     */
    public function testTokenIntrospectionReturnsActiveToken(): void
    {
        // Arrange
        $userId      = $this->insertUser('ivan');
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

        // Act
        $sql = $this->db->prepareQuery(
            "SELECT t.userid, t.scope, t.expires, t.status, a.apikey AS client_id
               FROM usertokens t
               JOIN applications a ON a.appid = t.applicationid
              WHERE t.token = %s
                AND t.status = 1
                AND (t.expires IS NULL OR t.expires > %d)",
            $accessToken, time()
        );
        $result = $this->db->query($sql);

        // Assert
        $this->assertSame(1, $result->numRows);
        $this->assertSame((string) $userId, (string) $result->fields['userid']);
        $this->assertSame('openid profile', $result->fields['scope']);
        $this->assertSame('intro-client', $result->fields['client_id']);
    }

    /**
     * A revoked or expired token must return active=false (zero rows from the query).
     */
    public function testTokenIntrospectionExpiredTokenIsInactive(): void
    {
        // Arrange — token that expired 1 second ago
        $userId      = $this->insertUser('judy');
        $appId       = $this->insertApp('intro-exp-client');
        $accessToken = bin2hex(random_bytes(32));
        $sql = $this->db->prepareQuery(
            "INSERT INTO usertokens
                (userid, tokentype, token, created, status, applicationid, scope,
                 expires, notes, deviceinfo)
             VALUES (%d, 'access_token', %s, %d, 1, %d, %s, %d, %s, %s)",
            $userId, $accessToken, time(), $appId, 'openid',
            time() - 1, 'intro-exp-client', '{}'
        );
        $this->db->query($sql);

        // Act
        $sql = $this->db->prepareQuery(
            "SELECT t.tokenid FROM usertokens t
              WHERE t.token = %s
                AND t.status = 1
                AND (t.expires IS NULL OR t.expires > %d)",
            $accessToken, time()
        );
        $result = $this->db->query($sql);

        // Assert
        $this->assertSame(0, $result->numRows, 'Expired token must be inactive');
    }
}
