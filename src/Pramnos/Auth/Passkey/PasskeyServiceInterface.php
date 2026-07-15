<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

/**
 * Public contract for passkey (WebAuthn/FIDO2) registration and authentication.
 *
 * Two ceremonies, each split into a "begin" step (issue options + challenge) and
 * a "finish" step (verify the authenticator's response):
 *
 *   Registration  beginRegistration()  → browser create() → finishRegistration()
 *   Authentication beginAuthentication() → browser get()   → finishAuthentication()
 *
 * Every parameter and return type is a framework-owned type — no webauthn-lib
 * class appears in this signature (anti-corruption boundary). The concrete
 * {@see PasskeyService} orchestrates the challenge store, persistence and
 * signature-counter replay protection, delegating the cryptography to a
 * {@see WebAuthnAdapterInterface}.
 *
 * All verification failures surface as {@see PasskeyException}.
 */
interface PasskeyServiceInterface
{
    /**
     * Begin registering a new passkey for a user.
     *
     * Issues creation options (including a fresh challenge) and stashes them in
     * the challenge store for the matching finish call. Already-registered
     * credentials for the user are excluded so the authenticator will not create
     * a duplicate.
     *
     * @param int         $userId User the credential will belong to.
     * @param string|null $label  Optional user-facing label for the passkey.
     * @return RegistrationOptions Options to pass to navigator.credentials.create().
     */
    public function beginRegistration(int $userId, ?string $label = null): RegistrationOptions;

    /**
     * Finish registration: verify the authenticator's attestation response and
     * persist the new credential.
     *
     * @param int                 $userId         User the credential belongs to.
     * @param RegistrationOptions $options        Options previously issued (challenge match).
     * @param string              $clientResponse Raw JSON from create().
     * @return PasskeyCredential The persisted credential.
     * @throws PasskeyException When the attestation cannot be verified.
     */
    public function finishRegistration(
        int $userId,
        RegistrationOptions $options,
        string $clientResponse
    ): PasskeyCredential;

    /**
     * Begin authenticating with a passkey.
     *
     * @param int|null $userId Target user, or null for a usernameless /
     *                         discoverable-credential ceremony.
     * @return AuthenticationOptions Options to pass to navigator.credentials.get().
     */
    public function beginAuthentication(?int $userId = null): AuthenticationOptions;

    /**
     * Finish authentication: verify the assertion, enforce signature-counter
     * monotonicity (replay/clone protection) and persist the new counter.
     *
     * @param AuthenticationOptions $options        Options previously issued (challenge match).
     * @param string                $clientResponse Raw JSON from get().
     * @return VerificationResult Resolved user id + updated credential.
     * @throws PasskeyException When the assertion cannot be verified or the
     *                          signature counter did not advance.
     */
    public function finishAuthentication(
        AuthenticationOptions $options,
        string $clientResponse
    ): VerificationResult;

    /**
     * List a user's active passkeys (for dashboard management).
     *
     * @return PasskeyCredential[]
     */
    public function listCredentials(int $userId): array;

    /**
     * Rename a passkey the user owns.
     *
     * @return bool false when it does not exist or is not the user's.
     */
    public function renameCredential(int $userId, int $credentialId, string $name): bool;

    /**
     * Revoke (soft-delete) a passkey the user owns.
     *
     * @return bool false when it does not exist or is not the user's.
     */
    public function revokeCredential(int $userId, int $credentialId): bool;

    /** Whether the user has at least one active passkey. */
    public function hasCredentials(int $userId): bool;
}
