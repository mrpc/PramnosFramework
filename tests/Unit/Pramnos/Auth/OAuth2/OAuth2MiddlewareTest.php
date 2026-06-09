<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\OAuth2Middleware;
use Pramnos\Application\Controller;

/**
 * Unit tests for OAuth2Middleware.
 *
 * OAuth2Middleware is a standalone service (not PSR-15) that controllers call
 * directly to protect routes with Bearer token authentication. It reads the
 * Authorization header, loads the token from the database, validates expiry,
 * and checks scopes.
 *
 * Test strategy:
 * - The class calls Factory::getDatabase() internally, so we replace the
 *   singleton with a mock that returns controlled QueryBuilder chains.
 * - exit() calls in sendUnauthorized / sendForbidden are avoided by testing
 *   only the return values via a subclass that overrides those methods.
 * - getAuthorizationHeader() reads from $_SERVER, so tests set/unset
 *   $_SERVER['HTTP_AUTHORIZATION'] directly.
 *
 * Paths covered:
 *  - Missing Authorization header → false
 *  - Malformed header (no Bearer prefix) → false
 *  - Valid Bearer token, not found in DB → false
 *  - Valid Bearer token, found in DB, expired → false
 *  - Valid Bearer token, found in DB, not expired, no scopes required → array
 *  - Valid Bearer token, found in DB, scope with JSON array → array
 *  - Valid Bearer token, scope with space-separated string → array
 *  - Valid Bearer token, insufficient scope → false
 *  - Throwable during token loading → false
 *  - revokeToken() delegates to DB update → true/false
 *  - getCurrentUserId() returns userid when token valid
 *  - getCurrentApplicationId() returns applicationid when token valid
 */
#[CoversClass(OAuth2Middleware::class)]
class OAuth2MiddlewareTest extends TestCase
{
    /** @var Controller&\PHPUnit\Framework\MockObject\MockObject */
    private Controller $controller;

    protected function setUp(): void
    {
        // Controller is only stored; the actual DB calls bypass it via Factory::getDatabase().
        $this->controller = $this->createMock(Controller::class);

        // Clear any Authorization headers set by previous tests.
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION']);

        // Reset the DB singleton so it does not leak into subsequent tests.
        // Setting to null forces the next call to getInstance() to create a
        // fresh Database object from config.
        $singleton = &\Pramnos\Database\Database::getInstance();
        $singleton = null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a testable subclass of OAuth2Middleware where sendUnauthorized()
     * and sendForbidden() record the call and return without calling exit().
     *
     * @return OAuth2Middleware An instance of the anonymous subclass.
     */
    private function makeTestableMiddleware(): OAuth2Middleware
    {
        return new class($this->controller) extends OAuth2Middleware
        {
            /** Last call: ['type' => 'unauthorized'|'forbidden', 'message' => '...'] */
            public ?array $lastError = null;

            protected function sendUnauthorized(string $message): void
            {
                $this->lastError = ['type' => 'unauthorized', 'message' => $message];
                // Do NOT call exit() — allows assertions after the call.
            }

            protected function sendForbidden(string $message): void
            {
                $this->lastError = ['type' => 'forbidden', 'message' => $message];
            }
        };
    }

    /**
     * Build a stub Result object (mimics \Pramnos\Database\Result) with the
     * given numRows value and fields array.
     */
    private function makeResult(int $numRows, array $fields = []): object
    {
        $r = new \stdClass();
        $r->numRows = $numRows;
        $r->fields  = $fields;
        return $r;
    }

    /**
     * Create a mock QueryBuilder chain that returns a given result from first()
     * and from update() (update used by lastused and revokeToken).
     *
     * The chain is: table()->leftJoin()->select()->where(×3)->first()
     * and separately: table()->where()->update()
     */
    private function mockDbForToken(?object $selectResult, bool $updateResult = true): \Pramnos\Database\Database
    {
        $db = $this->createMock(\Pramnos\Database\Database::class);

        // The QueryBuilder mock handles the full fluent chain.
        $qb = $this->getMockBuilder(\Pramnos\Database\QueryBuilder::class)
                   ->disableOriginalConstructor()
                   ->getMock();

        // All chained calls return $qb itself so the chain can be arbitrarily long.
        $qb->method('table')->willReturn($qb);
        $qb->method('leftJoin')->willReturn($qb);
        $qb->method('select')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('first')->willReturn($selectResult);
        $qb->method('update')->willReturn($updateResult ? 1 : 0);

        $db->method('queryBuilder')->willReturn($qb);

        return $db;
    }

    // ── validateAccessToken() — header detection ──────────────────────────────

    /**
     * When no Authorization header is present the method must return false and
     * record an "unauthorized" response. This guards every protected endpoint
     * against unauthenticated requests.
     */
    public function testValidateReturnsUnauthorizedWhenNoAuthorizationHeader(): void
    {
        // Arrange — header is absent (ensured in setUp)
        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert
        $this->assertFalse($result, 'Should return false when Authorization header is missing');
        $this->assertSame('unauthorized', $mw->lastError['type']);
        $this->assertStringContainsString('Missing', $mw->lastError['message']);
    }

    /**
     * An Authorization header that exists but does not start with "Bearer " must
     * be rejected with a 401. This prevents Basic or Digest credentials from
     * accidentally being treated as OAuth2 tokens.
     */
    public function testValidateReturnsUnauthorizedWhenHeaderIsNotBearer(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert
        $this->assertFalse($result);
        $this->assertSame('unauthorized', $mw->lastError['type']);
        $this->assertStringContainsString('Invalid Authorization', $mw->lastError['message']);
    }

    /**
     * A Bearer token that cannot be found in the database must be rejected with
     * a 401. This covers revoked, non-existent, or mistyped tokens.
     */
    public function testValidateReturnsUnauthorizedWhenTokenNotFoundInDatabase(): void
    {
        // Arrange — DB returns null (no matching row)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';
        $db = $this->mockDbForToken(null);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert
        $this->assertFalse($result);
        $this->assertSame('unauthorized', $mw->lastError['type']);
        $this->assertStringContainsString('Invalid or expired', $mw->lastError['message']);
    }

    /**
     * A token row found in the DB but with numRows == 0 must be treated as
     * "not found". The Result object may exist without containing any rows.
     */
    public function testValidateReturnsUnauthorizedWhenResultHasZeroRows(): void
    {
        // Arrange — result object exists but reports zero rows
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer zero-row-token';
        $emptyResult = $this->makeResult(0);
        $db = $this->mockDbForToken($emptyResult);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert
        $this->assertFalse($result);
        $this->assertSame('unauthorized', $mw->lastError['type']);
    }

    /**
     * An access token whose "expires" field is in the past must be rejected
     * even though the DB row exists and is active (status=1). This prevents
     * dead tokens from granting access.
     */
    public function testValidateReturnsUnauthorizedWhenTokenIsExpired(): void
    {
        // Arrange — token expired 60 seconds ago
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer expired-token-abc';
        $expiredRow = $this->makeResult(1, [
            'tokenid'       => 42,
            'userid'        => 7,
            'applicationid' => 3,
            'token'         => 'expired-token-abc',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => time() - 60,
            'scope'         => '[]',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($expiredRow);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert
        $this->assertFalse($result);
        $this->assertSame('unauthorized', $mw->lastError['type']);
    }

    /**
     * A valid token with expires=0 (never expires) must be accepted regardless
     * of the current time. This is the pattern used for internal service accounts.
     */
    public function testValidateAcceptsNonExpiringToken(): void
    {
        // Arrange — expires=0 means never-expires
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-no-expiry';
        $row = $this->makeResult(1, [
            'tokenid'       => 99,
            'userid'        => 5,
            'applicationid' => 2,
            'token'         => 'valid-no-expiry',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => 0,
            'scope'         => '[]',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($row);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert — array with the token row returned
        $this->assertIsArray($result);
        $this->assertSame(5, (int)$result['userid']);
    }

    /**
     * A valid token with a future expiry and no scope requirements must be
     * accepted and return the token row. This is the happy path for most API calls.
     */
    public function testValidateAcceptsValidTokenWithNoScopeRequirements(): void
    {
        // Arrange — token expires an hour from now
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid-future-token';
        $row = $this->makeResult(1, [
            'tokenid'       => 1,
            'userid'        => 10,
            'applicationid' => 1,
            'token'         => 'valid-future-token',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => time() + 3600,
            'scope'         => '["read","write"]',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($row);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert
        $this->assertIsArray($result);
        $this->assertSame(10, (int)$result['userid']);
        $this->assertNull($mw->lastError, 'No error should have been sent for a valid token');
    }

    // ── validateAccessToken() — scope checking ────────────────────────────────

    /**
     * When required scopes are all present in the JSON-encoded scope field the
     * token must be accepted. JSON array format is the primary encoding used by
     * the framework's token issuance code.
     */
    public function testValidateAcceptsTokenWithSufficientScopesJsonFormat(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer scoped-token';
        $row = $this->makeResult(1, [
            'tokenid'       => 2,
            'userid'        => 11,
            'applicationid' => 1,
            'token'         => 'scoped-token',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => 0,
            'scope'         => '["read","profile","admin"]',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($row);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act — require two scopes that are both present
        $result = $mw->validateAccessToken(['read', 'profile']);

        // Assert
        $this->assertIsArray($result);
        $this->assertSame(11, (int)$result['userid']);
    }

    /**
     * When required scopes are all present in space-separated format the token
     * must be accepted. Space-separated scopes is the RFC 6749 wire format and
     * must be supported as a fallback when JSON decoding yields nothing.
     */
    public function testValidateAcceptsTokenWithSufficientScopesSpaceSeparated(): void
    {
        // Arrange — scope stored as space-separated string (legacy format)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer space-token';
        $row = $this->makeResult(1, [
            'tokenid'       => 3,
            'userid'        => 12,
            'applicationid' => 1,
            'token'         => 'space-token',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => 0,
            'scope'         => 'read write profile',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($row);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act — require scopes that are present
        $result = $mw->validateAccessToken(['read', 'write']);

        // Assert
        $this->assertIsArray($result);
    }

    /**
     * When a required scope is missing from the token the middleware must send
     * a 403 Forbidden response and return false. The caller must NOT continue
     * processing the request.
     */
    public function testValidateReturnsForbiddenWhenRequiredScopeIsMissing(): void
    {
        // Arrange — token has only "read"; we require "write"
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer limited-token';
        $row = $this->makeResult(1, [
            'tokenid'       => 4,
            'userid'        => 13,
            'applicationid' => 1,
            'token'         => 'limited-token',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => 0,
            'scope'         => '["read"]',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($row);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken(['read', 'write']);

        // Assert
        $this->assertFalse($result);
        $this->assertSame('forbidden', $mw->lastError['type']);
        $this->assertStringContainsString('scope', strtolower($mw->lastError['message']));
    }

    /**
     * A Throwable thrown during token loading (e.g. DB connection failure) must
     * be caught, result in a 401, and return false. The middleware must never
     * propagate exceptions to callers — it owns the error response.
     */
    public function testValidateReturnsUnauthorizedOnThrowable(): void
    {
        // Arrange — DB mock throws an exception during queryBuilder chain
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer throw-token';
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('queryBuilder')->willThrowException(new \RuntimeException('DB down'));
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert — exception caught, unauthorized response sent
        $this->assertFalse($result);
        $this->assertSame('unauthorized', $mw->lastError['type']);
        $this->assertStringContainsString('failed', strtolower($mw->lastError['message']));
    }

    // ── revokeToken() ─────────────────────────────────────────────────────────

    /**
     * revokeToken() must delegate to a QueryBuilder UPDATE that sets status=0
     * for the matching token. When the update affects rows it must return true.
     */
    public function testRevokeTokenReturnsTrueWhenUpdateSucceeds(): void
    {
        // Arrange
        $db = $this->mockDbForToken(null, updateResult: true);
        $this->injectMockDatabase($db);

        $mw = new OAuth2Middleware($this->controller);

        // Act
        $result = $mw->revokeToken('some-access-token');

        // Assert — (bool) cast of 1 is true
        $this->assertTrue($result);
    }

    /**
     * revokeToken() must return false when the DB update affects zero rows
     * (token not found or already revoked).
     */
    public function testRevokeTokenReturnsFalseWhenUpdateAffectsNoRows(): void
    {
        // Arrange
        $db = $this->mockDbForToken(null, updateResult: false);
        $this->injectMockDatabase($db);

        $mw = new OAuth2Middleware($this->controller);

        // Act
        $result = $mw->revokeToken('unknown-token');

        // Assert — (bool) cast of 0 is false
        $this->assertFalse($result);
    }

    // ── getCurrentUserId() / getCurrentApplicationId() ────────────────────────

    /**
     * getCurrentUserId() must return the integer userid from the token row when
     * the token is valid. It internally calls validateAccessToken() with no scopes.
     */
    public function testGetCurrentUserIdReturnsUseridFromValidToken(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer uid-token';
        $row = $this->makeResult(1, [
            'tokenid'       => 5,
            'userid'        => 42,
            'applicationid' => 1,
            'token'         => 'uid-token',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => 0,
            'scope'         => '[]',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($row);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $userId = $mw->getCurrentUserId();

        // Assert
        $this->assertSame(42, $userId);
    }

    /**
     * getCurrentUserId() must return null when the token is missing or invalid.
     * Callers can use this to distinguish "no user" from "user=0".
     */
    public function testGetCurrentUserIdReturnsNullWhenTokenInvalid(): void
    {
        // Arrange — no Authorization header
        $mw = $this->makeTestableMiddleware();

        // Act
        $userId = $mw->getCurrentUserId();

        // Assert
        $this->assertNull($userId);
    }

    /**
     * getCurrentApplicationId() must return the integer applicationid when the
     * token is valid, enabling per-client rate limiting or feature flags.
     */
    public function testGetCurrentApplicationIdReturnsApplicationidFromValidToken(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer appid-token';
        $row = $this->makeResult(1, [
            'tokenid'       => 6,
            'userid'        => 1,
            'applicationid' => 77,
            'token'         => 'appid-token',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => 0,
            'scope'         => '[]',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($row);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $appId = $mw->getCurrentApplicationId();

        // Assert
        $this->assertSame(77, $appId);
    }

    /**
     * getCurrentApplicationId() must return null when the token is missing or invalid.
     */
    public function testGetCurrentApplicationIdReturnsNullWhenTokenInvalid(): void
    {
        // Arrange — no header
        $mw = $this->makeTestableMiddleware();

        // Act
        $appId = $mw->getCurrentApplicationId();

        // Assert
        $this->assertNull($appId);
    }

    // ── $_SERVER['Authorization'] (non-HTTP_ prefix) ──────────────────────────

    /**
     * getAuthorizationHeader() must also read from $_SERVER['Authorization']
     * (without the HTTP_ prefix). Some environments (Apache with mod_rewrite,
     * or custom SAPI configurations) expose the header under this key.
     */
    public function testValidateReadsAuthorizationKeyWithoutHttpPrefix(): void
    {
        // Arrange — use the non-prefixed key
        $_SERVER['Authorization'] = 'Bearer server-auth-token';
        $row = $this->makeResult(1, [
            'tokenid'       => 7,
            'userid'        => 20,
            'applicationid' => 1,
            'token'         => 'server-auth-token',
            'tokentype'     => 'access_token',
            'status'        => 1,
            'expires'       => 0,
            'scope'         => '[]',
            'lastused'      => 0,
        ]);
        $db = $this->mockDbForToken($row);
        $this->injectMockDatabase($db);

        $mw = $this->makeTestableMiddleware();

        // Act
        $result = $mw->validateAccessToken();

        // Assert — picked up from $_SERVER['Authorization']
        $this->assertIsArray($result);
        $this->assertSame(20, (int)$result['userid']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Inject a Database mock into the singleton so that Factory::getDatabase()
     * returns it during the test.
     *
     * Database::getInstance() uses a static local array. By taking a reference
     * to the 'default' slot and assigning the mock, we replace it for the
     * duration of the test without modifying the production code.
     *
     * Call injectMockDatabase(null) in tearDown if tests share state.
     */
    private function injectMockDatabase(\Pramnos\Database\Database $db): void
    {
        $singleton = &\Pramnos\Database\Database::getInstance();
        $singleton = $db;
    }
}
