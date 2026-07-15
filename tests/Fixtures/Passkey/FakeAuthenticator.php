<?php

declare(strict_types=1);

namespace Pramnos\Tests\Fixtures\Passkey;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * A minimal software WebAuthn authenticator for tests (ES256 / P-256).
 *
 * It produces the exact byte-level artefacts a real browser + authenticator
 * would hand to the server — a signed attestation response for registration and
 * a signed assertion response for authentication — so the full ceremony
 * (including the ECDSA signature check and the signature counter) can be
 * exercised end-to-end against {@see \Pramnos\Auth\Passkey\WebAuthnLibAdapter}
 * without a real device.
 *
 * Only what the tests need is implemented: attestation format "none", a single
 * EC P-256 credential, and configurable flags / sign count so counter-regression
 * (clone/replay) and user-verification paths can be driven deterministically.
 */
final class FakeAuthenticator
{
    /** Authenticator data flag bits. */
    private const FLAG_UP = 0x01; // user present
    private const FLAG_UV = 0x04; // user verified
    private const FLAG_AT = 0x40; // attested credential data included

    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;

    private string $coseKey;

    private string $credentialId;

    private string $aaguid;

    public function __construct(?string $credentialId = null)
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($key === false) {
            throw new RuntimeException('Unable to create EC key for the fake authenticator');
        }
        $this->privateKey = $key;

        $details = openssl_pkey_get_details($this->privateKey);
        // P-256 coordinates are 32 bytes; OpenSSL may strip a leading zero, so
        // left-pad to the fixed field width the COSE key requires.
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $this->coseKey      = $this->buildCoseKey($x, $y);
        $this->credentialId = $credentialId ?? random_bytes(32);
        $this->aaguid       = str_repeat("\0", 16);
    }

    /** Base64url (unpadded) credential id, as the client/JSON carries it. */
    public function credentialIdBase64Url(): string
    {
        return Base64UrlSafe::encodeUnpadded($this->credentialId);
    }

    /**
     * Produce an attestation (registration) response JSON string.
     *
     * @param string $challengeB64Url Challenge from the creation options.
     * @param string $origin          Browser origin (must be allowed).
     * @param int    $signCount       Initial signature counter.
     */
    public function attestationResponse(
        string $challengeB64Url,
        string $rpId,
        string $origin,
        int $signCount = 0
    ): string {
        $clientDataJSON = $this->clientDataJSON('webauthn.create', $challengeB64Url, $origin);

        $flags    = self::FLAG_UP | self::FLAG_UV | self::FLAG_AT;
        $authData = $this->authenticatorData($rpId, $flags, $signCount, true);

        $attestationObject = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return (string) json_encode([
            'id'    => $this->credentialIdBase64Url(),
            'rawId' => $this->credentialIdBase64Url(),
            'type'  => 'public-key',
            'response' => [
                'clientDataJSON'    => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports'        => ['internal'],
            ],
        ]);
    }

    /**
     * Produce an assertion (authentication) response JSON string.
     *
     * @param string      $challengeB64Url Challenge from the request options.
     * @param string      $userHandle      Raw user handle bytes.
     * @param int         $signCount       Signature counter for this assertion.
     * @param string|null $tamperSignature When set, replaces the signature to
     *                                      simulate a forged response.
     */
    public function assertionResponse(
        string $challengeB64Url,
        string $rpId,
        string $origin,
        string $userHandle,
        int $signCount = 1,
        ?string $tamperSignature = null
    ): string {
        $clientDataJSON = $this->clientDataJSON('webauthn.get', $challengeB64Url, $origin);

        $flags    = self::FLAG_UP | self::FLAG_UV;
        $authData = $this->authenticatorData($rpId, $flags, $signCount, false);

        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $dataToSign     = $authData . $clientDataHash;

        if ($tamperSignature !== null) {
            $signature = $tamperSignature;
        } else {
            openssl_sign($dataToSign, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        }

        return (string) json_encode([
            'id'    => $this->credentialIdBase64Url(),
            'rawId' => $this->credentialIdBase64Url(),
            'type'  => 'public-key',
            'response' => [
                'clientDataJSON'    => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature'         => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle'        => Base64UrlSafe::encodeUnpadded($userHandle),
            ],
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function clientDataJSON(string $type, string $challengeB64Url, string $origin): string
    {
        return (string) json_encode([
            'type'      => $type,
            'challenge' => $challengeB64Url,
            'origin'    => $origin,
        ]);
    }

    /**
     * rpIdHash(32) . flags(1) . signCount(4) [ . attestedCredentialData ].
     */
    private function authenticatorData(string $rpId, int $flags, int $signCount, bool $withAttestedData): string
    {
        $data = hash('sha256', $rpId, true);
        $data .= chr($flags);
        $data .= pack('N', $signCount);

        if ($withAttestedData) {
            $data .= $this->aaguid;
            $data .= pack('n', strlen($this->credentialId));
            $data .= $this->credentialId;
            $data .= $this->coseKey;
        }

        return $data;
    }

    /**
     * COSE_Key CBOR for an EC2 / P-256 / ES256 public key:
     *   {1:2, 3:-7, -1:1, -2:x, -3:y}
     */
    private function buildCoseKey(string $x, string $y): string
    {
        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))   // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))  // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))  // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))      // x
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));     // y
    }
}
