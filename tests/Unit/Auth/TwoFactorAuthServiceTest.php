<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Auth\TOTPHelper;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * Unit tests for Pramnos\Auth\TwoFactorAuthService — edge-case paths.
 *
 * The integration tests (TwoFactorAuthServiceMySQLTest / PostgreSQLTest) cover
 * the full 2FA lifecycle against a live database.  These unit tests focus on the
 * pure-logic branches that are not exercised by the happy-path integration flow:
 *
 *   - verifyCode() early-returns when 2FA is not enabled for the user
 *   - verifyCode() early-returns when the secret is absent / blank
 *   - verifyCode() handles the replay-attack guard (isRecentlyUsed = true)
 *   - getRemainingBackupCodes() returns 0 when backup_codes contains invalid JSON
 *   - getRemainingBackupCodes() returns 0 when the user has no user_twofactor row
 *   - getStatus() reports setup=false and 0 backup codes when 2FA is disabled
 *   - disable() returns false when the user has no user_twofactor row
 *   - regenerateBackupCodes() returns false when 2FA is not enabled
 *   - cleanupExpiredSessions() executes the delete query without error
 *
 * All tests use constructor injection (new TwoFactorAuthService($db)) with a
 * mock database so no Docker container is required.  Where the method under
 * test calls other public methods of the same class (isEnabled, getSecret),
 * those are overridden in an anonymous sub-class to keep each test focused.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(TwoFactorAuthService::class)]
class TwoFactorAuthServiceTest extends TestCase
{
    // =========================================================================
    // Infrastructure
    // =========================================================================

    /**
     * Build a fluent QueryBuilder mock that returns itself for every chaining
     * method and returns $terminalResult for first()/get()/insert()/update()/delete().
     *
     * @param object|null $firstResult  Value returned by first() — null means void/void
     * @param object|null $getResult    Value returned by get()
     */
    private function buildQb(
        ?object $firstResult = null,
        ?object $getResult   = null
    ): QueryBuilder {
        $qb = $this->createMock(QueryBuilder::class);

        // All chaining methods return $qb
        foreach (['table', 'select', 'where', 'orWhere', 'whereIn', 'orderBy', 'limit', 'offset'] as $method) {
            $qb->method($method)->willReturn($qb);
        }

        if ($firstResult !== null) {
            $qb->method('first')->willReturn($firstResult);
        }
        if ($getResult !== null) {
            $qb->method('get')->willReturn($getResult);
        }

        // insert/update/delete return null (void in practice)
        $qb->method('insert')->willReturn(null);
        $qb->method('update')->willReturn(null);
        $qb->method('delete')->willReturn(null);

        return $qb;
    }

    /** Anonymous result with numRows = 0 and no fields. */
    private function emptyResult(): object
    {
        return new class {
            public int   $numRows = 0;
            public array $fields  = [];
        };
    }

    /** Anonymous result with numRows = 1 and the given fields. */
    private function rowResult(array $fields): object
    {
        return new class($fields) {
            public int   $numRows = 1;
            public array $fields;
            public function __construct(array $f) { $this->fields = $f; }
        };
    }

    /**
     * Build a Database mock whose queryBuilder() always returns the given QB.
     */
    private function buildDb(QueryBuilder $qb): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($qb);
        return $db;
    }

    // =========================================================================
    // verifyCode() — guard paths
    // =========================================================================

    /**
     * verifyCode() must return false immediately when 2FA is not enabled for
     * the user, without making any further DB queries.
     *
     * The guard at line 271 in TwoFactorAuthService ("if (!$this->isEnabled(...))
     * return false") is the first line of verifyCode(). Coverage requires
     * isEnabled() to return false so the early-return path is taken.
     */
    public function testVerifyCodeReturnsFalseWhenNotEnabled(): void
    {
        // Arrange — subclass overrides isEnabled() to return false; inject a mock
        // DB so the constructor does not call Factory::getDatabase()
        $db      = $this->createMock(Database::class);
        $service = new class($db) extends TwoFactorAuthService {
            public function isEnabled(int $userId): bool { return false; }
        };

        // Act
        $result = $service->verifyCode(1, '123456');

        // Assert
        $this->assertFalse($result,
            'verifyCode must return false immediately when 2FA is not enabled');
    }

    /**
     * verifyCode() must return false when the user is enabled but has no stored
     * secret (null or empty string from getSecret()).
     *
     * This covers the guard at line 276–277: "if (!$secret) return false".
     */
    public function testVerifyCodeReturnsFalseWhenSecretIsNull(): void
    {
        // Arrange — enabled=true but secret=null; inject mock DB to bypass constructor
        $db      = $this->createMock(Database::class);
        $service = new class($db) extends TwoFactorAuthService {
            public function isEnabled(int $userId): bool    { return true; }
            public function getSecret(int $userId): ?string { return null; }
        };

        // Act
        $result = $service->verifyCode(1, '123456');

        // Assert
        $this->assertFalse($result,
            'verifyCode must return false when no secret is stored for the user');
    }

    /**
     * verifyCode() must return false and log a failed attempt when a valid TOTP
     * code is submitted but the current 30-second window was already consumed
     * (replay-attack protection, lines 282–284).
     *
     * We use a real TOTP secret + current-time code so that TOTPHelper::verifyCode()
     * returns true, then stub isRecentlyUsed() to return true via DB injection.
     */
    public function testVerifyCodeReturnsFalseOnReplayAttack(): void
    {
        // Arrange — generate a valid secret and current code
        $secret = TOTPHelper::generateSecret();
        $code   = TOTPHelper::generateCode($secret, time());

        // DB needs to handle:
        //   isEnabled()        → select enabled where userid   → enabled=1
        //   getSecret()        → select secret  where userid   → secret=$secret
        //   isRecentlyUsed()   → select last_used where userid → last_used=now (same window)
        //   logAttempt()       → insert into twofactor_attempts (fire and forget)

        $now         = time();
        $enabledRow  = $this->rowResult(['enabled' => 1]);
        $secretRow   = $this->rowResult(['secret'  => $secret]);
        $lastUsedRow = $this->rowResult(['last_used' => $now]);  // same 30-s window

        // Use willReturnOnConsecutiveCalls so each first() call gets the right row
        $qb = $this->createMock(QueryBuilder::class);
        foreach (['table', 'select', 'where', 'orWhere', 'orderBy', 'limit', 'offset'] as $m) {
            $qb->method($m)->willReturn($qb);
        }
        $qb->method('first')->willReturnOnConsecutiveCalls(
            $enabledRow,   // isEnabled()
            $secretRow,    // getSecret()
            $lastUsedRow   // isRecentlyUsed()
        );
        $qb->method('insert')->willReturn(null);
        $qb->method('update')->willReturn(null);
        $qb->method('delete')->willReturn(null);

        $db      = $this->buildDb($qb);
        $service = new TwoFactorAuthService($db);

        // Act
        $result = $service->verifyCode(1, $code);

        // Assert — replay must be rejected even though the code is cryptographically valid
        $this->assertFalse($result,
            'verifyCode must return false when the 30-second window was already used');
    }

    // =========================================================================
    // getRemainingBackupCodes() — invalid JSON path
    // =========================================================================

    /**
     * getRemainingBackupCodes() must return 0 when the stored backup_codes
     * value is not valid JSON.
     *
     * This covers the defensive branch at line 120:
     * "return is_array($codes) ? count($codes) : 0"
     * when json_decode() returns null for corrupt data.
     */
    public function testGetRemainingBackupCodesReturnsZeroForInvalidJson(): void
    {
        // Arrange — DB returns a row with corrupt JSON in backup_codes
        $qb = $this->buildQb(firstResult: $this->rowResult(['backup_codes' => 'not-valid-json']));
        $db = $this->buildDb($qb);
        $service = new TwoFactorAuthService($db);

        // Act
        $count = $service->getRemainingBackupCodes(1);

        // Assert
        $this->assertSame(0, $count,
            'getRemainingBackupCodes must return 0 when backup_codes is not valid JSON');
    }

    /**
     * getRemainingBackupCodes() must return 0 when the user has no user_twofactor row.
     *
     * This covers the early-return at line 115–116 when numRows === 0.
     */
    public function testGetRemainingBackupCodesReturnsZeroWhenNoRow(): void
    {
        // Arrange — DB returns no row for the user
        $qb = $this->buildQb(firstResult: $this->emptyResult());
        $db = $this->buildDb($qb);
        $service = new TwoFactorAuthService($db);

        // Act
        $count = $service->getRemainingBackupCodes(99);

        // Assert
        $this->assertSame(0, $count,
            'getRemainingBackupCodes must return 0 when the user has no 2FA row');
    }

    // =========================================================================
    // getStatus() — disabled branch
    // =========================================================================

    /**
     * getStatus() must report enabled=false, setup=true, and backup_codes_remaining=0
     * when the user has a secret but 2FA is disabled.
     *
     * When enabled=false the method must skip the getRemainingBackupCodes() call
     * and return 0 directly (line 98: "enabled ? $this->getRemainingBackupCodes() : 0").
     */
    public function testGetStatusReturnsZeroBackupCodesWhenDisabled(): void
    {
        // Arrange — user has a secret but enabled=0
        $qb = $this->buildQb(firstResult: $this->rowResult(['enabled' => 0, 'secret' => 'MYSECRET']));
        $db = $this->buildDb($qb);
        $service = new TwoFactorAuthService($db);

        // Act
        $status = $service->getStatus(1);

        // Assert
        $this->assertFalse($status['enabled'], 'enabled must be false');
        $this->assertTrue($status['setup'],    'setup must be true (secret present)');
        $this->assertSame(0, $status['backup_codes_remaining'],
            'backup_codes_remaining must be 0 when 2FA is not enabled');
    }

    /**
     * getStatus() must return all-false defaults when the user has no row.
     *
     * Covers the early-return at line 88–89 when numRows === 0.
     */
    public function testGetStatusReturnsDefaultsWhenNoRow(): void
    {
        // Arrange
        $qb = $this->buildQb(firstResult: $this->emptyResult());
        $db = $this->buildDb($qb);
        $service = new TwoFactorAuthService($db);

        // Act
        $status = $service->getStatus(404);

        // Assert
        $this->assertSame(
            ['enabled' => false, 'setup' => false, 'backup_codes_remaining' => 0],
            $status,
            'getStatus must return all-false defaults when no user_twofactor row exists'
        );
    }

    // =========================================================================
    // disable() — user not found
    // =========================================================================

    /**
     * disable() must return false when the user has no user_twofactor row.
     *
     * This covers the early-return at lines 319–320:
     * "if ($result->numRows === 0) return false".
     */
    public function testDisableReturnsFalseWhenUserNotFound(): void
    {
        // Arrange — no row for this user
        $qb = $this->buildQb(firstResult: $this->emptyResult());
        $db = $this->buildDb($qb);
        $service = new TwoFactorAuthService($db);

        // Act
        $result = $service->disable(999);

        // Assert
        $this->assertFalse($result,
            'disable() must return false when the user has no user_twofactor record');
    }

    // =========================================================================
    // regenerateBackupCodes() — not enabled
    // =========================================================================

    /**
     * regenerateBackupCodes() must return false when 2FA is not enabled.
     *
     * Covers the early-return at lines 354–355:
     * "if (!$this->isEnabled(...)) return false".
     */
    public function testRegenerateBackupCodesReturnsFalseWhenNotEnabled(): void
    {
        // Arrange — override isEnabled() to return false; inject mock DB
        $db      = $this->createMock(Database::class);
        $service = new class($db) extends TwoFactorAuthService {
            public function isEnabled(int $userId): bool { return false; }
        };

        // Act
        $result = $service->regenerateBackupCodes(1);

        // Assert
        $this->assertFalse($result,
            'regenerateBackupCodes must return false when 2FA is not enabled for the user');
    }

    // =========================================================================
    // cleanupExpiredSessions()
    // =========================================================================

    /**
     * cleanupExpiredSessions() must execute without error.
     *
     * The method fires a DELETE with compound WHERE conditions (used=1 OR
     * expires_at < now). This test verifies the code path executes without
     * exceptions when the QB mock accepts the call.
     */
    public function testCleanupExpiredSessionsRunsWithoutError(): void
    {
        // Arrange — QB that accepts the delete chain without error
        $qb = $this->createMock(QueryBuilder::class);
        foreach (['table', 'where', 'orWhere'] as $m) {
            $qb->method($m)->willReturn($qb);
        }
        $qb->expects($this->once())->method('delete')->willReturn(null);

        $db      = $this->buildDb($qb);
        $service = new TwoFactorAuthService($db);

        // Act — must not throw; the mock expectation above verifies delete() was called
        $service->cleanupExpiredSessions();
    }
}
