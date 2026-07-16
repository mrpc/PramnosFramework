<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Immutable outcome of a {@see LoginFlow} step.
 *
 * A single value object describes every terminal and intermediate state the
 * login state machine can reach, so a controller can branch on one thing:
 *
 *   SUCCESS          — the session was established; {@see self::$userId} is set.
 *   FAILED           — bad credentials, or a step-up code that did not verify.
 *   LOCKED           — brute-force lockout in effect; {@see self::$lockoutRemaining}
 *                      holds the seconds until it expires.
 *   STEP_UP_REQUIRED — the password was correct but a second factor is required.
 *                      {@see self::$stepUpMethods} lists which factors the user
 *                      may complete the step-up with (e.g. ['twofactor'],
 *                      ['twofactor','passkey']); the pending user is stashed in
 *                      the session by the flow, not exposed to the client.
 *
 * Named constructors keep every field consistent for a given status — a caller
 * can never build, say, a SUCCESS result without a user id. There is no public
 * constructor.
 */
readonly class LoginFlowResult
{
    /** The session was established. */
    public const SUCCESS = 'success';

    /** Credentials (or a step-up code) did not verify. */
    public const FAILED = 'failed';

    /** A brute-force lockout is in effect. */
    public const LOCKED = 'locked';

    /** Password verified; a second factor is required to finish. */
    public const STEP_UP_REQUIRED = 'step_up_required';

    /**
     * @param string        $status           One of the status constants above.
     * @param int|null       $userId          The user id on SUCCESS / STEP_UP_REQUIRED, null otherwise.
     * @param int            $lockoutRemaining Seconds until the lockout expires (LOCKED only, else 0).
     * @param string[]       $stepUpMethods    Available second-factor methods (STEP_UP_REQUIRED only).
     */
    private function __construct(
        public string $status,
        public ?int $userId = null,
        public int $lockoutRemaining = 0,
        public array $stepUpMethods = [],
    ) {}

    /** The session was established for $userId. */
    public static function success(int $userId): self
    {
        return new self(self::SUCCESS, $userId);
    }

    /** Credentials or a step-up code did not verify. */
    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    /** A lockout is in effect; $remaining seconds until it lifts. */
    public static function locked(int $remaining): self
    {
        return new self(self::LOCKED, null, max(0, $remaining));
    }

    /**
     * Password verified but a second factor is required.
     *
     * @param string[] $methods The factors the user may use to finish.
     */
    public static function stepUpRequired(int $userId, array $methods): self
    {
        return new self(self::STEP_UP_REQUIRED, $userId, 0, array_values($methods));
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function isLocked(): bool
    {
        return $this->status === self::LOCKED;
    }

    public function needsStepUp(): bool
    {
        return $this->status === self::STEP_UP_REQUIRED;
    }

    /** Whether the given second-factor method is offered for this step-up. */
    public function allowsStepUpMethod(string $method): bool
    {
        return in_array($method, $this->stepUpMethods, true);
    }
}
