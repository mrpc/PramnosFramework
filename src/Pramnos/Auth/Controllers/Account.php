<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\LoginFlowResult;

/**
 * General account controller — the single built-in surface a scaffolded auth
 * server exposes for a user's whole account lifecycle.
 *
 * It spans two concerns that share one controller but differ in authentication:
 *
 *   Public (no session) — the authentication entry flow, driven by {@see LoginFlow}:
 *     - login          — show the login form / process credentials (password leg)
 *     - verify         — complete a pending 2FA (TOTP/backup) step-up
 *     - logout         — tear the session down
 *   (A passkey second-factor step-up rides the same pending state via
 *    {@see LoginFlow::completePasskey()}; its browser ceremony is wired in a
 *    later phase alongside the WebAuthn front-end.)
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
 *
 * The legacy {@see Dashboard} name remains as a thin subclass for backward
 * compatibility.
 */
class Account extends Controller
{
    /**
     * Base route used in internal redirects (e.g. after form submission).
     * Override in subclasses when the controller is exposed under a different URL.
     * Example: class Dashboard extends Account { protected string $routeBase = 'Dashboard'; }
     */
    protected string $routeBase = 'Account';

    /** The login orchestrator (seam so tests can inject a double). */
    private ?LoginFlow $loginFlow = null;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Public authentication-entry actions.
        $this->addaction(['login', 'verify', 'logout']);
        // Authenticated account-management actions.
        $this->addAuthAction([
            'applications', 'revokeapplication',
            'exportdata', 'deleteaccount',
            'privacy', 'security', 'changepassword',
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

        if (!$this->checkCsrf()) {
            return $this->renderLogin(['error' => 'invalid_token']);
        }

        $username = $this->post('username');
        $password = $this->post('password', false); // never trim a password
        $remember = $this->post('remember') !== '';

        if ($username === '' || $password === '') {
            return $this->renderLogin(['error' => 'missing_credentials']);
        }

        return $this->presentResult($this->flow()->attempt($username, $password, $remember));
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

        $code = $this->post('code');
        if ($code === '') {
            return $this->renderStepUp(['error' => 'missing_code']);
        }

        $result = $this->flow()->completeTwoFactor($code);
        if ($result->isSuccess()) {
            $this->redirect($this->postLoginTarget($this->returnUrl()));
            return null;
        }

        // Pending state is kept by LoginFlow so the user can retry.
        return $this->renderStepUp(['error' => 'invalid_code']);
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

    // ── Authentication seams (overridable / mockable) ───────────────────────────

    /** The login orchestrator (lazy default; injectable for tests). */
    protected function flow(): LoginFlow
    {
        return $this->loginFlow ??= new LoginFlow();
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

    /** The document object (seam so tests can supply a stub). */
    protected function document(): object
    {
        return \Pramnos\Framework\Factory::getDocument();
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
        $doc->title = 'Login';

        $view            = $this->getView('account');
        $view->routeBase = $this->routeBase;
        $view->returnUrl = $this->returnUrl();
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
    protected function renderStepUp(array $ctx): mixed
    {
        $doc        = $this->document();
        $doc->title = 'Two-step verification';

        $view                = $this->getView('account');
        $view->routeBase     = $this->routeBase;
        $view->returnUrl     = $this->returnUrl();
        $view->pendingUserId = $this->flow()->pendingUserId();
        foreach ($ctx as $key => $value) {
            $view->$key = $value;
        }
        return $view->display('login_2fa');
    }

    /** Branch on a LoginFlow result into the right response. */
    protected function presentResult(LoginFlowResult $result): mixed
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
            ]);
        }
        return $this->renderLogin(['error' => 'invalid_credentials']);
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
        $doc->title = 'Account Dashboard';

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
                $this->redirect(sURL . $this->routeBase . '/profile?error=invalid_token');
                return;
            }

            $firstname = trim((string) ($_POST['firstname'] ?? ''));
            $lastname  = trim((string) ($_POST['lastname']  ?? ''));
            $email     = trim((string) ($_POST['email']     ?? ''));
            $phone     = trim((string) ($_POST['phone']     ?? ''));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->redirect(sURL . $this->routeBase . '/profile?error=invalid_email');
                return;
            }

            $currentUser->firstname = $firstname;
            $currentUser->lastname  = $lastname;
            $currentUser->email     = $email;
            $currentUser->phone     = $phone;
            $currentUser->save();

            $this->redirect(sURL . $this->routeBase . '/profile?message=profile_saved');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'My Profile';

        $view            = $this->getView('account');
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
        $doc->title = 'Authorized Applications';

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
                ->table('applications')
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
                ->table('usertokens')
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
    public function exportdata(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        try {
            $data = $this->buildExportData((int) $currentUser->userid);

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="user_data_export_' . date('Y-m-d') . '.json"');
            header('Cache-Control: no-cache, must-revalidate');

            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->terminate();

        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log('Error exporting user data: ' . $ex->getMessage());
            $this->redirect(sURL . $this->routeBase);
        }
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
            $password     = (string) ($_POST['password']     ?? '');
            $confirmation = (string) ($_POST['confirmation'] ?? '');

            if (!$this->verifyUserPassword((int) $currentUser->userid, $password)) {
                $this->redirect(sURL . $this->routeBase . '/deleteaccount?error=invalid_password');
                return;
            }

            if ($confirmation !== 'DELETE') {
                $this->redirect(sURL . $this->routeBase . '/deleteaccount?error=confirmation_required');
                return;
            }

            try {
                $this->eraseUserData((int) $currentUser->userid);

                $auth = \Pramnos\Framework\Factory::getAuth();
                $auth->logout();

                $this->redirect(sURL . '?message=account_deleted');

            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log('Error deleting account: ' . $ex->getMessage());
                $this->redirect(sURL . $this->routeBase . '/deleteaccount?error=deletion_failed');
            }
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Delete Account';

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

            $this->redirect(sURL . $this->routeBase . '/privacy');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Privacy Settings';

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
        $doc->title = 'Security Overview';

        $view->routeBase        = $this->routeBase;
        $view->recentActivity   = $this->getActivityLog((int) $currentUser->userid, 20);
        $view->twoFactorEnabled = $this->isTwoFactorEnabled((int) $currentUser->userid);

        return $view->display('security');
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
                $this->redirect(sURL . $this->routeBase . '/changepassword');
                return;
            }

            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword     = (string) ($_POST['new_password']     ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if (!$this->verifyUserPassword((int) $currentUser->userid, $currentPassword)) {
                $this->redirect(sURL . $this->routeBase . '/changepassword?error=wrong_password');
                return;
            }

            $policyError = $this->validatePasswordPolicy($newPassword, $confirmPassword);
            if ($policyError !== null) {
                $this->redirect(sURL . $this->routeBase . '/changepassword?error=' . urlencode($policyError));
                return;
            }

            $this->updatePassword((int) $currentUser->userid, $newPassword);
            $this->redirect(sURL . $this->routeBase . '/security?message=password_changed');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Change Password';

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
                'MAX(ut.lastused) AS last_used',
                'COUNT(ut.tokenid) AS token_count',
            ])
            ->distinct()
            ->where('ut.userid', $userId)
            ->where('ut.status', 1)
            ->where(function ($q) {
                $q->where('ut.expires', 0)->orWhere('ut.expires', '>', time());
            })
            ->groupBy(['a.appid', 'a.name', 'a.apikey', 'a.description'])
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
            ->table('user_activity_log')
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
     * Check whether 2FA is currently enabled for a user.
     */
    protected function isTwoFactorEnabled(int $userId): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('user_twofactor')
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
            ->table('users')
            ->where('userid', $userId)
            ->first();

        $userData = $result ? (array) $result->fields : [];

        // Remove sensitive fields
        unset($userData['password'], $userData['salt']);

        return [
            'export_date'      => date('c'),
            'userid'           => $userId,
            'data'             => $userData,
            'authorized_apps'  => $this->getAuthorizedApplications($userId),
            'recent_activity'  => $this->getActivityLog($userId, 1000),
            'privacy_settings' => $this->getPrivacySettings($userId),
        ];
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
            'user_activity_log'     => 'userid',
            'authserver.user_privacy_settings' => 'userid',
            'user_twofactor'        => 'userid',
            'twofactor_setup'       => 'userid',
        ];

        foreach ($tables as $table => $col) {
            $db->queryBuilder()
                ->table($table)
                ->where($col, $userId)
                ->delete();
        }

        $db->queryBuilder()
            ->table('users')
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

        if ($result && $result->numRows > 0) {
            return [
                'analytics' => (bool) ($result->fields['share_usage_analytics'] ?? false),
                'marketing' => (bool) ($result->fields['marketing_emails'] ?? false),
            ];
        }

        return ['analytics' => false, 'marketing' => false];
    }

    /**
     * Verify the user's password against the stored hash.
     */
    protected function verifyUserPassword(int $userId, string $password): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('users')
            ->select(['password'])
            ->where('userid', $userId)
            ->where('active', 1)
            ->first();

        if (!$result || $result->numRows == 0) {
            return false;
        }

        $stored = (string) ($result->fields['password'] ?? '');

        // Bcrypt hashes (default since v1.2); legacy SHA-256 plain fallback
        if (str_starts_with($stored, '$2')) {
            return password_verify($password, $stored);
        }

        return hash('sha256', $password) === $stored;
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
        $db   = \Pramnos\Framework\Factory::getDatabase();
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $db->queryBuilder()
           ->table('users')
           ->where('userid', $userId)
           ->update([
               'password' => $hash,
               'modified' => time(),
           ]);
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

        if (!$success) {
            $this->redirect(sURL . $this->routeBase . '/applications?error=' . urlencode($message));
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
