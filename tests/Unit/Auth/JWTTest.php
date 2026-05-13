<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\ExpiredException;
use Pramnos\Auth\JWT;

/**
 * Unit tests for Pramnos\Auth\JWT.
 *
 * JWT is used throughout the framework for session tokens, API keys, and OAuth
 * access tokens.  The tests cover:
 *  - encode() / decode() round-trip for HS256 (the default HMAC algorithm)
 *  - getTokenInformation() header parsing without signature verification
 *  - sign() HMAC output is deterministic
 *  - decode() error paths (malformed token, wrong segment count, expired token)
 *
 * RS256 and ECDSA tests are omitted because they require key-pair generation;
 * those algorithms are covered by the OAuth2 integration test suite.
 */
#[CoversClass(JWT::class)]
#[CoversClass(ExpiredException::class)]
class JWTTest extends TestCase
{
    /**
     * Shared test secret — HS256 requires at least 256 bits (32 bytes) per the
     * JWK spec enforced by web-token/jwt-framework.
     */
    private const SECRET = 'test-secret-key-pramnos-2024-framework-unit-test';

    // =========================================================================
    // encode / decode round-trip
    // =========================================================================

    /**
     * encode() produces a three-segment dotted string (header.payload.signature),
     * and decode() recovers the original claims from the same secret.
     */
    public function testEncodeDecodeRoundTripWithHs256(): void
    {
        // Arrange
        $payload = ['sub' => '42', 'name' => 'Alice', 'role' => 'admin'];

        // Act
        $token   = JWT::encode($payload, self::SECRET, 'HS256');
        $decoded = JWT::decode($token, self::SECRET, ['HS256']);

        // Assert – the decoded object contains the original claims
        $this->assertSame('42',    $decoded->sub);
        $this->assertSame('Alice', $decoded->name);
        $this->assertSame('admin', $decoded->role);
    }

    /**
     * encode() always produces a string with exactly two dots (three segments).
     * A compact JWT has the form header.payload.signature.
     */
    public function testEncodeProducesThreeSegmentToken(): void
    {
        // Arrange
        $payload = ['uid' => 1];

        // Act
        $token = JWT::encode($payload, self::SECRET, 'HS256');

        // Assert – exactly two dots → three segments
        $this->assertSame(2, substr_count($token, '.'));
    }

    /**
     * Two consecutive encode() calls with the same payload and secret produce
     * identical tokens (deterministic for HMAC-based algorithms — there is no
     * nonce in HS256).
     */
    public function testEncodeIsDeterministicForSamePayloadAndSecret(): void
    {
        // Arrange
        $payload = ['uid' => 99];

        // Act
        $token1 = JWT::encode($payload, self::SECRET, 'HS256');
        $token2 = JWT::encode($payload, self::SECRET, 'HS256');

        // Assert
        $this->assertSame($token1, $token2);
    }

    /**
     * decode() with a wrong secret throws an exception — the signature
     * verification must fail, preventing token forgery.
     */
    public function testDecodeWithWrongSecretThrows(): void
    {
        // Arrange
        $token = JWT::encode(['uid' => 1], self::SECRET, 'HS256');

        // Assert / Act
        $this->expectException(\Exception::class);
        JWT::decode($token, 'wrong-secret', ['HS256']);
    }

    /**
     * decode() rejects tokens whose algorithm is not in the $allowed_algs list,
     * preventing algorithm-confusion attacks.
     */
    public function testDecodeRejectsDisallowedAlgorithm(): void
    {
        // Arrange – HS256 token, but we only allow HS512
        $token = JWT::encode(['uid' => 2], self::SECRET, 'HS256');

        // Assert / Act
        $this->expectException(\DomainException::class);
        JWT::decode($token, self::SECRET, ['HS512']);
    }

    /**
     * decode() ignores the $key and $allowed_algs arguments when $key is null,
     * returning the payload without any signature verification.
     * This mode is used for trusted-internal token inspection.
     */
    public function testDecodeWithNullKeySkipsSignatureVerification(): void
    {
        // Arrange
        $token = JWT::encode(['uid' => 3, 'role' => 'reader'], self::SECRET, 'HS256');

        // Act – passing null for key skips verification
        $payload = JWT::decode($token, null, []);

        // Assert – payload is still accessible
        $this->assertSame(3,        $payload->uid);
        $this->assertSame('reader', $payload->role);
    }

    /**
     * decode() throws UnexpectedValueException when the token string does not
     * have the three-segment dot-separated structure.
     */
    public function testDecodeThrowsOnMalformedTokenWithTooFewSegments(): void
    {
        // Assert / Act
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Wrong number of segments');
        JWT::decode('not.a.valid.jwt.token', self::SECRET, ['HS256']);
    }

    /**
     * decode() throws UnexpectedValueException when the header segment
     * cannot be decoded as valid JSON.
     */
    public function testDecodeThrowsOnInvalidHeaderEncoding(): void
    {
        // Arrange – construct a token with a garbage header
        $garbage = base64_encode('!!!not-json!!!');
        $token   = $garbage . '.e30.' . 'sig';

        // Assert / Act
        $this->expectException(\UnexpectedValueException::class);
        JWT::decode($token, null, []);
    }

    // =========================================================================
    // Expiry / nbf claims
    // =========================================================================

    /**
     * decode() throws ExpiredException when the token's 'exp' claim is in the
     * past.  This is a critical security check — expired tokens must be rejected.
     */
    public function testDecodeThrowsExpiredExceptionForExpiredToken(): void
    {
        // Arrange – exp in the past (Unix epoch = 1970)
        $payload = ['uid' => 5, 'exp' => 1];
        $token   = JWT::encode($payload, self::SECRET, 'HS256');

        // Assert / Act
        $this->expectException(ExpiredException::class);
        JWT::decode($token, self::SECRET, ['HS256']);
    }

    /**
     * decode() accepts tokens whose 'exp' is in the future.
     */
    public function testDecodeAcceptsTokenWithFutureExpiry(): void
    {
        // Arrange – exp one hour from now
        $payload = ['uid' => 6, 'exp' => time() + 3600];
        $token   = JWT::encode($payload, self::SECRET, 'HS256');

        // Act
        $decoded = JWT::decode($token, self::SECRET, ['HS256']);

        // Assert
        $this->assertSame(6, $decoded->uid);
    }

    // =========================================================================
    // getTokenInformation
    // =========================================================================

    /**
     * getTokenInformation() parses the JWT header and returns the decoded
     * object without verifying the signature.  Useful for inspecting the
     * 'alg' or 'kid' before deciding which key to use.
     */
    public function testGetTokenInformationReturnsDecodedHeader(): void
    {
        // Arrange
        $token = JWT::encode(['uid' => 7], self::SECRET, 'HS256');

        // Act
        $header = JWT::getTokenInformation($token);

        // Assert – standard JWT header claims
        $this->assertIsObject($header);
        $this->assertSame('JWT',  $header->typ);
        $this->assertSame('HS256', $header->alg);
    }

    /**
     * getTokenInformation() returns false for a string that is not a
     * three-segment JWT.
     */
    public function testGetTokenInformationReturnsFalseForMalformedString(): void
    {
        // Arrange / Act
        $result = JWT::getTokenInformation('not-a-token');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * getTokenInformation() returns false when the header segment contains
     * invalid base64/JSON.
     */
    public function testGetTokenInformationReturnsFalseForInvalidHeader(): void
    {
        // Arrange – valid structure but garbage header
        $result = JWT::getTokenInformation('!!!.e30.sig');

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // sign
    // =========================================================================

    /**
     * sign() with HS256 returns a binary string (uses hash_hmac under the hood).
     * The result is deterministic: the same (message, key) pair always produces
     * the same binary digest.
     */
    public function testSignHs256IsDeterministic(): void
    {
        // Arrange
        $message = 'header.payload';

        // Act
        $sig1 = JWT::sign($message, self::SECRET, 'HS256');
        $sig2 = JWT::sign($message, self::SECRET, 'HS256');

        // Assert – both calls return identical binary output
        $this->assertSame($sig1, $sig2);
        // Assert – non-empty binary string (SHA-256 produces 32 bytes)
        $this->assertSame(32, strlen($sig1));
    }

    /**
     * sign() throws DomainException for an algorithm not listed in
     * $supported_algs, preventing silent fallback to no signature.
     */
    public function testSignThrowsForUnsupportedAlgorithm(): void
    {
        // Assert / Act
        $this->expectException(\DomainException::class);
        JWT::sign('message', self::SECRET, 'INVALID_ALG');
    }

    /**
     * sign() output changes when the key changes — different keys must never
     * produce the same HMAC digest for the same message.
     */
    public function testSignProducesDifferentOutputForDifferentKeys(): void
    {
        // Arrange
        $message = 'same.message';

        // Act
        $sig1 = JWT::sign($message, 'key-one', 'HS256');
        $sig2 = JWT::sign($message, 'key-two', 'HS256');

        // Assert
        $this->assertNotSame($sig1, $sig2);
    }

    // =========================================================================
    // $supported_algs static property
    // =========================================================================

    /**
     * The $supported_algs list must include HS256, HS384, HS512, and RS256 at
     * minimum — these are the algorithms used by the OAuth2 server and JWT
     * session middleware.
     */
    public function testSupportedAlgsContainsRequiredAlgorithms(): void
    {
        // Assert
        $this->assertArrayHasKey('HS256', JWT::$supported_algs);
        $this->assertArrayHasKey('HS384', JWT::$supported_algs);
        $this->assertArrayHasKey('HS512', JWT::$supported_algs);
        $this->assertArrayHasKey('RS256', JWT::$supported_algs);
    }
}
