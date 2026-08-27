<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\TOTPHelper;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * Integration tests for Pramnos\Auth\TwoFactorAuthService against PostgreSQL 14 / TimescaleDB.
 *
 * Mirrors TwoFactorAuthServiceMySQLTest but runs against the timescaledb container
 * (host: timescaledb, port: 5432). Each test runs in a separate process to avoid
 * the MySQL singleton being re-used for the PostgreSQL connection.
 *
 * On TimescaleDB, CreateTwofactorAttemptsTable converts the table to a hypertable
 * with 7-day chunks. The service tests are otherwise identical — the TimescaleDB
 * hypertable is transparent to DML.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
#[RunTestsInSeparateProcesses]
class TwoFactorAuthServicePostgreSQLTest extends TestCase
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
        // Tables live in the authserver schema on PostgreSQL
        $this->db->execute('DROP TABLE IF EXISTS authserver.twofactor_attempts CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS authserver.twofactor_setup CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS authserver.user_twofactor CASCADE');
    }

    protected function createTables(): void
    {
        // authserver schema must exist before any authserver.* tables can be created
        $this->db->execute('CREATE SCHEMA IF NOT EXISTS authserver');

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

    protected function setupUser(int $userId): array
    {
        $info   = $this->service->startSetup($userId, "user{$userId}@example.com");
        $secret = $info['secret'];
        $code   = TOTPHelper::generateCode($secret, time());
        $this->service->completeSetup($userId, $code);

        // The codes enrolment stored, which are the ones the user is given.
        // `startSetup()` used to return a set of its own and the setup screen
        // listed them — a different set from this one, already overwritten by
        // the time the page rendered.
        return ['secret' => $secret, 'backup_codes' => $this->service->takeNewBackupCodes()];
    }

    // -------------------------------------------------------------------------
    // startSetup()
    // -------------------------------------------------------------------------

    /**
     * startSetup() must insert a row in twofactor_setup with the correct TTL and
     * return all fields needed to provision the authenticator app.
     */
    public function testStartSetupInsertsSetupRow(): void
    {
        // Act
        $info = $this->service->startSetup(1, 'alice@example.com');

        // Assert — return value shape
        $this->assertArrayHasKey('secret', $info);
        $this->assertArrayHasKey('qr_code_url', $info);
        $this->assertTrue(TOTPHelper::isValidSecret($info['secret']));
        // No backup codes here, deliberately: they belong to enrolment, which
        // generates and stores its own. Returning a set from setup meant the
        // screen listed ten codes that could never work.
        $this->assertArrayNotHasKey('backup_codes', $info);

        // Assert — row was inserted
        $result = $this->db->query("SELECT userid, used, expires_at FROM authserver.twofactor_setup WHERE userid = 1");
        $this->assertSame(1, $result->numRows);
        $this->assertSame(0, (int) $result->fields['used']);
        $this->assertGreaterThan(time(), (int) $result->fields['expires_at']);
    }

    /**
     * Calling startSetup() twice must replace the previous setup session.
     */
    public function testStartSetupReplacesExistingSession(): void
    {
        // Arrange
        $this->service->startSetup(2, 'bob@example.com');
        $first       = $this->db->query("SELECT temp_secret FROM authserver.twofactor_setup WHERE userid = 2");
        $firstSecret = $first->fields['temp_secret'];

        // Act
        $this->service->startSetup(2, 'bob@example.com');
        $second = $this->db->query("SELECT temp_secret FROM authserver.twofactor_setup WHERE userid = 2");

        // Assert
        $this->assertSame(1, $second->numRows);
        $this->assertNotSame($firstSecret, $second->fields['temp_secret']);
    }

    // -------------------------------------------------------------------------
    // completeSetup()
    // -------------------------------------------------------------------------

    /**
     * completeSetup() with a valid TOTP code must create user_twofactor and mark
     * the setup session used.
     */
    public function testCompleteSetupCreatesUserRecord(): void
    {
        // Arrange
        $info   = $this->service->startSetup(10, 'carol@example.com');
        $code   = TOTPHelper::generateCode($info['secret'], time());

        // Act
        $result = $this->service->completeSetup(10, $code);

        // Assert
        $this->assertTrue($result);

        $row = $this->db->query("SELECT enabled, secret FROM authserver.user_twofactor WHERE userid = 10");
        $this->assertSame(1, $row->numRows);
        $this->assertSame(1, (int) $row->fields['enabled']);
        $this->assertSame($info['secret'], $row->fields['secret']);

        $setup = $this->db->query("SELECT used FROM authserver.twofactor_setup WHERE userid = 10");
        $this->assertSame(1, (int) $setup->fields['used']);
    }

    /**
     * completeSetup() must return false when the code is wrong.
     */
    public function testCompleteSetupReturnsFalseOnInvalidCode(): void
    {
        // Arrange
        $this->service->startSetup(11, 'dave@example.com');

        // Act
        $result = $this->service->completeSetup(11, '000000');

        // Assert
        $this->assertFalse($result);
        $row = $this->db->query("SELECT userid FROM authserver.user_twofactor WHERE userid = 11");
        $this->assertSame(0, $row->numRows);
    }

    /**
     * completeSetup() must return false when no setup session exists.
     */
    public function testCompleteSetupReturnsFalseWithoutSession(): void
    {
        $result = $this->service->completeSetup(99, '000000');
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // isEnabled() / getStatus()
    // -------------------------------------------------------------------------

    /**
     * isEnabled() must return false for a user with no record.
     */
    public function testIsEnabledReturnsFalseForUnknownUser(): void
    {
        $this->assertFalse($this->service->isEnabled(9999));
    }

    /**
     * isEnabled() must return true after a successful completeSetup().
     */
    public function testIsEnabledReturnsTrueAfterSetup(): void
    {
        $this->setupUser(20);
        $this->assertTrue($this->service->isEnabled(20));
    }

    /**
     * getStatus() must reflect enabled=true and backup_codes_remaining=10 after setup.
     */
    public function testGetStatusReflectsActualState(): void
    {
        $this->setupUser(21);
        $status = $this->service->getStatus(21);

        $this->assertTrue($status['enabled']);
        $this->assertTrue($status['setup']);
        $this->assertSame(10, $status['backup_codes_remaining']);
    }

    // -------------------------------------------------------------------------
    // verifyCode()
    // -------------------------------------------------------------------------

    /**
     * verifyCode() must return true for the correct TOTP code and log a success attempt.
     *
     * On TimescaleDB, the attempt_time column maps to TIMESTAMPTZ and the table is a
     * hypertable — the INSERT must succeed without error.
     */
    public function testVerifyCodeAcceptsValidTOTPCode(): void
    {
        // Arrange
        $data = $this->setupUser(30);
        $code = TOTPHelper::generateCode($data['secret'], time());

        // Act
        $result = $this->service->verifyCode(30, $code);

        // Assert
        $this->assertTrue($result);

        $attempts = $this->db->query("SELECT success FROM authserver.twofactor_attempts WHERE userid = 30 ORDER BY attempt_time DESC LIMIT 1");
        $this->assertSame(1, (int) $attempts->fields['success']);
    }

    /**
     * verifyCode() must return false for an incorrect TOTP code.
     */
    public function testVerifyCodeRejectsInvalidTOTPCode(): void
    {
        $this->setupUser(31);
        $result = $this->service->verifyCode(31, '000000');
        $this->assertFalse($result);
    }

    /**
     * verifyCode() must return false for a user who has not completed setup.
     */
    public function testVerifyCodeReturnsFalseWhenNotEnabled(): void
    {
        $this->assertFalse($this->service->verifyCode(9998, '123456'));
    }

    // -------------------------------------------------------------------------
    // verifyCode() — backup code
    // -------------------------------------------------------------------------

    /**
     * verifyCode() must accept a backup code and consume it.
     *
     * This verifies that backup code storage (JSON in TEXT column) and
     * verification work correctly on PostgreSQL.
     */
    public function testVerifyCodeAcceptsAndConsumesBackupCode(): void
    {
        // Arrange
        $info   = $this->service->startSetup(40, 'eve@example.com');
        $secret = $info['secret'];
        $this->service->completeSetup(40, TOTPHelper::generateCode($secret, time()));

        // The codes enrolment handed out. This used to have to regenerate to
        // obtain a usable code at all, because the set enrolment stored was
        // thrown away — the account's recovery codes were known to nobody, and
        // this test worked around it rather than reporting it.
        $freshCodes  = $this->service->takeNewBackupCodes();
        $this->assertCount(10, $freshCodes);
        $beforeCount = $this->service->getRemainingBackupCodes(40);

        // Act
        $result = $this->service->verifyCode(40, $freshCodes[0]);

        // Assert
        $this->assertTrue($result);
        $this->assertSame($beforeCount - 1, $this->service->getRemainingBackupCodes(40));

        // Second use rejected
        $this->assertFalse($this->service->verifyCode(40, $freshCodes[0]));
    }

    // -------------------------------------------------------------------------
    // Step-up: the password a caller collected must actually be checked
    // -------------------------------------------------------------------------

    /**
     * Disabling with a password that does not match is refused.
     *
     * The controller in front of this has always collected the account password
     * and passed it — and this method used to take one parameter, so PHP dropped
     * the argument and the check never happened. Any signed-in session could turn
     * 2FA off with an arbitrary password, and the controller's "That password is
     * not correct" branch was unreachable: `disable()` returned false only when
     * the account had no 2FA row at all.
     *
     * A stolen session cookie is exactly the case a second factor exists for, so
     * a step-up check that does not check is worth a test of its own.
     */
    public function testDisablingWithAWrongPasswordIsRefused(): void
    {
        // Arrange — an enrolled account. No `users` row exists for this id in
        // this schema, so no password can match, which is the point: a caller
        // that supplies one must be refused rather than waved through.
        $this->setupUser(70);
        $this->assertTrue($this->service->isEnabled(70));

        // Act
        $result = $this->service->disable(70, 'not-the-password');

        // Assert
        $this->assertFalse($result, 'a wrong password must not disable the second factor');
        $this->assertTrue($this->service->isEnabled(70), 'and 2FA must still be on');
    }

    /**
     * An empty password is wrong, not absent.
     *
     * `null` means "administrative call, no password to check". Collapsing the
     * two would make a form that submitted nothing pass the step-up check, which
     * is the easiest possible way through it.
     */
    public function testAnEmptyPasswordDoesNotCountAsNoPassword(): void
    {
        // Arrange
        $this->setupUser(71);

        // Act
        $result = $this->service->disable(71, '');

        // Assert
        $this->assertFalse($result);
        $this->assertTrue($this->service->isEnabled(71));
    }

    /**
     * Regenerating with a wrong password is refused, and leaves the codes alone.
     *
     * The same discarded argument. Rotating the codes both invalidates every code
     * the account's owner had written down and prints ten new ones to whoever
     * asked — so this is destructive as well as disclosing.
     */
    public function testRegeneratingWithAWrongPasswordIsRefused(): void
    {
        // Arrange
        $data = $this->setupUser(72);
        $before = $this->service->getRemainingBackupCodes(72);

        // Act
        $result = $this->service->regenerateBackupCodes(72, 'not-the-password');

        // Assert
        $this->assertFalse($result);
        $this->assertSame($before, $this->service->getRemainingBackupCodes(72));
        $this->assertTrue($this->service->verifyCode(72, $data['backup_codes'][0]),
            "and the owner's existing codes must still work");
    }

    /**
     * The administrative path still works: no password given, none checked.
     *
     * An operator clearing 2FA off an account whose owner cannot reach it has no
     * password to supply, and that has to stay possible — a fix that made every
     * call require one would lock out the recovery path it was protecting.
     */
    public function testTheAdministrativePathNeedsNoPassword(): void
    {
        // Arrange
        $this->setupUser(73);

        // Act
        $result = $this->service->disable(73);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($this->service->isEnabled(73));
    }

    /**
     * Enrolment hands over the codes it stored, once.
     *
     * They used to be generated, hashed, stored and dropped: the page enrolment
     * redirects to says "save your backup codes before leaving" and had none to
     * show, while the set displayed during setup was already overwritten. A user
     * who followed the instructions exactly held ten codes that could never work,
     * and found out the first time they lost their phone.
     */
    public function testEnrolmentHandsOverTheCodesItStored(): void
    {
        // Arrange
        $info = $this->service->startSetup(74, 'frank@example.com');
        $this->service->completeSetup(74, TOTPHelper::generateCode($info['secret'], time()));

        // Act
        $codes = $this->service->takeNewBackupCodes();

        // Assert — they are the stored ones, which is what makes them usable
        $this->assertCount(10, $codes);
        $this->assertTrue($this->service->verifyCode(74, $codes[0]),
            'a code handed out at enrolment must open the account');

        // …and only once: this is a show-once value
        $this->assertSame([], $this->service->takeNewBackupCodes());
    }

    // -------------------------------------------------------------------------
    // disable()
    // -------------------------------------------------------------------------

    /**
     * disable() must clear the secret, set enabled=0, and leave the row intact.
     */
    public function testDisableClearsSecretAndDisables(): void
    {
        // Arrange
        $this->setupUser(50);

        // Act
        $result = $this->service->disable(50);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($this->service->isEnabled(50));

        $row = $this->db->query("SELECT enabled, secret FROM authserver.user_twofactor WHERE userid = 50");
        $this->assertSame(1, $row->numRows);
        $this->assertSame(0, (int) $row->fields['enabled']);
        $this->assertEmpty($row->fields['secret']);
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
     * regenerateBackupCodes() must return 10 fresh codes and update the database.
     */
    public function testRegenerateBackupCodesReplacesExistingSet(): void
    {
        $this->setupUser(60);
        $newCodes = $this->service->regenerateBackupCodes(60);

        $this->assertIsArray($newCodes);
        $this->assertCount(10, $newCodes);
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
     * cleanupExpiredSessions() must remove used and expired setup session rows,
     * leaving active ones intact.
     */
    public function testCleanupExpiredSessionsRemovesStaleRows(): void
    {
        // Arrange
        $now     = time();
        $expired = $now - 1;
        $future  = $now + 900;

        $this->db->query("INSERT INTO authserver.twofactor_setup (userid, temp_secret, used, expires_at, created_at) VALUES (70, 'EXPIRED1234567890', 0, {$expired}, {$now})");
        $this->db->query("INSERT INTO authserver.twofactor_setup (userid, temp_secret, used, expires_at, created_at) VALUES (71, 'USED12345678901234', 1, {$future}, {$now})");
        $this->db->query("INSERT INTO authserver.twofactor_setup (userid, temp_secret, used, expires_at, created_at) VALUES (72, 'ACTIVE123456789012', 0, {$future}, {$now})");

        // Act
        $this->service->cleanupExpiredSessions();

        // Assert
        $remaining = $this->db->query("SELECT userid FROM authserver.twofactor_setup ORDER BY userid");
        $this->assertSame(1, $remaining->numRows);
        $this->assertSame(72, (int) $remaining->fields['userid']);
    }
}
