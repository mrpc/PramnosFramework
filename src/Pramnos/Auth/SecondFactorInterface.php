<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * One way of proving a second time that an account is its owner.
 *
 * The framework ships two — an authenticator app and a mailed code — and they were
 * written into the login flow by name. That is fine until somebody needs a third: an SMS,
 * a push to the application's own phone app, a hardware token, a call to a corporate
 * gateway. None of those belong in this framework (they need accounts, credentials and
 * money), and none of them should require forking the login to add.
 *
 * So a factor is an object that can answer five questions, and the flow asks the registry
 * rather than a list of `if`s.
 *
 * ## What an implementation owes the flow
 *
 * **It must be satisfiable or absent.** `isEnrolledFor()` returning true is a promise that
 * `verify()` can succeed for that account — the flow will otherwise offer a step-up
 * nobody can complete, which is a lockout wearing the clothes of a security feature. An
 * SMS adaptor with no phone number on file must answer false.
 *
 * **It must be single-use and time-limited if it sends anything.** The flow does not
 * enforce this and cannot: only the adaptor knows what it sent and when. See
 * {@see EmailSecondFactor} for the three limits that make a six-digit code safe — ten
 * minutes, an attempt cap that destroys the code, and single use.
 *
 * **It must not send anything from `isEnrolledFor()`.** That method is called while
 * deciding what to offer, including on screens that are then never submitted; sending
 * there mails or texts somebody on every failed password attempt.
 *
 * ## Ordering
 *
 * `strength()` decides what a step-up offers first. It is a number so that an application
 * can slot its own factor between the built-ins without them knowing about it — an SMS is
 * stronger than email and weaker than an app, and it can say so without either being
 * edited.
 */
interface SecondFactorInterface
{
    /**
     * The identifier the flow, the forms and the audit log use, e.g. `totp`, `sms`.
     *
     * Lower-case, no spaces. It travels in a form field and is written to the activity
     * log, so it is API: renaming it invalidates in-flight step-ups and makes old log
     * entries unreadable.
     */
    public function name(): string;

    /**
     * What a person is shown, e.g. "Authenticator app", "Text message".
     *
     * Names the *channel*, not the mechanism: "TOTP" and "HOTP" mean nothing to the
     * person holding the phone.
     */
    public function label(): string;

    /**
     * How much this factor is worth, for ordering. Higher is offered first.
     *
     * The built-ins are 60 (authenticator app) and 20 (mailed code), leaving room on
     * either side and between. A passkey is not a factor here — it is handled separately
     * as a primary method — but for calibration it would be 100.
     */
    public function strength(): int;

    /**
     * Can this account complete this factor right now?
     *
     * Must be free of side effects and must be honest: true is a promise that
     * {@see verify()} can succeed. False for an account that has not enrolled, for one
     * whose channel is missing (no phone number, no address), and whenever the
     * application has withdrawn the method.
     */
    public function isEnrolledFor(int $userId): bool;

    /**
     * Does this factor have to deliver something before it can be verified?
     *
     * An authenticator app answers false: the code is already on the person's phone. A
     * mailed code, an SMS or a push answers true, and the screen then offers to send.
     * The flow uses this to decide whether "send me a code" is a thing it can offer at
     * all.
     */
    public function needsSending(): bool;

    /**
     * Deliver a challenge, when this factor has one to deliver.
     *
     * Called only from a place where the person asked, never from the decision about what
     * to offer. Returns false when there was nothing to send to — which the caller
     * reports as such rather than pretending the step-up can be completed.
     *
     * Factors that answer false to {@see needsSending()} should return false here too.
     */
    public function send(int $userId): bool;

    /**
     * Is this the right answer for this account, once?
     *
     * A correct answer must not be reusable. Rate limiting and expiry belong here, not in
     * the caller: only the adaptor knows what it issued.
     */
    public function verify(int $userId, string $code): bool;
}
