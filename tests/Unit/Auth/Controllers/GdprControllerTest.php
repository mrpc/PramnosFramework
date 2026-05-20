<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Gdpr;

/**
 * Unit tests for Pramnos\Auth\Controllers\Gdpr.
 *
 * Gdpr is a controller that depends on $_SESSION, HTTP headers and the database.
 * We bypass the constructor (which calls parent::__construct() and instantiates
 * WebhookService) via ReflectionClass::newInstanceWithoutConstructor() and test
 * the private pure helpers in isolation.
 *
 * Tests cover:
 *   - resolveActor(): pure session/header reading with no DB required.
 *   - readJsonBody(): JSON decoding from a string (empty input path).
 */
#[CoversClass(Gdpr::class)]
class GdprControllerTest extends TestCase
{
    private Gdpr $gdpr;

    /**
     * Create the Gdpr object without running the constructor so that
     * WebhookService / Application boot / DB connections are never triggered.
     */
    protected function setUp(): void
    {
        // Arrange – bypass constructor
        $rc         = new \ReflectionClass(Gdpr::class);
        $this->gdpr = $rc->newInstanceWithoutConstructor();

        // Clean superglobal state
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    // ── resolveActor() ────────────────────────────────────────────────────────

    /**
     * When neither a Bearer token header nor session data are present,
     * resolveActor() must return [null, false].
     *
     * This covers the session-auth else-branch of resolveActor() (lines ~362-365)
     * where both user_id and is_admin fall back to null/false.
     */
    public function testResolveActorReturnsNullFalseWhenNoAuth(): void
    {
        // Arrange — no Authorization header, empty session

        // Act
        $result = $this->callPrivate('resolveActor');

        // Assert
        $this->assertSame([null, false], $result,
            'No session and no Bearer token must yield [null, false]');
    }

    /**
     * When $_SESSION['user_id'] is set, resolveActor() returns the cast integer
     * user ID and false for is_admin.
     *
     * This covers the `$_SESSION['user_id'] ??` path in resolveActor() (line ~362).
     */
    public function testResolveActorReturnsUserIdFromSessionUserId(): void
    {
        // Arrange
        $_SESSION['user_id'] = '42';

        // Act
        [$userId, $isAdmin] = $this->callPrivate('resolveActor');

        // Assert
        $this->assertSame(42, $userId,
            'Session user_id must be cast to int and returned');
        $this->assertFalse($isAdmin,
            'is_admin must default to false when not set in session');
    }

    /**
     * When $_SESSION has a nested 'user' array with a 'userid' key,
     * resolveActor() resolves the ID from the nested array.
     *
     * This covers the nested `$_SESSION['user']['userid']` fallback path
     * in resolveActor() (line ~362).
     */
    public function testResolveActorResolvesNestedUserArray(): void
    {
        // Arrange
        $_SESSION['user'] = ['userid' => 7, 'username' => 'alice'];

        // Act
        [$userId, $isAdmin] = $this->callPrivate('resolveActor');

        // Assert
        $this->assertSame(7, $userId,
            'Nested $_SESSION["user"]["userid"] must be resolved');
        $this->assertFalse($isAdmin);
    }

    /**
     * When $_SESSION['is_admin'] is truthy, resolveActor() must return true
     * for the isAdmin flag.
     *
     * This covers the `(bool)($_SESSION['is_admin'] ?? false)` cast on line ~363.
     */
    public function testResolveActorReturnsIsAdminTrueFromSession(): void
    {
        // Arrange
        $_SESSION['user_id']  = 1;
        $_SESSION['is_admin'] = 1;

        // Act
        [$userId, $isAdmin] = $this->callPrivate('resolveActor');

        // Assert
        $this->assertTrue($isAdmin,
            'is_admin=1 in session must be cast to true');
    }

    /**
     * When an Authorization header is present but the Bearer token format is
     * incorrect (e.g. "Basic …"), resolveActor() falls through to session auth
     * (no DB involved) and returns [null, false] when the session is also empty.
     *
     * This covers the `if ($authHeader && preg_match(...))` false branch when
     * the header scheme is not "Bearer" (line ~340).
     */
    public function testResolveActorFallsBackToSessionWhenHeaderIsNotBearer(): void
    {
        // Arrange — Basic auth header, no session
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        // Act
        $result = $this->callPrivate('resolveActor');

        // Assert — falls back to empty session → [null, false]
        $this->assertSame([null, false], $result,
            'A non-Bearer Authorization header must not match the Bearer preg_match');
    }

    // ── readJsonBody() ────────────────────────────────────────────────────────

    /**
     * readJsonBody() must return an empty array when php://input is empty.
     *
     * This covers the `if ($raw === false || $raw === '') return []` early-exit
     * in readJsonBody() (lines ~414-416).
     *
     * We cannot inject actual php://input content in a unit test, but we can
     * verify that invoking it in a CLI test context (where php://input is empty)
     * always returns [].
     */
    public function testReadJsonBodyReturnsEmptyArrayWhenInputIsEmpty(): void
    {
        // Act — in CLI context php://input is empty
        $result = $this->callPrivate('readJsonBody');

        // Assert
        $this->assertSame([], $result,
            'readJsonBody() must return [] when php://input is empty');
    }

    // ── VALID_REQUEST_TYPES constant ──────────────────────────────────────────

    /**
     * The VALID_REQUEST_TYPES constant must include 'export', 'delete', and
     * 'portability' — these are the three GDPR-mandated operations.
     *
     * Verifying the constant prevents accidental typos in future edits.
     * This covers the constant declaration line ~31.
     */
    public function testValidRequestTypesContainsExpectedValues(): void
    {
        // Act — read the constant via reflection (it is private)
        $rc    = new \ReflectionClass(Gdpr::class);
        $types = $rc->getConstant('VALID_REQUEST_TYPES');

        // Assert
        $this->assertContains('export',      $types);
        $this->assertContains('delete',      $types);
        $this->assertContains('portability', $types);
    }

    /**
     * The VALID_REVOKE_REASONS constant must contain the four expected strings.
     *
     * This covers the constant declaration on lines ~32-35.
     */
    public function testValidRevokeReasonsContainsExpectedValues(): void
    {
        // Act
        $rc      = new \ReflectionClass(Gdpr::class);
        $reasons = $rc->getConstant('VALID_REVOKE_REASONS');

        // Assert
        $this->assertContains('user_revoked',       $reasons);
        $this->assertContains('admin_revoked',       $reasons);
        $this->assertContains('gdpr_deletion',       $reasons);
        $this->assertContains('security_violation',  $reasons);
    }

    // ── Private reflection helper ─────────────────────────────────────────────

    /**
     * Call a private method on $this->gdpr via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(Gdpr::class, $method);
        return $rm->invoke($this->gdpr, ...$args);
    }
}
