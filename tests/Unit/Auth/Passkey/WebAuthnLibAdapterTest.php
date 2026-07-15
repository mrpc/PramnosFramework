<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Passkey;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Passkey\Config;
use Pramnos\Auth\Passkey\PasskeyException;
use Pramnos\Auth\Passkey\WebAuthnLibAdapter;
use Pramnos\Tests\Fixtures\Passkey\FakeAuthenticator;

/**
 * Round-trip tests for the WebAuthn adapter — the security-critical seam.
 *
 * WHAT: drives full registration (attestation) and authentication (assertion)
 *       ceremonies through {@see WebAuthnLibAdapter} using a software
 *       authenticator ({@see FakeAuthenticator}) that produces byte-exact,
 *       ES256-signed responses just like a real browser + device.
 * WHY:  this is where a mistake becomes an authentication bypass. The tests
 *       assert not only that a genuine ceremony succeeds, but that every forgery
 *       the check exists to stop — a replayed/cloned counter, a tampered
 *       signature, a wrong origin, the wrong ceremony type — is REJECTED. That
 *       is what makes the coverage meaningful (CLAUDE.md §11), not just green.
 */
class WebAuthnLibAdapterTest extends TestCase
{
    private const RP_ID  = 'example.com';
    private const ORIGIN = 'https://example.com';
    private const USER_ID = 42;

    private WebAuthnLibAdapter $adapter;
    private FakeAuthenticator $authenticator;

    protected function setUp(): void
    {
        // Arrange — a fixed RP config and a fresh software authenticator.
        $config = new Config(self::RP_ID, 'Example', [self::ORIGIN], 60000, 'preferred');
        $this->adapter = new WebAuthnLibAdapter($config);
        $this->authenticator = new FakeAuthenticator();
    }

    /** Register once and return the stored credential (helper for auth tests). */
    private function register(): \Pramnos\Auth\Passkey\PasskeyCredential
    {
        $options = $this->adapter->createRegistrationOptions(self::USER_ID, 'alice', 'Alice', []);
        $response = $this->authenticator->attestationResponse(
            $options->challenge,
            self::RP_ID,
            self::ORIGIN,
            0
        );
        return $this->adapter->verifyRegistration($options, $response, self::RP_ID);
    }

    /**
     * A genuine attestation response registers a credential whose id and initial
     * counter match what the authenticator produced.
     */
    public function testVerifyRegistrationAcceptsGenuineAttestation(): void
    {
        // Act
        $credential = $this->register();

        // Assert — the credential is materialised from the real attestation.
        $this->assertNull($credential->id, 'Not yet persisted');
        $this->assertSame(self::USER_ID, $credential->userId);
        $this->assertSame($this->authenticator->credentialIdBase64Url(), $credential->credentialId);
        $this->assertSame(0, $credential->signCount, 'Initial counter from the authenticator');
        $this->assertNotSame('', $credential->publicKey, 'COSE public key captured');
    }

    /**
     * A genuine assertion verifies against the stored credential and reports the
     * advanced signature counter and the resolved user id.
     */
    public function testVerifyAuthenticationAcceptsGenuineAssertion(): void
    {
        // Arrange
        $credential = $this->register();
        $options = $this->adapter->createAuthenticationOptions(self::USER_ID, [$credential->credentialId]);
        $response = $this->authenticator->assertionResponse(
            $options->challenge,
            self::RP_ID,
            self::ORIGIN,
            (string) self::USER_ID,
            1
        );

        // Act
        $result = $this->adapter->verifyAuthentication($options, $response, $credential, self::RP_ID);

        // Assert
        $this->assertSame(self::USER_ID, $result->userId);
        $this->assertSame(1, $result->signCount, 'Counter advanced 0 → 1');
        $this->assertSame(1, $result->credential->signCount);
    }

    /**
     * A usernameless ceremony (null user id) still verifies and resolves the
     * user via the credential's user handle.
     */
    public function testUsernamelessAuthenticationResolvesUser(): void
    {
        // Arrange — options issued with no pinned user.
        $credential = $this->register();
        $options = $this->adapter->createAuthenticationOptions(null, []);
        $response = $this->authenticator->assertionResponse(
            $options->challenge,
            self::RP_ID,
            self::ORIGIN,
            (string) self::USER_ID,
            2
        );

        // Act
        $result = $this->adapter->verifyAuthentication($options, $response, $credential, self::RP_ID);

        // Assert — the user is recovered even though it was not supplied up front.
        $this->assertSame(self::USER_ID, $result->userId);
    }

    /**
     * A non-increasing signature counter is rejected — this is the clone/replay
     * defence. Stored counter is 1; a fresh assertion also reporting 1 must fail.
     */
    public function testCounterRegressionIsRejected(): void
    {
        // Arrange — pretend the stored credential is already at counter 1.
        $credential = $this->register()->withSignCount(1);
        $options = $this->adapter->createAuthenticationOptions(self::USER_ID, [$credential->credentialId]);
        $response = $this->authenticator->assertionResponse(
            $options->challenge,
            self::RP_ID,
            self::ORIGIN,
            (string) self::USER_ID,
            1 // not greater than the stored 1 → clone signal
        );

        // Assert
        $this->expectException(PasskeyException::class);

        // Act
        $this->adapter->verifyAuthentication($options, $response, $credential, self::RP_ID);
    }

    /** A forged signature is rejected. */
    public function testTamperedSignatureIsRejected(): void
    {
        // Arrange — replace the real signature with random bytes.
        $credential = $this->register();
        $options = $this->adapter->createAuthenticationOptions(self::USER_ID, [$credential->credentialId]);
        $response = $this->authenticator->assertionResponse(
            $options->challenge,
            self::RP_ID,
            self::ORIGIN,
            (string) self::USER_ID,
            5,
            random_bytes(64)
        );

        // Assert
        $this->expectException(PasskeyException::class);

        // Act
        $this->adapter->verifyAuthentication($options, $response, $credential, self::RP_ID);
    }

    /** A response from a disallowed origin is rejected. */
    public function testWrongOriginIsRejected(): void
    {
        // Arrange — the authenticator claims a different origin than configured.
        $credential = $this->register();
        $options = $this->adapter->createAuthenticationOptions(self::USER_ID, [$credential->credentialId]);
        $response = $this->authenticator->assertionResponse(
            $options->challenge,
            self::RP_ID,
            'https://evil.example',
            (string) self::USER_ID,
            6
        );

        // Assert
        $this->expectException(PasskeyException::class);

        // Act
        $this->adapter->verifyAuthentication($options, $response, $credential, self::RP_ID);
    }

    /**
     * Feeding an assertion (get) response to the registration verifier — or any
     * malformed JSON — is rejected rather than mis-handled.
     */
    public function testVerifyRegistrationRejectsNonAttestationResponse(): void
    {
        // Arrange
        $options = $this->adapter->createRegistrationOptions(self::USER_ID, 'alice', 'Alice', []);
        $assertion = $this->authenticator->assertionResponse(
            $options->challenge,
            self::RP_ID,
            self::ORIGIN,
            (string) self::USER_ID,
            1
        );

        // Assert
        $this->expectException(PasskeyException::class);

        // Act — an assertion response is not a valid attestation.
        $this->adapter->verifyRegistration($options, $assertion, self::RP_ID);
    }

    /** Malformed client JSON surfaces as a PasskeyException, never a raw error. */
    public function testVerifyAuthenticationRejectsMalformedJson(): void
    {
        $credential = $this->register();
        $options = $this->adapter->createAuthenticationOptions(self::USER_ID, [$credential->credentialId]);

        $this->expectException(PasskeyException::class);

        $this->adapter->verifyAuthentication($options, '{not-json', $credential, self::RP_ID);
    }

    /** createRegistrationOptions embeds the RP, the excluded creds and attestation=none. */
    public function testRegistrationOptionsShape(): void
    {
        // Act — a valid base64url credential id to exclude.
        $existingId = $this->authenticator->credentialIdBase64Url();
        $options = $this->adapter->createRegistrationOptions(self::USER_ID, 'alice', 'Alice', [$existingId]);
        $client = $options->toClientArray();

        // Assert
        $this->assertSame(self::RP_ID, $client['rp']['id']);
        $this->assertSame('none', $client['attestation']);
        $this->assertCount(2, $client['pubKeyCredParams'], 'ES256 + RS256 offered');
        $this->assertCount(1, $client['excludeCredentials'], 'Existing credential excluded');
        $this->assertSame(self::USER_ID, $options->userId);
    }

    /** extractCredentialId normalises the client id to unpadded base64url. */
    public function testExtractCredentialId(): void
    {
        // A standard-base64 rawId with padding is normalised to base64url.
        $this->assertSame('ab-_', $this->adapter->extractCredentialId('{"id":"ab-_","rawId":"ab+/"}'));
        // Missing id/rawId → null.
        $this->assertNull($this->adapter->extractCredentialId('{"type":"public-key"}'));
        // Not JSON → null.
        $this->assertNull($this->adapter->extractCredentialId('nope'));
    }
}
