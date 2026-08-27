<?php

declare(strict_types=1);

namespace Pramnos\Auth\Factors;

use Pramnos\Auth\SecondFactorInterface;
use Pramnos\Auth\TwoFactorAuthService;

/**
 * The authenticator app, as a factor the registry can hold.
 *
 * A thin adaptor over {@see TwoFactorAuthService}, which keeps the enrolment flow, the
 * backup codes and the replay guard. Nothing moved: the service is what applications and
 * the account screens already use, and re-implementing it behind the interface would mean
 * two places that decide whether a code is valid.
 *
 * It is the strongest factor here (60) because the secret never leaves the person's
 * device: there is no channel to intercept, no mailbox to read, no number to port.
 */
class TotpSecondFactor implements SecondFactorInterface
{
    private ?TwoFactorAuthService $service;

    public function __construct(?TwoFactorAuthService $service = null)
    {
        $this->service = $service;
    }

    public function name(): string
    {
        return 'totp';
    }

    public function label(): string
    {
        return 'Authenticator app';
    }

    public function strength(): int
    {
        return 60;
    }

    public function isEnrolledFor(int $userId): bool
    {
        return $this->service()->isEnabled($userId);
    }

    /**
     * Nothing to deliver: the code is already on the person's phone.
     */
    public function needsSending(): bool
    {
        return false;
    }

    public function send(int $userId): bool
    {
        return false;
    }

    /**
     * Accepts a TOTP code or a backup code, exactly as the service does.
     *
     * Backup codes belong to this factor rather than to a fourth one: they are the same
     * enrolment's escape hatch, and an account that has them has this factor by
     * definition.
     */
    public function verify(int $userId, string $code): bool
    {
        return $this->service()->verifyCode($userId, trim($code));
    }

    private function service(): TwoFactorAuthService
    {
        return $this->service ??= new TwoFactorAuthService();
    }
}
