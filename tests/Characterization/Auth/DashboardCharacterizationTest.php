<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Dashboard;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Characterization tests for Dashboard controller QB migration.
 *
 * All private DB helper methods in Dashboard were refactored from
 * prepareQuery/query to the QueryBuilder API.  These tests verify that
 * the QB-generated SQL produces the same observable results as the
 * original raw SQL against a real MySQL database.
 *
 * Tables created inline (they are not in the basic User::setupDb() set
 * and may or may not exist depending on which migrations have run):
 *   - user_activity_log
 *   - user_privacy_settings   (column names match what the controller uses,
 *                               not the migration naming — pre-existing mismatch)
 *   - oauth2_user_consents
 *   - user_twofactor
 *   - twofactor_setup
 *
 * Runs on MySQL only.  The user_activity_log migration targets the
 * `authserver` PostgreSQL schema, which would require schema-qualified
 * table names in the controller to work on PG — a pre-existing issue.
 */
#[CoversClass(Dashboard::class)]
class DashboardCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    /** @var int[] user IDs created during a test; deleted in tearDown */
    private array $createdUserIds = [];

    /** @var int[] application IDs created during a test; deleted in tearDown */
    private array $createdAppIds = [];

    protected function setUp(): void
    {
        // Arrange — bootstrap MySQL application settings
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('DashboardCharacterizationTest runs on MySQL only.');
        }

        User::setupDb();
        $this->createHelperTables();
    }

    protected function tearDown(): void
    {
        // Remove test rows (preserve table structure for the next test in the same run)
        foreach ($this->createdUserIds as $uid) {
            $this->db->queryBuilder()->table('oauth2_user_consents')->where('userid', $uid)->delete();
            $this->db->queryBuilder()->table('user_activity_log')->where('userid', $uid)->delete();
            $this->db->queryBuilder()->table('user_privacy_settings')->where('userid', $uid)->delete();
            $this->db->queryBuilder()->table('user_twofactor')->where('userid', $uid)->delete();
            $this->db->queryBuilder()->table('twofactor_setup')->where('userid', $uid)->delete();
            $this->db->queryBuilder()->table('usertokens')->where('userid', $uid)->delete();
            $this->db->queryBuilder()->table('users')->where('userid', $uid)->delete();
        }
        foreach ($this->createdAppIds as $appId) {
            $this->db->queryBuilder()->table('applications')->where('appid', $appId)->delete();
        }
        $this->createdUserIds = [];
        $this->createdAppIds  = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create the helper tables that the Dashboard controller needs but that
     * may not exist in the test database (User::setupDb only creates the
     * core user tables).
     */
    private function createHelperTables(): void
    {
        $p = $this->db->prefix;

        // user_activity_log — column names match what Dashboard::getActivityLog() selects
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}user_activity_log` (
                `id`         bigint AUTO_INCREMENT PRIMARY KEY,
                `userid`     bigint NOT NULL,
                `action`     varchar(100) NOT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ip_address` varchar(45) DEFAULT NULL,
                `user_agent` text DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // user_privacy_settings — columns match Dashboard::getPrivacySettings() / privacy() POST
        // NOTE: the framework migration uses different column names (share_usage_analytics,
        // marketing_emails). The controller was written with analytics_consent / marketing_consent.
        // This table is created with the controller's expected names for test isolation.
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}user_privacy_settings` (
                `userid`            bigint NOT NULL PRIMARY KEY,
                `analytics_consent` tinyint NOT NULL DEFAULT 0,
                `marketing_consent` tinyint NOT NULL DEFAULT 0,
                `updated_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // oauth2_user_consents — columns match Dashboard::revokeapplication()
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}oauth2_user_consents` (
                `id`            bigint AUTO_INCREMENT PRIMARY KEY,
                `userid`        bigint NOT NULL,
                `applicationid` int NOT NULL,
                `scope`         text DEFAULT NULL,
                `created_at`    datetime DEFAULT NULL,
                `updated_at`    datetime DEFAULT NULL,
                UNIQUE KEY `uq_oauth2_consents_user_app` (`userid`, `applicationid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // user_twofactor — columns match Dashboard::isTwoFactorEnabled()
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}user_twofactor` (
                `userid`  bigint NOT NULL PRIMARY KEY,
                `secret`  varchar(255) DEFAULT NULL,
                `enabled` tinyint NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // twofactor_setup — referenced by Dashboard::eraseUserData()
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}twofactor_setup` (
                `id`        bigint AUTO_INCREMENT PRIMARY KEY,
                `userid`    bigint NOT NULL,
                `secret`    varchar(255) DEFAULT NULL,
                `created_at` datetime DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * Create a minimal test user and return its userid.
     */
    private function makeUser(string $suffix = ''): int
    {
        $username = 'dash_' . bin2hex(random_bytes(4)) . $suffix;
        $user = new User();
        $user->username  = $username;
        $user->email     = $username . '@example.com';
        $user->setPassword('Secr3t!pass');
        $user->save();
        $uid = (int) $user->userid;
        $this->createdUserIds[] = $uid;
        return $uid;
    }

    /**
     * Insert a minimal row into `applications` and return the appid.
     */
    private function makeApp(string $name, string $apikey, int $status = 1): int
    {
        $this->db->queryBuilder()
            ->table('applications')
            ->insert(['name' => $name, 'apikey' => $apikey, 'apisecret' => '', 'status' => $status]);

        $result = $this->db->queryBuilder()
            ->table('applications')
            ->select(['appid'])
            ->where('apikey', $apikey)
            ->first();

        $appId = (int) $result->fields['appid'];
        $this->createdAppIds[] = $appId;
        return $appId;
    }

    /**
     * Call a private method on Dashboard via reflection.
     */
    private function callPrivate(Dashboard $dashboard, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(Dashboard::class, $method);
        return $ref->invoke($dashboard, ...$args);
    }

    // ── Tests — getAuthorizedApplications ────────────────────────────────────

    /**
     * getAuthorizedApplications() must return a row for every application
     * that has at least one active, non-expired usertokens row for the given user.
     *
     * Tests the QB JOIN + DISTINCT + GROUP BY + MAX/COUNT logic.
     */
    public function testGetAuthorizedApplicationsReturnsActiveApps(): void
    {
        // Arrange
        $userId = $this->makeUser();
        $appId  = $this->makeApp('Test App QB', 'apikey_qa_' . bin2hex(random_bytes(4)));

        $now     = time();
        $expires = $now + 3600;

        // Insert two active tokens for the same app (proves COUNT and GROUP BY work)
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid'        => $userId,
            'tokentype'     => 'oauth2',
            'token'         => 'tok_' . bin2hex(random_bytes(6)),
            'applicationid' => $appId,
            'status'        => 1,
            'lastused'      => $now - 60,
            'expires'       => $expires,
            'notes'         => '',
        ]);
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid'        => $userId,
            'tokentype'     => 'oauth2',
            'token'         => 'tok_' . bin2hex(random_bytes(6)),
            'applicationid' => $appId,
            'status'        => 1,
            'lastused'      => $now,
            'expires'       => $expires,
            'notes'         => '',
        ]);

        // Act
        $dashboard = new Dashboard();
        $apps = $this->callPrivate($dashboard, 'getAuthorizedApplications', $userId);

        // Assert — exactly one row for the one application
        $this->assertCount(1, $apps, 'GROUP BY must collapse two tokens for one app into one row');

        $row = $apps[0];
        $this->assertSame($appId,    (int)    $row['appid'],       'appid must match');
        $this->assertSame('Test App QB', (string) $row['name'],    'name must match');
        $this->assertSame(2,         (int)    $row['token_count'], 'COUNT must return 2 for two tokens');
        $this->assertSame($now,      (int)    $row['last_used'],   'MAX(lastused) must be the most recent timestamp');
    }

    /**
     * getAuthorizedApplications() must NOT return apps whose tokens are expired
     * (expires != 0 AND expires <= now) or revoked (status != 1).
     *
     * This tests the nested OR condition (expires = 0 OR expires > now).
     */
    public function testGetAuthorizedApplicationsExcludesExpiredAndRevoked(): void
    {
        // Arrange
        $userId     = $this->makeUser();
        $expiredApp = $this->makeApp('Expired App', 'exp_key_' . bin2hex(random_bytes(4)));
        $revokedApp = $this->makeApp('Revoked App', 'rev_key_' . bin2hex(random_bytes(4)));

        // Token expired in the past
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid'        => $userId, 'tokentype' => 'oauth2',
            'token'         => 'tok_exp_' . bin2hex(random_bytes(4)),
            'applicationid' => $expiredApp, 'status' => 1,
            'lastused'      => time() - 7200, 'expires' => time() - 3600, 'notes' => '',
        ]);
        // Token status 3 = revoked
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid'        => $userId, 'tokentype' => 'oauth2',
            'token'         => 'tok_rev_' . bin2hex(random_bytes(4)),
            'applicationid' => $revokedApp, 'status' => 3,
            'lastused'      => time(), 'expires' => time() + 3600, 'notes' => '',
        ]);

        // Act
        $dashboard = new Dashboard();
        $apps = $this->callPrivate($dashboard, 'getAuthorizedApplications', $userId);

        // Assert — neither expired nor revoked tokens must appear
        $appIds = array_column($apps, 'appid');
        $this->assertNotContains($expiredApp, $appIds, 'Expired token must be excluded');
        $this->assertNotContains($revokedApp, $appIds, 'Revoked token must be excluded');
    }

    // ── Tests — getActivityLog ────────────────────────────────────────────────

    /**
     * getActivityLog() must return rows ordered newest-first, limited to N rows.
     *
     * Tests the QB ORDER BY + LIMIT combination.
     */
    public function testGetActivityLogReturnsOrderedAndLimited(): void
    {
        // Arrange
        $userId = $this->makeUser();

        // Insert 5 rows with distinct timestamps so ordering is deterministic
        $base = time() - 1000;
        for ($i = 0; $i < 5; $i++) {
            $this->db->queryBuilder()->table('user_activity_log')->insert([
                'userid'     => $userId,
                'action'     => "action_{$i}",
                'created_at' => date('Y-m-d H:i:s', $base + ($i * 100)),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'TestAgent',
            ]);
        }

        // Act — request at most 3 rows
        $dashboard = new Dashboard();
        $log = $this->callPrivate($dashboard, 'getActivityLog', $userId, 3);

        // Assert
        $this->assertCount(3, $log, 'LIMIT 3 must return exactly 3 rows');
        // Newest first: action_4 (timestamp +400) must be the first row
        $this->assertSame('action_4', $log[0]['action'], 'ORDER BY created_at DESC must put newest row first');
        $this->assertSame('action_3', $log[1]['action']);
        $this->assertSame('action_2', $log[2]['action']);
    }

    // ── Tests — isTwoFactorEnabled ────────────────────────────────────────────

    /**
     * isTwoFactorEnabled() returns true only when a user_twofactor row
     * exists with enabled = 1.
     *
     * Tests the QB single-row SELECT + bool coercion.
     */
    public function testIsTwoFactorEnabledReturnsTrueOnlyWhenEnabled(): void
    {
        // Arrange
        $userId = $this->makeUser();

        // Act — no row yet
        $dashboard = new Dashboard();
        $result = $this->callPrivate($dashboard, 'isTwoFactorEnabled', $userId);

        // Assert
        $this->assertFalse($result, 'Must return false when no row exists');

        // Arrange — insert row with enabled = 0
        $this->db->queryBuilder()->table('user_twofactor')
            ->insert(['userid' => $userId, 'secret' => 'base32abc', 'enabled' => 0]);

        // Act
        $result = $this->callPrivate($dashboard, 'isTwoFactorEnabled', $userId);

        // Assert — enabled = 0 must still be false
        $this->assertFalse($result, 'enabled = 0 must return false');

        // Arrange — update to enabled = 1
        $this->db->queryBuilder()->table('user_twofactor')
            ->where('userid', $userId)
            ->update(['enabled' => 1]);

        // Act
        $result = $this->callPrivate($dashboard, 'isTwoFactorEnabled', $userId);

        // Assert
        $this->assertTrue($result, 'enabled = 1 must return true');
    }

    // ── Tests — getPrivacySettings ────────────────────────────────────────────

    /**
     * getPrivacySettings() must return boolean defaults when no row exists,
     * and persisted values when a row does exist.
     *
     * Tests the QB SELECT + default-fallback logic.
     */
    public function testGetPrivacySettingsReturnsDefaultsAndPersistedValues(): void
    {
        // Arrange
        $userId = $this->makeUser();

        // Act — no row yet → defaults
        $dashboard = new Dashboard();
        $settings = $this->callPrivate($dashboard, 'getPrivacySettings', $userId);

        // Assert
        $this->assertFalse($settings['analytics'], 'Default analytics must be false');
        $this->assertFalse($settings['marketing'],  'Default marketing must be false');

        // Arrange — insert a row with both consents true
        $this->db->queryBuilder()->table('user_privacy_settings')->insert([
            'userid'            => $userId,
            'analytics_consent' => 1,
            'marketing_consent' => 1,
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        // Act
        $settings = $this->callPrivate($dashboard, 'getPrivacySettings', $userId);

        // Assert
        $this->assertTrue($settings['analytics'], 'Persisted analytics_consent = 1 must return true');
        $this->assertTrue($settings['marketing'],  'Persisted marketing_consent = 1 must return true');
    }

    // ── Tests — verifyUserPassword ────────────────────────────────────────────

    /**
     * verifyUserPassword() must return true for the correct bcrypt password
     * and false for wrong password or inactive user.
     *
     * Tests the QB SELECT + password_verify() branch.
     * Note: the users table has no `salt` column; the legacy SHA-256+salt
     * path was removed as a pre-existing bug fix (the column was dropped
     * from the schema).
     */
    public function testVerifyUserPasswordBcryptBranch(): void
    {
        // Arrange — create user with known password
        $userId = $this->makeUser();

        // Update the user to be active with known bcrypt hash
        $hash = password_hash('Secr3t!pass', PASSWORD_BCRYPT);
        $this->db->queryBuilder()
            ->table('users')
            ->where('userid', $userId)
            ->update(['password' => $hash, 'active' => 1]);

        // Act — correct password
        $dashboard = new Dashboard();
        $result = $this->callPrivate($dashboard, 'verifyUserPassword', $userId, 'Secr3t!pass');

        // Assert
        $this->assertTrue($result, 'Correct password must verify true');

        // Act — wrong password
        $result = $this->callPrivate($dashboard, 'verifyUserPassword', $userId, 'wrongpass');

        // Assert
        $this->assertFalse($result, 'Wrong password must return false');

        // Arrange — deactivate the user
        $this->db->queryBuilder()
            ->table('users')
            ->where('userid', $userId)
            ->update(['active' => 0]);

        // Act — correct password but inactive user
        $result = $this->callPrivate($dashboard, 'verifyUserPassword', $userId, 'Secr3t!pass');

        // Assert — active = 0 row is not returned by the WHERE active = 1 clause
        $this->assertFalse($result, 'Inactive user must not verify even with correct password');
    }

    // ── Tests — updatePassword ────────────────────────────────────────────────

    /**
     * updatePassword() must store a new bcrypt hash that verifies correctly
     * and update the modified timestamp.
     *
     * Tests the QB UPDATE with raw NOW() expression.
     */
    public function testUpdatePasswordPersistsNewHash(): void
    {
        // Arrange
        $userId = $this->makeUser();
        $this->db->queryBuilder()
            ->table('users')
            ->where('userid', $userId)
            ->update(['active' => 1]);

        // Act
        $dashboard = new Dashboard();
        $this->callPrivate($dashboard, 'updatePassword', $userId, 'N3wP@ssword!');

        // Assert — load the new hash directly from DB
        $result = $this->db->queryBuilder()
            ->table('users')
            ->select(['password'])
            ->where('userid', $userId)
            ->first();

        $this->assertNotNull($result, 'Row must still exist after update');
        $storedHash = (string) $result->fields['password'];
        $this->assertTrue(
            password_verify('N3wP@ssword!', $storedHash),
            'Newly stored hash must verify against the new password'
        );
        $this->assertFalse(
            password_verify('Secr3t!pass', $storedHash),
            'Old password must no longer verify'
        );
    }

    // ── Tests — eraseUserData ─────────────────────────────────────────────────

    /**
     * eraseUserData() must delete rows for the given userid from all 7 tables
     * (6 helper tables + users) and leave other users' rows untouched.
     *
     * Tests the QB loop-DELETE across multiple tables.
     */
    public function testEraseUserDataDeletesAllRowsForUser(): void
    {
        // Arrange
        $targetId  = $this->makeUser('_target');
        $survivorId = $this->makeUser('_survivor');

        // The target user's rows in helper tables
        $appId = $this->makeApp('EraseTestApp', 'erase_key_' . bin2hex(random_bytes(4)));

        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid' => $targetId, 'tokentype' => 'oauth2', 'status' => 1,
            'token' => 'tok_' . bin2hex(random_bytes(4)), 'applicationid' => $appId,
            'lastused' => time(), 'expires' => 0, 'notes' => '',
        ]);
        $this->db->queryBuilder()->table('oauth2_user_consents')->insert([
            'userid' => $targetId, 'applicationid' => $appId,
        ]);
        $this->db->queryBuilder()->table('user_activity_log')->insert([
            'userid' => $targetId, 'action' => 'login',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->queryBuilder()->table('user_privacy_settings')->insert([
            'userid' => $targetId, 'analytics_consent' => 1, 'marketing_consent' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->queryBuilder()->table('user_twofactor')->insert([
            'userid' => $targetId, 'secret' => 'abc', 'enabled' => 1,
        ]);
        $this->db->queryBuilder()->table('twofactor_setup')->insert([
            'userid' => $targetId, 'secret' => 'def',
        ]);

        // Also add a token for the survivor so we can verify it's untouched
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid' => $survivorId, 'tokentype' => 'oauth2', 'status' => 1,
            'token' => 'tok_surv_' . bin2hex(random_bytes(4)), 'applicationid' => $appId,
            'lastused' => time(), 'expires' => 0, 'notes' => '',
        ]);

        // Remove targetId from tearDown cleanup (eraseUserData deletes the users row)
        $this->createdUserIds = array_filter($this->createdUserIds, fn($id) => $id !== $targetId);

        // Act
        $dashboard = new Dashboard();
        $this->callPrivate($dashboard, 'eraseUserData', $targetId);

        // Assert — target's rows are gone from all tables
        $this->assertSame(
            0,
            $this->db->queryBuilder()->table('users')->where('userid', $targetId)->count(),
            'users row must be deleted'
        );
        $this->assertSame(
            0,
            $this->db->queryBuilder()->table('usertokens')->where('userid', $targetId)->count(),
            'usertokens rows must be deleted'
        );
        $this->assertSame(
            0,
            $this->db->queryBuilder()->table('oauth2_user_consents')->where('userid', $targetId)->count(),
            'oauth2_user_consents rows must be deleted'
        );
        $this->assertSame(
            0,
            $this->db->queryBuilder()->table('user_activity_log')->where('userid', $targetId)->count(),
            'user_activity_log rows must be deleted'
        );
        $this->assertSame(
            0,
            $this->db->queryBuilder()->table('user_privacy_settings')->where('userid', $targetId)->count(),
            'user_privacy_settings rows must be deleted'
        );
        $this->assertSame(
            0,
            $this->db->queryBuilder()->table('user_twofactor')->where('userid', $targetId)->count(),
            'user_twofactor rows must be deleted'
        );
        $this->assertSame(
            0,
            $this->db->queryBuilder()->table('twofactor_setup')->where('userid', $targetId)->count(),
            'twofactor_setup rows must be deleted'
        );

        // Assert — survivor's token is still there (blast radius check)
        $this->assertSame(
            1,
            $this->db->queryBuilder()->table('usertokens')->where('userid', $survivorId)->count(),
            'Survivor tokens must be untouched'
        );
    }
}
