<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithms;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * WebAuthn adapter backed by the web-auth/webauthn-lib 5.x package.
 *
 * ⚠️ SECURITY-CRITICAL. This is the single class that understands the WebAuthn
 * cryptography and the third-party library; a mistake here is an authentication
 * bypass. Everything crossing its public boundary is a framework-owned type.
 *
 * Wiring (5.x):
 *   - A {@see WebauthnSerializerFactory} builds a Symfony serializer that
 *     denormalises the browser's JSON into library value objects and serialises
 *     our option objects back to JSON.
 *   - A {@see CeremonyStepManagerFactory} assembles the ordered verification
 *     steps. Origins are pinned via setAllowedOrigins() (from {@see Config}), the
 *     COSE algorithm manager is limited to ES256 + RS256, and attestation
 *     support is "none" only (consumer passkeys — no attestation statement is
 *     required or trusted).
 *   - The registration flow uses the creation ceremony, authentication the
 *     request ceremony. The counter check (clone detection) is part of the
 *     request ceremony; the service additionally enforces monotonicity.
 *
 * Binary handling: credential ids travel/persist as base64url (unpadded), COSE
 * public keys as base64. Raw bytes exist only inside this class.
 *
 * The WebAuthn user handle is derived deterministically from the user id
 * ({@see self::userHandle()}), so a stored credential can be re-materialised for
 * assertion verification without persisting the handle separately.
 */
class WebAuthnLibAdapter implements WebAuthnAdapterInterface
{
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    private SerializerInterface $serializer;

    private AuthenticatorAttestationResponseValidator $attestationValidator;

    private AuthenticatorAssertionResponseValidator $assertionValidator;

    public function __construct(private readonly Config $config)
    {
        // Attestation support: "none" only. The same manager instance feeds both
        // the serializer (to denormalise the attestation object) and the ceremony.
        $attestationManager = AttestationStatementSupportManager::create();
        $attestationManager->add(NoneAttestationStatementSupport::create());

        $this->serializer = (new WebauthnSerializerFactory($attestationManager))->create();

        $ceremonyFactory = new CeremonyStepManagerFactory();
        $ceremonyFactory->setAllowedOrigins($this->config->allowedOrigins);
        $ceremonyFactory->setAttestationStatementSupportManager($attestationManager);
        $ceremonyFactory->setAlgorithmManager(
            Manager::create()->add(ES256::create(), RS256::create())
        );

        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $ceremonyFactory->creationCeremony()
        );
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $ceremonyFactory->requestCeremony()
        );
    }

    public function createRegistrationOptions(
        int $userId,
        string $userName,
        string $displayName,
        array $excludeCredentialIds = []
    ): RegistrationOptions {
        $challenge = random_bytes(32);

        $rp   = PublicKeyCredentialRpEntity::create($this->config->rpName, $this->config->rpId);
        $user = PublicKeyCredentialUserEntity::create(
            $userName,
            $this->userHandle($userId),
            $displayName !== '' ? $displayName : $userName
        );

        $exclude = [];
        foreach ($excludeCredentialIds as $encodedId) {
            $exclude[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($encodedId)
            );
        }

        $options = PublicKeyCredentialCreationOptions::create(
            $rp,
            $user,
            $challenge,
            [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                $this->config->userVerification,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
            ),
            Config::ATTESTATION,
            $exclude,
            $this->config->timeout
        );

        return new RegistrationOptions(
            Base64UrlSafe::encodeUnpadded($challenge),
            $this->serializer->serialize($options, 'json'),
            $userId
        );
    }

    public function verifyRegistration(
        RegistrationOptions $options,
        string $clientResponse,
        string $host
    ): PasskeyCredential {
        try {
            $creationOptions = $this->serializer->deserialize(
                $options->json,
                PublicKeyCredentialCreationOptions::class,
                'json'
            );
            $publicKeyCredential = $this->serializer->deserialize(
                $clientResponse,
                PublicKeyCredential::class,
                'json'
            );

            $response = $publicKeyCredential->response;
            if (!$response instanceof AuthenticatorAttestationResponse) {
                throw new PasskeyException('Expected an attestation response');
            }

            $record = $this->attestationValidator->check($response, $creationOptions, $host);

            return $this->toCredential($record, $options->userId);
        } catch (PasskeyException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PasskeyException('Passkey registration verification failed', 0, $e);
        }
    }

    public function createAuthenticationOptions(
        ?int $userId,
        array $allowCredentialIds = []
    ): AuthenticationOptions {
        $challenge = random_bytes(32);

        $allow = [];
        foreach ($allowCredentialIds as $encodedId) {
            $allow[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($encodedId)
            );
        }

        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            $this->config->rpId,
            $allow,
            $this->config->userVerification,
            $this->config->timeout
        );

        return new AuthenticationOptions(
            Base64UrlSafe::encodeUnpadded($challenge),
            $this->serializer->serialize($options, 'json'),
            $userId
        );
    }

    public function verifyAuthentication(
        AuthenticationOptions $options,
        string $clientResponse,
        PasskeyCredential $stored,
        string $host
    ): VerificationResult {
        try {
            $requestOptions = $this->serializer->deserialize(
                $options->json,
                PublicKeyCredentialRequestOptions::class,
                'json'
            );
            $publicKeyCredential = $this->serializer->deserialize(
                $clientResponse,
                PublicKeyCredential::class,
                'json'
            );

            $response = $publicKeyCredential->response;
            if (!$response instanceof AuthenticatorAssertionResponse) {
                throw new PasskeyException('Expected an assertion response');
            }

            // When the ceremony targeted a known user, pin the expected handle;
            // for usernameless auth pass null and let the library match the
            // response handle against the stored credential's handle.
            $expectedHandle = $options->userId !== null
                ? $this->userHandle($options->userId)
                : null;

            $updated = $this->assertionValidator->check(
                $this->toRecord($stored),
                $response,
                $requestOptions,
                $host,
                $expectedHandle
            );

            return new VerificationResult(
                $stored->userId,
                $stored->withSignCount($updated->counter),
                $updated->counter
            );
        } catch (PasskeyException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PasskeyException('Passkey authentication verification failed', 0, $e);
        }
    }

    public function extractCredentialId(string $clientResponse): ?string
    {
        $data = json_decode($clientResponse, true);
        if (!is_array($data)) {
            return null;
        }
        // PublicKeyCredential JSON carries the credential id as base64url in both
        // "id" and "rawId"; "id" is the canonical base64url form.
        $rawId = $data['id'] ?? $data['rawId'] ?? null;
        if (!is_string($rawId) || $rawId === '') {
            return null;
        }
        // Normalise to unpadded base64url so it matches the stored form.
        return rtrim(strtr($rawId, '+/', '-_'), '=');
    }

    // ── Mapping between our credential VO and the library record ─────────────

    /** Map a freshly validated CredentialRecord to our (unpersisted) VO. */
    private function toCredential(CredentialRecord $record, int $userId): PasskeyCredential
    {
        return new PasskeyCredential(
            null,
            $userId,
            Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            base64_encode($record->credentialPublicKey),
            $record->counter,
            $record->aaguid->__toString(),
            $record->transports,
            null,
            (bool) $record->backupEligible,
            (bool) $record->backupStatus,
            true
        );
    }

    /** Re-materialise a stored credential as a library CredentialRecord. */
    private function toRecord(PasskeyCredential $stored): CredentialRecord
    {
        return CredentialRecord::create(
            Base64UrlSafe::decodeNoPadding($stored->credentialId),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $stored->transports,
            Config::ATTESTATION,
            EmptyTrustPath::create(),
            $this->aaguidToUuid($stored->aaguid),
            (string) base64_decode($stored->publicKey, true),
            $this->userHandle($stored->userId),
            $stored->signCount,
            null,
            $stored->backupEligible,
            $stored->backupState
        );
    }

    /** Parse a stored AAGUID string into a Uuid, defaulting to the nil UUID. */
    private function aaguidToUuid(?string $aaguid): Uuid
    {
        if ($aaguid !== null && $aaguid !== '' && Uuid::isValid($aaguid)) {
            return Uuid::fromString($aaguid);
        }
        return Uuid::fromString(self::NIL_UUID);
    }

    /**
     * Deterministic WebAuthn user handle for a user id.
     *
     * The handle is an opaque byte sequence bound to the account; deriving it
     * from the id keeps registration and assertion consistent without a stored
     * handle. The id is not secret (it already appears in issued tokens).
     */
    private function userHandle(int $userId): string
    {
        return (string) $userId;
    }
}
