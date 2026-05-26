<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Admin controller for managing application users.
 *
 * Provides a DataTable list of users and CRUD operations. Applications should
 * extend this class rather than modify it directly.
 *
 * Routes:
 *   GET  /users              — display()        DataTable list
 *   GET  /users/edit/:id     — edit()           create/edit form
 *   POST /users/save         — save()           create or update
 *   GET  /users/delete/:id   — delete()         soft-delete or deactivate
 *   GET  /users/lock/:id     — lock()           set active=0
 *   GET  /users/unlock/:id   — unlock()         set active=1
 *   GET  /users/sessions/:id — sessions()       list active sessions
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class UsersController extends Controller
{
    /** Minimum usertype required to access this controller. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'view', 'edit', 'save', 'delete', 'lock', 'unlock', 'sessions', 'tokens', 'deactivateToken', 'deleteToken', 'resetpassword']);
        parent::__construct($application);
    }

    /**
     * Read-only detail view for a single user.
     *
     * Displays profile, account details, usage stats (token count, unique apps),
     * active session count, and the 5 most recent tokens.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption).
     */
    public function view(mixed $id = null): mixed
    {
        $doc = Factory::getDocument();

        $this->requireMinUserType($this->requiredUserType);

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id < 1) {
            $this->redirect(sURL . 'users');
            return null;
        }

        $user = new User();
        $user->load($id);
        if ((int) $user->userid !== $id) {
            $this->redirect(sURL . 'users');
            return null;
        }

        $doc->title = 'User: ' . htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8');

        $usageStats = $user->getDataUsageStats();

        $db = \Pramnos\Database\Database::getInstance();
        $sessionCount = 0;
        try {
            $sessionCount = $db->queryBuilder()
                ->table('#PREFIX#sessions')
                ->where('userid', $id)
                ->count();
        } catch (\Exception $e) {
            // sessions table may not exist in all deployments
        }

        $recentTokens = array_slice($user->getAllTokens(), 0, 5);

        $view               = $this->getView('users');
        $view->action       = 'view';
        $view->user         = [
            'userid'    => (int) $user->userid,
            'username'  => (string) $user->username,
            'email'     => (string) $user->email,
            'firstname' => (string) $user->firstname,
            'lastname'  => (string) $user->lastname,
            'usertype'  => (int) $user->usertype,
            'active'    => (int) $user->active,
            'validated' => (int) $user->validated,
            'regdate'   => (int) $user->regdate,
            'lastlogin' => (int) $user->lastlogin,
            'phone'     => (string) $user->phone,
            'mobile'    => (string) $user->mobile,
            'language'  => (string) $user->language,
            'timezone'  => (string) $user->timezone,
        ];
        $view->usageStats   = $usageStats;
        $view->sessionCount = $sessionCount;
        $view->recentTokens = $recentTokens;
        return $view->display('view');
    }

    /**
     * DataTable list of users ordered by userid descending.
     */
    public function display(): mixed
    {
        $doc = Factory::getDocument();
        $doc->title = 'Users';

        $this->requireMinUserType($this->requiredUserType);

        $db = \Pramnos\Database\Database::getInstance();
        $users = $db->queryBuilder()
            ->table('#PREFIX#users')
            ->select(['userid', 'username', 'email', 'usertype', 'active', 'validated', 'lastlogin'])
            ->orderBy('userid', 'desc')
            ->limit(500)
            ->getAll();

        $view          = $this->getView('users');
        $view->users   = $users;
        $view->success = $_SESSION['users_success'] ?? '';
        $view->error   = $_SESSION['users_error']   ?? '';
        unset($_SESSION['users_success'], $_SESSION['users_error']);
        return $view->display();
    }

    /**
     * Create / edit form for a single user.
     *
     * @param string|int|null $id User ID to edit; null/0 for new user.
     */
    public function edit(mixed $id = null): mixed
    {
        $doc = Factory::getDocument();

        $this->requireMinUserType($this->requiredUserType);

        $id    = (int) \Pramnos\Http\Request::staticGetOption();
        $user  = new User();
        $isNew = ($id === 0);

        if (!$isNew) {
            $user->load($id);
            $doc->title = 'Edit User: ' . htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8');
        } else {
            $doc->title = 'New User';
        }

        $currentUser     = User::getCurrentUser();
        $currentUserType = $currentUser ? (int) $currentUser->usertype : 1;

        $view                  = $this->getView('users');
        $view->action          = 'edit';
        $view->currentUserType = $currentUserType;
        $view->user            = [
            'userid'    => (int) ($user->userid ?? 0),
            'username'  => (string) ($user->username ?? ''),
            'email'     => (string) ($user->email ?? ''),
            'usertype'  => (int) ($user->usertype ?? 1),
            'firstname' => (string) ($user->firstname ?? ''),
            'lastname'  => (string) ($user->lastname ?? ''),
            'active'    => (int) ($user->active ?? 1),
            'validated' => (int) ($user->validated ?? 1),
        ];
        $view->isNew   = $isNew;
        $view->error   = $_SESSION['users_error'] ?? '';
        unset($_SESSION['users_error']);
        return $view->display('edit');
    }

    /**
     * Create or update a user (POST handler).
     */
    public function save(): void
    {
        $this->requireMinUserType($this->requiredUserType);

        // CSRF validation — token must match the session token.
        $session = \Pramnos\Http\Session::getInstance();
        if (!$session->verifyCsrfToken((string) ($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['users_error'] = 'Invalid security token. Please try again.';
            $this->redirect(sURL . 'users/edit/');
            return;
        }

        $id        = (int) ($_POST['userid']    ?? 0);
        $username  = trim((string) ($_POST['username']   ?? ''));
        $email     = trim((string) ($_POST['email']      ?? ''));
        $firstname = trim((string) ($_POST['firstname']  ?? ''));
        $lastname  = trim((string) ($_POST['lastname']   ?? ''));
        $usertype  = (int) ($_POST['usertype']   ?? 0);
        $active    = isset($_POST['active'])     ? 1 : 0;
        $validated = isset($_POST['validated'])  ? 1 : 0;
        $password  = (string) ($_POST['password'] ?? '');

        // Privilege cap: no one can assign a type higher than their own.
        $currentUser = User::getCurrentUser();
        $currentType = $currentUser ? (int) $currentUser->usertype : 0;
        if ($usertype > $currentType) {
            $usertype = $currentType;
        }

        if ($username === '') {
            $_SESSION['users_error'] = 'Username must not be empty.';
            $this->redirect(sURL . 'users/edit/' . ($id ?: ''));
            return;
        }

        $user = new User();
        if ($id > 0) {
            $user->load($id);

            // Prevent editing a user whose privilege is higher than the current user.
            if ((int) $user->usertype > $currentType) {
                $_SESSION['users_error'] = 'You cannot edit users with a higher privilege level.';
                $this->redirect(sURL . 'users');
                return;
            }
        }

        $user->username  = $username;
        $user->email     = $email;
        $user->firstname = $firstname;
        $user->lastname  = $lastname;
        $user->usertype  = $usertype;
        $user->active    = $active;
        $user->validated = $validated;

        if ($password !== '') {
            if ($id === 0) {
                // New user: save first to get userid, then set password
                $user->save();
                $id = (int) $user->userid;
                $user->load($id);
            }
            $user->setPassword($password);
        }

        $user->save();
        $this->redirect(sURL . 'users');
    }

    /**
     * Deactivate (soft-disable) a user without removing their record.
     *
     * @param string|int|null $id User ID.
     */
    public function delete(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id < 2) {
            // Protect userid=1 (Guest/Admin) and invalid ids
            $this->redirect(sURL . 'users');
            return;
        }

        $this->setActiveFlag($id, 0);
        $this->redirect(sURL . 'users');
    }

    /**
     * Set a user's active flag to 0.
     *
     * @param string|int|null $id User ID.
     */
    public function lock(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id > 1) {
            $this->setActiveFlag($id, 0);
        }
        $this->redirect(sURL . 'users');
    }

    /**
     * Set a user's active flag to 1.
     *
     * @param string|int|null $id User ID.
     */
    public function unlock(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id > 1) {
            $this->setActiveFlag($id, 1);
        }
        $this->redirect(sURL . 'users');
    }

    /**
     * Send a password reset email to the specified user (admin-initiated).
     *
     * Generates a `password_reset` token, stores it in `usertokens`, and
     * emails the user a link pointing to getPasswordResetUrl($token). The
     * reset URL defaults to `sURL . 'home/resetpassword/' . $token`; subclasses
     * may override getPasswordResetUrl() to use a different route.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption).
     */
    public function resetpassword(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id < 2) {
            $this->redirect(sURL . 'users');
            return;
        }

        $user = new User();
        $user->load($id);

        if ((int) ($user->userid ?? 0) !== $id) {
            $_SESSION['users_error'] = 'User not found.';
            $this->redirect(sURL . 'users');
            return;
        }

        $email = (string) ($user->email ?? '');
        if ($email === '') {
            $_SESSION['users_error'] = 'User has no email address — cannot send reset link.';
            $this->redirect(sURL . 'users');
            return;
        }

        $token    = bin2hex(random_bytes(32));
        $user->addToken('password_reset', $token, 'Admin-initiated password reset');

        $resetUrl = $this->getPasswordResetUrl($token);
        $siteName = (string) \Pramnos\Application\Settings::getSetting('sitename', 'System');
        $fromAddr = (string) \Pramnos\Application\Settings::getSetting('admin_mail', '');
        if ($fromAddr === '') {
            $host     = parse_url(sURL, PHP_URL_HOST) ?? 'localhost';
            $fromAddr = 'noreply@' . $host;
        }

        $mailer          = new \Pramnos\Email\Email();
        $mailer->to      = $email;
        $mailer->from    = $fromAddr;
        $mailer->subject = 'Password Reset — ' . $siteName;
        $mailer->body    = $this->buildPasswordResetEmailBody($user, $resetUrl, $siteName);

        if ($mailer->send()) {
            $_SESSION['users_success'] = 'Password reset email sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.';
        } else {
            $_SESSION['users_error'] = 'Failed to send password reset email.';
        }

        $this->redirect(sURL . 'users');
    }

    /**
     * Return the URL the reset email should point to.
     * Override in a subclass to use a custom route.
     */
    protected function getPasswordResetUrl(string $token): string
    {
        return sURL . 'home/resetpassword/' . $token;
    }

    /**
     * Build the HTML body of the password reset email.
     */
    private function buildPasswordResetEmailBody(User $user, string $resetUrl, string $siteName): string
    {
        $username = htmlspecialchars((string) ($user->username ?? ''), ENT_QUOTES, 'UTF-8');
        $link     = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $site     = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');

        return '<p>Hello ' . $username . ',</p>'
            . '<p>A password reset has been requested for your account on <strong>' . $site . '</strong>.</p>'
            . '<p>Click the link below to set a new password:</p>'
            . '<p><a href="' . $link . '">' . $link . '</a></p>'
            . '<p>If you did not request this, please ignore this email. '
            . 'The link expires in 24 hours.</p>'
            . '<p>— ' . $site . ' Team</p>';
    }

    /**
     * List active sessions for a specific user.
     *
     * @param string|int|null $id User ID.
     */
    public function sessions(mixed $id = null): mixed
    {
        $doc = Factory::getDocument();

        $this->requireMinUserType($this->requiredUserType);

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        $user = new User();
        if ($id > 0) {
            $user->load($id);
        }
        $doc->title = 'Sessions: ' . htmlspecialchars($user->username ?? '', ENT_QUOTES, 'UTF-8');

        $db = \Pramnos\Database\Database::getInstance();
        $sessionList = $db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('userid', $id)
            ->orderBy('date', 'desc')
            ->getAll();

        $view              = $this->getView('users');
        $view->action      = 'sessions';
        $view->user        = ['username' => (string) ($user->username ?? '')];
        $view->sessionList = $sessionList;
        return $view->display('sessions');
    }

    /**
     * List all tokens for a specific user.
     *
     * @param string|int|null $id User ID.
     */
    public function tokens(mixed $id = null): mixed
    {
        $doc = Factory::getDocument();

        $this->requireMinUserType($this->requiredUserType);

        $id   = (int) \Pramnos\Http\Request::staticGetOption();
        $user = new User();
        if ($id > 0) {
            $user->load($id);
        }
        $doc->title = 'Tokens: ' . htmlspecialchars($user->username ?? '', ENT_QUOTES, 'UTF-8');

        $tokenList = $user->getAllTokens();

        $view            = $this->getView('users');
        $view->action    = 'tokens';
        $view->user      = ['userid' => (int) ($user->userid ?? 0), 'username' => (string) ($user->username ?? '')];
        $view->tokenList = $tokenList;
        return $view->display('tokens');
    }

    /**
     * Deactivate a specific token belonging to a user.
     * Expects POST: userid, tokenid.
     */
    public function deactivateToken(): void
    {
        $this->requireMinUserType($this->requiredUserType);

        $userId  = (int) ($_POST['userid']  ?? 0);
        $tokenId = (int) ($_POST['tokenid'] ?? 0);

        if ($userId > 0 && $tokenId > 0) {
            $user = new User();
            $user->load($userId);
            if ((int) $user->userid === $userId) {
                $user->deactivateToken($tokenId);
            }
        }

        $this->redirect(sURL . 'users/tokens/' . $userId);
    }

    /**
     * Delete (status=2) a specific token belonging to a user.
     * Expects POST: userid, tokenid.
     */
    public function deleteToken(): void
    {
        $this->requireMinUserType($this->requiredUserType);

        $userId  = (int) ($_POST['userid']  ?? 0);
        $tokenId = (int) ($_POST['tokenid'] ?? 0);

        if ($userId > 0 && $tokenId > 0) {
            $user = new User();
            $user->load($userId);
            if ((int) $user->userid === $userId) {
                $user->deleteToken($tokenId);
            }
        }

        $this->redirect(sURL . 'users/tokens/' . $userId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function setActiveFlag(int $id, int $active): void
    {
        $db = \Pramnos\Database\Database::getInstance();
        $db->query($db->prepareQuery(
            "UPDATE `#PREFIX#users` SET `active` = %d WHERE `userid` = %d",
            $active, $id
        ));
    }

    /**
     * Redirect to homepage if the current user's usertype is below $minType.
     */
    protected function requireMinUserType(int $minType): void
    {
        $user = User::getCurrentUser();
        if ($user === null || (int) $user->usertype < $minType) {
            $this->redirect(sURL);
        }
    }
}
