<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;

/**
 * User account management dashboard controller.
 *
 * Provides authenticated account management actions. All actions require
 * a logged-in session (addAuthAction). HTML views are resolved from the
 * application view path, typically under `dashboard` or `OAuth2`.
 *
 * Supported actions:
 *   - display          — dashboard overview (auth apps + recent activity)
 *   - applications     — list of authorized OAuth2 applications
 *   - revokeapplication — revoke all tokens for one application (AJAX or redirect)
 *   - exportdata       — GDPR data portability (JSON download)
 *   - deleteaccount    — GDPR right to erasure (POST with password + confirmation)
 *   - privacy          — privacy / consent settings
 *   - security         — security overview (logins, sessions, 2FA status)
 *   - changepassword   — change password (POST with current + new password)
 *
 * @package     PramnosFramework
 * @subpackage  Auth\Controllers
 */
class Dashboard extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'applications', 'revokeapplication',
            'exportdata', 'deleteaccount',
            'privacy', 'security', 'changepassword',
        ]);
        parent::__construct($application);
    }

    // ── Display ───────────────────────────────────────────────────────────────

    /**
     * Dashboard overview — authorized applications + recent activity summary.
     */
    public function display(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === null || !isset($currentUser->userid)) {
            $this->redirect(sURL . 'login');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Account Dashboard';

        $view = $this->getView('dashboard');

        $view->user              = $currentUser;
        $view->authorizedApps    = $this->getAuthorizedApplications((int) $currentUser->userid);
        $view->recentActivity    = $this->getActivityLog((int) $currentUser->userid, 5);
        $view->twoFactorEnabled  = $this->isTwoFactorEnabled((int) $currentUser->userid);

        $view->display();
    }

    // ── Authorized applications ───────────────────────────────────────────────

    /**
     * List all applications that have active OAuth2 tokens for the current user.
     */
    public function applications(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        $view        = $this->getView('OAuth2');

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Authorized Applications';

        $view->authorizedApps = $this->getAuthorizedApplications((int) $currentUser->userid);

        $view->display('authorized_applications');
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
            $db  = \Pramnos\Framework\Factory::getDatabase();
            $sql = $db->prepareQuery(
                'SELECT appid, name FROM applications WHERE apikey = %s AND status = 1',
                $clientId
            );
            $result = $db->query($sql);

            if (!$result || $result->numRows == 0) {
                $this->sendRevokeResponse($isAjax, false, 'Application not found');
                return;
            }

            $appId   = (int) $result->fields['appid'];
            $appName = (string) $result->fields['name'];

            // Revoke tokens (status 3 = revoked, kept for audit trail)
            $sql = $db->prepareQuery(
                'UPDATE usertokens SET status = 3, removedate = %d
                  WHERE userid = %d AND applicationid = %d AND status = 1',
                time(),
                $currentUser->userid,
                $appId
            );
            $db->query($sql);

            // Remove consent record if present
            $sql = $db->prepareQuery(
                'DELETE FROM oauth2_user_consents
                  WHERE userid = %d AND applicationid = %d',
                $currentUser->userid,
                $appId
            );
            $db->query($sql);

            $this->sendRevokeResponse($isAjax, true, "Access revoked for {$appName}");

        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log('Error revoking application access: ' . $ex->getMessage());
            $this->sendRevokeResponse($isAjax, false, 'Failed to revoke access');
        }

        if (!$isAjax) {
            $this->redirect(sURL . 'Dashboard/applications');
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
            exit;

        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log('Error exporting user data: ' . $ex->getMessage());
            $this->redirect(sURL . 'Dashboard');
        }
    }

    // ── GDPR — account deletion ───────────────────────────────────────────────

    /**
     * Delete account (GDPR Article 17 — right to erasure).
     * GET: show confirmation form.
     * POST: verify password + "DELETE" confirmation, then delete all user data.
     */
    public function deleteaccount(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $password     = (string) ($_POST['password']     ?? '');
            $confirmation = (string) ($_POST['confirmation'] ?? '');

            if (!$this->verifyUserPassword((int) $currentUser->userid, $password)) {
                $this->redirect(sURL . 'Dashboard/deleteaccount?error=invalid_password');
                return;
            }

            if ($confirmation !== 'DELETE') {
                $this->redirect(sURL . 'Dashboard/deleteaccount?error=confirmation_required');
                return;
            }

            try {
                $this->eraseUserData((int) $currentUser->userid);

                $auth = \Pramnos\Framework\Factory::getAuth();
                $auth->logout();

                $this->redirect(sURL . '?message=account_deleted');

            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log('Error deleting account: ' . $ex->getMessage());
                $this->redirect(sURL . 'Dashboard/deleteaccount?error=deletion_failed');
            }
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Delete Account';

        $view = $this->getView('OAuth2');
        $view->display('delete_account');
    }

    // ── Privacy settings ──────────────────────────────────────────────────────

    /**
     * Privacy / consent settings management.
     * GET: show current settings.
     * POST: save updated settings.
     */
    public function privacy(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $db  = \Pramnos\Framework\Factory::getDatabase();
            $sql = $db->prepareQuery(
                "INSERT INTO user_privacy_settings (userid, analytics_consent, marketing_consent, updated_at)
                 VALUES (%d, %d, %d, NOW())
                 ON CONFLICT (userid) DO UPDATE
                     SET analytics_consent  = EXCLUDED.analytics_consent,
                         marketing_consent  = EXCLUDED.marketing_consent,
                         updated_at         = NOW()",
                $currentUser->userid,
                isset($_POST['analytics'])  ? 1 : 0,
                isset($_POST['marketing'])  ? 1 : 0
            );
            $db->query($sql);

            $this->redirect(sURL . 'Dashboard/privacy');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Privacy Settings';

        $view                   = $this->getView('OAuth2');
        $view->privacySettings  = $this->getPrivacySettings((int) $currentUser->userid);

        $view->display('privacy_settings');
    }

    // ── Security overview ─────────────────────────────────────────────────────

    /**
     * Security overview — recent logins, active sessions, 2FA status.
     */
    public function security(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        $view        = $this->getView('OAuth2');

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Security Overview';

        $view->recentActivity   = $this->getActivityLog((int) $currentUser->userid, 20);
        $view->twoFactorEnabled = $this->isTwoFactorEnabled((int) $currentUser->userid);

        $view->display('security');
    }

    // ── Change password ───────────────────────────────────────────────────────

    /**
     * Change password.
     * GET: show form.
     * POST: verify current password, enforce policy, update.
     *
     * Password policy: ≥ 8 chars, at least one digit, at least one non-alphanumeric.
     */
    public function changepassword(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $session = \Pramnos\Http\Session::getInstance();
            if (!$session->checkToken('post')) {
                $this->redirect(sURL . 'Dashboard/changepassword');
                return;
            }

            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword     = (string) ($_POST['new_password']     ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if (!$this->verifyUserPassword((int) $currentUser->userid, $currentPassword)) {
                $this->redirect(sURL . 'Dashboard/changepassword?error=wrong_password');
                return;
            }

            $policyError = $this->validatePasswordPolicy($newPassword, $confirmPassword);
            if ($policyError !== null) {
                $this->redirect(sURL . 'Dashboard/changepassword?error=' . urlencode($policyError));
                return;
            }

            $this->updatePassword((int) $currentUser->userid, $newPassword);
            $this->redirect(sURL . 'Dashboard/security?message=password_changed');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Change Password';

        $view = $this->getView('OAuth2');
        $view->display('change_password');
    }

    // ── Private — DB helpers ──────────────────────────────────────────────────

    /**
     * Return authorized applications (grouped by app) for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getAuthorizedApplications(int $userId): array
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            'SELECT DISTINCT a.appid, a.name, a.apikey, a.description,
                    MAX(ut.lastused) AS last_used, COUNT(ut.tokenid) AS token_count
               FROM usertokens ut
               JOIN applications a ON ut.applicationid = a.appid
              WHERE ut.userid = %d AND ut.status = 1
                AND (ut.expires = 0 OR ut.expires > %d)
              GROUP BY a.appid, a.name, a.apikey, a.description',
            $userId,
            time()
        );
        $result = $db->query($sql);
        $apps   = [];

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
    private function getActivityLog(int $userId, int $limit = 10): array
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            'SELECT action, created_at, ip_address, user_agent
               FROM user_activity_log
              WHERE userid = %d
              ORDER BY created_at DESC
              LIMIT %d',
            $userId,
            $limit
        );
        $result = $db->query($sql);
        $log    = [];

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
    private function isTwoFactorEnabled(int $userId): bool
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "SELECT enabled FROM user_twofactor WHERE userid = %d",
            $userId
        );
        $result = $db->query($sql);
        return $result && $result->numRows > 0 && (int) ($result->fields['enabled'] ?? 0) === 1;
    }

    /**
     * Build the GDPR data export payload for a user.
     *
     * @return array<string, mixed>
     */
    private function buildExportData(int $userId): array
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            'SELECT * FROM users WHERE userid = %d',
            $userId
        );
        $result   = $db->query($sql);
        $userData = $result ? (array) $result->fields : [];

        // Remove sensitive fields
        unset($userData['password'], $userData['salt']);

        return [
            'export_date'          => date('c'),
            'userid'               => $userId,
            'data'                 => $userData,
            'authorized_apps'      => $this->getAuthorizedApplications($userId),
            'recent_activity'      => $this->getActivityLog($userId, 1000),
            'privacy_settings'     => $this->getPrivacySettings($userId),
        ];
    }

    /**
     * Delete all personal data rows for a user across all relevant tables.
     * The users row itself is deleted last.
     */
    private function eraseUserData(int $userId): void
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $tables = [
            'usertokens'              => 'userid',
            'oauth2_user_consents'    => 'userid',
            'user_activity_log'       => 'userid',
            'user_privacy_settings'   => 'userid',
            'user_twofactor'          => 'userid',
            'twofactor_setup'         => 'userid',
        ];

        foreach ($tables as $table => $col) {
            $sql = $db->prepareQuery(
                "DELETE FROM {$table} WHERE {$col} = %d",
                $userId
            );
            $db->query($sql);
        }

        // Delete the account itself
        $sql = $db->prepareQuery('DELETE FROM users WHERE userid = %d', $userId);
        $db->query($sql);
    }

    /**
     * Return privacy settings for a user, or defaults if not set.
     *
     * @return array<string, mixed>
     */
    private function getPrivacySettings(int $userId): array
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            'SELECT analytics_consent, marketing_consent FROM user_privacy_settings WHERE userid = %d',
            $userId
        );
        $result = $db->query($sql);

        if ($result && $result->numRows > 0) {
            return [
                'analytics'  => (bool) ($result->fields['analytics_consent'] ?? false),
                'marketing'  => (bool) ($result->fields['marketing_consent'] ?? false),
            ];
        }

        return ['analytics' => false, 'marketing' => false];
    }

    /**
     * Verify the user's password against the stored hash.
     */
    private function verifyUserPassword(int $userId, string $password): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $sql    = $db->prepareQuery(
            'SELECT password, salt FROM users WHERE userid = %d AND active = 1',
            $userId
        );
        $result = $db->query($sql);

        if (!$result || $result->numRows == 0) {
            return false;
        }

        $stored = (string) ($result->fields['password'] ?? '');
        $salt   = (string) ($result->fields['salt']     ?? '');

        // Support both bcrypt (default) and legacy SHA-256+salt
        if (str_starts_with($stored, '$2')) {
            return password_verify($password, $stored);
        }

        return hash('sha256', $salt . $password) === $stored
            || hash('sha256', $password) === $stored;
    }

    /**
     * Validate the new password against the policy.
     * Returns an error key string on failure, null on success.
     */
    private function validatePasswordPolicy(string $newPassword, string $confirmPassword): ?string
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
    private function updatePassword(int $userId, string $newPassword): void
    {
        $db   = \Pramnos\Framework\Factory::getDatabase();
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $sql  = $db->prepareQuery(
            'UPDATE users SET password = %s, modified = NOW() WHERE userid = %d',
            $hash,
            $userId
        );
        $db->query($sql);
    }

    // ── Private — response helpers ────────────────────────────────────────────

    /**
     * Send the revokeapplication response as JSON or redirect.
     */
    private function sendRevokeResponse(bool $isAjax, bool $success, string $message): void
    {
        if ($isAjax) {
            echo json_encode(['success' => $success, 'message' => $message]);
            exit;
        }

        if (!$success) {
            $this->redirect(sURL . 'Dashboard/applications?error=' . urlencode($message));
        }
    }
}
