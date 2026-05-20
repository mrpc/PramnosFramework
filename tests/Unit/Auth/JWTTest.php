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
    // Static asymmetric key material — generated once per class
    // =========================================================================

    /** @var string RSA 2048-bit private key PEM */
    private static string $rsaPrivateKey = '';
    /** @var string RSA 2048-bit public key PEM */
    private static string $rsaPublicKey = '';
    /** @var \OpenSSLAsymmetricKey|null Raw key object for testing the OpenSSL-object path */
    private static ?\OpenSSLAsymmetricKey $rsaKeyObject = null;
    /** @var string ECDSA P-256 private key PEM (ES256) */
    private static string $ecP256Private = '';
    /** @var string ECDSA P-256 public key PEM (ES256) */
    private static string $ecP256Public = '';
    /** @var string ECDSA P-384 private key PEM (ES384) */
    private static string $ecP384Private = '';
    /** @var string ECDSA P-384 public key PEM (ES384) */
    private static string $ecP384Public = '';
    /** @var string ECDSA P-521 private key PEM (ES512) */
    private static string $ecP521Private = '';
    /** @var string ECDSA P-521 public key PEM (ES512) */
    private static string $ecP521Public = '';

    /**
     * Generate asymmetric key pairs once for the entire test class to avoid
     * repeated expensive key generation in individual test methods.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // RSA 2048-bit — covers RS256/RS384/RS512/PS256/PS384/PS512
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($rsa !== false) {
            openssl_pkey_export($rsa, self::$rsaPrivateKey);
            $d = openssl_pkey_get_details($rsa);
            if (is_array($d)) {
                self::$rsaPublicKey = $d['key'];
            }
            self::$rsaKeyObject = $rsa;
        }

        // ECDSA P-256 — for ES256
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($ec !== false) {
            openssl_pkey_export($ec, self::$ecP256Private);
            $d = openssl_pkey_get_details($ec);
            if (is_array($d)) {
                self::$ecP256Public = $d['key'];
            }
        }

        // ECDSA P-384 — for ES384
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        if ($ec !== false) {
            openssl_pkey_export($ec, self::$ecP384Private);
            $d = openssl_pkey_get_details($ec);
            if (is_array($d)) {
                self::$ecP384Public = $d['key'];
            }
        }

        // ECDSA P-521 — for ES512
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp521r1']);
        if ($ec !== false) {
            openssl_pkey_export($ec, self::$ecP521Private);
            $d = openssl_pkey_get_details($ec);
            if (is_array($d)) {
                self::$ecP521Public = $d['key'];
            }
        }
    }

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

    // =========================================================================
    // decode() — additional edge cases
    // =========================================================================

    /**
     * decode() throws DomainException when the header's 'alg' field names an
     * algorithm that is entirely absent from $supported_algs.  This is distinct
     * from the "not allowed" error: "not supported" means the server has no
     * implementation at all; "not allowed" means the caller restricted the set.
     */
    public function testDecodeThrowsWhenAlgorithmIsAbsentFromSupportedAlgs(): void
    {
        // Arrange — craft a JWT whose header claims an unknown algorithm
        $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'FOO999'])), '+/', '-_'), '=');
        $body   = rtrim(strtr(base64_encode(json_encode(['uid' => 1])), '+/', '-_'), '=');
        $jwt    = "{$header}.{$body}.fakesig";

        // Act + Assert — line 130: the supported_algs check fires before the allowed_algs check
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Algorithm not supported');
        JWT::decode($jwt, 'secret', ['FOO999']);
    }

    /**
     * decode() throws DomainException when the JWT header has no 'alg' field.
     * An algorithm claim is mandatory — the server must refuse to guess.
     */
    public function testDecodeThrowsWhenHeaderHasNoAlgorithm(): void
    {
        // Arrange — craft a 3-segment JWT whose header omits 'alg'
        $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT'])), '+/', '-_'), '=');
        $body   = rtrim(strtr(base64_encode(json_encode(['uid' => 1])), '+/', '-_'), '=');
        $jwt    = "{$header}.{$body}.fakesig";

        // Act + Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Empty algorithm');
        JWT::decode($jwt, 'secret', ['HS256']);
    }

    /**
     * decode() throws UnexpectedValueException when the body segment cannot be
     * decoded as valid JSON.  A corrupt payload must not silently return null.
     */
    public function testDecodeThrowsOnInvalidPayloadEncoding(): void
    {
        // Arrange — valid header, garbage body
        $header  = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'])), '+/', '-_'), '=');
        $garbage = rtrim(strtr(base64_encode('not-valid-json'), '+/', '-_'), '=');
        $jwt     = "{$header}.{$garbage}.fakesig";

        // Act + Assert — null key skips signature check but payload check runs first
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid claims encoding');
        JWT::decode($jwt, null, []);
    }

    /**
     * decode() with an array of keys picks the correct key via the 'kid' claim
     * in the JWT header.  This is the standard key-rotation lookup path.
     */
    public function testDecodeWithKeyArraySelectsKeyByKid(): void
    {
        // Arrange — two keys in rotation; token is signed with key2
        $key1 = str_repeat('a', 32);
        $key2 = str_repeat('b', 32);
        $kid  = 'key-v2';
        $jwt  = JWT::encode(['uid' => 50, 'exp' => time() + 60], $key2, 'HS256', $kid);

        // Act — pass both keys; the kid in the header must select key2
        $decoded = JWT::decode($jwt, [$kid => $key2, 'key-v1' => $key1], ['HS256']);

        // Assert
        $this->assertSame(50, $decoded->uid);
    }

    /**
     * decode() throws DomainException when the token's 'kid' is present but
     * does not match any key in the provided array.  Prevents silent fallback.
     */
    public function testDecodeThrowsWhenKidNotFoundInKeyArray(): void
    {
        // Arrange — token signed with 'known-kid', but array only has 'other-kid'
        $key = str_repeat('k', 32);
        $jwt = JWT::encode(['uid' => 51, 'exp' => time() + 60], $key, 'HS256', 'known-kid');

        // Act + Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Key ID not found');
        JWT::decode($jwt, ['other-kid' => $key], ['HS256']);
    }

    /**
     * decode() throws DomainException when the key argument is an array but the
     * token has no 'kid' header claim — cannot select a key without an identifier.
     */
    public function testDecodeThrowsWhenKeyIsArrayButTokenHasNoKid(): void
    {
        // Arrange — token encoded without a kid, but caller passes key array
        $key = str_repeat('m', 32);
        $jwt = JWT::encode(['uid' => 52, 'exp' => time() + 60], $key, 'HS256');

        // Act + Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('"kid" empty');
        JWT::decode($jwt, ['some-kid' => $key], ['HS256']);
    }

    /**
     * decode() throws UnexpectedValueException when the 'nbf' (not-before) claim
     * is in the future.  Even a valid signature must not bypass the activation time.
     */
    public function testDecodeThrowsForTokenNotYetValid(): void
    {
        // Arrange — nbf is 5 minutes in the future
        $key = str_repeat('n', 32);
        $jwt = JWT::encode([
            'uid' => 60,
            'nbf' => time() + 300,
            'exp' => time() + 600,
        ], $key, 'HS256');

        // Act + Assert
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/Cannot handle token prior to/');
        JWT::decode($jwt, $key, ['HS256']);
    }

    /**
     * decode() throws UnexpectedValueException when the 'iat' (issued-at) claim
     * is in the future.  Pre-issued tokens must be rejected to prevent replay attacks
     * with backdated issuance windows.
     */
    public function testDecodeThrowsForTokenWithFutureIssuedAt(): void
    {
        // Arrange — iat is 5 minutes in the future
        $key = str_repeat('i', 32);
        $jwt = JWT::encode([
            'uid' => 61,
            'iat' => time() + 300,
            'exp' => time() + 600,
        ], $key, 'HS256');

        // Act + Assert
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/Cannot handle token prior to/');
        JWT::decode($jwt, $key, ['HS256']);
    }

    // =========================================================================
    // encode() — additional features
    // =========================================================================

    /**
     * encode() with a non-null $keyId sets the 'kid' header claim so that
     * multi-key consumers can select the correct verification key.
     */
    public function testEncodeWithKeyIdSetsKidInHeader(): void
    {
        // Arrange
        $payload = ['uid' => 80];
        $keyId   = 'rotation-key-001';

        // Act
        $token  = JWT::encode($payload, self::SECRET, 'HS256', $keyId);
        $header = JWT::getTokenInformation($token);

        // Assert — kid claim must be present and exact
        $this->assertIsObject($header);
        $this->assertSame($keyId, $header->kid);
    }

    /**
     * encode() throws DomainException when the payload cannot be serialized to
     * JSON (e.g. invalid UTF-8 byte sequences).  The error must not be swallowed.
     */
    public function testEncodeThrowsWhenPayloadCannotBeJsonEncoded(): void
    {
        // Arrange — array containing a raw non-UTF-8 byte sequence
        $badPayload = ['data' => "\xB1\x31"];

        // Act + Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Unable to encode payload to JSON');
        JWT::encode($badPayload, self::SECRET, 'HS256');
    }

    // =========================================================================
    // HMAC family — HS384 / HS512
    // =========================================================================

    /**
     * HS384 tokens round-trip correctly through encode/decode.
     * Exercises the HS384 branch in getAlgorithmsByName().
     */
    public function testEncodeDecodeRoundTripWithHs384(): void
    {
        // Arrange
        $payload = ['uid' => 70, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::SECRET, 'HS384');
        $decoded = JWT::decode($token, self::SECRET, ['HS384']);

        // Assert
        $this->assertSame(70, $decoded->uid);
    }

    /**
     * HS512 tokens round-trip correctly through encode/decode.
     * HS512 requires at least 512 bits (64 bytes) per the JWK spec, so we use
     * a dedicated key rather than the shared SECRET constant.
     * Exercises the HS512 branch in getAlgorithmsByName().
     */
    public function testEncodeDecodeRoundTripWithHs512(): void
    {
        // Arrange — 64-byte key satisfies the HS512 minimum requirement
        $key512  = str_repeat('s', 64);
        $payload = ['uid' => 71, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, $key512, 'HS512');
        $decoded = JWT::decode($token, $key512, ['HS512']);

        // Assert
        $this->assertSame(71, $decoded->uid);
    }

    // =========================================================================
    // RSA family — RS384 / RS512
    // (RS256 is already exercised by the OAuth2 integration test suite)
    // =========================================================================

    /**
     * RS384 tokens round-trip: sign with RSA private key, verify with public key.
     * Exercises the RS384 branch in getAlgorithmsByName().
     */
    public function testEncodeDecodeRoundTripWithRs384(): void
    {
        // Arrange
        if (self::$rsaPrivateKey === '') {
            $this->markTestSkipped('RSA key generation not available in this environment');
        }
        $payload = ['uid' => 101, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::$rsaPrivateKey, 'RS384');
        $decoded = JWT::decode($token, self::$rsaPublicKey, ['RS384']);

        // Assert — payload recovered through RSA384 signature round-trip
        $this->assertSame(101, $decoded->uid);
    }

    /**
     * RS512 tokens round-trip correctly with RSA keys.
     * Exercises the RS512 branch in getAlgorithmsByName().
     */
    public function testEncodeDecodeRoundTripWithRs512(): void
    {
        // Arrange
        if (self::$rsaPrivateKey === '') {
            $this->markTestSkipped('RSA key generation not available in this environment');
        }
        $payload = ['uid' => 102, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::$rsaPrivateKey, 'RS512');
        $decoded = JWT::decode($token, self::$rsaPublicKey, ['RS512']);

        // Assert
        $this->assertSame(102, $decoded->uid);
    }

    // =========================================================================
    // ECDSA family — ES256 / ES384 / ES512
    // =========================================================================

    /**
     * ES256 tokens round-trip: sign with P-256 private key, verify with public key.
     * Exercises the ES256 branch in getAlgorithmsByName().
     */
    public function testEncodeDecodeRoundTripWithEs256(): void
    {
        // Arrange
        if (self::$ecP256Private === '') {
            $this->markTestSkipped('EC P-256 key generation not available');
        }
        $payload = ['uid' => 110, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::$ecP256Private, 'ES256');
        $decoded = JWT::decode($token, self::$ecP256Public, ['ES256']);

        // Assert
        $this->assertSame(110, $decoded->uid);
    }

    /**
     * ES384 tokens round-trip with P-384 keys.
     * Exercises the ES384 branch in getAlgorithmsByName().
     */
    public function testEncodeDecodeRoundTripWithEs384(): void
    {
        // Arrange
        if (self::$ecP384Private === '') {
            $this->markTestSkipped('EC P-384 key generation not available');
        }
        $payload = ['uid' => 111, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::$ecP384Private, 'ES384');
        $decoded = JWT::decode($token, self::$ecP384Public, ['ES384']);

        // Assert
        $this->assertSame(111, $decoded->uid);
    }

    /**
     * ES512 tokens round-trip with P-521 keys.
     * Exercises the ES512 branch in getAlgorithmsByName().
     */
    public function testEncodeDecodeRoundTripWithEs512(): void
    {
        // Arrange
        if (self::$ecP521Private === '') {
            $this->markTestSkipped('EC P-521 key generation not available');
        }
        $payload = ['uid' => 112, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::$ecP521Private, 'ES512');
        $decoded = JWT::decode($token, self::$ecP521Public, ['ES512']);

        // Assert
        $this->assertSame(112, $decoded->uid);
    }

    // =========================================================================
    // RSA-PSS family — PS256 / PS384 / PS512
    // =========================================================================

    /**
     * PS256 (RSA-PSS + SHA-256) tokens round-trip with RSA keys.
     * Exercises the PS256 branch in getAlgorithmsByName().
     * @note web-token/jwt-framework 3.x calls chr() with out-of-range values in
     *       its RSA padding helper (fixed upstream in v4.x); ignore until upgraded.
     */
    #[\PHPUnit\Framework\Attributes\IgnoreDeprecations]
    public function testEncodeDecodeRoundTripWithPs256(): void
    {
        // Arrange
        if (self::$rsaPrivateKey === '') {
            $this->markTestSkipped('RSA key generation not available');
        }
        $payload = ['uid' => 120, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::$rsaPrivateKey, 'PS256');
        $decoded = JWT::decode($token, self::$rsaPublicKey, ['PS256']);

        // Assert
        $this->assertSame(120, $decoded->uid);
    }

    /**
     * PS384 tokens round-trip with RSA keys.
     * Exercises the PS384 branch in getAlgorithmsByName().
     * @note web-token/jwt-framework 3.x calls chr() with out-of-range values in
     *       its RSA padding helper (fixed upstream in v4.x); ignore until upgraded.
     */
    #[\PHPUnit\Framework\Attributes\IgnoreDeprecations]
    public function testEncodeDecodeRoundTripWithPs384(): void
    {
        // Arrange
        if (self::$rsaPrivateKey === '') {
            $this->markTestSkipped('RSA key generation not available');
        }
        $payload = ['uid' => 121, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::$rsaPrivateKey, 'PS384');
        $decoded = JWT::decode($token, self::$rsaPublicKey, ['PS384']);

        // Assert
        $this->assertSame(121, $decoded->uid);
    }

    /**
     * PS512 tokens round-trip with RSA keys.
     * Exercises the PS512 branch in getAlgorithmsByName().
     * @note web-token/jwt-framework 3.x calls chr() with out-of-range values in
     *       its RSA padding helper (fixed upstream in v4.x); ignore until upgraded.
     */
    #[\PHPUnit\Framework\Attributes\IgnoreDeprecations]
    public function testEncodeDecodeRoundTripWithPs512(): void
    {
        // Arrange
        if (self::$rsaPrivateKey === '') {
            $this->markTestSkipped('RSA key generation not available');
        }
        $payload = ['uid' => 122, 'exp' => time() + 60];

        // Act
        $token   = JWT::encode($payload, self::$rsaPrivateKey, 'PS512');
        $decoded = JWT::decode($token, self::$rsaPublicKey, ['PS512']);

        // Assert
        $this->assertSame(122, $decoded->uid);
    }

    // =========================================================================
    // sign() — openssl path
    // =========================================================================

    /**
     * sign() with RS256 produces a binary signature that can be verified with
     * the matching public key via openssl_verify().  Exercises the 'openssl'
     * branch of sign()'s switch statement.
     */
    public function testSignWithRs256ProducesVerifiableSignature(): void
    {
        // Arrange
        if (self::$rsaPrivateKey === '') {
            $this->markTestSkipped('RSA key generation not available');
        }
        $message = 'header.payload';

        // Act
        $signature = JWT::sign($message, self::$rsaPrivateKey, 'RS256');

        // Assert — non-empty binary output that verifies with the public key
        $this->assertGreaterThan(0, strlen($signature));
        $ok = openssl_verify($message, $signature, self::$rsaPublicKey, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $ok, 'RS256 signature must verify with the matching public key');
    }

    /**
     * sign() throws DomainException when openssl_sign() fails, which happens
     * when the supplied key cannot be used for signing (e.g. invalid PEM).
     * Exercises the failure branch inside the 'openssl' case of sign().
     * PHP emits a warning before returning false; we silence it intentionally.
     */
    public function testSignThrowsWhenOpenSslSignFails(): void
    {
        // Arrange — 'not-valid-pem' is not a usable private key for openssl_sign
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('OpenSSL unable to sign data');

        // Act — openssl_sign emits a warning then returns false; DomainException follows
        set_error_handler(static fn() => true); // silence the openssl warning for this call
        try {
            JWT::sign('any message', 'not-valid-pem', 'RS256');
        } finally {
            restore_error_handler();
        }
    }

    // =========================================================================
    // createJWKFromKey() — OpenSSLAsymmetricKey object path
    // =========================================================================

    /**
     * decode() accepts an OpenSSLAsymmetricKey object as the $key argument.
     * createJWKFromKey() must convert it to PEM before passing to JWKFactory.
     * Exercises the is_resource / instanceof OpenSSLAsymmetricKey branch.
     */
    public function testDecodeWithOpenSslKeyObjectConvertsToJwk(): void
    {
        // Arrange — encode with PEM string, then decode with the raw key object
        if (self::$rsaPrivateKey === '' || self::$rsaKeyObject === null) {
            $this->markTestSkipped('RSA key generation not available');
        }
        $token = JWT::encode(['uid' => 95, 'exp' => time() + 60], self::$rsaPrivateKey, 'RS256');

        // Get the public key as an OpenSSLAsymmetricKey object (not PEM)
        $pubKeyObj = openssl_pkey_get_public(self::$rsaPublicKey);
        if ($pubKeyObj === false) {
            $this->markTestSkipped('openssl_pkey_get_public() failed');
        }

        // Act — decode() receives an OpenSSLAsymmetricKey, must extract its PEM internally
        $decoded = JWT::decode($token, $pubKeyObj, ['RS256']);

        // Assert — payload is correctly recovered through the key-object path
        $this->assertSame(95, $decoded->uid);
    }

    // =========================================================================
    // verifyWithWebToken() — exception catch path
    // =========================================================================

    /**
     * verifyWithWebToken() catches any exception thrown by JWKFactory and
     * returns false, which causes decode() to throw 'Signature verification failed'.
     *
     * To trigger this: forge a JWT that claims RS256 but supply a plain string
     * key that is not valid PEM — JWKFactory::createFromKey() will throw, hitting
     * the catch block in verifyWithWebToken().
     */
    public function testDecodeHandlesJwkCreationExceptionAsSignatureFailure(): void
    {
        // Arrange — forge a JWT header claiming RS256; body and sig don't matter
        $fakeHeader = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'RS256'])), '+/', '-_'), '=');
        $body       = rtrim(strtr(base64_encode(json_encode(['uid' => 99, 'exp' => time() + 60])), '+/', '-_'), '=');
        $jwt        = "{$fakeHeader}.{$body}.fakesig";

        // Act + Assert — non-PEM string triggers JWKFactory exception → caught → false → throws
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Signature verification failed');
        JWT::decode($jwt, 'not-a-valid-pem-key', ['RS256']);
    }

    // =========================================================================
    // getAlgorithmsByName() — default (unknown algorithm) path
    // =========================================================================

    /**
     * getAlgorithmsByName() throws DomainException for an algorithm name that
     * is in $supported_algs but not handled by the internal switch statement.
     *
     * This branch is unreachable under normal use; the test temporarily injects
     * a synthetic algorithm to exercise the default case (dead-code guard).
     */
    public function testGetAlgorithmsByNameThrowsForAlgorithmNotInSwitch(): void
    {
        // Arrange — inject a synthetic alg that passes the supported_algs check
        // but falls through to the default case in getAlgorithmsByName()
        JWT::$supported_algs['_TEST_UNSUPPORTED'] = ['openssl', 'SHA256'];

        try {
            // Act — encode() calls encodeWithWebToken() → getAlgorithmsByName()
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('Algorithm not supported');
            JWT::encode(['uid' => 1], 'any-key', '_TEST_UNSUPPORTED');
        } finally {
            // Restore static state regardless of outcome
            unset(JWT::$supported_algs['_TEST_UNSUPPORTED']);
        }
    }

    // =========================================================================
    // b64UrlEncode() — private utility (inverse of b64UrlDecode)
    // =========================================================================

    /**
     * b64UrlEncode() is the inverse of b64UrlDecode(): round-tripping any
     * binary string through encode → decode must return the original value.
     * Verified via reflection since the method is private.
     */
    public function testB64UrlEncodeIsInverseOfB64UrlDecode(): void
    {
        // Arrange — arbitrary binary string with characters that need URL-safe encoding
        $original = "Hello+World/Test==\x00\xFF";

        // Act — call both private methods via reflection
        $encode = new \ReflectionMethod(JWT::class, 'b64UrlEncode');
        $decode = new \ReflectionMethod(JWT::class, 'b64UrlDecode');

        $encoded = $encode->invoke(null, $original);
        $decoded = $decode->invoke(null, $encoded);

        // Assert — must round-trip to the original binary value
        $this->assertSame($original, $decoded);
        // A URL-safe base64 string must not contain +, /, or = characters
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }
}
