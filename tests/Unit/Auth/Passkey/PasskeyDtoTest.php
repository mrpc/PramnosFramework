<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Passkey;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\Config;
use Pramnos\Auth\Passkey\PasskeyCredential;
use Pramnos\Auth\Passkey\RegistrationOptions;
use Pramnos\Auth\Passkey\VerificationResult;

/**
 * Unit tests for the passkey value objects and RP config.
 *
 * WHAT: the framework-owned DTOs that cross the passkey public boundary —
 *       their (de)serialisation for the challenge store, the public
 *       (secret-free) credential view, and Config's settings/URL derivation.
 * WHY:  these types are the anti-corruption contract; the challenge store
 *       round-trip (toArray/fromArray) must be lossless or a finish step would
 *       verify against the wrong options, and toPublicArray must never leak the
 *       COSE public key.
 */
class PasskeyDtoTest extends TestCase
{
    /** RegistrationOptions survives a store round-trip unchanged. */
    public function testRegistrationOptionsRoundTrip(): void
    {
        // Arrange
        $opts = new RegistrationOptions('chal', '{"rp":{"id":"x"}}', 7);

        // Act
        $restored = RegistrationOptions::fromArray($opts->toArray());

        // Assert
        $this->assertSame('chal', $restored->challenge);
        $this->assertSame(7, $restored->userId);
        $this->assertSame(['id' => 'x'], $restored->toClientArray()['rp']);
    }

    /** RegistrationOptions with malformed JSON yields an empty client array. */
    public function testRegistrationOptionsMalformedJson(): void
    {
        $opts = new RegistrationOptions('c', 'not-json', 1);
        $this->assertSame([], $opts->toClientArray());
    }

    /** AuthenticationOptions preserves a null user id (usernameless). */
    public function testAuthenticationOptionsNullUser(): void
    {
        $opts = AuthenticationOptions::fromArray(['challenge' => 'c', 'json' => '{}', 'userId' => null]);
        $this->assertNull($opts->userId);
        // And a present user id is coerced to int.
        $opts2 = AuthenticationOptions::fromArray(['challenge' => 'c', 'json' => '{}', 'userId' => '9']);
        $this->assertSame(9, $opts2->userId);
    }

    /** withSignCount returns a copy with only the counter changed. */
    public function testCredentialWithSignCount(): void
    {
        // Arrange
        $cred = new PasskeyCredential(1, 42, 'cid', 'pk', 3, 'aaguid', ['internal'], 'My key');

        // Act
        $updated = $cred->withSignCount(10);

        // Assert — counter changes, everything else preserved, original untouched.
        $this->assertSame(10, $updated->signCount);
        $this->assertSame(3, $cred->signCount, 'Original is immutable');
        $this->assertSame('My key', $updated->name);
        $this->assertSame(42, $updated->userId);
    }

    /** toPublicArray exposes management fields but NEVER the public key. */
    public function testCredentialPublicArrayHidesPublicKey(): void
    {
        // Arrange
        $cred = new PasskeyCredential(1, 42, 'cid', 'SECRET-COSE-KEY', 3, null, [], 'Key');

        // Act
        $public = $cred->toPublicArray();

        // Assert
        $this->assertArrayNotHasKey('public_key', $public, 'COSE key must not leak');
        $this->assertSame('cid', $public['credential_id']);
        $this->assertSame('Key', $public['name']);
    }

    /** VerificationResult carries the resolved user and updated counter. */
    public function testVerificationResult(): void
    {
        $cred = new PasskeyCredential(1, 42, 'cid', 'pk', 5);
        $result = new VerificationResult(42, $cred, 5);
        $this->assertSame(42, $result->userId);
        $this->assertSame(5, $result->signCount);
        $this->assertSame($cred, $result->credential);
    }

    /** Config derives RP id and origin from a site URL when no override is set. */
    public function testConfigFromSiteUrl(): void
    {
        // Act — no dedicated passkey settings, so it falls back to the site URL.
        $config = Config::fromSettings('https://auth.example.com:8443/path');

        // Assert
        $this->assertSame('auth.example.com', $config->rpId, 'RP id = host');
        $this->assertContains('https://auth.example.com:8443', $config->allowedOrigins);
        $this->assertSame('none', Config::ATTESTATION);
    }

    /** Config falls back safely when the site URL cannot be parsed. */
    public function testConfigWithUnparseableUrl(): void
    {
        $config = Config::fromSettings('');
        // No host → empty RP id / origins, but the object is still usable.
        $this->assertSame('', $config->rpId);
        $this->assertSame([], $config->allowedOrigins);
        $this->assertSame('preferred', $config->userVerification);
    }

    /**
     * Config honours explicit passkey_* settings: a comma-separated origins list
     * and an invalid user-verification value that falls back to 'preferred'.
     */
    public function testConfigFromExplicitSettings(): void
    {
        // Arrange — in-memory settings (not persisted).
        \Pramnos\Application\Settings::setSetting('passkey_rp_id', 'rp.test', false);
        \Pramnos\Application\Settings::setSetting('passkey_origins', 'https://a.test, https://b.test', false);
        \Pramnos\Application\Settings::setSetting('passkey_user_verification', 'bogus', false);

        try {
            // Act
            $config = Config::fromSettings('https://ignored.test');

            // Assert — explicit list parsed; invalid UV coerced to the default.
            $this->assertSame('rp.test', $config->rpId);
            $this->assertSame(['https://a.test', 'https://b.test'], $config->allowedOrigins);
            $this->assertSame('preferred', $config->userVerification);
        } finally {
            // Cleanup — reset the in-memory settings.
            \Pramnos\Application\Settings::setSetting('passkey_rp_id', '', false);
            \Pramnos\Application\Settings::setSetting('passkey_origins', '', false);
            \Pramnos\Application\Settings::setSetting('passkey_user_verification', '', false);
        }
    }

    /** A directly-constructed Config keeps its explicit values. */
    public function testConfigDirect(): void
    {
        $config = new Config('rp.id', 'RP Name', ['https://a', 'https://b'], 30000, 'required');
        $this->assertSame('rp.id', $config->rpId);
        $this->assertSame('RP Name', $config->rpName);
        $this->assertSame(30000, $config->timeout);
        $this->assertSame('required', $config->userVerification);
        $this->assertCount(2, $config->allowedOrigins);
    }
}
