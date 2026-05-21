<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Auth\WebhookService;
use Pramnos\Application\Controller;

/**
 * Device flow verification controller (RFC 8628).
 *
 * Handles the user-facing side of the device authorization flow:
 *   - display (GET) — show verification form or confirmation for logged-in users
 *   - POST action=verify — authenticate user + approve/deny the device
 *
 * HTML views are resolved from the application view path under `device`.
 * Sub-views: display (form), confirmation, success, deny, errormessage.
 *
 * @package     PramnosFramework
 * @subpackage  Auth\Controllers
 */
class Device extends Controller
{
    private WebhookService $webhookService;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        $this->webhookService = new WebhookService(\Pramnos\Framework\Factory::getDatabase());
    }

    // ── Display ───────────────────────────────────────────────────────────────

    /**
     * Entry point for the device verification page.
     *
     * When the user submits the form (action=verify) the verification handler
     * is invoked; otherwise the form is rendered.
     */
    public function display(): void
    {
        $userCode = (string) ($_GET['user_code'] ?? $_POST['user_code'] ?? '');
        $action   = (string) ($_POST['action']   ?? 'show_form');

        if ($action === 'verify') {
            $this->handleVerification();
        } else {
            $this->showVerificationForm($userCode);
        }
    }

    // ── Private — render helpers ──────────────────────────────────────────────

    /**
     * Show the user code entry form, or the confirmation screen if the user
     * is already authenticated.
     */
    private function showVerificationForm(string $userCode): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();

        if ($currentUser && isset($currentUser->userid) && (int) $currentUser->userid > 0
            && isset($currentUser->active) && (int) $currentUser->active === 1) {
            $this->showAuthorizationConfirmation($userCode, [
                'userid'   => $currentUser->userid,
                'username' => $currentUser->username,
                'email'    => $currentUser->email,
            ]);
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Device Authorization';

        $view           = $this->getView('device');
        $view->userCode = $userCode;
        $view->display();
    }

    /**
     * Handle the POST submission from the verification form.
     *
     * Flow:
     *  1. Resolve user identity (session or credential login).
     *  2. Look up the pending device authorization by user_code.
     *  3. Approve or deny it, update the DB, and queue a webhook event.
     */
    private function handleVerification(): void
    {
        $userCode    = (string) ($_POST['user_code']    ?? '');
        $verifyAction = (string) ($_POST['verify_action'] ?? 'authorize');
        $username    = (string) ($_POST['username']    ?? '');
        $password    = (string) ($_POST['password']    ?? '');

        try {
            if ($userCode === '') {
                throw new \RuntimeException('Missing user_code');
            }

            // Resolve user — prefer session, fall back to submitted credentials
            $currentUser = \Pramnos\User\User::getCurrentUser();
            if ($currentUser && isset($currentUser->userid) && (int) $currentUser->userid > 0) {
                $user = [
                    'userid'   => (int) $currentUser->userid,
                    'username' => $currentUser->username,
                    'email'    => $currentUser->email,
                ];
            } else {
                if ($username === '' || $password === '') {
                    throw new \RuntimeException('Please fill in all fields');
                }
                $user = $this->validateCredentials($username, $password);
                if (empty($user['userid'])) {
                    throw new \RuntimeException('Invalid username or password');
                }
            }

            // Look up the pending device authorization
            $db  = \Pramnos\Framework\Factory::getDatabase();
            $sql = $db->prepareQuery(
                "SELECT * FROM " . $db->schema()->quoteTable('authserver.oauth2_device_codes') . "
                 
                  WHERE user_code = %s AND status = 'pending' AND expires_at > %d",
                $userCode,
                time()
            );
            $result = $db->query($sql);

            if (!$result || $result->numRows == 0) {
                throw new \RuntimeException('Invalid or expired device code');
            }

            $deviceAuth = (array) $result->fields;

            if ($verifyAction === 'authorize') {
                $this->approveDevice($db, $deviceAuth, $user);
                $this->showSuccessPage($deviceAuth);
            } else {
                $this->denyDevice($db, $deviceAuth);
                $this->showDeniedPage();
            }

        } catch (\Exception $ex) {
            $this->showErrorPage($ex->getMessage(), $userCode);
        }
    }

    // ── Private — DB operations ───────────────────────────────────────────────

    /**
     * Approve the device authorization and queue a webhook event.
     */
    private function approveDevice(
        \Pramnos\Database\Database $db,
        array $deviceAuth,
        array $user
    ): void {
        $t   = $db->schema()->quoteTable('authserver.oauth2_device_codes');
        $sql = $db->prepareQuery(
            "UPDATE {$t}
                SET status = 'authorized', user_id = %d, authorized_at = %d
              WHERE user_code = %s",
            $user['userid'],
            time(),
            $deviceAuth['user_code']
        );
        $db->query($sql);

        $this->webhookService->queueEvent(
            'device_authorized',
            (int) $user['userid'],
            [
                'device_code' => $deviceAuth['device_code'],
                'user_code'   => $deviceAuth['user_code'],
                'client_id'   => $deviceAuth['client_id'],
                'scope'       => $deviceAuth['scope'] ?? '',
            ],
            $deviceAuth['device_code']
        );
    }

    /**
     * Deny the device authorization and queue a webhook event.
     */
    private function denyDevice(
        \Pramnos\Database\Database $db,
        array $deviceAuth
    ): void {
        $t   = $db->schema()->quoteTable('authserver.oauth2_device_codes');
        $sql = $db->prepareQuery(
            "UPDATE {$t}
                SET status = 'denied', authorized_at = %d
              WHERE user_code = %s",
            time(),
            $deviceAuth['user_code']
        );
        $db->query($sql);

        $this->webhookService->queueEvent(
            'device_deauthorized',
            0,
            [
                'device_code' => $deviceAuth['device_code'],
                'user_code'   => $deviceAuth['user_code'],
                'client_id'   => $deviceAuth['client_id'],
                'reason'      => 'user_denied',
            ],
            $deviceAuth['device_code']
        );
    }

    // ── Private — view helpers ────────────────────────────────────────────────

    private function showSuccessPage(array $deviceAuth): void
    {
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Device Authorized';

        $view             = $this->getView('device');
        $view->deviceAuth = (object) $deviceAuth;
        $view->display('success');
    }

    private function showDeniedPage(): void
    {
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Device Authorization Denied';

        $view = $this->getView('device');
        $view->display('deny');
    }

    private function showErrorPage(string $error, string $userCode = ''): void
    {
        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Authorization Error';

        $view           = $this->getView('device');
        $view->error    = $error;
        $view->userCode = $userCode;
        $view->display('errormessage');
    }

    /**
     * Show the authorization confirmation screen for already-logged-in users.
     */
    private function showAuthorizationConfirmation(string $userCode, array $user): void
    {
        if ($userCode === '') {
            $this->showErrorPage('Missing user_code');
            return;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Authorize Device';

        $view                    = $this->getView('device');
        $view->userCode          = $userCode;
        $view->user              = $user;
        $view->isAlreadyLoggedIn = true;
        $view->display('confirmation');
    }

    // ── Private — auth helpers ────────────────────────────────────────────────

    /**
     * Validate username/password credentials and return user data on success.
     *
     * @return array{userid: int, username: string, email: string}|array{}
     */
    private function validateCredentials(string $username, string $password): array
    {
        if (!method_exists(\Pramnos\User\User::class, 'validateUserCredentials')) {
            // Fallback: direct DB check
            return $this->validateCredentialsViaDb($username, $password);
        }

        $credentials = \Pramnos\User\User::validateUserCredentials($username, $password);

        if (empty($credentials['userid'])) {
            return [];
        }

        return [
            'userid'   => (int) $credentials['userid'],
            'username' => (string) $credentials['username'],
            'email'    => (string) $credentials['email'],
        ];
    }

    /**
     * Direct DB credential check used when User::validateUserCredentials() is
     * unavailable in the host application.
     *
     * @return array{userid: int, username: string, email: string}|array{}
     */
    private function validateCredentialsViaDb(string $username, string $password): array
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            'SELECT userid, username, email FROM users
              WHERE (username = %s OR email = %s)
                AND password = %s AND active = 1',
            $username,
            $username,
            hash('sha256', $password)
        );
        $result = $db->query($sql);

        if (!$result || $result->numRows == 0) {
            return [];
        }

        return [
            'userid'   => (int) $result->fields['userid'],
            'username' => (string) $result->fields['username'],
            'email'    => (string) $result->fields['email'],
        ];
    }
}
