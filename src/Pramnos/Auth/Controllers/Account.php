<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\LoginFlowResult;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\PasskeyException;
use Pramnos\Auth\Passkey\PasskeyService;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;
use Pramnos\Http\Response;

/**
 * General account controller — the single built-in surface a scaffolded auth
 * server exposes for a user's whole account lifecycle.
 *
 * It spans two concerns that share one controller but differ in authentication:
 *
 *   Public (no session) — the authentication entry flow, driven by {@see LoginFlow}:
 *     - login          — show the login form / process credentials (password leg)
 *     - verify         — complete a pending 2FA (TOTP/backup) step-up
 *     - passkeyOptions — issue WebAuthn assertion options for a pending passkey step-up
 *     - passkeyVerify  — verify the assertion and finish a pending passkey step-up
 *     - logout         — tear the session down
 *
 *   Authenticated — account management (require a logged-in session):
 *     - display        — dashboard overview (auth apps + recent activity)
 *     - profile        — view / edit profile
 *     - applications   — list of authorized OAuth2 applications
 *     - revokeapplication — revoke all tokens for one application (AJAX or redirect)
 *     - exportdata     — GDPR data portability (JSON download)
 *     - deleteaccount  — GDPR right to erasure (POST with password + confirmation)
 *     - privacy        — privacy / consent settings
 *     - security       — security overview (logins, sessions, 2FA status)
 *     - changepassword — change password (POST with current + new password)
 *
 * The password is never round-tripped through a form between the credential leg
 * and a step-up — {@see LoginFlow} keeps the pending state server-side. HTML views
 * are resolved from the application view path; every render/redirect and each
 * collaborator is a protected seam so a scaffolded app can rebrand or re-wire one
 * piece by subclassing, and the flow stays unit-testable.
 */
class Account extends Controller
{
    /**
     * Base route used in internal redirects (e.g. after form submission).
     * Override in subclasses when the controller is exposed under a different URL.
     * Example: class MyAccount extends Account { protected string $routeBase = 'MyAccount'; }
     */
    protected string $routeBase = 'Account';

    /** Session key holding the in-flight passkey step-up challenge. */
    protected const S_STEPUP_CHALLENGE = 'account_stepup_passkey_challenge';

    /** The login orchestrator (seam so tests can inject a double). */
    private ?LoginFlow $loginFlow = null;

    /** The passkey service (seam so tests can inject a double). */
    private ?PasskeyServiceInterface $passkeyService = null;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Public authentication-entry actions.
        $this->addaction([
            'login', 'verify', 'passkeyOptions', 'passkeyVerify', 'logout',
            'forgotpassword', 'resetpassword', 'register', 'sso',
            // Public because the link is opened from a mail client, in a browser that may
            // never have seen this session — the token is the credential.
            'authlink',
        ]);
        // Authenticated account-management actions.
        $this->addAuthAction([
            'applications', 'revokeapplication',
            'exportdata', 'deleteaccount',
            'privacy', 'security', 'changepassword', 'emailfactor',
            'sessions', 'revokesession',
            'profile',
        ]);
        parent::__construct($application);
    }

    // ── Authentication entry (public, LoginFlow-driven) ─────────────────────────

    /**
     * Login. GET renders the form; POST processes the credential (password) leg.
     *
     * Outcomes (all through {@see LoginFlow}):
     *   - already logged in  → straight to the return URL / dashboard.
     *   - correct + no 2FA   → session established, redirect.
     *   - second factor due  → render the step-up form (NO session yet).
     *   - locked             → re-render with the remaining lockout seconds.
     *   - wrong / missing     → re-render with the reason.
     */
    public function login(): mixed
    {
        if ($this->currentUserId() !== null) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));
            return null;
        }

        if ($this->requestMethod() !== 'POST') {
            return $this->renderLogin([]);
        }

        $username = $this->post('username');
        $password = $this->post('password', false); // never trim a password
        $remember = $this->post('remember') !== '';

        if (!$this->checkCsrf()) {
            return $this->renderLogin(['error' => 'invalid_token', 'username' => $username]);
        }

        if ($username === '' || $password === '') {
            return $this->renderLogin(['error' => 'missing_credentials', 'username' => $username]);
        }

        return $this->presentResult($this->flow()->attempt($username, $password, $remember), $username);
    }

    /**
     * Complete a pending second-factor step-up with a TOTP / backup code.
     *
     * Requires a pending login (from {@see self::login()}); a wrong code
     * re-renders the step-up form and leaves the pending state intact for retry.
     */
    public function verify(): mixed
    {
        if ($this->currentUserId() !== null) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));
            return null;
        }

        // No pending step-up → the half-login expired or was never started.
        if ($this->flow()->pendingUserId() === null) {
            return $this->renderLogin(['error' => 'session_expired']);
        }

        if ($this->requestMethod() !== 'POST') {
            return $this->renderStepUp([]);
        }

        if (!$this->checkCsrf()) {
            return $this->renderStepUp(['error' => 'invalid_token']);
        }

        // Asking for the sign-in link again, when that is what is being demanded.
        if ($this->post('send_auth_link') !== '') {
            return $this->renderStepUp(
                $this->flow()->sendAuthLink($this->returnUrl())
                    ? ['notice' => 'auth_link_sent']
                    : ['error' => 'auth_link_failed']
            );
        }

        // "Send me a code by email" is a POST to the same action rather than a link:
        // a GET that sends mail is one a crawler, a link preview or a back button can
        // fire, and each firing invalidates the code the person is holding.
        if ($this->post('send_email_code') !== '') {
            return $this->renderStepUp(
                $this->flow()->sendEmailCode()
                    ? ['notice' => 'email_code_sent']
                    : ['error' => 'email_code_failed']
            );
        }

        $code = $this->post('code');
        if ($code === '') {
            return $this->renderStepUp(['error' => 'missing_code']);
        }

        // Which factor the code belongs to comes from the form, not from guessing: both
        // are six digits, and trying one then the other would consume an email attempt
        // every time somebody typed a TOTP code.
        $result = $this->post('method') === \Pramnos\Auth\EmailSecondFactor::METHOD
            ? $this->flow()->completeEmailCode($code)
            : $this->flow()->completeTwoFactor($code);

        if ($result->isSuccess()) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));
            return null;
        }

        // Pending state is kept by LoginFlow so the user can retry.
        return $this->renderStepUp(['error' => 'invalid_code']);
    }

    /**
     * Issue WebAuthn assertion options for a pending passkey step-up (JSON).
     *
     * The ceremony is pinned to the user who passed the password leg — a passkey
     * belonging to a different account can never be offered here. The challenge is
     * kept server-side; only the public options go to the browser.
     */
    public function passkeyOptions(): mixed
    {
        $pending = $this->flow()->pendingUserId();
        if ($pending === null) {
            return Response::json(['error' => 'no_pending_login'], 401);
        }

        $options = $this->passkeys()->beginAuthentication($pending);
        $_SESSION[static::S_STEPUP_CHALLENGE] = $options->challenge;

        return Response::json(['options' => $options->toClientArray()], 200);
    }

    /**
     * Verify a passkey assertion and finish a pending passkey step-up (JSON).
     *
     * Requires a matching in-flight challenge issued by {@see self::passkeyOptions()}.
     * The assertion is verified for the pending user, then {@see LoginFlow::completePasskey()}
     * establishes the session only when the resolved user matches the pending one.
     * Returns the post-login redirect target for the browser to follow.
     */
    public function passkeyVerify(): mixed
    {
        $pending = $this->flow()->pendingUserId();
        if ($pending === null) {
            return Response::json(['error' => 'no_pending_login'], 401);
        }

        $challenge = (string) ($_SESSION[static::S_STEPUP_CHALLENGE] ?? '');
        if ($challenge === '') {
            return Response::json(['error' => 'no_ceremony'], 400);
        }
        unset($_SESSION[static::S_STEPUP_CHALLENGE]);

        try {
            $result = $this->passkeys()->finishAuthentication(
                new AuthenticationOptions($challenge, '', $pending),
                $this->rawRequestBody()
            );
        } catch (PasskeyException $e) {
            return Response::json(['error' => 'authentication_failed'], 401);
        }

        $flowResult = $this->flow()->completePasskey($result->userId);
        if (!$flowResult->isSuccess()) {
            return Response::json(['error' => 'login_failed'], 401);
        }

        return Response::json([
            'status'   => 'ok',
            'redirect' => $this->postLoginTarget($this->returnUrl()),
        ], 200);
    }

    /**
     * Log out: drop any pending step-up, tear the session down, back to login.
     */
    public function logout(): void
    {
        $this->flow()->cancel();
        $this->authService()->logout();
        $this->redirect(sURL . 'login');
    }

    /**
     * Forgot password. GET renders the email form; POST issues a reset link.
     *
     * To avoid revealing which emails have accounts, the POST response is always
     * the same generic "if that email exists, we've sent a link" — whether or not
     * a matching user was found. The token is random, stored only as a SHA-256
     * hash (in userdetails), and expires in one hour.
     */
    public function forgotpassword(): mixed
    {
        if ($this->currentUserId() !== null) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));
            return null;
        }
        if ($this->requestMethod() !== 'POST') {
            return $this->renderForgot([]);
        }
        $email = $this->post('email');

        if (!$this->checkCsrf()) {
            return $this->renderForgot(['error' => 'invalid_token', 'email' => $email]);
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->renderForgot(['error' => 'invalid_email', 'email' => $email]);
        }

        $userId = $this->findUserIdByEmail($email);
        if ($userId !== null) {
            $token = $this->generateResetToken();
            $this->storeResetToken($userId, hash('sha256', $token), time() + 3600);
            $this->sendResetEmail($email, $token);
            \Pramnos\Auth\ActivityLog::record($userId, 'password_reset_requested');
        }

        // Same message regardless of whether the email matched (anti-enumeration).
        return $this->renderForgot(['message' => 'sent']);
    }

    /**
     * Reset password. GET (with a token) renders the new-password form; POST
     * verifies the token, enforces the password policy and updates the password.
     *
     * A wrong / expired token or a policy failure re-renders the form; success
     * consumes the token and sends the user to the login page.
     */
    public function resetpassword(): mixed
    {
        if ($this->currentUserId() !== null) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));
            return null;
        }

        $token = $this->requestMethod() === 'POST' ? $this->post('token') : $this->query('token');

        if ($this->requestMethod() !== 'POST') {
            if ($token === '') {
                return $this->renderForgot(['error' => 'invalid_reset_link']);
            }
            return $this->renderReset(['token' => $token]);
        }

        if (!$this->checkCsrf()) {
            return $this->renderReset(['token' => $token, 'error' => 'invalid_token']);
        }

        $userId = $this->consumeResetToken($token);
        if ($userId === null) {
            return $this->renderReset(['token' => '', 'error' => 'invalid_reset_link']);
        }

        $new     = $this->post('password', false);
        $confirm = $this->post('confirm_password', false);
        $policyError = $this->validatePasswordPolicy($new, $confirm);
        if ($policyError !== null) {
            return $this->renderReset(['token' => $token, 'error' => $policyError]);
        }

        $this->updatePassword($userId, $new);
        $this->clearResetToken($userId);
        \Pramnos\Auth\ActivityLog::record($userId, 'password_reset_completed');

        $this->addMessage('Your password has been reset. Please sign in with your new password.');
        $this->redirect(sURL . 'login');
        return null;
    }

    /**
     * Self-service registration. GET renders the form; POST creates the account.
     *
     * **Off unless the `auth_allow_registration` setting is on.** A scaffolded
     * application should not gain an open sign-up page by being upgraded, and
     * most applications behind this framework create their accounts by some
     * other route entirely. With the setting off the form still renders — it says
     * registration is closed, rather than 404-ing a page the navigation links to.
     *
     * The registration view exists in every bundled theme and had no controller
     * at all, so its form posted to a route that did not exist and the discovery
     * document's `registration_endpoint` pointed at nothing.
     *
     * ## What it tells an attacker
     *
     * A "that username is taken" message confirms an account exists, and there is
     * no way to both refuse a duplicate and not confirm it — a form that has to
     * let a person pick a different name has to say why. So this reveals it, and
     * the mitigations are the ones that actually work: leave registration off
     * when you do not need it, and keep the login lockout in place, since the
     * value of an enumerated username is what you do with it afterwards. The
     * *email* case is worded so that it does not add a second, independent
     * confirmation.
     *
     * The new account is created active but with the lowest privilege level;
     * nothing here can grant a usertype.
     */
    public function register(): mixed
    {
        if ($this->currentUserId() !== null) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));
            return null;
        }

        if (!$this->registrationIsOpen()) {
            return $this->renderRegister(['error' => 'registration_closed']);
        }

        if ($this->requestMethod() !== 'POST') {
            return $this->renderRegister([]);
        }

        $username = $this->post('username');
        $email     = $this->post('email');
        $password  = $this->post('password', false);
        $confirm   = $this->post('confirm_password', false);

        // Echoed back so a rejected submission does not empty the form. The
        // password deliberately is not.
        $formData = ['username' => $username, 'email' => $email];

        if (!$this->checkCsrf()) {
            return $this->renderRegister(['error' => 'invalid_token', 'formData' => $formData]);
        }

        $fieldError = $this->validateRegistration($username, $email);
        if ($fieldError !== null) {
            return $this->renderRegister(['error' => $fieldError, 'formData' => $formData]);
        }

        $policyError = $this->validatePasswordPolicy($password, $confirm);
        if ($policyError !== null) {
            return $this->renderRegister(['error' => $policyError, 'formData' => $formData]);
        }

        if ($this->usernameExists($username)) {
            return $this->renderRegister(['error' => 'username_taken', 'formData' => $formData]);
        }

        if ($this->findUserIdByEmail($email) !== null) {
            return $this->renderRegister(['error' => 'email_unavailable', 'formData' => $formData]);
        }

        $userId = $this->createUser($username, $email, $password);
        if ($userId === null) {
            return $this->renderRegister(['error' => 'registration_failed', 'formData' => $formData]);
        }

        \Pramnos\Auth\ActivityLog::record($userId, 'account_registered');

        $this->addMessage('Your account has been created. Please sign in.');
        $this->redirect(sURL . 'login');
        return null;
    }

    /**
     * Single sign-on status page — am I signed in here, and to what?
     *
     * The page a person lands on from another application to find out whether
     * this server already knows them, and which applications they have
     * authorized. Public, because the answer for a signed-out visitor is the
     * useful half of it.
     *
     * The view shipped in every bundled theme without a controller to render it.
     */
    public function sso(): mixed
    {
        $doc        = $this->document();
        $doc->title = t('Single sign-on');

        $userId = $this->currentUserId();

        $view             = $this->getView('sso');
        $view->routeBase  = $this->routeBase;
        $view->header     = 'Single Sign-On';
        $view->isLoggedIn = $userId !== null;
        $view->user       = $userId !== null ? $this->currentUser() : null;
        $view->activeApps = $userId !== null ? $this->getAuthorizedApplications($userId) : [];

        return $view->display('sso');
    }

    // ── Authentication seams (overridable / mockable) ───────────────────────────

    /** The login orchestrator (lazy default; injectable for tests). */
    protected function flow(): LoginFlow
    {
        return $this->loginFlow ??= new LoginFlow();
    }

    /** The passkey service (lazy default; injectable for tests). */
    protected function passkeys(): PasskeyServiceInterface
    {
        return $this->passkeyService ??= new PasskeyService();
    }

    /** The raw request body (seam so tests can supply a WebAuthn assertion). */
    protected function rawRequestBody(): string
    {
        return \Pramnos\Http\Request::rawBody();
    }

    /** Current logged-in user id (> 1), or null when not authenticated. */
    protected function currentUserId(): ?int
    {
        $user = $this->currentUser();
        if ($user === null || $user === false
            || !isset($user->userid) || (int) $user->userid <= 1) {
            return null;
        }
        return (int) $user->userid;
    }

    /** The current user object from the session (seam so tests can supply one). */
    protected function currentUser(): mixed
    {
        return \Pramnos\User\User::getCurrentUser();
    }

    /** The HTTP method, upper-cased. */
    protected function requestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** A POST field (trimmed unless $trim is false — never trim passwords). */
    protected function post(string $key, bool $trim = true): string
    {
        $value = (string) ($_POST[$key] ?? '');
        return $trim ? trim($value) : $value;
    }

    /** Verify the anti-CSRF token on a POST. */
    protected function checkCsrf(): bool
    {
        return \Pramnos\Http\Session::getInstance()->checkToken('post');
    }

    /** The framework auth service (seam so tests can inject a double). */
    protected function authService(): \Pramnos\Auth\Auth
    {
        return \Pramnos\Framework\Factory::getAuth();
    }

    /**
     * The requested post-login return target, sanitised against open redirects.
     * Empty string when none / rejected (caller falls back to the dashboard).
     */
    protected function returnUrl(): string
    {
        $return = (string) ($_POST['return'] ?? $_GET['return'] ?? '');
        return $this->sanitizeReturnUrl(trim($return));
    }

    /**
     * Reject cross-origin / protocol-relative / control-character return URLs so
     * a crafted `?return=` cannot bounce a freshly-authenticated user off-site.
     * Same-origin absolute URLs (starting with sURL) and site-relative paths pass.
     */
    protected function sanitizeReturnUrl(string $return): string
    {
        if ($return === '') {
            return '';
        }
        // Control chars (incl. embedded newlines) are never valid in a URL here.
        if (preg_match('/[\x00-\x1f]/', $return)) {
            return '';
        }
        // Protocol-relative //host bypasses the scheme check below.
        if (str_starts_with($return, '//')) {
            return '';
        }
        if (preg_match('#^https?://#i', $return)) {
            $base = $this->baseUrl();
            return ($base !== '' && str_starts_with($return, $base)) ? $return : '';
        }
        return $return;
    }

    /** The application base URL used to whitelist same-origin absolute returns. */
    protected function baseUrl(): string
    {
        return defined('sURL') ? (string) sURL : '';
    }

    /**
     * Ask the theme for its standalone layout, if it has one.
     *
     * Every one of the built-in auth views is designed as a full-page centred card
     * — `min-height: 100vh` in the plain-CSS and Bootstrap themes, `min-h-screen` in
     * the Tailwind one — which only makes sense with nothing above it. They were
     * being wrapped in the theme's `theme.html.php` all the same, so `/login` came
     * with the site header, the navigation and a "Sign in" link to the page you were
     * already on, and then a full viewport of centred card below it.
     *
     * The mechanism is the theme's own and predates this: `Theme::$elements` has
     * mapped `'login'` to `login.php` from the beginning, so `loadtheme()` looks for
     * `<theme>/login.php` for this content type and falls back to `theme.html.php`
     * when there is none. No theme shipped the file, which is why the fallback was
     * the only path anyone ever saw. **An application theme without `login.php`
     * therefore keeps rendering exactly as it did** — nothing here forces a layout
     * that does not exist.
     *
     * Called from the render methods rather than from the constructor because the
     * theme is read at render time (`Html::render()` calls `loadTheme()` itself), and
     * because this controller also serves signed-in pages — a profile page belongs in
     * the site chrome.
     */
    protected function useStandaloneLayout(): void
    {
        $theme = $this->document()->themeObject ?? null;

        if (is_object($theme) && method_exists($theme, 'setContentType')) {
            $theme->setContentType('login');
        }
    }

    /** The document object (seam so tests can supply a stub). */
    protected function document(): object
    {
        return \Pramnos\Framework\Factory::getDocument();
    }

    /**
     * Branding passed to the built-in views. Every value is settings-driven with
     * a safe default, so a scaffolded app rebrands by setting a handful of keys
     * (or by overriding this method) — no view edits required.
     *
     * @return array{name:string, logo:string, primary_color:string, footer:string}
     */
    protected function brand(): array
    {
        $name = $this->setting('auth_brand_name');
        if ($name === '') {
            $name = $this->setting('sitename');
        }

        $color = $this->setting('auth_brand_primary_color');

        return [
            'name'          => $name !== '' ? $name : 'Sign in',
            'logo'          => $this->setting('auth_brand_logo'),
            'primary_color' => $color !== '' ? $color : '#2563eb',
            'footer'        => $this->setting('auth_brand_footer'),
        ];
    }

    /** Read a single application setting as a string (seam so tests avoid the DB). */
    protected function setting(string $key): string
    {
        return (string) (\Pramnos\Application\Settings::getSetting($key) ?? '');
    }

    /** Resolve the post-login redirect: the return URL, else the dashboard. */
    protected function postLoginTarget(string $return): string
    {
        return $return !== '' ? $return : (sURL . $this->routeBase);
    }

    /**
     * Render the login form. $ctx carries optional 'error' / 'lockoutSeconds'.
     * Overridable so a scaffolded app can rebrand without touching the flow.
     */
    protected function renderLogin(array $ctx): mixed
    {
        $doc        = $this->document();
        $doc->title = t('Login');
        $this->useStandaloneLayout();

        $view            = $this->getView('login');
        $view->routeBase = $this->routeBase;
        $view->returnUrl = $this->returnUrl();
        $view->brand     = $this->brand();
        foreach ($ctx as $key => $value) {
            $view->$key = $value;
        }
        return $view->display('login');
    }

    /**
     * Render the second-factor step-up form. $ctx carries optional 'error'.
     * The pending user id is exposed for the view; the password is NOT — it never
     * leaves the server (unlike a hidden-field password round-trip).
     */
    /**
     * Finish a sign-in from the emailed link.
     *
     * Public, and it has to be: the link is opened from a mail client, often on another
     * device, in a browser with no pending step-up. The token carries the authorisation and
     * is spent by the flow before a session is established.
     *
     * A bad or expired token renders the login form with a message rather than a 403 — the
     * common cause is a link that has been sitting in an inbox, and the useful next step is
     * to sign in again, which is the form.
     */
    public function authlink(): mixed
    {
        $result = $this->flow()->completeAuthLink($this->query('token'));

        if ($result->isSuccess()) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));

            return null;
        }

        return $this->renderLogin(['error' => 'authlink_invalid']);
    }

    protected function renderStepUp(array $ctx): mixed
    {
        $doc        = $this->document();
        $doc->title = t('Two-step verification');
        $this->useStandaloneLayout();

        $view                = $this->getView('login');
        $view->routeBase     = $this->routeBase;
        $view->returnUrl     = $this->returnUrl();
        $view->brand         = $this->brand();
        $view->pendingUserId = $this->flow()->pendingUserId();

        // What the screen may offer. Without this the view can only ever draw the
        // authenticator-app form, so an account whose only second factor is email would
        // be shown a box it has no way to fill.
        //
        // Asked of the flow rather than of the services: the flow owns those
        // collaborators and a test can replace it, which a `new EmailSecondFactor()` here
        // could not — this renderer would then open a database connection to draw a page.
        // Held in locals and assigned once. Reading `$view->emailFactor` back to compute
        // the next line assumes the view keeps what it is given, which a view is not
        // obliged to do — and a stub that does not raises "undefined property" on a page
        // that otherwise renders.
        $methods     = $this->flow()->pendingStepUpMethods();
        $hasEmail    = in_array(\Pramnos\Auth\EmailSecondFactor::METHOD, $methods, true);

        $view->methods          = $methods;
        $view->totpFactor       = in_array('twofactor', $methods, true);
        $view->emailFactor      = $hasEmail;
        $view->emailCodePending = $hasEmail && $this->flow()->hasLiveEmailCode();
        // The link case takes over the screen: there is nothing to type, so a code box
        // would be a field with no source. The link was already sent when the step-up
        // began — see LoginFlow::beginStepUp().
        $view->authLink         = in_array(\Pramnos\Auth\NewDeviceAuthLink::METHOD, $methods, true);

        foreach ($ctx as $key => $value) {
            $view->$key = $value;
        }
        return $view->display('login_2fa');
    }

    /** Branch on a LoginFlow result into the right response. */
    protected function presentResult(LoginFlowResult $result, string $username = ''): mixed
    {
        if ($result->isSuccess()) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));
            return null;
        }
        if ($result->needsStepUp()) {
            return $this->renderStepUp(['methods' => $result->stepUpMethods]);
        }
        if ($result->isLocked()) {
            return $this->renderLogin([
                'error'          => 'locked',
                'lockoutSeconds' => $result->lockoutRemaining,
                'username'       => $username,
            ]);
        }
        return $this->renderLogin(['error' => 'invalid_credentials', 'username' => $username]);
    }

    // ── Forgot / reset password seams ───────────────────────────────────────────

    /** A GET/query field (seam for tests). */
    protected function query(string $key): string
    {
        return isset($_GET[$key]) ? trim((string) $_GET[$key]) : '';
    }

    /** A fresh, cryptographically-random reset token (raw; only its hash is stored). */
    protected function generateResetToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Resolve a user id from an email, or null when no active user matches. */
    protected function findUserIdByEmail(string $email): ?int
    {
        $uid = (int) \Pramnos\User\User::getuserid($email, 'email');
        return $uid > 1 ? $uid : null;
    }

    /**
     * Store the reset token hash + expiry for a user in the userdetails key-value
     * store (no dedicated table needed).
     */
    protected function storeResetToken(int $userId, string $tokenHash, int $expires): void
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        foreach ([
            'password_reset_hash'    => $tokenHash,
            'password_reset_expires' => (string) $expires,
        ] as $field => $value) {
            $db->queryBuilder()->table('#PREFIX#userdetails')->upsert(
                ['userid' => $userId, 'fieldname' => $field, 'value' => $value],
                ['userid', 'fieldname'],
                ['value']
            );
        }
    }

    /**
     * Resolve the user id for a raw reset token, or null when it is unknown or
     * expired. An expired token is cleared as a side effect.
     */
    protected function consumeResetToken(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $row = $db->queryBuilder()->table('#PREFIX#userdetails')
            ->select(['userid'])
            ->where('fieldname', 'password_reset_hash')
            ->where('value', hash('sha256', $token))
            ->first();
        if (!$row || $row->numRows < 1) {
            return null;
        }
        $userId  = (int) $row->fields['userid'];
        $expRow  = $db->queryBuilder()->table('#PREFIX#userdetails')
            ->select(['value'])
            ->where('userid', $userId)
            ->where('fieldname', 'password_reset_expires')
            ->first();
        $expires = ($expRow && $expRow->numRows > 0) ? (int) $expRow->fields['value'] : 0;
        if ($expires < time()) {
            $this->clearResetToken($userId);
            return null;
        }
        return $userId;
    }

    /** Remove a user's reset token rows (single-use / cleanup). */
    protected function clearResetToken(int $userId): void
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        foreach (['password_reset_hash', 'password_reset_expires'] as $field) {
            $db->queryBuilder()->table('#PREFIX#userdetails')
                ->where('userid', $userId)
                ->where('fieldname', $field)
                ->delete();
        }
    }

    /** The absolute URL of the reset form for a raw token. */
    protected function resetLink(string $token): string
    {
        return sURL . $this->routeBase . '/resetpassword?token=' . urlencode($token);
    }

    /** Email a password-reset link (seam so tests do not send mail). */
    protected function sendResetEmail(string $email, string $token): void
    {
        $brand = $this->brand();
        $link  = $this->resetLink($token);
        $name  = htmlspecialchars((string) ($brand['name'] ?? 'Account'), ENT_QUOTES);
        $body  = '<p>We received a request to reset your ' . $name . ' password.</p>'
            . '<p>To choose a new password, follow this link (valid for one hour):</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">'
            . htmlspecialchars($link, ENT_QUOTES) . '</a></p>'
            . '<p>If you did not request this, you can safely ignore this email.</p>';
        try {
            $mailer = new \Pramnos\Email\Email();
            $mailer->subject = 'Password reset';
            $mailer->body    = $body;
            $mailer->to      = $email;
            $mailer->module  = 'auth'; // tag it in the mails audit log
            $mailer->send();
        } catch (\Throwable $e) {
            // A mail-transport failure must not break the flow (nor reveal, via a
            // 500, whether the email matched an account). The reset token is still
            // stored; the request can be retried once mail is configured.
            \Pramnos\Logs\Logger::log('Password-reset email failed: ' . $e->getMessage(), 'auth');
        }
    }

    /**
     * Render the forgot-password form. $ctx carries optional 'error' / 'message'.
     */
    protected function renderForgot(array $ctx): mixed
    {
        $doc        = $this->document();
        $doc->title = t('Forgot password');
        $this->useStandaloneLayout();

        $view            = $this->getView('login');
        $view->routeBase = $this->routeBase;
        $view->brand     = $this->brand();
        foreach ($ctx as $key => $value) {
            $view->$key = $value;
        }
        return $view->display('forgotpassword');
    }

    /**
     * Is self-service registration switched on?
     *
     * A separate seam from `setting()` so a subclass can decide on something
     * other than a global flag — an invite code, a domain allow-list, a
     * per-organization policy — without reimplementing the action around it.
     */
    protected function registrationIsOpen(): bool
    {
        return in_array(
            strtolower($this->setting('auth_allow_registration')),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    /**
     * Field-level validation for a registration submission.
     *
     * Returns an error key, or null when the fields are acceptable. The password
     * is checked separately by {@see validatePasswordPolicy()}, which the reset
     * flow also uses — one policy, one place.
     *
     * The username character set is restricted rather than merely non-empty: a
     * username reaches URLs, log lines and email subjects, and the set below is
     * the one that needs no escaping in any of them.
     *
     * @return string|null Error key for the view, or null when valid
     */
    protected function validateRegistration(string $username, string $email): ?string
    {
        if ($username === '') {
            return 'username_required';
        }
        if (mb_strlen($username) < 3 || mb_strlen($username) > 60) {
            return 'username_length';
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            return 'username_invalid';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid_email';
        }
        return null;
    }

    /** Whether a username is already taken (seam so tests avoid the database). */
    protected function usernameExists(string $username): bool
    {
        return \Pramnos\User\User::getuserid($username, 'username') !== false;
    }

    /**
     * Create the account and return its id, or null when it could not be saved.
     *
     * Two saves, and the order matters: the first assigns the userid, and
     * `setPassword()` salts the hash with that id — the same salt
     * `DatabaseAuthDriver` verifies against. Hashing before the id exists stores
     * a hash no login can ever match.
     */
    protected function createUser(string $username, string $email, string $password): ?int
    {
        try {
            $user            = new \Pramnos\User\User();
            $user->username  = $username;
            $user->email     = $email;
            $user->active    = 1;
            $user->validated = 1;
            $user->regdate   = time();
            $user->save();

            if ((int) $user->userid <= 1) {
                return null;
            }

            $user->setPassword($password);
            $user->save();

            return (int) $user->userid;
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::log('Registration failed: ' . $ex->getMessage());
            return null;
        }
    }

    /**
     * Render the registration form. $ctx carries optional 'error' and 'formData'.
     */
    protected function renderRegister(array $ctx): mixed
    {
        $doc        = $this->document();
        $doc->title = t('Create account');
        $this->useStandaloneLayout();

        $view                 = $this->getView('register');
        $view->routeBase      = $this->routeBase;
        $view->brand          = $this->brand();
        $view->header         = 'Create Account';
        $view->formData       = [];
        $view->registrationOpen = $this->registrationIsOpen();
        foreach ($ctx as $key => $value) {
            $view->$key = $value;
        }
        return $view->display('register');
    }

    /**
     * Render the reset-password form. $ctx carries 'token' and optional 'error'.
     */
    protected function renderReset(array $ctx): mixed
    {
        $doc        = $this->document();
        $doc->title = t('Reset password');
        $this->useStandaloneLayout();

        $view            = $this->getView('login');
        $view->routeBase = $this->routeBase;
        $view->brand     = $this->brand();
        foreach ($ctx as $key => $value) {
            $view->$key = $value;
        }
        return $view->display('resetpassword');
    }

    // ── Display ───────────────────────────────────────────────────────────────

    /**
     * Dashboard overview — authorized applications + recent activity summary.
     */
    public function display()
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === null || !isset($currentUser->userid)) {
            $this->redirect(sURL . 'login');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Account Dashboard');

        $view = $this->getView('dashboard');

        $view->routeBase         = $this->routeBase;
        $view->user              = $currentUser;
        $view->authorizedApps    = $this->getAuthorizedApplications((int) $currentUser->userid);
        $view->recentActivity    = $this->getActivityLog((int) $currentUser->userid, 5);
        $view->twoFactorEnabled  = $this->isTwoFactorEnabled((int) $currentUser->userid);

        return $view->display();
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    /**
     * User profile — view and edit display name, email, phone.
     * GET: render edit form. POST: validate input, save, redirect.
     */
    public function profile()
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === null || !isset($currentUser->userid)) {
            $this->redirect(sURL . 'login');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $session = \Pramnos\Http\Session::getInstance();
            if (!$session->checkToken('post')) {
                $this->addError('Your session expired. Please try again.');
                $this->redirect(sURL . $this->routeBase . '/profile');
                return;
            }

            $firstname = trim((string) ($_POST['firstname'] ?? ''));
            $lastname  = trim((string) ($_POST['lastname']  ?? ''));
            $email     = trim((string) ($_POST['email']     ?? ''));
            $phone     = trim((string) ($_POST['phone']     ?? ''));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->addError('Please enter a valid email address.');
                $this->redirect(sURL . $this->routeBase . '/profile');
                return;
            }

            $currentUser->firstname = $firstname;
            $currentUser->lastname  = $lastname;
            $currentUser->email     = $email;
            $currentUser->phone     = $phone;
            $currentUser->save();

            $this->addMessage('Your profile has been updated.');
            $this->redirect(sURL . $this->routeBase . '/profile');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('My Profile');

        $view            = $this->getView('profile');
        $view->routeBase = $this->routeBase;
        $view->user      = $currentUser;

        return $view->display('profile');
    }

    // ── Authorized applications ───────────────────────────────────────────────

    /**
     * List all applications that have active OAuth2 tokens for the current user.
     */
    public function applications()
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        $view        = $this->getView('OAuth2');

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Authorized Applications');

        $view->routeBase      = $this->routeBase;
        $view->authorizedApps = $this->getAuthorizedApplications((int) $currentUser->userid);

        return $view->display('authorized_applications');
    }

    /**
     * Revoke all active tokens for one application.
     * Supports both AJAX (returns JSON) and standard form submission (redirect).
     */
    public function revokeapplication(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        $clientId    = (string) ($_POST['client_id'] ?? '');
        $isAjax      = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

        if ($isAjax) {
            header('Access-Control-Allow-Origin: *');
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                header('Access-Control-Allow-Methods: POST, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Authorization');
                exit(0);
            }
            header('Content-Type: application/json');
        }

        if ($clientId === '') {
            $this->sendRevokeResponse($isAjax, false, 'client_id is required');
            return;
        }

        try {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()
                ->table('#PREFIX#applications')
                ->select(['appid', 'name'])
                ->where('apikey', $clientId)
                ->where('status', 1)
                ->first();

            if (!$result || $result->numRows == 0) {
                $this->sendRevokeResponse($isAjax, false, 'Application not found');
                return;
            }

            $appId   = (int)    $result->fields['appid'];
            $appName = (string) $result->fields['name'];

            // Revoke tokens (status 3 = revoked, kept for audit trail)
            $db->queryBuilder()
                ->table('#PREFIX#usertokens')
                ->where('userid', $currentUser->userid)
                ->where('applicationid', $appId)
                ->where('status', 1)
                ->update(['status' => 3, 'removedate' => time()]);

            // Remove consent record if present
            $db->queryBuilder()
                ->table('authserver.oauth2_user_consents')
                ->where('userid', $currentUser->userid)
                ->where('applicationid', $appId)
                ->delete();

            \Pramnos\Auth\ActivityLog::record((int) $currentUser->userid, 'application_revoked', [
                'application_id'   => $appId,
                'application_name' => $appName,
            ]);

            $this->sendRevokeResponse($isAjax, true, "Access revoked for {$appName}");

        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log('Error revoking application access: ' . $ex->getMessage());
            $this->sendRevokeResponse($isAjax, false, 'Failed to revoke access');
        }

        if (!$isAjax) {
            $this->redirect(sURL . $this->routeBase . '/applications');
        }
    }

    // ── GDPR — data export ────────────────────────────────────────────────────

    /**
     * Export all personal data for the current user as a JSON download.
     * GDPR Article 20 — right to data portability.
     */
    /**
     * Export all personal data (GDPR Article 20).
     * GET  : confirmation page (what will be exported + a download button).
     * POST : build the full export and stream it as a JSON download.
     */
    public function exportdata()
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!\Pramnos\Http\Session::getInstance()->checkToken('post')) {
                $this->addError('Your session expired. Please try again.');
                $this->redirect(sURL . $this->routeBase . '/exportdata');
                return null;
            }

            try {
                $data = $this->buildExportData((int) $currentUser->userid);
                \Pramnos\Auth\ActivityLog::record((int) $currentUser->userid, 'data_export_requested');

                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="user_data_export_' . date('Y-m-d') . '.json"');
                header('Cache-Control: no-cache, must-revalidate');

                echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $this->terminate();
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log('Error exporting user data: ' . $ex->getMessage());
                $this->addError('An error occurred while exporting your data. Please try again.');
                $this->redirect(sURL . $this->routeBase . '/exportdata');
            }
            return null;
        }

        // GET — confirmation page.
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Export My Data');

        $view                 = $this->getView('OAuth2');
        $view->routeBase       = $this->routeBase;
        // NB: NOT 'sections' — that name is reserved by View's layout system.
        $view->exportSections  = $this->exportSectionLabels();

        return $view->display('export_data');
    }

    // ── GDPR — account deletion ───────────────────────────────────────────────

    /**
     * Delete account (GDPR Article 17 — right to erasure).
     * GET: show confirmation form.
     * POST: verify password + "DELETE" confirmation, then delete all user data.
     */
    public function deleteaccount()
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!\Pramnos\Http\Session::getInstance()->checkToken('post')) {
                $this->addError('Your session expired. Please try again.');
                $this->redirect(sURL . $this->routeBase . '/deleteaccount');
                return;
            }

            $password     = (string) ($_POST['password']     ?? '');
            $confirmation = (string) ($_POST['confirmation'] ?? '');

            if (!$this->verifyUserPassword((int) $currentUser->userid, $password)) {
                $this->addError('The password you entered is incorrect.');
                $this->redirect(sURL . $this->routeBase . '/deleteaccount');
                return;
            }

            if ($confirmation !== 'DELETE') {
                $this->addError('You must type DELETE in the confirmation field.');
                $this->redirect(sURL . $this->routeBase . '/deleteaccount');
                return;
            }

            try {
                // Durable deletion audit: the activity_log rows for this user are
                // hard-deleted by eraseUserData() in the same flow, so record the
                // deletion in the framework log (which the erase never touches)
                // BEFORE erasing. Keeps a who/when trail without a new table.
                \Pramnos\Logs\Logger::log(
                    'account_deleted: userid=' . (int) $currentUser->userid
                        . ' ip=' . \Pramnos\Http\Request::clientIp('unknown'),
                    'auth'
                );
                $this->eraseUserData((int) $currentUser->userid);

                $auth = \Pramnos\Framework\Factory::getAuth();
                $auth->logout();

                $this->addMessage('Your account has been deleted.');
                $this->redirect(sURL);

            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log('Error deleting account: ' . $ex->getMessage());
                $this->addError('An error occurred while deleting your account. Please try again.');
                $this->redirect(sURL . $this->routeBase . '/deleteaccount');
            }
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Delete Account');

        $view = $this->getView('OAuth2');
        $view->routeBase = $this->routeBase;
        return $view->display('delete_account');
    }

    // ── Privacy settings ──────────────────────────────────────────────────────

    /**
     * Privacy / consent settings management.
     * GET: show current settings.
     * POST: save updated settings.
     */
    public function privacy()
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $db = \Pramnos\Framework\Factory::getDatabase();
            $qb = $db->queryBuilder();
            $qb->table('authserver.user_privacy_settings')
               ->upsert(
                   [
                       'userid'                => (int) $currentUser->userid,
                       'share_usage_analytics' => isset($_POST['analytics']) ? 1 : 0,
                       'marketing_emails'      => isset($_POST['marketing']) ? 1 : 0,
                       'updated_at'            => $qb->raw('NOW()'),
                   ],
                   ['userid'],
                   ['share_usage_analytics', 'marketing_emails', 'updated_at']
               );

            \Pramnos\Auth\NewSignInAlert::setEnabledFor(
                (int) $currentUser->userid,
                isset($_POST['notifysignin'])
            );

            \Pramnos\Auth\ActivityLog::record((int) $currentUser->userid, 'privacy_settings_updated', [
                'analytics'    => isset($_POST['analytics']),
                'marketing'    => isset($_POST['marketing']),
                'notifysignin' => isset($_POST['notifysignin']),
            ]);

            $this->addMessage('Your privacy settings have been saved.');
            $this->redirect(sURL . $this->routeBase . '/privacy');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Privacy Settings');

        $view                   = $this->getView('OAuth2');
        $view->routeBase        = $this->routeBase;
        $view->privacySettings  = $this->getPrivacySettings((int) $currentUser->userid);

        return $view->display('privacy_settings');
    }

    // ── Security overview ─────────────────────────────────────────────────────

    /**
     * Security overview — recent logins, active sessions, 2FA status.
     */
    public function security()
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        $view        = $this->getView('OAuth2');

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Security Overview');

        $emailFactor = new \Pramnos\Auth\EmailSecondFactor();

        $view->routeBase        = $this->routeBase;
        $view->recentActivity   = $this->getActivityLog((int) $currentUser->userid, 20);
        $view->twoFactorEnabled = $this->isTwoFactorEnabled((int) $currentUser->userid);
        $view->activeSessions   = $this->getActiveSessions((int) $currentUser->userid);
        $view->currentSid       = md5(session_id());
        // Offered only where the application allows the method: a switch for something
        // that cannot happen is worse than no switch.
        $view->emailFactorOffered = \Pramnos\Auth\EmailSecondFactor::isAvailable();
        $view->emailFactorEnabled = $emailFactor->isEnabledFor((int) $currentUser->userid);
        // Mid-enrolment: a code has been mailed and is still live, so the card asks for it
        // instead of offering to send another. Without this the screen would look
        // identical before and after the mail went out.
        $view->emailFactorPending = $view->emailFactorOffered
            && !$view->emailFactorEnabled
            && $emailFactor->hasLiveCode(
                (int) $currentUser->userid,
                \Pramnos\Auth\EmailSecondFactor::PURPOSE_ENROL
            );

        return $view->display('security');
    }

    /**
     * Take or drop the email second factor, for the signed-in account.
     *
     * **Enrolling is verified by email, not by password**, and the difference matters. A
     * password proves who is asking; it does not prove that the address on the account is
     * one the person can still read. Attaching a factor to a stale or mistyped address
     * would build a lockout on purpose — the account would then be asked, at every sign-in
     * from a new device, for a code arriving somewhere nobody reads. So the flow is the one
     * the authenticator app already uses: prove you hold the channel, *then* it becomes a
     * factor.
     *
     * Two steps, both POSTs to here:
     *
     *   1. no code yet → mail one (purpose `enrol`, so it cannot complete a login) and
     *      come back to the screen, which now asks for it;
     *   2. a code → verify it and switch the factor on.
     *
     * **Dropping it asks for the password instead**, and that asymmetry is not an
     * oversight. Removing a factor is the direction an attacker wants, so it needs proof of
     * *identity*; and requiring a mailed code to switch it off would strand exactly the
     * person whose mailbox has become unreachable — the one case where turning it off is
     * urgent.
     */
    public function emailfactor(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === null || (int) $currentUser->userid < 2) {
            $this->redirect(sURL . 'login');

            return;
        }

        $back = sURL . $this->routeBase . '/security';

        if ($this->requestMethod() !== 'POST' || !$this->checkCsrf()) {
            $this->redirect($back);

            return;
        }

        if (!\Pramnos\Auth\EmailSecondFactor::isAvailable()) {
            $_SESSION['account_error'] = t('That sign-in method is not available here.');
            $this->redirect($back);

            return;
        }

        $userId = (int) $currentUser->userid;
        $factor = new \Pramnos\Auth\EmailSecondFactor();
        $enrol  = \Pramnos\Auth\EmailSecondFactor::PURPOSE_ENROL;

        // ── Dropping it: identity, not channel ────────────────────────────────
        if ($this->post('enable') === '0') {
            if (!$currentUser->verifyPassword((string) $this->post('password'))) {
                $_SESSION['account_error'] = t('That password is not correct.');
                $this->redirect($back);

                return;
            }

            // Called once and remembered: reading the result twice would write twice.
            $done = $factor->setEnabledFor($userId, false);
            $_SESSION[$done ? 'account_success' : 'account_error'] = $done
                ? t('Sign-in codes by email are turned off.')
                : t('That could not be changed.');
            $this->redirect($back);

            return;
        }

        // ── Enrolling, step 2: the code came back ─────────────────────────────
        $code = $this->post('code');
        if ($code !== '') {
            if (!$factor->verify($userId, $code, $enrol)) {
                $_SESSION['account_error'] = t('That code is wrong or has expired. Ask for another one.');
                $this->redirect($back);

                return;
            }

            $done = $factor->setEnabledFor($userId, true);
            $_SESSION[$done ? 'account_success' : 'account_error'] = $done
                ? t('You will be asked for a code by email when you sign in.')
                : t('That could not be changed.');
            $this->redirect($back);

            return;
        }

        // ── Enrolling, step 1: prove the mailbox ──────────────────────────────
        if ($factor->send($userId, $enrol)) {
            $_SESSION['account_success'] = t('We have emailed you a code. Enter it below to finish.');
        } else {
            $_SESSION['account_error'] = t('We could not email you a code. Check the address on your profile.');
        }

        $this->redirect($back);
    }

    /**
     * Sign out one of the current user's other sessions/devices (POST only).
     *
     * Sets `sessions.logout = 1` for the target sid, scoped to the current user
     * so nobody can revoke another account's session. The session-tracking layer
     * force-logs-out that device on its next request (it clears the session when
     * it sees logout=1 for the visitor).
     */
    public function revokesession(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || !\Pramnos\Http\Session::getInstance()->checkToken('post')) {
            $this->redirect(sURL . $this->routeBase . '/security');
            return;
        }

        $sid = (string) ($_POST['sid'] ?? '');
        if ($sid !== '') {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#sessions')
                ->where('userid', (int) $currentUser->userid)
                ->where('sid', $sid)
                ->update(['logout' => 1]);
            \Pramnos\Auth\ActivityLog::record((int) $currentUser->userid, 'session_revoked');
            $this->addMessage('The selected session has been signed out.');
        }

        $this->redirect(sURL . $this->routeBase . '/security');
    }

    // ── Change password ───────────────────────────────────────────────────────

    /**
     * Change password.
     * GET: show form.
     * POST: verify current password, enforce policy, update.
     *
     * Password policy: ≥ 8 chars, at least one digit, at least one non-alphanumeric.
     */
    public function changepassword()
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $session = \Pramnos\Http\Session::getInstance();
            if (!$session->checkToken('post')) {
                $this->addError('Your session expired. Please try again.');
                $this->redirect(sURL . $this->routeBase . '/changepassword');
                return;
            }

            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword     = (string) ($_POST['new_password']     ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if (!$this->verifyUserPassword((int) $currentUser->userid, $currentPassword)) {
                $this->addError($this->passwordErrorMessage('wrong_password'));
                $this->redirect(sURL . $this->routeBase . '/changepassword');
                return;
            }

            $policyError = $this->validatePasswordPolicy($newPassword, $confirmPassword);
            if ($policyError !== null) {
                $this->addError($this->passwordErrorMessage($policyError));
                $this->redirect(sURL . $this->routeBase . '/changepassword');
                return;
            }

            $this->updatePassword((int) $currentUser->userid, $newPassword);
            \Pramnos\Auth\ActivityLog::record(
                (int) $currentUser->userid,
                'password_changed'
            );

            /**
             * End the other sessions, when the application asked for it.
             *
             * People change a password *because* they think somebody else has it. Leaving
             * the other sessions alive means the other person keeps the account while the
             * owner believes they have just taken it back — which is worse than not
             * offering the change, because it manufactures false confidence.
             *
             * The current session is spared: being signed out by your own password change
             * reads as a failure, and the person then cannot tell whether it worked.
             *
             * Opt-in, like the rest of `auth.security`: for an application that treats a
             * password change as routine hygiene rather than as a response to compromise,
             * signing every device out is a support call.
             */
            $endedOthers = 0;
            if (\Pramnos\Auth\SecurityPolicy::revokesSessionsOnPasswordChange()) {
                $endedOthers = (new \Pramnos\User\User((int) $currentUser->userid))
                    ->revokeOtherSessions(md5(session_id()));

                if ($endedOthers > 0) {
                    \Pramnos\Auth\ActivityLog::record(
                        (int) $currentUser->userid,
                        'sessions_revoked_on_password_change',
                        ['count' => $endedOthers]
                    );
                }
            }

            $this->addMessage($endedOthers > 0
                ? 'Your password has been updated, and your other sessions have been signed out.'
                : 'Your password has been updated successfully.');
            $this->redirect(sURL . $this->routeBase . '/security');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Change Password');

        $view = $this->getView('OAuth2');
        $view->routeBase = $this->routeBase;
        return $view->display('change_password');
    }

    // ── Private — DB helpers ──────────────────────────────────────────────────

    /**
     * Return authorized applications (grouped by app) for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAuthorizedApplications(int $userId): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('usertokens ut')
            ->join('applications a', 'ut.applicationid', '=', 'a.appid')
            ->select([
                'a.appid', 'a.name', 'a.apikey', 'a.description',
                // The SSO status page links each application, and the view was
                // documented as receiving `website_url` while the query never
                // selected it — so every entry rendered without its link.
                'a.url AS website_url',
                'MAX(ut.lastused) AS last_used',
                'COUNT(ut.tokenid) AS token_count',
            ])
            ->distinct()
            ->where('ut.userid', $userId)
            ->where('ut.status', 1)
            ->where(function ($q) {
                $q->where('ut.expires', 0)->orWhere('ut.expires', '>', time());
            })
            ->groupBy(['a.appid', 'a.name', 'a.apikey', 'a.description', 'a.url'])
            ->get();

        $apps = [];
        if ($result) {
            while ($result->fetch()) {
                $apps[] = (array) $result->fields;
            }
        }

        return $apps;
    }

    /**
     * Return the N most recent activity log entries for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getActivityLog(int $userId, int $limit = 10): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('authserver.user_activity_log')
            ->select(['action', 'created_at', 'ip_address', 'user_agent'])
            ->where('userid', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $log = [];
        if ($result) {
            while ($result->fetch()) {
                $log[] = (array) $result->fields;
            }
        }

        return $log;
    }

    /**
     * Return the user's currently-active sessions (devices).
     *
     * Reads the `sessions` table (public schema — populated by the built-in
     * session tracking): non-guest, non-revoked rows for this user, newest
     * first. Each row carries the sid used to revoke it and the agent/IP/time
     * for display.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getActiveSessions(int $userId): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->select(['sid', 'host_addr', 'agent', 'time', 'url'])
            ->where('userid', $userId)
            ->where('guest', 0)
            ->where('logout', 0)
            ->orderBy('time', 'desc')
            ->get();

        $rows = [];
        if ($result) {
            while ($result->fetch()) {
                $rows[] = (array) $result->fields;
            }
        }

        return $rows;
    }

    /**
     * Check whether 2FA is currently enabled for a user.
     */
    protected function isTwoFactorEnabled(int $userId): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('authserver.user_twofactor')
            ->select(['enabled'])
            ->where('userid', $userId)
            ->first();

        return $result && $result->numRows > 0 && (int) ($result->fields['enabled'] ?? 0) === 1;
    }

    /**
     * Build the GDPR data export payload for a user.
     *
     * @return array<string, mixed>
     */
    protected function buildExportData(int $userId): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#users')
            ->where('userid', $userId)
            ->first();

        $userData = $result ? (array) $result->fields : [];

        // Remove sensitive fields
        unset($userData['password'], $userData['salt']);

        $export = [
            'export_date'      => date('c'),
            'userid'           => $userId,
            'profile'          => $userData,
            'authorized_apps'  => $this->getAuthorizedApplications($userId),
            'oauth_consents'   => $this->exportOauthConsents($userId),
            'passkeys'         => $this->exportPasskeys($userId),
            'two_factor'       => $this->exportTwoFactorStatus($userId),
            'active_sessions'  => $this->getActiveSessions($userId),
            'tokens'           => $this->exportTokens($userId),
            'token_actions'    => $this->exportTokenActions($userId),
            'account_details'  => $this->exportUserDetails($userId),
            'privacy_settings' => $this->getPrivacySettings($userId),
            'activity_log'     => $this->getActivityLog($userId, 1000),
        ];

        // Extensibility: applications built on the framework contribute their
        // own sections by listening for 'account.data_export' and returning an
        // associative array of [section => data]. Core keys are protected from
        // being overwritten.
        foreach (\Pramnos\Event\Event::fire('account.data_export', $userId) as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            foreach ($extra as $section => $rows) {
                if (!array_key_exists($section, $export)) {
                    $export[$section] = $rows;
                }
            }
        }

        return $export;
    }

    /**
     * Human-readable labels of the sections the export will contain — shown on
     * the confirmation page. Includes a note when applications have registered
     * extra sections via the 'account.data_export' event.
     *
     * @return array<int, string>
     */
    protected function exportSectionLabels(): array
    {
        $labels = [
            'Profile information',
            'Authorized applications',
            'OAuth consents',
            'Passkeys (metadata only)',
            'Two-factor status',
            'Active sessions / devices',
            'Access tokens (metadata)',
            'Token activity',
            'Account details',
            'Privacy settings',
            'Activity log',
        ];
        if (\Pramnos\Event\Event::hasListeners('account.data_export')) {
            $labels[] = 'Application-specific data';
        }
        return $labels;
    }

    /**
     * OAuth2 consents granted by the user. Guarded — returns [] if unavailable.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function exportOauthConsents(int $userId): array
    {
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('authserver.oauth2_user_consents')
                ->select(['applicationid', 'scope', 'created_at', 'updated_at'])
                ->where('userid', $userId)
                ->get();
            $rows = [];
            if ($result) {
                while ($result->fetch()) {
                    $rows[] = (array) $result->fields;
                }
            }
            return $rows;
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * Passkey metadata (never the public key / credential id / counters that
     * would aid impersonation). Guarded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function exportPasskeys(int $userId): array
    {
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('authserver.passkey_credentials')
                ->select(['name', 'transports', 'is_active', 'created_at', 'last_used_at'])
                ->where('userid', $userId)
                ->get();
            $rows = [];
            if ($result) {
                while ($result->fetch()) {
                    $rows[] = (array) $result->fields;
                }
            }
            return $rows;
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * Two-factor status only — never the shared secret or backup codes. Guarded.
     *
     * @return array<string, mixed>
     */
    protected function exportTwoFactorStatus(int $userId): array
    {
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('authserver.user_twofactor')
                ->select(['enabled', 'setup_completed_at', 'created_at'])
                ->where('userid', $userId)
                ->first();
            return ($result && $result->numRows > 0) ? (array) $result->fields : ['enabled' => 0];
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * Extra per-user key/value details (userdetails EAV), excluding security
     * tokens (password-reset hash/expiry). Guarded.
     *
     * @return array<string, mixed>
     */
    protected function exportUserDetails(int $userId): array
    {
        $deny = ['password_reset_hash', 'password_reset_expires'];
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#userdetails')
                ->select(['fieldname', 'value'])
                ->where('userid', $userId)
                ->get();
            $rows = [];
            if ($result) {
                while ($result->fetch()) {
                    $field = (string) ($result->fields['fieldname'] ?? '');
                    if ($field === '' || in_array($field, $deny, true)) {
                        continue;
                    }
                    $rows[$field] = $result->fields['value'] ?? null;
                }
            }
            return $rows;
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * The user's issued tokens (usertokens) — metadata only. Never the token
     * value itself or the PKCE challenge (both secrets). Guarded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function exportTokens(int $userId): array
    {
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#usertokens')
                ->select([
                    'tokentype', 'notes', 'created', 'lastused', 'status',
                    'applicationid', 'actions', 'expires', 'ipaddress',
                    'deviceinfo', 'removedate',
                ])
                ->where('userid', $userId)
                ->orderBy('created', 'desc')
                ->get();
            $rows = [];
            if ($result) {
                while ($result->fetch()) {
                    $rows[] = (array) $result->fields;
                }
            }
            return $rows;
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * Per-token activity (tokenactions) across the user's tokens, joined to the
     * URL registry. The `params` column is intentionally excluded — it stores
     * raw request bodies that can contain submitted secrets. Capped to keep the
     * in-memory JSON export bounded. Guarded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function exportTokenActions(int $userId, int $limit = 5000): array
    {
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('tokenactions ta')
                ->join('usertokens ut', 'ta.tokenid', '=', 'ut.tokenid')
                ->leftJoin('urls u', 'ta.urlid', '=', 'u.urlid')
                ->select([
                    'ta.tokenid', 'u.url', 'ta.method',
                    'ta.servertime', 'ta.return_status', 'ta.action_time',
                ])
                ->where('ut.userid', $userId)
                ->orderBy('ta.servertime', 'desc')
                ->limit($limit)
                ->get();
            $rows = [];
            if ($result) {
                while ($result->fetch()) {
                    $rows[] = (array) $result->fields;
                }
            }
            return $rows;
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * Delete all personal data rows for a user across all relevant tables.
     * The users row itself is deleted last.
     */
    protected function eraseUserData(int $userId): void
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $tables = [
            'usertokens'            => 'userid',
            'authserver.oauth2_user_consents' => 'userid',
            'authserver.user_activity_log'     => 'userid',
            'authserver.user_privacy_settings' => 'userid',
            'authserver.user_twofactor'        => 'userid',
            'authserver.twofactor_setup'       => 'userid',
        ];

        foreach ($tables as $table => $col) {
            $db->queryBuilder()
                ->table($table)
                ->where($col, $userId)
                ->delete();
        }

        $db->queryBuilder()
            ->table('#PREFIX#users')
            ->where('userid', $userId)
            ->delete();
    }

    /**
     * Return privacy settings for a user, or defaults if not set.
     *
     * @return array<string, mixed>
     */
    protected function getPrivacySettings(int $userId): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('authserver.user_privacy_settings')
            ->select(['share_usage_analytics', 'marketing_emails'])
            ->where('userid', $userId)
            ->first();

        // The new-sign-in opt-in lives in `userdetails`, not in this table, and the
        // reason is worth the extra read: it needs no migration, so it works on every
        // installation the moment the framework is upgraded — including the ones whose
        // migration_cutoff skips baseline migrations. Two stores, one form.
        $notifySignIn = \Pramnos\Auth\NewSignInAlert::isEnabledFor($userId);

        if ($result && $result->numRows > 0) {
            return [
                'analytics'     => (bool) ($result->fields['share_usage_analytics'] ?? false),
                'marketing'     => (bool) ($result->fields['marketing_emails'] ?? false),
                'notifysignin'  => $notifySignIn,
            ];
        }

        return ['analytics' => false, 'marketing' => false, 'notifysignin' => $notifySignIn];
    }

    /**
     * Verify the user's password against the stored hash.
     */
    protected function verifyUserPassword(int $userId, string $password): bool
    {
        // Load through the User model — the single source of truth. Every
        // status mutation (activate/deactivate/save/deleteuser) flushes the
        // 'userlist' cache and the in-process instance cache, so the loaded
        // row (active flag included) reflects the current database state.
        $user = new \Pramnos\User\User($userId);
        if ((int) $user->userid < 2) {
            return false;
        }

        // Only an active account may confirm its password (inactive/banned/
        // deleted must not verify). 1 or 't' (PostgreSQL boolean) both mean
        // active.
        $active = $user->active;
        if ($active != 1 && $active !== 't' && $active !== true) {
            return false;
        }

        // verifyPassword() applies the same md5(securitySalt . userid) salt
        // DatabaseAuthDriver uses — a bare password_verify() here would reject
        // every correct password.
        return $user->verifyPassword($password);
    }

    /**
     * Map a password error key to a human-readable message.
     *
     * Shared by the change-password flow so server-side rejections surface as
     * flash errors (addError) instead of query-string keys the view must decode.
     */
    protected function passwordErrorMessage(string $key): string
    {
        return [
            'wrong_password'         => 'The current password you entered is incorrect.',
            'password_required'      => 'New password is required.',
            'password_too_short'     => 'New password must be at least 8 characters.',
            'password_needs_digit'   => 'New password must contain at least one digit.',
            'password_needs_symbol'  => 'New password must contain at least one special character.',
            'passwords_do_not_match' => 'New passwords do not match.',
        ][$key] ?? 'Could not change your password. Please try again.';
    }

    /**
     * Validate the new password against the policy.
     * Returns an error key string on failure, null on success.
     */
    protected function validatePasswordPolicy(string $newPassword, string $confirmPassword): ?string
    {
        if ($newPassword === '') {
            return 'password_required';
        }
        if (strlen($newPassword) < 8) {
            return 'password_too_short';
        }
        if (!preg_match('/\d/', $newPassword)) {
            return 'password_needs_digit';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            return 'password_needs_symbol';
        }
        if ($newPassword !== $confirmPassword) {
            return 'passwords_do_not_match';
        }
        return null;
    }

    /**
     * Update the stored password hash for a user.
     */
    protected function updatePassword(int $userId, string $newPassword): void
    {
        // Use the User model's setPassword(), which salts with
        // md5(securitySalt . userid) exactly as DatabaseAuthDriver verifies —
        // a raw password_hash() here would store a hash login could never match.
        $user = new \Pramnos\User\User($userId);
        if ((int) $user->userid > 1) {
            $user->setPassword($newPassword);
            $user->save();
        }
    }

    // ── Private — response helpers ────────────────────────────────────────────

    /**
     * Send the revokeapplication response as JSON or redirect.
     */
    protected function sendRevokeResponse(bool $isAjax, bool $success, string $message): void
    {
        if ($isAjax) {
            echo json_encode(['success' => $success, 'message' => $message]);
            $this->terminate();
            return;
        }

        // Non-AJAX: surface the outcome as a flash message on the applications page.
        if ($success) {
            $this->addMessage($message);
        } else {
            $this->addError($message);
        }
    }

    /**
     * Terminate the request. Can be mocked in tests.
     */
    protected function terminate(): void
    {
        exit;
    }
}
