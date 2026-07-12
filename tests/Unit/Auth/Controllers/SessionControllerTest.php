<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Session;

/**
 * Unit tests for Pramnos\Auth\Controllers\Session.
 *
 * Session is a stateful controller that depends on $_SESSION, HTTP headers,
 * and the database. Because we cannot boot the full application in a unit-test
 * process, we test the pure/deterministic helpers in isolation.
 *
 * We bypass the constructor (which calls header()) via
 * ReflectionClass::newInstanceWithoutConstructor() and invoke private methods
 * through reflection — this is the only way to get real coverage on Session.php
 * rather than running equivalent logic copied into the test class.
 *
 *   - extractBearerToken() — parses Authorization header via regex
 *   - groupTokensByApp()   — aggregates flat token rows into per-app summaries
 *   - extractField()       — extracts a field from array or object session data
 *   - getTimeRemaining()   — computes remaining session lifetime (no Bearer token)
 *   - isUserLoggedIn()     — returns false when $_SESSION has no 'user' key
 */
#[CoversClass(Session::class)]
class SessionControllerTest extends TestCase
{
    /** Session instance created without running the constructor. */
    private Session $session;

    /**
     * Create the Session object by bypassing the constructor so that
     * header() is never called during test setup.
     */
    protected function setUp(): void
    {
        // Arrange – bypass constructor to avoid header() / Application boot
        $rc = new \ReflectionClass(Session::class);
        $this->session = $rc->newInstanceWithoutConstructor();

        // Ensure a clean superglobal state
        $_SESSION = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    // ── extractBearerToken() ──────────────────────────────────────────────────

    /**
     * A correctly formatted `Authorization: Bearer <token>` header must return
     * the token value (everything after the "Bearer " prefix).
     *
     * This covers extractBearerToken() lines ~271-285 (the preg_match branch).
     */
    public function testExtractBearerTokenReturnsTokenFromValidHeader(): void
    {
        // Arrange
        $expectedToken = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.payload.sig';
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$expectedToken}";

        // Act
        $result = $this->callPrivate('extractBearerToken');

        // Assert
        $this->assertSame($expectedToken, $result,
            'Token value after the "Bearer " prefix must be returned');
    }

    /**
     * A header with scheme "bearer" (lower-case) must also be accepted.
     *
     * The regex uses /i — RFC 6750 does not require a specific case.
     * This covers the preg_match('/^Bearer\s+(.+)$/i', …) true branch.
     */
    public function testExtractBearerTokenIsCaseInsensitive(): void
    {
        // Arrange
        $token = 'some.token.value';
        $_SERVER['HTTP_AUTHORIZATION'] = "bearer {$token}";

        // Act
        $result = $this->callPrivate('extractBearerToken');

        // Assert
        $this->assertSame($token, $result,
            'Bearer scheme matching must be case-insensitive');
    }

    /**
     * An "Authorization: Basic …" header must yield null — Basic credentials
     * must not be treated as a Bearer token.
     *
     * This covers the preg_match false branch inside extractBearerToken().
     */
    public function testExtractBearerTokenReturnsNullForBasicAuth(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        // Act
        $result = $this->callPrivate('extractBearerToken');

        // Assert
        $this->assertNull($result,
            'A Basic auth header must not be treated as a Bearer token');
    }

    /**
     * When no Authorization header exists, null must be returned with no
     * exception — session auth is the fallback.
     *
     * This covers the `if ($authHeader === null) return null` path in
     * extractBearerToken() (lines ~276-278).
     */
    public function testExtractBearerTokenReturnsNullWhenNoHeader(): void
    {
        // Arrange — HTTP_AUTHORIZATION is already unset in setUp()

        // Act
        $result = $this->callPrivate('extractBearerToken');

        // Assert
        $this->assertNull($result, 'No Authorization header must produce null');
    }

    // ── groupTokensByApp() ────────────────────────────────────────────────────

    /**
     * Multiple tokens for the same application must be merged into one entry
     * with an aggregated token_count and the maximum last_used timestamp.
     *
     * This covers the entire loop body in groupTokensByApp() (lines ~403-419).
     */
    public function testGroupTokensByAppMergesMultipleTokensForSameApp(): void
    {
        // Arrange
        $tokens = [
            ['app_name' => 'MyApp', 'lastused' => 1000],
            ['app_name' => 'MyApp', 'lastused' => 2000],
            ['app_name' => 'MyApp', 'lastused' => 1500],
        ];

        // Act
        $grouped = $this->callPrivate('groupTokensByApp', $tokens);

        // Assert
        $this->assertCount(1, $grouped,
            'Three tokens for one app must produce one group');
        $this->assertSame('MyApp', $grouped[0]['name']);
        $this->assertSame(3,       $grouped[0]['token_count'],
            'token_count must be the sum of all tokens');
        $this->assertSame(2000,    $grouped[0]['last_used'],
            'last_used must be the maximum across all tokens');
    }

    /**
     * Tokens for different applications must remain in separate groups.
     *
     * This covers the `!isset($byApp[$name])` branch — new group initialised
     * for each distinct app_name (lines ~409-411).
     */
    public function testGroupTokensByAppProducesOneGroupPerApp(): void
    {
        // Arrange
        $tokens = [
            ['app_name' => 'AppA', 'lastused' => 100],
            ['app_name' => 'AppB', 'lastused' => 200],
            ['app_name' => 'AppA', 'lastused' => 300],
        ];

        // Act
        $grouped = $this->callPrivate('groupTokensByApp', $tokens);

        // Assert
        $this->assertCount(2, $grouped,
            'Tokens for two apps must produce two groups');
        $names = array_column($grouped, 'name');
        $this->assertContains('AppA', $names);
        $this->assertContains('AppB', $names);
    }

    /**
     * An empty token list must produce an empty array.
     *
     * This covers the case where the foreach loop body is never entered, and
     * array_values([]) is returned.
     */
    public function testGroupTokensByAppReturnsEmptyArrayForNoTokens(): void
    {
        // Act
        $grouped = $this->callPrivate('groupTokensByApp', []);

        // Assert
        $this->assertSame([], $grouped, 'No tokens must produce no groups');
    }

    /**
     * A token row with no app_name key must fall back to the string 'unknown'.
     *
     * This covers the `$token['app_name'] ?? 'unknown'` null-coalesce branch
     * in groupTokensByApp() (line ~408).
     */
    public function testGroupTokensByAppUsesUnknownForMissingAppName(): void
    {
        // Arrange — token row has no app_name key
        $tokens = [['lastused' => 500]];

        // Act
        $grouped = $this->callPrivate('groupTokensByApp', $tokens);

        // Assert
        $this->assertSame('unknown', $grouped[0]['name'],
            'Missing app_name must fall back to "unknown"');
    }

    // ── extractField() ────────────────────────────────────────────────────────

    /**
     * extractField() must return the correct value from an associative array.
     *
     * This covers the `if (is_array($data))` true branch of extractField()
     * (lines ~429-431).
     */
    public function testExtractFieldFromArray(): void
    {
        // Arrange
        $data = ['userid' => 42, 'username' => 'alice'];

        // Act
        $userId   = $this->callPrivate('extractField', $data, 'userid');
        $username = $this->callPrivate('extractField', $data, 'username');
        $missing  = $this->callPrivate('extractField', $data, 'nonexistent');

        // Assert
        $this->assertSame(42,      $userId);
        $this->assertSame('alice', $username);
        $this->assertNull($missing,
            'Missing array key must return null, not a warning');
    }

    /**
     * extractField() must return the correct value from a stdClass object.
     *
     * This covers the `if (is_object($data))` true branch of extractField()
     * (lines ~432-434).
     */
    public function testExtractFieldFromObject(): void
    {
        // Arrange
        $data           = new \stdClass();
        $data->userid   = 7;
        $data->username = 'bob';

        // Act
        $userId  = $this->callPrivate('extractField', $data, 'userid');
        $missing = $this->callPrivate('extractField', $data, 'email');

        // Assert
        $this->assertSame(7,    $userId);
        $this->assertNull($missing,
            'Missing object property must return null, not a warning');
    }

    /**
     * extractField() must return null for neither-array-nor-object input.
     *
     * This covers the final `return null` fallthrough in extractField()
     * (line ~435).
     */
    public function testExtractFieldFromNullReturnsNull(): void
    {
        // Act
        $result = $this->callPrivate('extractField', null, 'userid');

        // Assert
        $this->assertNull($result);
    }

    // ── getTimeRemaining() ────────────────────────────────────────────────────

    /**
     * Without a Bearer token, getTimeRemaining() must return a value derived
     * from ini_get('session.gc_maxlifetime') and $_SESSION['last_activity'].
     *
     * This covers the `$token !== null` false branch in getTimeRemaining()
     * (lines ~261-263): the session-based computation path.
     */
    public function testGetTimeRemainingForRecentSessionReturnsPositiveValue(): void
    {
        // Arrange — last activity 60 seconds ago, no Bearer token in headers
        $_SESSION['last_activity'] = time() - 60;

        // Act
        $remaining = $this->callPrivate('getTimeRemaining');

        // Assert — must be positive (session is fresh) and ≤ gc_maxlifetime
        $this->assertGreaterThan(0, $remaining,
            'A fresh session must have positive time remaining');
        $this->assertLessThanOrEqual(
            (int) ini_get('session.gc_maxlifetime'),
            $remaining
        );
    }

    /**
     * When last_activity was far in the past, getTimeRemaining() must return 0
     * (never negative) — clamped by max(0, …).
     *
     * This covers the `max(0, …)` clamp on line ~263.
     */
    public function testGetTimeRemainingIsNeverNegative(): void
    {
        // Arrange — last activity 100 000 seconds ago (far past any timeout)
        $_SESSION['last_activity'] = time() - 100_000;

        // Act
        $remaining = $this->callPrivate('getTimeRemaining');

        // Assert
        $this->assertSame(0, $remaining,
            'An expired session remaining time must be 0, never negative');
    }

    /**
     * When $_SESSION has no last_activity key, getTimeRemaining() must use
     * time() as the default (i.e. the session was just refreshed) and return
     * approximately gc_maxlifetime seconds remaining.
     *
     * This covers the `$_SESSION['last_activity'] ?? time()` default path
     * on line ~262.
     */
    public function testGetTimeRemainingDefaultsToFullLifetimeWhenNoLastActivity(): void
    {
        // Arrange — no last_activity in session
        unset($_SESSION['last_activity']);

        // Act
        $remaining = $this->callPrivate('getTimeRemaining');
        $maxLifetime = (int) ini_get('session.gc_maxlifetime');

        // Assert — allow ±2 s tolerance for CPU/clock skew
        $this->assertGreaterThanOrEqual($maxLifetime - 2, $remaining,
            'No last_activity must default to full session lifetime remaining');
    }

    // ── isUserLoggedIn() ──────────────────────────────────────────────────────

    /**
     * isUserLoggedIn() must return false when $_SESSION has no 'user' key and
     * there is no Bearer token in the headers.
     *
     * This covers the `if (empty($_SESSION['user'])) return false` path on
     * lines ~217-219.
     */
    public function testIsUserLoggedInReturnsFalseWithEmptySession(): void
    {
        // Arrange — session is empty, no Bearer token
        $_SESSION = [];

        // Act
        $result = $this->callPrivate('isUserLoggedIn');

        // Assert
        $this->assertFalse($result,
            'Empty session with no Bearer token must report not logged in');
    }

    // ── Private reflection helper ─────────────────────────────────────────────

    /**
     * Call a private method on $this->session via reflection.
     *
     * @param string $method  Method name
     * @param mixed  ...$args Arguments to pass to the method
     * @return mixed
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(Session::class, $method);
        return $rm->invoke($this->session, ...$args);
    }
}
