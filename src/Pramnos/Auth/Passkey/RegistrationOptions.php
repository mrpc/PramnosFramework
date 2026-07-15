<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

/**
 * Options for a passkey registration (attestation) ceremony (value object).
 *
 * Produced by {@see PasskeyServiceInterface::beginRegistration()}. The {@see $json}
 * field is the client-facing `PublicKeyCredentialCreationOptions` — the exact
 * payload the browser passes to `navigator.credentials.create()`. The same JSON
 * is stored server-side (keyed by {@see $challenge}) and handed back to
 * {@see PasskeyServiceInterface::finishRegistration()} so the verification runs
 * against the very options that were issued.
 *
 * {@see $json} is opaque to callers: its internal structure is a webauthn-lib
 * serialization detail that only the adapter understands. Callers forward it to
 * the browser and echo the {@see $challenge} back; they never parse it.
 */
final class RegistrationOptions
{
    /**
     * @param string $challenge Base64url-encoded challenge (the store key).
     * @param string $json      Client-facing options JSON for the browser.
     * @param int    $userId    User the credential is being registered for.
     */
    public function __construct(
        public readonly string $challenge,
        public readonly string $json,
        public readonly int $userId
    ) {
    }

    /** The options as a decoded array, ready to hand to the browser. */
    public function toClientArray(): array
    {
        $decoded = json_decode($this->json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Serialize for the challenge store.
     *
     * @return array{challenge:string,json:string,userId:int}
     */
    public function toArray(): array
    {
        return ['challenge' => $this->challenge, 'json' => $this->json, 'userId' => $this->userId];
    }

    /**
     * Rebuild from a challenge-store entry.
     *
     * @param array{challenge?:string,json?:string,userId?:int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['challenge'] ?? ''),
            (string) ($data['json'] ?? ''),
            (int) ($data['userId'] ?? 0)
        );
    }
}
