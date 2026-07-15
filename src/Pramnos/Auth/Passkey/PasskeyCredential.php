<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

/**
 * A stored WebAuthn/FIDO2 passkey credential (value object).
 *
 * Mirrors one row of `authserver.passkey_credentials`. Binary WebAuthn values
 * are carried in their portable text form — the credential id is base64url and
 * the COSE public key is base64 — exactly as persisted (see the migration).
 * The raw binary lives only inside the WebAuthn adapter.
 *
 * This is our own type: it never exposes a webauthn-lib class, so consumers of
 * PasskeyServiceInterface depend only on the framework (BC, anti-corruption).
 */
final class PasskeyCredential
{
    /**
     * @param int|null    $id              Primary key (null before persistence).
     * @param int         $userId          Owner user id.
     * @param string      $credentialId    Base64url-encoded raw credential id.
     * @param string      $publicKey       Base64-encoded COSE public key.
     * @param int         $signCount       Signature counter (clone-detection).
     * @param string|null $aaguid          Authenticator AAGUID (may be all-zero).
     * @param string[]    $transports      Transport hints (usb, nfc, ble, internal, hybrid).
     * @param string|null $name            User-supplied label.
     * @param bool        $backupEligible  Credential is eligible for backup (syncable).
     * @param bool        $backupState     Credential is currently backed up.
     * @param bool        $isActive        Soft-delete / revocation flag.
     * @param string|null $createdAt       Creation timestamp (Y-m-d H:i:s) or null.
     * @param string|null $lastUsedAt      Last authentication timestamp or null.
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $userId,
        public readonly string $credentialId,
        public readonly string $publicKey,
        public readonly int $signCount,
        public readonly ?string $aaguid = null,
        public readonly array $transports = [],
        public readonly ?string $name = null,
        public readonly bool $backupEligible = false,
        public readonly bool $backupState = false,
        public readonly bool $isActive = true,
        public readonly ?string $createdAt = null,
        public readonly ?string $lastUsedAt = null
    ) {
    }

    /**
     * Return a copy with an updated signature counter.
     *
     * Used after a successful assertion: the counter must move forward, and a
     * non-increasing value is what the replay/clone check rejects.
     */
    public function withSignCount(int $signCount): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->credentialId,
            $this->publicKey,
            $signCount,
            $this->aaguid,
            $this->transports,
            $this->name,
            $this->backupEligible,
            $this->backupState,
            $this->isActive,
            $this->createdAt,
            $this->lastUsedAt
        );
    }

    /**
     * Public, safe-to-serialize view for dashboards / APIs.
     *
     * Deliberately omits the COSE public key — clients never need it and it
     * should not travel outside the server.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'credential_id'   => $this->credentialId,
            'aaguid'          => $this->aaguid,
            'transports'      => $this->transports,
            'backup_eligible' => $this->backupEligible,
            'backup_state'    => $this->backupState,
            'is_active'       => $this->isActive,
            'created_at'      => $this->createdAt,
            'last_used_at'    => $this->lastUsedAt,
        ];
    }
}
