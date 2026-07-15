<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

/**
 * Options for a passkey authentication (assertion) ceremony (value object).
 *
 * Produced by {@see PasskeyServiceInterface::beginAuthentication()}. The
 * {@see $json} field is the client-facing `PublicKeyCredentialRequestOptions`
 * that the browser passes to `navigator.credentials.get()`. It is stored
 * server-side (keyed by {@see $challenge}) and replayed to
 * {@see PasskeyServiceInterface::finishAuthentication()} for verification.
 *
 * {@see $userId} is null for a usernameless / discoverable-credential ceremony:
 * the user is only known once the authenticator returns a user handle, so no
 * allowCredentials list is pinned up front.
 */
final class AuthenticationOptions
{
    /**
     * @param string   $challenge Base64url-encoded challenge (the store key).
     * @param string   $json      Client-facing options JSON for the browser.
     * @param int|null $userId    Target user, or null for usernameless auth.
     */
    public function __construct(
        public readonly string $challenge,
        public readonly string $json,
        public readonly ?int $userId = null
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
     * @return array{challenge:string,json:string,userId:int|null}
     */
    public function toArray(): array
    {
        return ['challenge' => $this->challenge, 'json' => $this->json, 'userId' => $this->userId];
    }

    /**
     * Rebuild from a challenge-store entry.
     *
     * @param array{challenge?:string,json?:string,userId?:int|null} $data
     */
    public static function fromArray(array $data): self
    {
        $userId = $data['userId'] ?? null;
        return new self(
            (string) ($data['challenge'] ?? ''),
            (string) ($data['json'] ?? ''),
            $userId === null ? null : (int) $userId
        );
    }
}
