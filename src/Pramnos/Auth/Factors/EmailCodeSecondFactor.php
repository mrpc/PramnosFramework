<?php

declare(strict_types=1);

namespace Pramnos\Auth\Factors;

use Pramnos\Auth\EmailSecondFactor;
use Pramnos\Auth\SecondFactorInterface;

/**
 * The mailed six-digit code, as a factor the registry can hold.
 *
 * An adaptor over {@see EmailSecondFactor}, which keeps the code store, the three limits
 * that make six digits safe, and the enrolment flow. As with the authenticator adaptor,
 * nothing was moved: that class is what the account screens call.
 *
 * Weakest of the factors (20) because the channel is one somebody else may be able to
 * read — a shared mailbox, a mail client left open, a provider account with a reused
 * password. It exists because it is the only factor an account can have without setting
 * anything up first, and a weak second factor beats a password alone.
 *
 * An SMS adaptor would sit at 30 or 40: harder to read remotely than a mailbox, easier to
 * steal than a device secret.
 */
class EmailCodeSecondFactor implements SecondFactorInterface
{
    private ?EmailSecondFactor $service;

    public function __construct(?EmailSecondFactor $service = null)
    {
        $this->service = $service;
    }

    public function name(): string
    {
        return EmailSecondFactor::METHOD;
    }

    public function label(): string
    {
        return 'Code by email';
    }

    public function strength(): int
    {
        return 20;
    }

    public function isEnrolledFor(int $userId): bool
    {
        return $this->service()->isEnabledFor($userId);
    }

    public function needsSending(): bool
    {
        return true;
    }

    public function send(int $userId): bool
    {
        return $this->service()->send($userId);
    }

    public function verify(int $userId, string $code): bool
    {
        return $this->service()->verify($userId, $code);
    }

    /**
     * Is a code already outstanding?
     *
     * Beyond the interface, and used by the step-up screen so it can say "enter the code
     * we sent" instead of offering to send one that is already in the person's inbox. A
     * factor without it is simply always offered as sendable, which is why it is not in
     * the contract.
     */
    public function hasLiveCode(int $userId): bool
    {
        return $this->service()->hasLiveCode($userId);
    }

    private function service(): EmailSecondFactor
    {
        return $this->service ??= new EmailSecondFactor();
    }
}
