<?php

declare(strict_types=1);

namespace Pramnos\Auth\Factors;

use Pramnos\Auth\PushApprovals;
use Pramnos\Auth\SecondFactorInterface;

/**
 * A sign-in approved from a trusted phone, as a second factor.
 *
 * The adaptor between {@see PushApprovals} and the login flow. Enrolled means the account has
 * a trusted device that can receive notifications — there is nothing else to set up, which is
 * the point: trusting your phone *is* enrolling it.
 *
 * Scored 70, above the authenticator app, so that it is offered first as Google offers its
 * prompt first — nothing to type, a device the person already holds, and a number to match
 * on every ask. The score orders the offer; it does **not** make the prompt a factor that
 * satisfies `require_factor_enrolment_from_usertype` ({@see \Pramnos\Auth\FactorEnrolment}),
 * because the trust behind it may have come from a mailed code. Never the only way: the flow
 * always offers another beside it.
 *
 * There is no code. {@see verify()} asks whether the ask this browser is waiting on was
 * approved, and uses that approval up.
 */
class PushApprovalSecondFactor implements SecondFactorInterface
{
    public const METHOD = 'push';

    public function __construct(private ?PushApprovals $approvals = null)
    {
    }

    public function name(): string
    {
        return self::METHOD;
    }

    public function label(): string
    {
        return 'A notification to your phone';
    }

    public function strength(): int
    {
        return 70;
    }

    public function isEnrolledFor(int $userId): bool
    {
        return $this->approvals()->availableFor($userId);
    }

    public function needsSending(): bool
    {
        return true;
    }

    public function send(int $userId): bool
    {
        return $this->approvals()->start($userId);
    }

    public function verify(int $userId, string $code): bool
    {
        return $this->approvals()->consume($userId);
    }

    private function approvals(): PushApprovals
    {
        return $this->approvals ??= new PushApprovals();
    }
}
