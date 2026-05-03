<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use Pramnos\Auth\ExpiredException;
use Pramnos\Auth\JWT;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for JWT compatibility behavior.
 *
 * These tests lock current observable semantics for token parsing,
 * algorithm validation, signature verification, expiration, and leeway.
 */
class JWTCharacterizationTest extends TestCase
{
    protected function tearDown(): void
    {
        // Arrange
        JWT::$leeway = 0;
    }

    /**
     * Ensures token header parsing returns false for malformed JWT structures.
     */
    public function testGetTokenInformationReturnsFalseForMalformedToken(): void
    {
        // Arrange
        $malformedToken = 'not-a-jwt';

        // Act
        $header = JWT::getTokenInformation($malformedToken);

        // Assert
        $this->assertFalse($header, 'Malformed token should not produce decoded header information.');
    }

    /**
     * Ensures HS256 tokens round-trip through encode/decode with matching key.
     */
    public function testEncodeDecodeRoundTripForHs256(): void
    {
        // Arrange
        $key = str_repeat('a', 32);
        $payload = [
            'sub' => 'user-42',
            'iat' => time(),
            'exp' => time() + 120,
        ];

        // Act
        $jwt = JWT::encode($payload, $key, 'HS256');
        $decoded = JWT::decode($jwt, $key, ['HS256']);

        // Assert
        $this->assertIsObject($decoded);
        $this->assertSame('user-42', $decoded->sub);
        // This proves compatibility for common HMAC signing and claim restoration.
        $this->assertSame($payload['exp'], $decoded->exp);
    }

    /**
     * Ensures decode rejects malformed token segment counts.
     */
    public function testDecodeThrowsForWrongSegmentCount(): void
    {
        // Arrange
        $invalid = 'one.two';

        // Act + Assert
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Wrong number of segments');
        JWT::decode($invalid, 'secret', ['HS256']);
    }

    /**
     * Ensures decode rejects unsupported algorithms before signature verification.
     */
    public function testDecodeThrowsWhenAlgorithmIsNotSupported(): void
    {
        // Arrange
        $header = $this->base64UrlEncodeJson(['typ' => 'JWT', 'alg' => 'FOO256']);
        $payload = $this->base64UrlEncodeJson(['sub' => 'user']);
        $signature = 'signature';
        $jwt = $header . '.' . $payload . '.' . $signature;

        // Act + Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Algorithm not supported');
        JWT::decode($jwt, 'secret', ['FOO256']);
    }

    /**
     * Ensures decode rejects valid algorithms that are not explicitly allowed.
     */
    public function testDecodeThrowsWhenAlgorithmIsNotAllowed(): void
    {
        // Arrange
        $key = str_repeat('b', 32);
        $jwt = JWT::encode(['sub' => 'user', 'exp' => time() + 60], $key, 'HS256');

        // Act + Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Algorithm not allowed');
        JWT::decode($jwt, $key, ['HS384']);
    }

    /**
     * Ensures expired tokens fail with ExpiredException when no leeway is set.
     */
    public function testDecodeThrowsExpiredExceptionForExpiredTokenWithoutLeeway(): void
    {
        // Arrange
        $key = str_repeat('c', 32);
        $jwt = JWT::encode([
            'sub' => 'user',
            'iat' => time() - 30,
            'exp' => time() - 5,
        ], $key, 'HS256');

        // Act + Assert
        $this->expectException(ExpiredException::class);
        JWT::decode($jwt, $key, ['HS256']);
    }

    /**
     * Ensures leeway allows slightly expired tokens due to clock skew.
     */
    public function testDecodeAcceptsSlightlyExpiredTokenWhenLeewayIsSet(): void
    {
        // Arrange
        $key = str_repeat('d', 32);
        JWT::$leeway = 10;
        $jwt = JWT::encode([
            'sub' => 'user',
            'iat' => time() - 30,
            'exp' => time() - 5,
        ], $key, 'HS256');

        // Act
        $decoded = JWT::decode($jwt, $key, ['HS256']);

        // Assert
        $this->assertIsObject($decoded);
        $this->assertSame('user', $decoded->sub);
    }

    /**
     * Ensures sign() uses the expected HMAC implementation for HS256.
     */
    public function testSignProducesExpectedHs256BinarySignature(): void
    {
        // Arrange
        $message = 'payload';
        $key = 'secret';

        // Act
        $signature = JWT::sign($message, $key, 'HS256');

        // Assert
        $this->assertSame(hash_hmac('SHA256', $message, $key, true), $signature);
    }

    /**
     * Ensures sign() rejects unknown algorithms explicitly.
     */
    public function testSignThrowsForUnsupportedAlgorithm(): void
    {
        // Arrange
        $message = 'payload';

        // Act + Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Algorithm not supported');
        JWT::sign($message, 'secret', 'INVALID');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function base64UrlEncodeJson(array $data): string
    {
        $json = json_encode($data);
        if ($json === false) {
            self::fail('JSON encoding failed for test fixture.');
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
