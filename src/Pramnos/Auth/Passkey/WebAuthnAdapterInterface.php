<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

/**
 * Anti-corruption boundary around a concrete WebAuthn library.
 *
 * This is the ONLY seam that speaks the third-party WebAuthn dialect. Every
 * method takes and returns framework-owned types ({@see RegistrationOptions},
 * {@see AuthenticationOptions}, {@see PasskeyCredential}, {@see VerificationResult}),
 * so {@see PasskeyService} — and everything above it — never touches a
 * webauthn-lib class. Swapping the implementation (a different library, or a
 * hand-rolled one) is a matter of providing another adapter.
 *
 * The default implementation is {@see WebAuthnLibAdapter}.
 *
 * Implementations MUST derive the WebAuthn user handle deterministically from
 * the user id, using the same mapping in both ceremonies, so a stored credential
 * can be re-materialised for assertion verification without persisting the handle.
 */
interface WebAuthnAdapterInterface
{
    /**
     * Build attestation (registration) options for the browser.
     *
     * @param int      $userId               User the credential is for.
     * @param string   $userName             Account name (e.g. username / email).
     * @param string   $displayName          Human-friendly display name.
     * @param string[] $excludeCredentialIds Base64url credential ids to exclude
     *                                        (already-registered passkeys).
     */
    public function createRegistrationOptions(
        int $userId,
        string $userName,
        string $displayName,
        array $excludeCredentialIds = []
    ): RegistrationOptions;

    /**
     * Verify an attestation response and produce an unpersisted credential.
     *
     * @param RegistrationOptions $options        Options that were issued.
     * @param string              $clientResponse Raw JSON from create().
     * @param string              $host           Request host, for RP-id checks.
     * @return PasskeyCredential Credential with id = null (not yet stored).
     * @throws PasskeyException On any verification failure.
     */
    public function verifyRegistration(
        RegistrationOptions $options,
        string $clientResponse,
        string $host
    ): PasskeyCredential;

    /**
     * Build assertion (authentication) options for the browser.
     *
     * @param int|null $userId             Target user, or null for usernameless.
     * @param string[] $allowCredentialIds Base64url credential ids the user may
     *                                     use (empty for usernameless).
     */
    public function createAuthenticationOptions(
        ?int $userId,
        array $allowCredentialIds = []
    ): AuthenticationOptions;

    /**
     * Verify an assertion response against a stored credential.
     *
     * The returned result carries the new signature counter; enforcing that it
     * advanced (replay/clone protection) is the caller's responsibility.
     *
     * @param AuthenticationOptions $options        Options that were issued.
     * @param string                $clientResponse Raw JSON from get().
     * @param PasskeyCredential     $stored         The stored credential to verify against.
     * @param string                $host           Request host, for RP-id checks.
     * @throws PasskeyException On any verification failure.
     */
    public function verifyAuthentication(
        AuthenticationOptions $options,
        string $clientResponse,
        PasskeyCredential $stored,
        string $host
    ): VerificationResult;

    /**
     * Extract the base64url credential id (rawId) from a client response, so the
     * caller can look up which stored credential to verify against.
     *
     * @return string|null Base64url credential id, or null when absent/malformed.
     */
    public function extractCredentialId(string $clientResponse): ?string;
}
