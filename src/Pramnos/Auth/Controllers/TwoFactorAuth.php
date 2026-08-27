<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Auth\TOTPHelper;
use Pramnos\Application\Controller;

/**
 * Two-factor authentication management controller.
 *
 * All actions require a logged-in session (addAuthAction). The HTML views are
 * resolved from the application's view path under the name 'twofactor'.
 *
 * Supported actions:
 *   - display  — 2FA settings overview page
 *   - setup    — start setup flow (GET) / verify and activate (POST)
 *   - disable  — deactivate 2FA (POST, requires password confirmation)
 *   - backup   — view / regenerate backup codes
 *   - status   — JSON status endpoint (AJAX)
 *   - test     — debug: generate a TOTP code from a fresh secret (JSON)
 *
 */
class TwoFactorAuth extends Controller
{
    private TwoFactorAuthService $twoFactorService;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['setup', 'disable', 'backup', 'status']);
        parent::__construct($application);

        $this->twoFactorService = new TwoFactorAuthService($this->application->database);
    }

    /**
     * 2FA settings overview page — shows current status and available actions.
     */
    public function display(): mixed
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === false) {
            $this->addError('Please sign in to continue.');
            $this->redirect(sURL . 'login');
            return null;
        }
        $view        = $this->getView('twofactor');

        $view->user   = $currentUser;
        $view->status = $this->twoFactorService->getStatus($currentUser->userid);

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Two-Factor Authentication';

        return $view->display();
    }

    /**
     * Start the 2FA setup process (GET) or verify the initial code (POST).
     *
     * On GET: generates a new TOTP secret, stores it in the setup table,
     * and renders the QR code for the authenticator app.
     *
     * On POST with `verify_code`: validates the supplied TOTP code and, if
     * correct, promotes the pending secret to active status.
     */
    public function setup(): mixed
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === false) {
            $this->addError('Please sign in to continue.');
            $this->redirect(sURL . 'login');
            return null;
        }

        if ($this->twoFactorService->isEnabled($currentUser->userid)) {
            $this->addError('Two-factor authentication is already enabled on this account.');
            $this->redirect(sURL . 'TwoFactorAuth');
            return null;
        }

        $request = new \Pramnos\Http\Request();

        if ($request->get('verify_code', '', 'post') !== '') {
            $this->verifySetup();
            return null;
        }

        $setupData = $this->twoFactorService->startSetup(
            $currentUser->userid,
            $currentUser->email
        );

        $view = $this->getView('twofactor');

        $view->setupData = $setupData;
        $view->user      = $currentUser;

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = '2FA Setup';

        return $view->display('setup');
    }

    /**
     * Verify the initial TOTP code submitted during setup and activate 2FA.
     * Private — called only from setup() when a POST form field is present.
     */
    private function verifySetup(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === false) {
            $this->addError('Please sign in to continue.');
            $this->redirect(sURL . 'login');
            return;
        }
        $request     = new \Pramnos\Http\Request();
        $code        = $request->get('verify_code', '', 'post');

        if ($code === '') {
            $this->addError('Enter the code from your authenticator app.');
            $this->redirect(sURL . 'TwoFactorAuth/setup');
            return;
        }

        if ($this->twoFactorService->completeSetup($currentUser->userid, $code)) {
            \Pramnos\Auth\ActivityLog::record((int) $currentUser->userid, 'twofactor_enabled');
            $this->redirect(sURL . 'TwoFactorAuth/backup?setup=complete');
        } else {
            $this->addError('That code is not correct.');
            $this->redirect(sURL . 'TwoFactorAuth/setup');
        }
    }

    /**
     * Deactivate 2FA for the current user (POST only).
     * Requires the account password as additional confirmation.
     */
    public function disable(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === false) {
            $this->addError('Please sign in to continue.');
            $this->redirect(sURL . 'login');
            return;
        }
        $request     = new \Pramnos\Http\Request();
        $password    = $request->get('confirm_password', '', 'post');

        if ($password === '') {
            $this->addError('Your password is required to confirm this.');
            $this->redirect(sURL . 'TwoFactorAuth');
            return;
        }

        if ($this->twoFactorService->disable($currentUser->userid, $password)) {
            \Pramnos\Auth\ActivityLog::record((int) $currentUser->userid, 'twofactor_disabled');
            $this->addMessage('Two-factor authentication is now off.');
            $this->redirect(sURL . 'TwoFactorAuth');
        } else {
            $this->addError('That password is not correct.');
            $this->redirect(sURL . 'TwoFactorAuth');
        }
    }

    /**
     * Display and optionally regenerate backup (recovery) codes.
     *
     * On POST with `regenerate_password`: generates a new set of backup codes
     * after verifying the account password.
     */
    public function backup(): mixed
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === false) {
            $this->addError('Please sign in to continue.');
            $this->redirect(sURL . 'login');
            return null;
        }

        if (!$this->twoFactorService->isEnabled($currentUser->userid)) {
            $this->addError('Two-factor authentication is not enabled on this account.');
            $this->redirect(sURL . 'TwoFactorAuth');
            return null;
        }

        $request = new \Pramnos\Http\Request();
        $view    = $this->getView('twofactor');

        $regeneratePassword = $request->get('regenerate_password', '', 'post');
        if ($regeneratePassword !== '') {
            $newCodes = $this->twoFactorService->regenerateBackupCodes(
                $currentUser->userid,
                $regeneratePassword
            );

            if ($newCodes !== false) {
                $view->newBackupCodes = $newCodes;
                $view->success        = 'New backup codes generated. Store them in a safe place.';
            } else {
                $view->error = 'Invalid password. Backup codes could not be regenerated.';
            }
        }

        $view->user           = $currentUser;
        $view->remainingCodes = $this->twoFactorService->getRemainingBackupCodes($currentUser->userid);

        if ($request->get('setup', '', 'get') === 'complete') {
            $view->setupComplete = true;
            $view->success       = 'Two-factor authentication has been enabled successfully!';
            // The codes the enrolment just stored. This page says "save your
            // backup codes before leaving" and used to show none: they were
            // generated, hashed and dropped, so the account's recovery codes
            // were known to nobody.
            $view->newBackupCodes = $this->twoFactorService->takeNewBackupCodes();
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = '2FA Backup Codes';

        return $view->display('backup');
    }

    /**
     * Return the current 2FA status as JSON (AJAX endpoint).
     */
    public function status(): void
    {
        $currentUser = \Pramnos\User\User::getCurrentUser();
        if ($currentUser === false) {
            $this->addError('Please sign in to continue.');
            $this->redirect(sURL . 'login');
            return;
        }

        header('Content-Type: application/json');
        echo json_encode($this->twoFactorService->getStatus($currentUser->userid));
        return;
    }

    /**
     * Debug helper — generates a fresh TOTP secret and a matching code.
     * Useful for smoke-testing the TOTP pipeline without a real authenticator app.
     * Should be disabled or access-restricted in production.
     */
    public function test(): void
    {
        $secret    = TOTPHelper::generateSecret();
        $code      = TOTPHelper::generateCode($secret);
        $qrDataUri = TOTPHelper::getQRCodeDataUri($secret, 'test@example.com');

        header('Content-Type: application/json');
        echo json_encode([
            'secret'           => $secret,
            'code'             => $code,
            'qr_data_uri'      => $qrDataUri !== null ? '(generated)' : null,
            'remaining_time'   => TOTPHelper::getRemainingTime(),
            'is_valid_secret'  => TOTPHelper::isValidSecret($secret),
            'verify_test'      => TOTPHelper::verifyCode($secret, $code),
        ]);
        return;
    }
}
