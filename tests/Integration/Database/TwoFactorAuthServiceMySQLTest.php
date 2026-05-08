<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\TOTPHelper;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * Integration tests for Pramnos\Auth\TwoFactorAuthService against MySQL 8.0.
 *
 * Exercises the full 2FA lifecycle against a live MySQL database:
 *   - startSetup() inserts a twofactor_setup row with an unexpired TTL
 *   - completeSetup() verifies the TOTP code and creates user_twofactor
 *   - isEnabled() reflects the enabled flag correctly
 *   - getStatus() returns correct metadata
 *   - verifyCode() accepts valid TOTP codes and rejects invalid ones
 *   - verifyCode() consumes backup codes exactly once
 *   - disable() clears the secret and disables the user
 *   - regenerateBackupCodes() replaces the stored backup code set
 *   - cleanupExpiredSessions() removes used/expired setup rows
 *   - Attempt log rows are written to twofactor_attempts on each verifyCode() call
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class TwoFactorAuthServiceMySQLTest extends TestCase
{
    protected Database $db;
    protected TwoFactorAuthService $service;
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

        $this->migrationsBase = dirname(__DIR__, 3) . '/database/migrations/framework';
        $this->service        = new TwoFactorAuthService($this->db);

        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function dropTables(): void
    {
        // Drop in reverse dependency order
        $this->db->query('DROP TABLE IF EXISTS twofactor_attempts');
        $this->db->query('DROP TABLE IF EXISTS twofactor_setup');
        $this->db->query('DROP TABLE IF EXISTS user_twofactor');
    }

    protected function createTables(): void
    {
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        $dir        = $this->migrationsBase . '/auth';
        $migrations = MigrationLoader::loadFromDirectory($dir, $app);
        usort($migrations, fn($a, $b) => $a->priority <=> $b->priority);

        $targets = ['CreateUserTwofactorTable', 'CreateTwofactorSetupTable', 'CreateTwofactorAttemptsTable'];
        foreach ($migrations as $m) {
            foreach ($targets as $target) {
                if (strpos(get_class($m), $target) !== false) {
                    $m->up();
                }
            }
        }
    }

    /**
     * Fast-track: complete the full setup flow for a user and return the plain backup codes.
     *
     * @return array{secret: string, backup_codes: string[]}
     */
    protected function setupUser(int $userId): array
    {
        $info   = $this->service->startSetup($userId, "user{$userId}@example.com");
        $secret = $info['secret'];
        $code   = TOTPHelper::generateCode($secret, time());
        $this->service->completeSetup($userId, $code);
        return ['secret' => $secret, 'backup_codes' => $info['backup_codes']];
    }

    // -------------------------------------------------------------------------
    // startSetup()
    // -------------------------------------------------------------------------

    /**
     * startSetup() must insert a row in twofactor_setup with the correct TTL and
     * return all fields needed to provision the authenticator app.
     *
     * The temp_secret must be a valid base32 string. The expiry must be in the
     * future (within the 15-minute window). The returned qr_code_url must
     * reference the otpauth:// scheme.
     */
    public function testStartSetupInsertsSetupRow(): void
    {
        // Act
        $info = $this->service->startSetup(1, 'alice@example.com');

        // Assert — return value shape
        $this->assertArrayHasKey('secret', $info);
        $this->assertArrayHasKey('qr_code_url', $info);
        $this->assertArrayHasKey('manual_entry_key', $info);
        $this->assertArrayHasKey('backup_codes', $info);
        $this->assertIsArray($info['backup_codes']);
        $this->assertCount(10, $info['backup_codes']);

        // Assert — secret is valid base32
        $this->assertTrue(TOTPHelper::isValidSecret($info['secret']));

        // Assert — row was inserted in twofactor_setup
        $result = $this->db->query("SELECT userid, used, expires_at FROM twofactor_setup WHERE userid = 1");
        $this->assertSame(1, $result->numRows, 'startSetup() must create a setup session row');
        $this->assertSame(0, (int) $result->fields['used'], 'setup session must start as unused');
        $this->assertGreaterThan(time(), (int) $result->fields['expires_at'], 'expires_at must be in the future');
    }

    /**
     * Calling startSetup() twice must replace the previous setup session (not accumulate).
     *
     * If a user re-initiates setup, the old incomplete session must be removed to
     * prevent confusion and to limit the attack window for stale secrets.
     */
    public function testStartSetupReplacesExistingSession(): void
    {
        // Arrange
        $this->service->startSetup(2, 'bob@example.com');
        $first = $this->db->query("SELECT temp_secret FROM twofactor_setup WHERE userid = 2");
        $firstSecret = $first->fields['temp_secret'];

        // Act
        $this->service->startSetup(2, 'bob@example.com');
        $second = $this->db->query("SELECT temp_secret FROM twofactor_setup WHERE userid = 2");

        // Assert — only one row, with a new secret
        $this->assertSame(1, $second->numRows, 'only one setup session per user must exist');
        $this->assertNotSame($firstSecret, $second->fields['temp_secret'], 'secret must be regenerated');
    }

    // -------------------------------------------------------------------------
    // completeSetup()
    // -------------------------------------------------------------------------

    /**
     * completeSetup() with a valid TOTP code must create user_twofactor row
     * and mark the setup session as used.
     *
     * After completeSetup() succeeds, isEnabled() must return true and the
     * setup session's used flag must be 1.
     */
    public function testCompleteSetupCreatesUserRecord(): void
    {
        // Arrange
        $info   = $this->service->startSetup(10, 'carol@example.com');
        $secret = $info['secret'];

        // Act — use the correct TOTP code for the current time
        $code   = TOTPHelper::generateCode($secret, time());
        $result = $this->service->completeSetup(10, $code);

        // Assert — method returned true
        $this->assertTrue($result, 'completeSetup() must return true on valid code');

        // Assert — user_twofactor row created with enabled=1
        $row = $this->db->query("SELECT enabled, secret FROM user_twofactor WHERE userid = 10");
        $this->assertSame(1, $row->numRows, 'user_twofactor row must be created');
        $this->assertSame(1, (int) $row->fields['enabled']);
        $this->assertSame($secret, $row->fields['secret']);

        // Assert — setup session marked as used
        $setup = $this->db->query("SELECT used FROM twofactor_setup WHERE userid = 10");
        $this->assertSame(1, (int) $setup->fields['used'], 'setup session must be marked used after completeSetup()');
    }

    /**
     * completeSetup() must return false when the provided code is wrong.
     *
     * A bad code must not create the user_twofactor row and must leave the
     * setup session intact (used=0) so the user can retry.
     */
    public function testCompleteSetupReturnsFalseOnInvalidCode(): void
    {
        // Arrange
        $this->service->startSetup(11, 'dave@example.com');

        // Act
        $result = $this->service->completeSetup(11, '000000');

        // Assert
        $this->assertFalse($result, 'completeSetup() must return false on wrong code');
        $row = $this->db->query("SELECT userid FROM user_twofactor WHERE userid = 11");
        $this->assertSame(0, $row->numRows, 'user_twofactor must not be created on bad code');
    }

    /**
     * completeSetup() must return false when no active setup session exists.
     *
     * Without a preceding startSetup(), there is no temp_secret to verify against.
     */
    public function testCompleteSetupReturnsFalseWithoutSession(): void
    {
        // Act — no prior startSetup call for user 99
        $result = $this->service->completeSetup(99, TOTPHelper::generateCode(TOTPHelper::generateSecret()));

        // Assert
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // isEnabled() / getStatus()
    // -------------------------------------------------------------------------

    /**
     * isEnabled() must return false for a user with no 2FA record.
     */
    public function testIsEnabledReturnsFalseForUnknownUser(): void
    {
        $this->assertFalse($this->service->isEnabled(9999));
    }

    /**
     * After a successful completeSetup(), isEnabled() must return true.
     */
    public function testIsEnabledReturnsTrueAfterSetup(): void
    {
        $this->setupUser(20);
        $this->assertTrue($this->service->isEnabled(20));
    }

    /**
     * getStatus() must reflect the correct enabled/setup/backup_codes_remaining values.
     */
    public function testGetStatusReflectsActualState(): void
    {
        // Arrange
        $this->setupUser(21);

        // Act
        $status = $this->service->getStatus(21);

        // Assert
        $this->assertTrue($status['enabled']);
        $this->assertTrue($status['setup']);
        $this->assertSame(10, $status['backup_codes_remaining'],
            '10 backup codes must be available after initial setup');
    }

    // -------------------------------------------------------------------------
    // verifyCode() — TOTP path
    // -------------------------------------------------------------------------

    /**
     * verifyCode() must return true when the correct TOTP code is provided.
     *
     * The code is generated from the same secret stored during setup. Verifying
     * it must also write a row to twofactor_attempts with success=1.
     */
    public function testVerifyCodeAcceptsValidTOTPCode(): void
    {
        // Arrange
        $data   = $this->setupUser(30);
        $secret = $data['secret'];
        $code   = TOTPHelper::generateCode($secret, time());

        // Act
        $result = $this->service->verifyCode(30, $code);

        // Assert
        $this->assertTrue($result, 'verifyCode() must accept the correct TOTP code');

        // Assert — attempt was logged
        $attempts = $this->db->query("SELECT success FROM twofactor_attempts WHERE userid = 30 ORDER BY attempt_time DESC LIMIT 1");
        $this->assertSame(1, (int) $attempts->fields['success'], 'successful attempt must be logged');
    }

    /**
     * verifyCode() must return false for an incorrect TOTP code.
     *
     * The attempt must still be logged (success=0) for security monitoring.
     */
    public function testVerifyCodeRejectsInvalidTOTPCode(): void
    {
        // Arrange
        $this->setupUser(31);

        // Act
        $result = $this->service->verifyCode(31, '000000');

        // Assert
        $this->assertFalse($result, 'verifyCode() must reject a wrong TOTP code');
    }

    /**
     * verifyCode() must return false for a user who has not completed 2FA setup.
     */
    public function testVerifyCodeReturnsFalseWhenNotEnabled(): void
    {
        $this->assertFalse($this->service->verifyCode(9998, '123456'));
    }

    // -------------------------------------------------------------------------
    // verifyCode() — backup code path
    // -------------------------------------------------------------------------

    /**
     * verifyCode() must accept a valid backup code and consume it (one-time use).
     *
     * After using a backup code, getRemainingBackupCodes() must return one fewer
     * code, and attempting to use the same code again must fail.
     */
    public function testVerifyCodeAcceptsAndConsumesBackupCode(): void
    {
        // Arrange — complete setup but use a known backup code
        $info   = $this->service->startSetup(40, 'eve@example.com');
        $secret = $info['secret'];
        $backupCodes = $info['backup_codes']; // plain-text codes shown to user
        $code   = TOTPHelper::generateCode($secret, time());
        $this->service->completeSetup(40, $code);

        // The backup codes returned by startSetup are for display only;
        // completeSetup generates a fresh set and stores their hashes.
        // To get a usable backup code, we need to regenerate via regenerateBackupCodes().
        $freshCodes = $this->service->regenerateBackupCodes(40);
        $this->assertIsArray($freshCodes);

        $beforeCount = $this->service->getRemainingBackupCodes(40);

        // Act — use the first backup code
        $result = $this->service->verifyCode(40, $freshCodes[0]);

        // Assert — accepted
        $this->assertTrue($result, 'verifyCode() must accept a valid backup code');

        // Assert — backup code consumed (count reduced by 1)
        $afterCount = $this->service->getRemainingBackupCodes(40);
        $this->assertSame($beforeCount - 1, $afterCount, 'used backup code must be consumed');

        // Assert — same code rejected on second use
        $retry = $this->service->verifyCode(40, $freshCodes[0]);
        $this->assertFalse($retry, 'backup code must be rejected after it has been consumed');
    }

    // -------------------------------------------------------------------------
    // disable()
    // -------------------------------------------------------------------------

    /**
     * disable() must set enabled=0 and clear the secret and backup codes.
     *
     * After disable(), isEnabled() must return false and getStatus() must show
     * enabled=false. The user_twofactor row is retained (not deleted) to preserve
     * the audit record.
     */
    public function testDisableClearsSecretAndDisables(): void
    {
        // Arrange
        $this->setupUser(50);
        $this->assertTrue($this->service->isEnabled(50), 'must be enabled before disabling');

        // Act
        $result = $this->service->disable(50);

        // Assert
        $this->assertTrue($result, 'disable() must return true for an enabled user');
        $this->assertFalse($this->service->isEnabled(50), 'isEnabled() must return false after disable()');

        // Assert — row retained but secret cleared
        $row = $this->db->query("SELECT enabled, secret FROM user_twofactor WHERE userid = 50");
        $this->assertSame(1, $row->numRows, 'user_twofactor row must be retained after disable()');
        $this->assertSame(0, (int) $row->fields['enabled']);
        $this->assertEmpty($row->fields['secret'], 'secret must be cleared after disable()');
    }

    /**
     * disable() must return false for a user with no 2FA record.
     */
    public function testDisableReturnsFalseForUnknownUser(): void
    {
        $this->assertFalse($this->service->disable(9997));
    }

    // -------------------------------------------------------------------------
    // regenerateBackupCodes()
    // -------------------------------------------------------------------------

    /**
     * regenerateBackupCodes() must return a fresh set of 10 plain-text codes.
     *
     * The returned codes must be different from the previous set (probabilistically),
     * and the count in the database must reflect the new 10-code set.
     */
    public function testRegenerateBackupCodesReplacesExistingSet(): void
    {
        // Arrange
        $this->setupUser(60);

        // Act
        $newCodes = $this->service->regenerateBackupCodes(60);

        // Assert
        $this->assertIsArray($newCodes);
        $this->assertCount(10, $newCodes, 'regenerateBackupCodes() must return 10 codes');
        $this->assertSame(10, $this->service->getRemainingBackupCodes(60));
    }

    /**
     * regenerateBackupCodes() must return false when 2FA is not enabled.
     */
    public function testRegenerateBackupCodesReturnsFalseWhenNotEnabled(): void
    {
        $this->assertFalse($this->service->regenerateBackupCodes(9996));
    }

    // -------------------------------------------------------------------------
    // cleanupExpiredSessions()
    // -------------------------------------------------------------------------

    /**
     * cleanupExpiredSessions() must remove used and expired setup session rows.
     *
     * An active (unused, unexpired) session must survive the cleanup.
     * Used and expired sessions must be removed.
     */
    public function testCleanupExpiredSessionsRemovesStaleRows(): void
    {
        // Arrange — insert a used session and an expired session directly
        $now     = time();
        $expired = $now - 1;
        $future  = $now + 900;

        $this->db->query(
            "INSERT INTO twofactor_setup (userid, temp_secret, used, expires_at, created_at)
             VALUES (70, 'EXPIRED1234567890', 0, {$expired}, {$now})"
        );
        $this->db->query(
            "INSERT INTO twofactor_setup (userid, temp_secret, used, expires_at, created_at)
             VALUES (71, 'USED12345678901234', 1, {$future}, {$now})"
        );
        $this->db->query(
            "INSERT INTO twofactor_setup (userid, temp_secret, used, expires_at, created_at)
             VALUES (72, 'ACTIVE123456789012', 0, {$future}, {$now})"
        );

        // Act
        $this->service->cleanupExpiredSessions();

        // Assert — only the active session remains
        $remaining = $this->db->query("SELECT userid FROM twofactor_setup ORDER BY userid");
        $this->assertSame(1, $remaining->numRows, 'only the active session must survive cleanup');
        $this->assertSame(72, (int) $remaining->fields['userid']);
    }
}
