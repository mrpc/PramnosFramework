<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Auth\Passkey\PasskeyService;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;

/**
 * Whether a privileged account still has to enrol a real second factor.
 *
 * `auth.security.require_second_factor_from_usertype` makes a second factor a **condition
 * of signing in**. It does not make anybody enrol one: an administrator who has set up
 * nothing is offered a six-digit code by email, completes it, and is in. That is deliberate
 * — enrolment happens after signing in, so refusing the mailed code to an account with no
 * other factor would be a lockout by design, and the mail is the only on-ramp there is.
 *
 * It is also not the destination. A mailed code is the weakest factor the framework has
 * ({@see Factors\EmailCodeSecondFactor} scores 20 against an authenticator's 60): it is one
 * mailbox compromise away from being no factor at all, on exactly the accounts worth the
 * most. So `auth.security.require_factor_enrolment_from_usertype` says which accounts must
 * hold something better, and {@see \Pramnos\Http\Middleware\RequireFactorEnrolmentMiddleware}
 * walks them to the setup screen until they do.
 *
 * The two switches are meant to be set together, and to the same number:
 *
 * ```php
 * 'auth' => ['security' => [
 *     'require_second_factor_from_usertype'   => 80,   // cannot sign in without one
 *     'require_factor_enrolment_from_usertype' => 80,  // and mail alone will not do
 * ]],
 * ```
 *
 * ## What counts as enrolled
 *
 * An authenticator app, a passkey, or any adaptor an application registered that scores at
 * least {@see MIN_STRENGTH}. Not the mailed code, which is the thing being escaped from.
 *
 * Passkeys are asked separately because they are not a registered second factor — they
 * replace the password rather than follow it, so `SecondFactorRegistry` does not know about
 * them, and an account that has one is exactly as protected as one with an authenticator.
 *
 * ## It fails open
 *
 * A store that cannot answer means "no requirement": the alternative is walling every
 * administrator out of the screen that would fix it, on the strength of a query that failed
 * once.
 */
class FactorEnrolment
{
    /**
     * The weakest factor that satisfies the requirement.
     *
     * Above the mailed code's 20 and at or below an authenticator's 60. An SMS adaptor at
     * 40 counts — it is weak against a determined attacker and it is not one mailbox away
     * from the password reset that also arrives by mail.
     */
    public const MIN_STRENGTH = 40;

    private ?PasskeyServiceInterface $passkeys;

    public function __construct(?PasskeyServiceInterface $passkeys = null)
    {
        $this->passkeys = $passkeys;
    }

    /**
     * Does this account have to enrol something before it does anything else?
     *
     * @param int $userId   The account.
     * @param int $usertype Its usertype, which the caller already has.
     */
    public function isRequiredFor(int $userId, int $usertype): bool
    {
        if ($userId < 1) {
            return false;
        }

        $floor = SecurityPolicy::factorEnrolmentFromUsertype();

        if ($floor < 1 || $usertype < $floor) {
            return false;
        }

        return !$this->hasStrongFactor($userId);
    }

    /**
     * Whether the account holds a factor stronger than a mailed code.
     */
    public function hasStrongFactor(int $userId): bool
    {
        try {
            foreach (SecondFactorRegistry::enrolledFor($userId) as $factor) {
                if ($factor->strength() >= self::MIN_STRENGTH) {
                    return true;
                }
            }
        } catch (\Throwable $exception) {
            $this->log('registry', $userId, $exception);

            return true;
        }

        try {
            return $this->passkeyService()->hasCredentials($userId);
        } catch (\Throwable $exception) {
            $this->log('passkeys', $userId, $exception);

            return true;
        }
    }

    /**
     * The names of what the account does hold, for a support screen or a command.
     *
     * @return list<string>
     */
    public function factorsFor(int $userId): array
    {
        $names = array();

        try {
            foreach (SecondFactorRegistry::enrolledFor($userId) as $factor) {
                $names[] = $factor->name() . ' (' . $factor->strength() . ')';
            }
        } catch (\Throwable) {
            // Reported as nothing rather than guessed at.
        }

        try {
            if ($this->passkeyService()->hasCredentials($userId)) {
                $names[] = 'passkey';
            }
        } catch (\Throwable) {
            // As above.
        }

        return $names;
    }

    /**
     * Lazily, because a request that is not gated must not pay for this.
     */
    protected function passkeyService(): PasskeyServiceInterface
    {
        return $this->passkeys ??= new PasskeyService();
    }

    private function log(string $what, int $userId, \Throwable $exception): void
    {
        \Pramnos\Logs\Logger::log(
            'FactorEnrolment could not read ' . $what . ' for ' . $userId . ': '
            . $exception->getMessage() . ' — treated as enrolled, so nobody is walled out '
            . 'of the screen that would fix it.',
            'auth'
        );
    }
}
