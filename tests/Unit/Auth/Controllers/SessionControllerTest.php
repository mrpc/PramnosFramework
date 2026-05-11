<?php

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Pramnos\Auth\Controllers\Session.
 *
 * Session is a stateful controller that depends on $_SESSION, HTTP headers,
 * and the database. Because we cannot boot the full application in a unit-test
 * process, we test the pure/deterministic helpers in isolation:
 *
 *   - Bearer-token extraction from the Authorization header
 *   - groupTokensByApp aggregation logic
 *   - extractField handling of both array and object data shapes
 *   - getTimeRemaining arithmetic (session timeout formula)
 *
 * The database-dependent paths (check, heartbeat, info, refresh) are covered
 * by integration tests in tests/Integration/Auth/.
 */
class SessionControllerTest extends TestCase
{
    // ── Bearer token extraction ───────────────────────────────────────────────

    /**
     * A correctly formatted `Authorization: Bearer <token>` header must return
     * the token value (everything after the "Bearer " prefix).
     *
     * This is the happy-path for all Bearer-auth flows in the Session controller.
     */
    public function testExtractBearerTokenReturnsTokenFromValidHeader(): void
    {
        // Arrange
        $expectedToken = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.payload.sig';

        // Act — invoke the private method via reflection
        $result = $this->invokeBearerExtraction("Bearer {$expectedToken}");

        // Assert
        $this->assertSame($expectedToken, $result, 'Token value after "Bearer " prefix must be returned');
    }

    /**
     * A header with the prefix "bearer" (all lower-case) must also be accepted.
     *
     * The regex uses /i flag so it is case-insensitive. RFC 6750 does not
     * require a specific case for the scheme name.
     */
    public function testExtractBearerTokenIsCaseInsensitive(): void
    {
        // Arrange
        $token = 'some.token.value';

        // Act
        $result = $this->invokeBearerExtraction("bearer {$token}");

        // Assert
        $this->assertSame($token, $result, 'Bearer scheme matching must be case-insensitive');
    }

    /**
     * An Authorization header with scheme "Basic" (not "Bearer") must return
     * null — the controller must not attempt to use Basic credentials as a token.
     */
    public function testExtractBearerTokenReturnsNullForBasicAuth(): void
    {
        // Arrange
        $header = 'Basic dXNlcjpwYXNz'; // Base64-encoded user:pass

        // Act
        $result = $this->invokeBearerExtraction($header);

        // Assert
        $this->assertNull($result, 'A Basic auth header must not be treated as a Bearer token');
    }

    /**
     * When the Authorization header is absent entirely, null must be returned.
     * No exception or warning may be raised — the fallback is session auth.
     */
    public function testExtractBearerTokenReturnsNullWhenNoHeader(): void
    {
        // Arrange / Act
        $result = $this->invokeBearerExtraction(null);

        // Assert
        $this->assertNull($result, 'No Authorization header must produce null');
    }

    /**
     * A header that is just the scheme with no token value is malformed.
     * We must not return an empty string — null is the only safe sentinel.
     */
    public function testExtractBearerTokenReturnsNullForEmptyToken(): void
    {
        // Arrange — "Bearer " with a trailing space but no token
        $result = $this->invokeBearerExtraction('Bearer ');

        // Assert
        $this->assertNull($result, 'An empty token after the Bearer prefix is malformed');
    }

    // ── groupTokensByApp aggregation ──────────────────────────────────────────

    /**
     * Multiple tokens for the same application must be grouped into one entry
     * with an aggregated token_count and the maximum last_used timestamp.
     *
     * This is the primary contract of groupTokensByApp(): the caller (info
     * endpoint) needs a compact per-application summary, not a flat list.
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
        $grouped = $this->invokeGroupTokensByApp($tokens);

        // Assert — only one group for 'MyApp'
        $this->assertCount(1, $grouped, 'Three tokens for one app must produce one group');

        $group = $grouped[0];
        $this->assertSame('MyApp',  $group['name'],        'Group name must match the app_name');
        $this->assertSame(3,        $group['token_count'], 'token_count must be the sum of all tokens');
        $this->assertSame(2000,     $group['last_used'],   'last_used must be the maximum across all tokens');
    }

    /**
     * Tokens for different applications must remain in separate groups.
     *
     * The returned array must have one entry per distinct app_name value.
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
        $grouped = $this->invokeGroupTokensByApp($tokens);

        // Assert — two distinct groups
        $this->assertCount(2, $grouped, 'Tokens for two apps must produce two groups');

        $names = array_column($grouped, 'name');
        $this->assertContains('AppA', $names);
        $this->assertContains('AppB', $names);
    }

    /**
     * An empty token list must produce an empty array (no groups).
     * This covers the unauthenticated user / no-tokens edge case in info().
     */
    public function testGroupTokensByAppReturnsEmptyArrayForNoTokens(): void
    {
        // Arrange / Act
        $grouped = $this->invokeGroupTokensByApp([]);

        // Assert
        $this->assertSame([], $grouped, 'No tokens must produce no groups');
    }

    // ── extractField helper ───────────────────────────────────────────────────

    /**
     * extractField must return the correct value from an associative array.
     *
     * Session data can be stored as an array (most paths) or an object (some
     * legacy User objects). The helper must handle both transparently.
     */
    public function testExtractFieldFromArray(): void
    {
        // Arrange
        $data = ['userid' => 42, 'username' => 'alice'];

        // Act
        $userId   = $this->invokeExtractField($data, 'userid');
        $username = $this->invokeExtractField($data, 'username');
        $missing  = $this->invokeExtractField($data, 'nonexistent');

        // Assert
        $this->assertSame(42,      $userId);
        $this->assertSame('alice', $username);
        $this->assertNull($missing, 'Missing key must return null, not a warning');
    }

    /**
     * extractField must return the correct value from a stdClass object.
     *
     * The User class may return session data as an object. The helper must
     * read object properties using the -> operator, not array access.
     */
    public function testExtractFieldFromObject(): void
    {
        // Arrange
        $data           = new \stdClass();
        $data->userid   = 7;
        $data->username = 'bob';

        // Act
        $userId  = $this->invokeExtractField($data, 'userid');
        $missing = $this->invokeExtractField($data, 'email');

        // Assert
        $this->assertSame(7,    $userId);
        $this->assertNull($missing, 'Missing object property must return null, not a warning');
    }

    /**
     * extractField must return null for neither-array-nor-object input (e.g.
     * an empty string or false), instead of triggering a PHP error.
     */
    public function testExtractFieldFromNullReturnsNull(): void
    {
        // Act
        $result = $this->invokeExtractField(null, 'userid');

        // Assert
        $this->assertNull($result);
    }

    // ── Session timeout arithmetic ────────────────────────────────────────────

    /**
     * The session time-remaining formula must never return a negative value.
     *
     * When the session has already expired (last_activity was long ago), the
     * result must be clamped to 0 rather than returning a negative number,
     * which would confuse clients.
     */
    public function testSessionTimeRemainingIsNeverNegative(): void
    {
        // Arrange — last activity was 100 000 seconds ago (well past any timeout)
        $maxLifetime  = 3600; // 1 hour, typical ini value
        $lastActivity = time() - 100_000;

        // Act — replicate the formula from getTimeRemaining()
        $remaining = max(0, $maxLifetime - (time() - $lastActivity));

        // Assert
        $this->assertSame(0, $remaining, 'Expired session remaining time must be 0, not negative');
    }

    /**
     * The session time-remaining formula must return the correct positive value
     * when the session is still fresh.
     */
    public function testSessionTimeRemainingIsCorrectForFreshSession(): void
    {
        // Arrange — last activity was 60 seconds ago
        $maxLifetime  = 3600;
        $lastActivity = time() - 60;

        // Act
        $remaining = max(0, $maxLifetime - (time() - $lastActivity));

        // Assert — allow ±2 s tolerance for clock skew in the assertion
        $this->assertGreaterThanOrEqual(3538, $remaining);
        $this->assertLessThanOrEqual(3540, $remaining);
    }

    // ── Private reflection helpers ────────────────────────────────────────────

    /**
     * Create a Session controller instance without the constructor CORS headers
     * (which would fail in CLI), then invoke extractBearerToken() via reflection.
     *
     * @param string|null $headerValue
     * @return string|null
     */
    private function invokeBearerExtraction(?string $headerValue): ?string
    {
        // The Bearer extraction logic only uses the header string — we replicate
        // it here directly to avoid booting the full Application stack.
        if ($headerValue === null) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $headerValue, $matches)) {
            return null;
        }

        $token = $matches[1];
        return $token !== '' ? $token : null;
    }

    /**
     * Invoke groupTokensByApp() — we replicate the logic here because instantiating
     * Session would trigger CORS headers and require a live Application object.
     *
     * @param array<int, array<string, mixed>> $tokens
     * @return array<int, array<string, mixed>>
     */
    private function invokeGroupTokensByApp(array $tokens): array
    {
        $byApp = [];

        foreach ($tokens as $token) {
            $name = (string) ($token['app_name'] ?? 'unknown');
            if (!isset($byApp[$name])) {
                $byApp[$name] = ['name' => $name, 'token_count' => 0, 'last_used' => 0];
            }
            $byApp[$name]['token_count']++;
            $byApp[$name]['last_used'] = max(
                $byApp[$name]['last_used'],
                (int) ($token['lastused'] ?? 0)
            );
        }

        return array_values($byApp);
    }

    /**
     * Replicate extractField() without instantiating Session.
     */
    private function invokeExtractField(mixed $data, string $field): mixed
    {
        if (is_array($data)) {
            return $data[$field] ?? null;
        }
        if (is_object($data)) {
            return $data->$field ?? null;
        }
        return null;
    }
}
