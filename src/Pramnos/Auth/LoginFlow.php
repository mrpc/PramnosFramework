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
    private ?EmailSecondFactor $emailFactor;
    private ?NewDeviceAuthLink $authLink;

    /**
     * All collaborators are optional — production code lets the seams lazily
     * resolve the real singletons; tests inject doubles.
     */
    public function __construct(
        ?Auth $auth = null,
        ?Loginlockout $lockout = null,
        ?TwoFactorAuthService $twoFactor = null,
        ?PasskeyServiceInterface $passkeys = null,
        ?EmailSecondFactor $emailFactor = null,
        ?NewDeviceAuthLink $authLink = null
    ) {
        $this->auth        = $auth;
        $this->lockout     = $lockout;
        $this->twoFactor   = $twoFactor;
        $this->passkeys    = $passkeys;
        $this->emailFactor = $emailFactor;
        $this->authLink    = $authLink;
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

        /**
         * The per-address limit, when the application asked for one.
         *
         * Checked beside the per-account lockout because it answers the other half of the
         * question. The per-account counter protects one account from being guessed at;
         * it is no defence at all against the attack that actually happens — a list of
         * leaked username/password pairs, one attempt each, from one address. Every
         * counter stays at 1 and nothing ever locks.
         *
         * Refused before the password is checked, like the account lockout, so a limited
         * address cannot even learn whether a password was right.
         */
        $ipLimit = SecurityPolicy::ipRateLimit();
        $clientIp = $ipLimit === null ? '' : (string) (\Pramnos\Http\Request::clientIp() ?: '');

        if ($ipLimit !== null && $clientIp !== '') {
            $ipStatus = $this->lockout()->getLockoutStatus('ip', $clientIp);
            if (!empty($ipStatus['locked'])) {
                return LoginFlowResult::locked((int) $ipStatus['remaining']);
            }
        }

        $response = $this->verifyCredentials(trim($username), $password, $remember);
        if ($response === false || empty($response['status']) || empty($response['uid'])) {
            $this->lockout()->recordFailedAttempt('identifier', $identifier);

            if ($ipLimit !== null && $clientIp !== '') {
                $this->lockout()->recordFailedAttemptWithin(
                    'ip',
                    $clientIp,
                    $ipLimit['window'],
                    $ipLimit['attempts']
                );
            }
            // Attribute the failure (and any lockout it triggers) to a real
            // account when the identifier resolves to one; unknown identifiers
            // leave no trail. ActivityLog is self-guarding, so this is a no-op
            // for apps without the authserver activity table.
            $failedId = $this->resolveUserId(trim($username));
            if ($failedId !== null) {
                ActivityLog::record($failedId, 'login_failed');
                $after = $this->lockout()->getLockoutStatus('identifier', $identifier);
                if (!empty($after['locked'])) {
                    ActivityLog::record($failedId, 'account_locked');
                }
            }
            return LoginFlowResult::failed();
        }

        $userId  = (int) $response['uid'];
        $methods = $this->stepUpMethods($userId);

        if ($methods !== []) {
            $this->beginStepUp($userId, $remember, $identifier, $methods);
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
        return $this->finishLogin($pending['userId'], $pending['remember'], $pending['identifier'], 'twofactor');
    }

    /**
     * Send the pending login an email code, and say whether it went.
     *
     * Separate from `attempt()` on purpose: a step-up does **not** mail a code the moment
     * the password is accepted. An account that has an authenticator app and email as a
     * fallback would get a mail on every single sign-in it never reads, and a person who
     * mistypes their password three times would get three. The screen asks for it — which
     * is also the only reading of "resend" that cannot be triggered by somebody else's
     * failed password attempt.
     *
     * Rate limiting is the code store's own: a second request replaces the first code
     * rather than adding one, so asking repeatedly gains an attacker nothing.
     *
     * @return bool False when there is no pending login, the method is not available to
     *              this account, or there was no address to send to.
     */
    public function sendEmailCode(): bool
    {
        $pending = $this->pending();
        if ($pending === null) {
            return false;
        }

        if (!$this->emailFactor()->isEnabledFor($pending['userId'])) {
            return false;
        }

        return $this->emailFactor()->send($pending['userId']);
    }

    /**
     * Which factors the pending login may be completed with.
     *
     * The same decision `attempt()` made, asked again on a later request — a step-up
     * screen rendered by a fresh GET has no result object to read it from.
     *
     * It belongs here rather than in the controller because answering it means asking the
     * factor services, and those are this class's collaborators: a controller that
     * constructed them itself would query the database from its own view layer, which is
     * both the wrong place and untestable without one.
     *
     * @return string[] Empty when nothing is pending.
     */
    public function pendingStepUpMethods(): array
    {
        $pending = $this->pending();
        if ($pending === null) {
            return [];
        }

        return $this->stepUpMethods($pending['userId']);
    }

    /**
     * Is a code already outstanding for the pending login?
     *
     * So the step-up screen can say "enter the code we sent" instead of offering to send
     * one that is already in the person's inbox.
     */
    public function hasLiveEmailCode(): bool
    {
        $pending = $this->pending();
        if ($pending === null) {
            return false;
        }

        return $this->emailFactor()->hasLiveCode($pending['userId']);
    }

    /**
     * Mail the pending login a single-use sign-in link.
     *
     * Refuses without a pending login, and that refusal is the security property: with it,
     * the endpoint cannot be used to mail an arbitrary account a link to sign in. Somebody
     * has to have got the password right first.
     */
    public function sendAuthLink(string $returnUrl = ''): bool
    {
        $pending = $this->pending();
        if ($pending === null) {
            return false;
        }

        return $this->authLink()->send($pending['userId'], $returnUrl);
    }

    /**
     * Finish a login from a link, with no pending state required.
     *
     * The link deliberately works in a browser that has never seen this session: people
     * read mail on a phone and click there, and a flow that only worked in the original
     * browser would send them back to the password form with no way to explain why.
     *
     * The token *is* the authorisation, which is why {@see NewDeviceAuthLink::consume()}
     * spends it before this method is allowed to establish anything.
     */
    public function completeAuthLink(string $token): LoginFlowResult
    {
        $userId = $this->authLink()->consume($token);
        if ($userId === null) {
            return LoginFlowResult::failed();
        }

        // Any pending step-up for this browser is now settled — the link outranks it, and
        // leaving it behind would strand a half-login in the session.
        $pending = $this->pending();
        $remember = $pending !== null && $pending['userId'] === $userId
            ? (bool) $pending['remember']
            : false;
        $identifier = $pending !== null && $pending['userId'] === $userId
            ? (string) $pending['identifier']
            : '';
        $this->clearPending();

        return $this->finishLogin($userId, $remember, $identifier, NewDeviceAuthLink::METHOD);
    }

    /**
     * Second leg (email path): finish a pending login with a mailed code.
     *
     * Mirrors {@see completeTwoFactor()} — a wrong code leaves the pending state intact
     * so the person can retry, and the attempt cap inside the factor decides when
     * retrying stops being possible. The method is recorded as `email` rather than
     * `twofactor` so an audit can tell which factor actually carried a login.
     */
    public function completeEmailCode(string $code): LoginFlowResult
    {
        $pending = $this->pending();
        if ($pending === null) {
            return LoginFlowResult::failed();
        }

        if (!$this->emailFactor()->verify($pending['userId'], trim($code))) {
            return LoginFlowResult::failed();
        }

        $this->clearPending();

        return $this->finishLogin(
            $pending['userId'],
            $pending['remember'],
            $pending['identifier'],
            EmailSecondFactor::METHOD
        );
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
        return $this->finishLogin($pending['userId'], $pending['remember'], $pending['identifier'], 'passkey');
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
        $hasTotp = $this->twoFactor()->isEnabled($userId);

        $methods = [];

        if ($hasTotp) {
            $methods[] = 'twofactor';
        }

        // Ranked below the authenticator app, always. An account that has both is asked
        // for the app and *offered* mail; putting email first would silently downgrade
        // every account that had done the stronger thing.
        if ($this->emailFactor()->isEnabledFor($userId)) {
            $methods[] = EmailSecondFactor::METHOD;
        }

        // The site's new-device policy, read before anything expensive: it is a setting,
        // and when it is `notify` — the default — an account with no second factor needs
        // no further questions asked about it.
        $action = NewSignInAlert::action();

        if ($methods === [] && $action === 'notify') {
            return [];
        }

        // Asked only now. Every login used to reach this, which put a passkey lookup on
        // the critical path of accounts that have none — an extra query per sign-in for an
        // answer nothing was going to use.
        $hasPasskey = $this->passkeys()->hasCredentials($userId);

        if ($methods !== [] && $hasPasskey) {
            $methods[] = 'passkey';
        }

        /**
         * What the *site* demands of a device it has not seen before.
         *
         * Merged in rather than replacing what the account has chosen, and merged in even
         * when the account has chosen nothing — that is the case it exists for. An account
         * with no second factor at all previously went straight through on a stolen
         * password, and the only response available was a mail telling its owner
         * afterwards.
         *
         * The demand can therefore add `email` to an account that never asked for it: see
         * {@see NewSignInAlert::requiredFor()}, which is where the "must be satisfiable"
         * rule lives.
         */
        $demanded = $action === 'notify' ? [] : NewSignInAlert::requiredFor(
            $userId,
            // Whether this sign-in qualifies at all, which is the site's other setting:
            // every unfamiliar browser, or only one there is something to be suspicious
            // about. Asked only once the action is something other than `notify`, so the
            // default path reads neither the activity log nor the session table.
            $this->qualifiesForDemand($userId),
            $hasTotp,
            $hasPasskey
        );

        foreach ($demanded as $method) {
            if (!in_array($method, $methods, true)) {
                $methods[] = $method;
            }
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

    /**
     * Resolve a login identifier (username or email) to a user id, or null.
     *
     * Used only to attribute failed-login / lockout activity to a real account
     * — an unrecognised identifier returns null and produces no activity entry.
     *
     * @param string $identifier The submitted username or email.
     * @return int|null The matching users.userid, or null when none matches.
     */
    protected function resolveUserId(string $identifier): ?int
    {
        if ($identifier === '') {
            return null;
        }
        // Best-effort attribution only — never let a DB hiccup here turn a
        // failed-login into a hard error.
        try {
            $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#users')
                ->select('userid')
                ->where('username', $identifier)
                ->orWhere('email', $identifier)
                ->first();
            return ($row && $row->numRows > 0) ? (int) $row->fields['userid'] : null;
        } catch (\Throwable $ex) {
            return null;
        }
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

    /**
     * The email second factor (seam so tests can inject a double).
     */
    protected function emailFactor(): EmailSecondFactor
    {
        return $this->emailFactor ??= new EmailSecondFactor();
    }

    /**
     * Does this sign-in meet the site's trigger for demanding something?
     *
     * `new_device` asks the fingerprint question alone. `suspicious` asks
     * {@see SignInRisk} and accepts only the signals that are hard to explain innocently
     * — a country the account has never used, two places at once, a country change too
     * soon to have travelled, a success straight after a run of failures.
     *
     * A seam because the alternative is a login flow that cannot be unit-tested: both
     * readings query the activity log, and one of them queries the session table too.
     */
    protected function qualifiesForDemand(int $userId): bool
    {
        if (NewSignInAlert::trigger() === 'suspicious') {
            return SignInRisk::isSuspicious($userId);
        }

        return NewSignInAlert::isNew($userId, SignInFingerprint::current());
    }

    /**
     * The new-device auth link (seam so tests can inject a double).
     */
    protected function authLink(): NewDeviceAuthLink
    {
        return $this->authLink ??= new NewDeviceAuthLink();
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
     *
     * The $method tags the login in the activity log so a straight password
     * login, a two-factor step-up and a passkey step-up are distinguishable. It
     * is set on the Auth instance (via {@see Auth::setLoginMethod()}) just
     * before {@see self::establishSession()} — a BC-safe seam that never changes
     * loginById()'s public signature (CLAUDE.md §6). The trailing default keeps
     * the signature compatible with any subclass overriding finishLogin().
     *
     * @param string $method 'password' | 'twofactor' | 'email' | 'passkey' — recorded as the
     *                       factor that carried the login, so an audit can tell them apart.
     */
    protected function finishLogin(int $userId, bool $remember, string $identifier, string $method = 'password'): LoginFlowResult
    {
        if ($identifier !== '') {
            $this->lockout()->clearSuccessfulLoginState('identifier', $identifier);
        }

        $this->auth()->setLoginMethod($method);

        if (!$this->establishSession($userId, $remember)) {
            return LoginFlowResult::failed();
        }

        return LoginFlowResult::success($userId);
    }

    /** Stash the pending step-up so a completion call can pick it up. */
    protected function beginStepUp(
        int $userId,
        bool $remember,
        string $identifier,
        array $methods = []
    ): void {
        // The completion call is a second request; without a session there is nothing
        // for it to pick this up from, and the step-up would restart for ever.
        \Pramnos\Http\Session::getInstance()->ensureStarted();

        $_SESSION[static::S_PENDING_USER]       = $userId;
        $_SESSION[static::S_PENDING_REMEMBER]   = $remember;
        $_SESSION[static::S_PENDING_IDENTIFIER] = $identifier;
        $_SESSION[static::S_PENDING_TIME]       = time();

        /**
         * The auth link is sent here, once, and nowhere else.
         *
         * It is the one step-up method the person cannot start themselves: a screen saying
         * "we have emailed you a link" with no mail sent is a dead end, and it is the
         * shape this would take if sending were left to the view.
         *
         * Not sent from the renderer, deliberately — a renderer runs again on every
         * refresh and on every failed retry, so the link would be reissued (invalidating
         * the one the person is holding) each time they reloaded the page.
         *
         * The emailed *code* is not sent here, and that asymmetry is intentional: a code
         * is an alternative the person may never use, whereas the link is the only way
         * through. See {@see sendEmailCode()}.
         */
        if (in_array(NewDeviceAuthLink::METHOD, $methods, true)) {
            $this->sendAuthLink();
        }
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
