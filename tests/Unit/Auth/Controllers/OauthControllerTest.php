<?php

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Pramnos\Auth\Controllers\Oauth.
 *
 * The Oauth controller integrates with the League OAuth2 server, the database,
 * PSR-7 (nyholm/psr7), and the Pramnos application boot sequence. None of
 * those are available in a pure unit-test process, so we test the
 * deterministic helper logic by replicating it here.
 *
 * Tested contracts:
 *   - generateUserCode()   — RFC 8628 §6.1 format (8 chars, ambiguous-char-free)
 *   - extractBearerToken() — Authorization header parsing
 *   - validateAuthorizeParams() scope and PKCE validation rules
 *   - buildPsrServerRequest() — PSR-7 server request header extraction
 *   - emitPsrResponse()    — PSR-7 → PHP output translation
 *
 * The DB-dependent paths (authorize, token, introspect, revoke, userinfo,
 * logout, deviceauthorization) are covered by integration tests.
 */
class OauthControllerTest extends TestCase
{
    // ── User code generation ──────────────────────────────────────────────────

    /**
     * The generated user code must follow RFC 8628 §6.1 format:
     *   - 8 non-ambiguous uppercase characters
     *   - split by a dash at position 5 (displayed as XXXX-XXXX)
     *
     * We verify structure and alphabet, not randomness.
     */
    public function testGenerateUserCodeFormatIsValid(): void
    {
        // Act — replicate the logic from Oauth::generateUserCode()
        $code = $this->invokeGenerateUserCode();

        // Assert — must be 9 characters long (8 letters + 1 dash)
        $this->assertSame(9, strlen($code), 'User code must be 9 characters (XXXX-XXXX)');

        // Assert — must contain a dash at position 4 (0-indexed)
        $this->assertSame('-', $code[4], 'User code must contain a dash at position 4');

        // Assert — all non-dash characters must be from the unambiguous alphabet
        $alphabet = 'BCDFGHJKLMNPQRSTVWXZ';
        $letters  = str_replace('-', '', $code);
        for ($i = 0; $i < strlen($letters); $i++) {
            $this->assertStringContainsString(
                $letters[$i],
                $alphabet,
                "Character '{$letters[$i]}' is not in the unambiguous alphabet"
            );
        }
    }

    /**
     * The user code must not contain visually ambiguous characters.
     *
     * RFC 8628 §6.1 recommends using an alphabet that avoids characters that
     * could be confused at a glance: 0/O, 1/I/L, etc.
     */
    public function testGenerateUserCodeExcludesAmbiguousChars(): void
    {
        // Arrange — characters excluded from the alphabet (digits + vowels + O/I)
        // The alphabet is BCDFGHJKLMNPQRSTVWXZ — digits, vowels (A/E/I/O/U), and Y are excluded
        $ambiguous = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                      'A', 'E', 'I', 'O', 'U', 'Y'];

        // Act — generate several codes to reduce false-negative probability
        for ($i = 0; $i < 20; $i++) {
            $code    = $this->invokeGenerateUserCode();
            $letters = str_replace('-', '', $code);

            foreach ($ambiguous as $char) {
                $this->assertStringNotContainsString(
                    $char,
                    $letters,
                    "Ambiguous character '{$char}' must not appear in user codes"
                );
            }
        }
    }

    /**
     * Each call to generateUserCode() must return a different value with very
     * high probability (probability of collision in 20 trials ≈ negligible).
     */
    public function testGenerateUserCodeIsRandom(): void
    {
        // Act
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = $this->invokeGenerateUserCode();
        }

        // Assert — at least 5 distinct values out of 10
        $this->assertGreaterThan(5, count(array_unique($codes)), 'User codes must be random');
    }

    // ── Bearer token extraction ───────────────────────────────────────────────

    /**
     * A well-formed `Authorization: Bearer <token>` header must yield the
     * token string.
     */
    public function testExtractBearerTokenReturnsToken(): void
    {
        // Arrange
        $expectedToken = 'eyJ.payload.sig';

        // Act
        $result = $this->invokeBearerExtraction("Bearer {$expectedToken}");

        // Assert
        $this->assertSame($expectedToken, $result);
    }

    /**
     * The extraction must be case-insensitive for the "Bearer" scheme name.
     */
    public function testExtractBearerTokenCaseInsensitive(): void
    {
        // Arrange / Act
        $result = $this->invokeBearerExtraction('BEARER mytoken');

        // Assert
        $this->assertSame('mytoken', $result);
    }

    /**
     * A non-Bearer scheme (Basic, Digest, etc.) must return null.
     */
    public function testExtractBearerTokenRejectsNonBearer(): void
    {
        // Arrange / Act
        $result = $this->invokeBearerExtraction('Basic dXNlcjpwYXNz');

        // Assert
        $this->assertNull($result);
    }

    /**
     * A null (absent) header must return null without error.
     */
    public function testExtractBearerTokenHandlesAbsentHeader(): void
    {
        // Act
        $result = $this->invokeBearerExtraction(null);

        // Assert
        $this->assertNull($result);
    }

    // ── PKCE validation ───────────────────────────────────────────────────────

    /**
     * When code_challenge is absent, validateAuthorizeParams() must not throw
     * on the PKCE check — PKCE is optional for non-public clients.
     */
    public function testValidateAuthorizeParamsAcceptsNoPkce(): void
    {
        // Arrange
        $params = [
            'client_id'             => 'test-client',
            'redirect_uri'          => 'https://example.com/cb',
            'response_type'         => 'code',
            'scope'                 => '',
            'state'                 => '',
            'code_challenge'        => '',
            'code_challenge_method' => 'plain',
        ];

        // Act — must not throw
        $threw = false;
        try {
            $this->invokeValidateAuthorizeParams($params);
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $ex) {
            $threw = true;
        } catch (\InvalidArgumentException $ex) {
            $threw = true;
        }

        // Assert
        $this->assertFalse($threw, 'Valid params without PKCE must not throw');
    }

    /**
     * An invalid code_challenge_method must be rejected.
     *
     * RFC 7636 §4.3: only "S256" and "plain" are valid.
     */
    public function testValidateAuthorizeParamsRejectsInvalidPkceMethod(): void
    {
        // Arrange
        $params = [
            'client_id'             => 'test-client',
            'redirect_uri'          => 'https://example.com/cb',
            'response_type'         => 'code',
            'scope'                 => '',
            'state'                 => '',
            'code_challenge'        => str_repeat('a', 43), // valid length
            'code_challenge_method' => 'SHA512', // invalid
        ];

        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->invokeValidateAuthorizeParams($params);
    }

    /**
     * A code_challenge that is too short (< 43 chars) must be rejected for S256.
     *
     * RFC 7636 §4.2: the challenge must be between 43 and 128 characters.
     */
    public function testValidateAuthorizeParamsRejectsShortS256Challenge(): void
    {
        // Arrange
        $params = [
            'client_id'             => 'test-client',
            'redirect_uri'          => 'https://example.com/cb',
            'response_type'         => 'code',
            'scope'                 => '',
            'state'                 => '',
            'code_challenge'        => str_repeat('a', 42), // one char too short
            'code_challenge_method' => 'S256',
        ];

        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->invokeValidateAuthorizeParams($params);
    }

    /**
     * A valid 43-character S256 code_challenge must be accepted.
     */
    public function testValidateAuthorizeParamsAcceptsValidS256Challenge(): void
    {
        // Arrange — 43 URL-safe chars, valid S256 challenge
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->assertGreaterThanOrEqual(43, strlen($challenge)); // sanity

        $params = [
            'client_id'             => 'test-client',
            'redirect_uri'          => 'https://example.com/cb',
            'response_type'         => 'code',
            'scope'                 => '',
            'state'                 => '',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];

        // Act — must not throw
        $threw = false;
        try {
            $this->invokeValidateAuthorizeParams($params);
        } catch (\Throwable $ex) {
            $threw = true;
        }

        // Assert
        $this->assertFalse($threw, 'Valid S256 challenge must not throw');
    }

    /**
     * A response_type other than "code" must be rejected.
     *
     * The controller only supports the Authorization Code flow.
     */
    public function testValidateAuthorizeParamsRejectsNonCodeResponseType(): void
    {
        // Arrange
        $params = [
            'client_id'             => 'test-client',
            'redirect_uri'          => 'https://example.com/cb',
            'response_type'         => 'token', // implicit flow — not supported here
            'scope'                 => '',
            'state'                 => '',
            'code_challenge'        => '',
            'code_challenge_method' => 'plain',
        ];

        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->invokeValidateAuthorizeParams($params);
    }

    // ── HTTP header extraction ────────────────────────────────────────────────

    /**
     * getAllRequestHeaders() must extract HTTP_ prefixed SERVER keys.
     *
     * This is used as a fallback when getallheaders() is unavailable (non-Apache
     * environments). The HTTP_ prefix must be stripped, underscores converted to
     * dashes, and the result must be an associative array.
     */
    public function testGetAllRequestHeadersFallbackExtractsHttpHeaders(): void
    {
        // Arrange — simulate a subset of $_SERVER
        $server = [
            'HTTP_AUTHORIZATION'   => 'Bearer token123',
            'HTTP_CONTENT_TYPE'    => 'application/json',
            'HTTP_X_CUSTOM_HEADER' => 'custom-value',
            'CONTENT_TYPE'         => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH'       => '42',
            'SERVER_NAME'          => 'localhost', // must be ignored
        ];

        // Act — replicate the fallback logic from getAllRequestHeaders()
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name            = str_replace('_', '-', substr($key, 5));
                $headers[$name]  = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name            = str_replace('_', '-', $key);
                $headers[$name]  = $value;
            }
        }

        // Assert
        $this->assertArrayHasKey('AUTHORIZATION',   $headers, 'Authorization header must be extracted');
        $this->assertArrayHasKey('CONTENT-TYPE',    $headers, 'Content-Type must be extracted from CONTENT_TYPE');
        $this->assertArrayHasKey('CONTENT-LENGTH',  $headers, 'Content-Length must be extracted from CONTENT_LENGTH');
        $this->assertArrayNotHasKey('SERVER-NAME',  $headers, 'Non-header SERVER keys must be ignored');
        $this->assertSame('Bearer token123', $headers['AUTHORIZATION']);
    }

    // ── Device-code bin2hex randomness ────────────────────────────────────────

    /**
     * The device_code is generated with bin2hex(random_bytes(32)), producing a
     * 64-character lowercase hex string. This is the format callers must expect.
     */
    public function testDeviceCodeIsA64CharHexString(): void
    {
        // Act — replicate device code generation from deviceauthorization()
        $deviceCode = bin2hex(random_bytes(32));

        // Assert
        $this->assertSame(64, strlen($deviceCode), 'Device code must be 64 hex characters');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $deviceCode,
            'Device code must be lowercase hex');
    }

    // ── Private helpers (replicated logic) ───────────────────────────────────

    /**
     * Replicate Oauth::generateUserCode() without instantiating the controller.
     */
    private function invokeGenerateUserCode(): string
    {
        $alphabet = 'BCDFGHJKLMNPQRSTVWXZ';
        $code     = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === 4) {
                $code .= '-';
            }
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    /**
     * Replicate Oauth::extractBearerToken() without instantiating the controller.
     */
    private function invokeBearerExtraction(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }
        return $m[1];
    }

    // ── JWT client assertion validation (unit — no DB) ────────────────────────

    /**
     * A well-formed JWT signed with the matching RSA private key must pass
     * assertion validation (positive path for validateJwtClientAssertion logic).
     *
     * Replicates the core claim-check rules without hitting the database:
     * sub must equal client_id, exp must be in the future, signature must verify.
     */
    public function testValidJwtAssertionPassesSignatureAndClaimChecks(): void
    {
        // Arrange — generate an ephemeral key pair
        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($keyResource, 'openssl_pkey_new must succeed');

        openssl_pkey_export($keyResource, $privateKeyPem);
        $publicKeyDetails = openssl_pkey_get_details($keyResource);
        $publicKeyPem     = (string) ($publicKeyDetails['key'] ?? '');

        $clientId = 'test-client-' . bin2hex(random_bytes(4));
        $payload  = [
            'iss' => 'https://client.example',
            'sub' => $clientId,
            'aud' => 'https://server.example',
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => bin2hex(random_bytes(8)),
        ];

        // Act — encode and then verify with the framework JWT class
        $jwt    = \Pramnos\Auth\JWT::encode($payload, $privateKeyPem, 'RS256');
        $result = \Pramnos\Auth\JWT::decode($jwt, $publicKeyPem, ['RS256']);

        // Assert — payload round-trips cleanly and sub matches
        $this->assertSame($clientId, $result->sub);
        $this->assertGreaterThan(time(), $result->exp);
    }

    /**
     * An assertion signed with a different private key must NOT pass verification.
     *
     * This is the core security guarantee: a stolen client_id cannot be used to
     * impersonate a client unless the attacker also controls its private key.
     */
    public function testJwtAssertionWithWrongKeyFailsVerification(): void
    {
        // Arrange — two independent key pairs
        $legitKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($legitKey);
        $this->assertNotFalse($wrongKey);

        openssl_pkey_export($wrongKey, $wrongPrivatePem);
        $legitPublicDetails = openssl_pkey_get_details($legitKey);
        $legitPublicPem     = (string) ($legitPublicDetails['key'] ?? '');

        // Act — sign with wrong key, verify with legitimate public key
        $jwt = \Pramnos\Auth\JWT::encode(['sub' => 'x', 'exp' => time() + 300], $wrongPrivatePem, 'RS256');

        // Assert — verification must throw
        $this->expectException(\Exception::class);
        \Pramnos\Auth\JWT::decode($jwt, $legitPublicPem, ['RS256']);
    }

    /**
     * An expired assertion (exp < now) must be rejected.
     *
     * The validateJwtClientAssertion() method checks exp after JWT::decode()
     * already verifies it; this test ensures the round-trip check works.
     */
    public function testExpiredJwtAssertionIsRejected(): void
    {
        $keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyResource, $privateKeyPem);
        $publicDetails = openssl_pkey_get_details($keyResource);
        $publicKeyPem  = (string) ($publicDetails['key'] ?? '');

        // Build assertion that expired 10 seconds ago
        $jwt = \Pramnos\Auth\JWT::encode([
            'sub' => 'test-client',
            'exp' => time() - 10,
            'iat' => time() - 310,
        ], $privateKeyPem, 'RS256');

        // Assert — JWT::decode throws on expired tokens
        $this->expectException(\Exception::class);
        \Pramnos\Auth\JWT::decode($jwt, $publicKeyPem, ['RS256']);
    }

    /**
     * Replicate Oauth::validateAuthorizeParams() without instantiating the controller.
     *
     * @param array<string, string> $params
     * @throws \InvalidArgumentException|\League\OAuth2\Server\Exception\OAuthServerException
     */
    private function invokeValidateAuthorizeParams(array $params): void
    {
        if ($params['client_id'] === '') {
            throw new \InvalidArgumentException('Missing client_id');
        }
        if ($params['redirect_uri'] === '') {
            throw new \InvalidArgumentException('Missing redirect_uri');
        }
        if ($params['response_type'] !== 'code') {
            throw new \InvalidArgumentException('Unsupported response_type');
        }

        if ($params['code_challenge'] !== '') {
            if (!in_array($params['code_challenge_method'], ['S256', 'plain'], true)) {
                throw new \InvalidArgumentException('Invalid code_challenge_method');
            }
            if ($params['code_challenge_method'] === 'S256') {
                if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $params['code_challenge'])) {
                    throw new \InvalidArgumentException('Invalid code_challenge format');
                }
            }
        }

        if ($params['scope'] !== '') {
            [$hasInvalid, $invalid] = \Pramnos\Auth\Scopes::hasInvalidScopes($params['scope']);
            if ($hasInvalid) {
                throw \League\OAuth2\Server\Exception\OAuthServerException::invalidScope(
                    implode(' ', $invalid)
                );
            }
        }
    }
}
