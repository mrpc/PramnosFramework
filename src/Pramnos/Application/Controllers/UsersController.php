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
        $this->addAuthAction(['display', 'edit', 'save', 'delete', 'lock', 'unlock', 'sessions']);
        parent::__construct($application);
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
        $result = $db->query(
            "SELECT `userid`, `username`, `email`, `usertype`, `active`, `validated`, `lastlogin`"
            . " FROM `#PREFIX#users` ORDER BY `userid` DESC LIMIT 500"
        );

        $users = [];
        while (!$result->EOF) {
            $users[] = $result->fields;
            $result->moveNext();
        }

        $view        = $this->getView('users');
        $view->users = $users;
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

        $id    = (int) ($id ?? 0);
        $user  = new User();
        $isNew = ($id === 0);

        if (!$isNew) {
            $user->load($id);
            $doc->title = 'Edit User: ' . htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8');
        } else {
            $doc->title = 'New User';
        }

        $view          = $this->getView('users');
        $view->action  = 'edit';
        $view->user    = $user;
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

        $id       = (int) ($_POST['userid']   ?? 0);
        $username = trim((string) ($_POST['username']  ?? ''));
        $email    = trim((string) ($_POST['email']     ?? ''));
        $usertype = (int) ($_POST['usertype']  ?? 0);
        $active   = isset($_POST['active'])    ? 1 : 0;
        $validated= isset($_POST['validated']) ? 1 : 0;
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '') {
            $_SESSION['users_error'] = 'Username must not be empty.';
            $this->redirect(sURL . 'users/edit/' . ($id ?: ''));
            return;
        }

        $user = new User();
        if ($id > 0) {
            $user->load($id);
        }

        $user->username  = $username;
        $user->email     = $email;
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

        $id = (int) ($id ?? 0);
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
        $id = (int) ($id ?? 0);
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
        $id = (int) ($id ?? 0);
        if ($id > 1) {
            $this->setActiveFlag($id, 1);
        }
        $this->redirect(sURL . 'users');
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

        $id = (int) ($id ?? 0);
        $user = new User();
        if ($id > 0) {
            $user->load($id);
        }
        $doc->title = 'Sessions: ' . htmlspecialchars($user->username ?? '', ENT_QUOTES, 'UTF-8');

        $db = \Pramnos\Database\Database::getInstance();
        $result = $db->query(
            $db->prepareQuery(
                "SELECT * FROM `#PREFIX#sessions` WHERE `userid` = %d ORDER BY `date` DESC",
                $id
            )
        );

        $sessionList = [];
        while (!$result->EOF) {
            $sessionList[] = $result->fields;
            $result->moveNext();
        }

        $view              = $this->getView('users');
        $view->action      = 'sessions';
        $view->user        = $user;
        $view->sessionList = $sessionList;
        return $view->display('sessions');
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
