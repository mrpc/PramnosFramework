<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

/**
 * A passkey store with a fixed answer — or one that cannot answer at all.
 *
 * Only `hasCredentials()` is ever called by the tests that use it; the rest of the contract returns concrete
 * option objects, so the unused methods refuse rather than invent one. A stub that returned
 * something plausible would let a future call through silently.
 */
class FakePasskeyService implements \Pramnos\Auth\Passkey\PasskeyServiceInterface
{
    public function __construct(private bool $has, private bool $explodes = false)
    {
    }

    public function hasCredentials(int $userId): bool
    {
        if ($this->explodes) {
            throw new \RuntimeException('the passkey table is not there');
        }

        return $this->has;
    }

    public function beginRegistration(int $userId, ?string $label = null): \Pramnos\Auth\Passkey\RegistrationOptions
    {
        throw new \LogicException('not used by these tests');
    }

    public function finishRegistration(
        int $userId,
        \Pramnos\Auth\Passkey\RegistrationOptions $options,
        string $clientResponse
    ): \Pramnos\Auth\Passkey\PasskeyCredential {
        throw new \LogicException('not used by these tests');
    }

    public function beginAuthentication(?int $userId = null): \Pramnos\Auth\Passkey\AuthenticationOptions
    {
        throw new \LogicException('not used by these tests');
    }

    public function finishAuthentication(
        \Pramnos\Auth\Passkey\AuthenticationOptions $options,
        string $clientResponse
    ): \Pramnos\Auth\Passkey\VerificationResult {
        throw new \LogicException('not used by these tests');
    }

    public function listCredentials(int $userId): array
    {
        return [];
    }

    public function renameCredential(int $userId, int $credentialId, string $name): bool
    {
        return false;
    }

    public function revokeCredential(int $userId, int $credentialId): bool
    {
        return false;
    }
}
