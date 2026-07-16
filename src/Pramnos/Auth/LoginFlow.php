<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Auth\Passkey\PasskeyService;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;

/**
 * Overridable login state machine for scaffolded auth servers.
 *
 * Composes the framework's existing building blocks — {@see Auth} credential
 * verification, {@see Loginlockout} brute-force protection, the
 * {@see TwoFactorAuthService} and the {@see PasskeyService} — into the canonical
 * password-then-step-up flow a fresh authserver adopts with zero custom code:
 *
 *   attempt()             password  → lockout check → credentials → (step-up?)
 *   completeTwoFactor()   TOTP / backup code step-up → session
 *   completePasskey()     passkey (second factor) step-up → session
 *
 * The pending-login state between {@see self::attempt()} and its completion is
 * kept **server-side, in the session** — only the user id, the "remember me"
 * flag and the lockout identifier, never the password. This is the deliberate
 * departure from apps that round-trip a base64 password through a hidden form
 * field: nothing sensitive leaves the server, and a step-up can only complete a
 * login this same session started.
 *
 * Backward compatibility (CLAUDE.md §6, memory project_scaffoldable_authserver):
 * this is a NEW entry point. Apps with their own login controller
 * (an application auth layer's Home::processLogin) never enter it, so their own 2FA is
 * untouched. It relies only on {@see Auth::loginById()} for the session bootstrap
 * and NEVER puts second-factor enforcement inside {@see Auth::auth()}, which
 * those apps still call for session-only bootstrap.
 *
 * Every collaborator and policy decision is reached through a protected seam
 * (`auth()`, `lockout()`, `twoFactor()`, `passkeys()`, `stepUpMethods()`,
 * `establishSession()`, the pending-state accessors) so a scaffolded app can
 * subclass this to change one rule — e.g. make a passkey a mandatory second
 * factor, add IP-scoped lockout, or lengthen the step-up window — without
 * forking, and so the flow is unit-testable without a live session or database.
 */
class LoginFlow
{
    /** Session key: pending step-up user id. */
    protected const S_PENDING_USER = 'loginflow_pending_userid';

    /** Session key: pending "remember me" flag. */
    protected const S_PENDING_REMEMBER = 'loginflow_pending_remember';

    /** Session key: pending lockout identifier (to clear on success). */
    protected const S_PENDING_IDENTIFIER = 'loginflow_pending_identifier';

    /** Session key: unix time the step-up became pending (TTL anchor). */
    protected const S_PENDING_TIME = 'loginflow_pending_time';

    private ?Auth $auth;
    private ?Loginlockout $lockout;
    private ?TwoFactorAuthService $twoFactor;
    private ?PasskeyServiceInterface $passkeys;

    /**
     * All collaborators are optional — production code lets the seams lazily
     * resolve the real singletons; tests inject doubles.
     */
    public function __construct(
        ?Auth $auth = null,
        ?Loginlockout $lockout = null,
        ?TwoFactorAuthService $twoFactor = null,
        ?PasskeyServiceInterface $passkeys = null
    ) {
        $this->auth      = $auth;
        $this->lockout   = $lockout;
        $this->twoFactor = $twoFactor;
        $this->passkeys  = $passkeys;
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * First leg: verify a username/password and decide what happens next.
     *
     * Order of concerns (each is a protected seam):
     *   1. If a lockout is active for this identifier → {@see LoginFlowResult::locked()}.
     *   2. Verify credentials. On failure → record the attempt, {@see LoginFlowResult::failed()}.
     *   3. If the user needs a second factor → stash pending state, {@see LoginFlowResult::stepUpRequired()}.
     *   4. Otherwise finish the login immediately → {@see LoginFlowResult::success()}.
     *
     * @param string $username Username or email.
     * @param string $password Plain-text password.
     * @param bool   $remember Set a persistent login cookie once logged in.
     */
    public function attempt(string $username, string $password, bool $remember = true): LoginFlowResult
    {
        $identifier = $this->lockoutIdentifier($username);

        $status = $this->lockout()->getLockoutStatus('identifier', $identifier);
        if (!empty($status['locked'])) {
            return LoginFlowResult::locked((int) $status['remaining']);
        }

        $response = $this->verifyCredentials(trim($username), $password, $remember);
        if ($response === false || empty($response['status']) || empty($response['uid'])) {
            $this->lockout()->recordFailedAttempt('identifier', $identifier);
            return LoginFlowResult::failed();
        }

        $userId  = (int) $response['uid'];
        $methods = $this->stepUpMethods($userId);

        if ($methods !== []) {
            $this->beginStepUp($userId, $remember, $identifier);
            return LoginFlowResult::stepUpRequired($userId, $methods);
        }

        return $this->finishLogin($userId, $remember, $identifier);
    }

    /**
     * Second leg (TOTP path): finish a pending login with a 2FA code.
     *
     * Verifies the code against the pending user's {@see TwoFactorAuthService}.
     * A wrong code leaves the pending state intact so the user can retry; a
     * correct one clears it and establishes the session.
     *
     * @param string $code TOTP or backup code.
     */
    public function completeTwoFactor(string $code): LoginFlowResult
    {
        $pending = $this->pending();
        if ($pending === null) {
            return LoginFlowResult::failed();
        }

        if (!$this->twoFactor()->verifyCode($pending['userId'], trim($code))) {
            return LoginFlowResult::failed();
        }

        $this->clearPending();
        return $this->finishLogin($pending['userId'], $pending['remember'], $pending['identifier']);
    }

    /**
     * Second leg (passkey path): finish a pending login with a verified passkey.
     *
     * The controller runs the WebAuthn assertion ceremony (via the passkey
     * service) and passes the user id it resolved. The step-up only succeeds
     * when that id matches the user who passed the password leg — a passkey
     * belonging to a different account can never complete someone else's login.
     *
     * @param int $verifiedUserId The user id the passkey ceremony verified.
     */
    public function completePasskey(int $verifiedUserId): LoginFlowResult
    {
        $pending = $this->pending();
        if ($pending === null || $verifiedUserId !== $pending['userId']) {
            return LoginFlowResult::failed();
        }

        $this->clearPending();
        return $this->finishLogin($pending['userId'], $pending['remember'], $pending['identifier']);
    }

    /**
     * The user id whose step-up is currently pending, or null when none is in
     * flight (or it expired). Controllers use this to know whether to render the
     * step-up form and to scope a passkey step-up ceremony to the right user.
     */
    public function pendingUserId(): ?int
    {
        $pending = $this->pending();
        return $pending === null ? null : $pending['userId'];
    }

    /** Abandon a pending step-up (e.g. the user backed out to the login form). */
    public function cancel(): void
    {
        $this->clearPending();
    }

    // ── Policy seams ──────────────────────────────────────────────────────────

    /**
     * Which second factors, if any, the user must satisfy after the password.
     *
     * Default policy: a second factor is required only when the user has 2FA
     * enabled. When it is, TOTP is the mandatory method and a passkey is offered
     * as an alternative if the user has one registered — so they can complete
     * the step-up with either their authenticator app or a passkey. A registered
     * passkey alone (without 2FA) does NOT force a step-up, since passkeys are
     * primarily a passwordless *primary* login method.
     *
     * Override to change policy (e.g. always require a passkey as second factor).
     *
     * @return string[] Empty = no step-up. Otherwise a subset of ['twofactor','passkey'].
     */
    protected function stepUpMethods(int $userId): array
    {
        if (!$this->twoFactor()->isEnabled($userId)) {
            return [];
        }

        $methods = ['twofactor'];
        if ($this->passkeys()->hasCredentials($userId)) {
            $methods[] = 'passkey';
        }
        return $methods;
    }

    /**
     * Normalise a username into the lockout tracking key.
     *
     * Lower-cased + trimmed so "Alice" and " alice " share one failure counter
     * and an attacker cannot dodge the lockout by varying the case.
     */
    protected function lockoutIdentifier(string $username): string
    {
        return strtolower(trim($username));
    }

    /** Seconds a pending step-up stays valid before it must be restarted. */
    protected function stepUpTtl(): int
    {
        return 300;
    }

    // ── Collaborator seams (lazy singletons; tests inject doubles) ────────────

    /**
     * Verify credentials WITHOUT establishing a session.
     *
     * Delegates to {@see Auth::verifyCredentials()} (not `auth()`), so the
     * session is only bootstrapped later via {@see self::establishSession()}
     * once any required step-up has passed.
     *
     * @return array<string,mixed>|false The login-response array, or false.
     */
    protected function verifyCredentials(string $username, string $password, bool $remember): array|false
    {
        return $this->auth()->verifyCredentials($username, $password, false, $remember);
    }

    /**
     * Establish the session for a fully-verified user (passwordless bootstrap).
     */
    protected function establishSession(int $userId, bool $remember): bool
    {
        return $this->auth()->loginById($userId, $remember);
    }

    protected function auth(): Auth
    {
        return $this->auth ??= \Pramnos\Framework\Factory::getAuth();
    }

    protected function lockout(): Loginlockout
    {
        return $this->lockout ??= new Loginlockout();
    }

    protected function twoFactor(): TwoFactorAuthService
    {
        return $this->twoFactor ??= new TwoFactorAuthService();
    }

    protected function passkeys(): PasskeyServiceInterface
    {
        return $this->passkeys ??= new PasskeyService();
    }

    // ── Pending step-up state (server-side, session-backed) ───────────────────

    /**
     * Finish a login: clear the failure counter, then bootstrap the session.
     * Returns SUCCESS, or FAILED if the session bootstrap is refused (e.g. the
     * account became inactive between the password leg and here).
     */
    protected function finishLogin(int $userId, bool $remember, string $identifier): LoginFlowResult
    {
        if ($identifier !== '') {
            $this->lockout()->clearSuccessfulLoginState('identifier', $identifier);
        }

        if (!$this->establishSession($userId, $remember)) {
            return LoginFlowResult::failed();
        }

        return LoginFlowResult::success($userId);
    }

    /** Stash the pending step-up so a completion call can pick it up. */
    protected function beginStepUp(int $userId, bool $remember, string $identifier): void
    {
        $_SESSION[static::S_PENDING_USER]       = $userId;
        $_SESSION[static::S_PENDING_REMEMBER]   = $remember;
        $_SESSION[static::S_PENDING_IDENTIFIER] = $identifier;
        $_SESSION[static::S_PENDING_TIME]       = time();
    }

    /**
     * Read the pending step-up state, or null when none is in flight. An expired
     * pending state (older than {@see self::stepUpTtl()}) is cleared and treated
     * as absent so a stale half-login can never be completed.
     *
     * @return array{userId:int, remember:bool, identifier:string}|null
     */
    protected function pending(): ?array
    {
        if (!isset($_SESSION[static::S_PENDING_USER])) {
            return null;
        }

        $startedAt = (int) ($_SESSION[static::S_PENDING_TIME] ?? 0);
        if ($startedAt + $this->stepUpTtl() < time()) {
            $this->clearPending();
            return null;
        }

        return [
            'userId'     => (int) $_SESSION[static::S_PENDING_USER],
            'remember'   => (bool) ($_SESSION[static::S_PENDING_REMEMBER] ?? false),
            'identifier' => (string) ($_SESSION[static::S_PENDING_IDENTIFIER] ?? ''),
        ];
    }

    /** Drop every pending-step-up session key. */
    protected function clearPending(): void
    {
        unset(
            $_SESSION[static::S_PENDING_USER],
            $_SESSION[static::S_PENDING_REMEMBER],
            $_SESSION[static::S_PENDING_IDENTIFIER],
            $_SESSION[static::S_PENDING_TIME]
        );
    }
}
