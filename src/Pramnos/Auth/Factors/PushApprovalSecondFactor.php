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
 * whenever the attempt is not plainly the account's own. The score orders the offer. Whether
 * it also satisfies `require_factor_enrolment_from_usertype` is the site's setting
 * ({@see ENROLMENT_SETTING}, {@see countsForEnrolment()}), because the trust behind it may have
 * come from a mailed code. Never the only way: the flow always offers another beside it.
 *
 * There is no code. {@see verify()} asks whether the ask this browser is waiting on was
 * approved, and uses that approval up.
 */
class PushApprovalSecondFactor implements SecondFactorInterface
{
    public const METHOD = 'push';

    /**
     * Does the phone prompt count as the account's real second factor?
     * `strong` (default): only when a phone that would be asked was trusted with a factor that
     * counts itself. `always`: whenever there is a phone to ask. `never`.
     */
    public const ENROLMENT_SETTING = 'auth_push_counts_for_enrolment';

    public const ENROLMENT_POLICIES = ['strong', 'always', 'never'];

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

    /** The site's {@see ENROLMENT_SETTING}, `strong` unless it says otherwise. */
    public static function enrolmentPolicy(): string
    {
        $policy = (string) \Pramnos\Application\Settings::getSetting(self::ENROLMENT_SETTING, 'strong');

        return in_array($policy, self::ENROLMENT_POLICIES, true) ? $policy : 'strong';
    }

    /**
     * Does this account's phone prompt satisfy the requirement to hold a real factor?
     *
     * Asked by {@see \Pramnos\Auth\FactorEnrolment}, following {@see enrolmentPolicy()}.
     */
    public function countsForEnrolment(int $userId): bool
    {
        return match (self::enrolmentPolicy()) {
            'never'  => false,
            'always' => $this->isEnrolledFor($userId),
            default  => $this->approvals()->enabled() && array_filter(
                $this->approvals()->recipients($userId),
                static fn (array $recipient): bool => \Pramnos\Auth\FactorEnrolment::methodIsStrong(
                    (string) ($recipient['trusted_via'] ?? '')
                )
            ) !== [],
        };
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
