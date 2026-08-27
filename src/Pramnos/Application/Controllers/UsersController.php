<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Framework\Factory;
use Pramnos\Html\Icon;
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
 * @license    MIT
 */
class UsersController extends Controller
{
    /** Minimum usertype required to access this controller. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'display', 'data', 'view', 'edit', 'save', 'delete', 'lock', 'unlock',
            'sessions', 'tokens', 'deactivateToken', 'deleteToken', 'resetpassword',
            // The per-user record screens and the operator actions they offer.
            'activity', 'activitydata', 'unlocklogin', 'disabletwofactor', 'revokepasskey',
        ]);
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
            $this->redirect(adminUrl('users'));
            return null;
        }

        $user = new User();
        $user->load($id);
        if ((int) $user->userid !== $id) {
            $this->redirect(adminUrl('users'));
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
        // Everything else the framework records about this account — see userRecords().
        $view->records      = $this->userRecords($id);
        return $view->display('view');
    }

    /**
     * Everything the framework records **about one user**, in one place.
     *
     * An administrator looking at an account needs to see what the system knows about it,
     * and what the system knows was spread across nine stores that no screen joined:
     * sign-in history in `user_activity_log`, GDPR requests in `gdpr_requests`, failed
     * attempts in `loginlockouts`, second factors in `user_twofactor`, passkeys in
     * `passkey_credentials`, consent in `user_privacy_settings`, tokens and what was done
     * with them in `usertokens` / `tokenactions`, and notifications. Some of it was
     * visible in the DevPanel — a development tool — and the rest nowhere at all.
     *
     * **Every read is guarded on its own.** These tables arrive with features: an
     * application without `authserver` has none of the `authserver.*` ones, and one that
     * has not migrated yet has some. A screen that fails because a feature is off is
     * worse than a screen with an empty panel, so each store answers `[]` for itself and
     * the page renders whatever exists.
     *
     * Counts come back beside the rows, because "the last ten of 4,312" and "all ten
     * there are" are different facts and a list of ten cannot tell them apart.
     *
     * @param  int $userId
     * @return array<string, mixed>
     */
    protected function userRecords(int $userId): array
    {
        $db = \Pramnos\Database\Database::getInstance();

        /** One read, its failure absorbed: a missing table is a missing feature. */
        $rows = static function (callable $query) use ($db): array {
            try {
                $result = $query($db->queryBuilder());

                return $result ? (array) $result->fetchAll() : [];
            } catch (\Throwable) {
                return [];
            }
        };
        $count = static function (callable $query) use ($db): int {
            try {
                return (int) $query($db->queryBuilder());
            } catch (\Throwable) {
                return 0;
            }
        };

        return [
            // Sign-ins, logouts and whatever else the application records.
            'activity' => $rows(fn ($qb) => $qb->table('authserver.user_activity_log')
                ->where('userid', $userId)->orderBy('created_at', 'desc')->limit(10)->get()),
            'activityCount' => $count(fn ($qb) => $qb->table('authserver.user_activity_log')
                ->where('userid', $userId)->count()),

            // Export and erasure requests. Visible to an operator because answering one
            // is an operator's job, and a request nobody can see is a request nobody
            // answers.
            'gdpr' => $rows(fn ($qb) => $qb->table('authserver.gdpr_requests')
                ->where('userid', $userId)->orderBy('requested_at', 'desc')->limit(10)->get()),
            'gdprCount' => $count(fn ($qb) => $qb->table('authserver.gdpr_requests')
                ->where('userid', $userId)->count()),

            // Failed attempts and any active lockout, which is what "I cannot sign in"
            // resolves to nine times out of ten.
            'lockouts' => $rows(fn ($qb) => $qb->table('authserver.loginlockouts')
                ->where('userid', $userId)->orderBy('lastfailedat', 'desc')->limit(10)->get()),

            // The second factor: whether it is on, and the codes left.
            'twofactor' => $rows(fn ($qb) => $qb->table('authserver.user_twofactor')
                ->where('userid', $userId)->limit(1)->get())[0] ?? null,

            // Registered passkeys, which are credentials an operator may need to revoke.
            'passkeys' => $rows(fn ($qb) => $qb->table('authserver.passkey_credentials')
                ->where('userid', $userId)->orderBy('created_at', 'desc')->limit(10)->get()),

            // What the user chose about their own data.
            'privacy' => $rows(fn ($qb) => $qb->table('authserver.user_privacy_settings')
                ->where('userid', $userId)->limit(1)->get())[0] ?? null,

            // What was done with this account's tokens — issued, revoked, refreshed.
            'tokenActions' => $rows(fn ($qb) => $qb->table('#PREFIX#tokenactions')
                ->where('userid', $userId)->orderBy('actiondate', 'desc')->limit(10)->get()),
            'tokenActionCount' => $count(fn ($qb) => $qb->table('#PREFIX#tokenactions')
                ->where('userid', $userId)->count()),

            // Which organizations the account belongs to.
            'organizations' => $rows(fn ($qb) => $qb->table('authserver.user_organizations uo')
                ->join('organizations o', 'uo.organization_id', '=', 'o.organization_id')
                ->select(['o.organization_id', 'o.name', 'uo.granted_at'])
                ->where('uo.userid', $userId)->where('uo.is_active', 1)->getAll()),
        ];
    }

    /**
     * DataTable list of users — table shell only; rows loaded via AJAX from data().
     */
    public function display(): mixed
    {
        $doc = Factory::getDocument();
        $doc->title = 'Users';

        $this->requireMinUserType($this->requiredUserType);

        $dt = new \Pramnos\Html\Datatable('dt-users');
        $dt->source    = adminUrl('users/data');
        $dt->bootstrap = false;

        /**
         * Per-column filters, which is what makes a list of thousands usable.
         *
         * One search box over every column answers "find this person"; it cannot
         * answer "the administrators registered this month", and that is most of
         * what an operator asks a user list. The 9th argument of `addColumn()` is
         * the filter: `true` for a text box under the column, or the **id** of a
         * control rendered into the footer — which is how an enumerated column gets
         * a dropdown instead of a text box nobody can guess the values for.
         *
         * The text boxes wait for `minSearchLength` characters and debounce; without
         * that, each keystroke is a query across the whole table.
         */
        $dt->footerTextSearch = true;

        // The bands this application declares, or the framework's — see
        // {@see \Pramnos\User\UserTypes}. A numeric column is matched **equal** rather
        // than with `LIKE` (`LIKE '%5%'` on a number matches 5, 15 and 50), so the
        // option's label carries the value it sends.
        $typeFilter = new \Pramnos\Html\Select('usertype_filter');
        $typeFilter->id = 'dt-users-type';
        $typeFilter->addOption('Any type', '');
        $typeFilter->addOptions(\Pramnos\User\UserTypes::options());

        $statusFilter = new \Pramnos\Html\Select('status_filter');
        $statusFilter->id = 'dt-users-status';
        $statusFilter->addOptions(['' => 'Any status', '1' => 'Active', '0' => 'Inactive']);

        $dt->addColumn('ID',         true, true,  true,  'num-html', '', true, 'left', true)
           ->addColumn('Username',   true, true,  true,  '',         '', true, 'left', true)
           ->addColumn('Email',      true, true,  true,  '',         '', true, 'left', true)
           ->addColumn(
               'Type',
               true,
               true,
               true,
               'html',
               $typeFilter->render(),
               true,
               'left',
               'dt-users-type',
               (string) \Pramnos\Http\Request::staticGet('usertype_filter', '', 'get')
           )
           ->addColumn(
               'Status',
               true,
               true,
               true,
               'html',
               $statusFilter->render(),
               true,
               'left',
               'dt-users-status',
               (string) \Pramnos\Http\Request::staticGet('status_filter', '', 'get')
           )
           ->addColumn('Registered', true, true,  false, 'num')
           ->addColumn('Actions',    true, false, false, 'html');

        $view          = $this->getView('users');
        $view->datatable = $dt;
        $view->success = $_SESSION['users_success'] ?? '';
        $view->error   = $_SESSION['users_error']   ?? '';
        unset($_SESSION['users_success'], $_SESSION['users_error']);
        return $view->display();
    }

    /**
     * AJAX data endpoint for the users DataTable.
     * Reads DataTables server-side params from POST and returns paginated JSON.
     */
    public function data(): mixed
    {
        $this->requireMinUserType($this->requiredUserType);
        \Pramnos\Framework\Factory::getDocument('json');

        $fields = ['userid', 'username', 'email', 'usertype', 'active', 'regdate'];
        $result = \Pramnos\Html\Datatable\Datasource::getList(
            '#PREFIX#users',
            $fields,
            false
        );

        // Use $dataKey to get a direct lvalue reference — the ?? operator returns a copy
        // (rvalue) so &$row references inside foreach would not modify $result in place.
        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        foreach ($result[$dataKey] as &$row) {
            $id      = (int) $row[0];
            $active  = (int) $row[4];
            $regdate = (int) $row[5];
            $viewUrl = adminUrl('users/view/') . $id;

            /**
             * The id and the username open the record.
             *
             * A row whose only way in is the last cell makes the whole row a target
             * people click and nothing happens. The two cells that identify the record
             * are the ones a person aims at, so they are the link.
             */
            $row[0] = '<a href="' . $viewUrl . '">' . $id . '</a>';
            $row[1] = '<a href="' . $viewUrl . '">'
                . htmlspecialchars((string) $row[1], ENT_QUOTES, 'UTF-8') . '</a>';
            $row[2] = htmlspecialchars((string) $row[2], ENT_QUOTES, 'UTF-8');

            // The band, not the number. `usertype` is a threshold — 85 is a Manager —
            // and a column of bare integers asks every reader to know the bands.
            $row[3] = htmlspecialchars(
                \Pramnos\User\UserTypes::label((int) $row[3]),
                ENT_QUOTES,
                'UTF-8'
            ) . ' <span class="pf-muted">' . (int) $row[3] . '</span>';

            $row[4] = $active
                ? '<span class="pf-state pf-state-on">Active</span>'
                : '<span class="pf-state pf-state-off">Inactive</span>';
            $row[5] = $regdate > 0 ? date('Y-m-d', $regdate) : '';

            $row[]  = Icon::link($viewUrl, 'view', 'View this user')
                    . Icon::link(adminUrl('users/edit/') . $id, 'edit', 'Edit this user')
                    . Icon::link(adminUrl('users/tokens/') . $id, 'tokens', 'Tokens')
                    . Icon::link(adminUrl('users/sessions/') . $id, 'sessions', 'Sessions')
                    . Icon::link(
                        adminUrl('users/delete/') . $id,
                        'deactivate',
                        'Deactivate this user',
                        ['data-confirm' => 'Deactivate this user?', 'class' => 'pf-action-danger']
                    );
            unset($row['DT_RowId']);
        }
        unset($row);

        return \Pramnos\Http\Response::json($result);
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
            $this->redirect(adminUrl('users/edit/'));
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
            $this->redirect(adminUrl('users/edit/') . ($id ?: ''));
            return;
        }

        $user = new User();
        if ($id > 0) {
            $user->load($id);

            // Prevent editing a user whose privilege is higher than the current user.
            if ((int) $user->usertype > $currentType) {
                $_SESSION['users_error'] = 'You cannot edit users with a higher privilege level.';
                $this->redirect(adminUrl('users'));
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
        $this->redirect(adminUrl('users'));
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
            $this->redirect(adminUrl('users'));
            return;
        }

        $this->setActiveFlag($id, 0);
        $this->redirect(adminUrl('users'));
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
        $this->redirect(adminUrl('users'));
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
        $this->redirect(adminUrl('users'));
    }

    /**
     * Clear a login lockout, so the account can try again now.
     *
     * The single most common support request an authentication server gets is "I cannot
     * sign in", and nine times out of ten the answer is a lockout with minutes left on
     * it. Without this an operator's options were to wait it out or to edit a table.
     *
     * The rows are deleted rather than expired: a lockout row *is* the count of failed
     * attempts, so zeroing it and removing it are the same state, and removing it does
     * not leave a row that will lock the account again on the next mistake.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function unlocklogin(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id < 2) {
            $this->redirect(adminUrl('users'));

            return;
        }

        try {
            \Pramnos\Database\Database::getInstance()->queryBuilder()
                ->table('authserver.loginlockouts')
                ->where('userid', $id)
                ->delete();
            \Pramnos\Auth\ActivityLog::record($id, 'lockout_cleared', [
                'by' => (int) (\Pramnos\User\User::getCurrentUser()->userid ?? 0),
            ]);
            $_SESSION['users_success'] = 'Login lockout cleared.';
        } catch (\Throwable $ex) {
            // The table ships with the `auth` feature; an application without it has
            // nothing to clear, which is not an error worth a stack trace on screen.
            $_SESSION['users_error'] = 'Could not clear the lockout: ' . $ex->getMessage();
        }

        $this->redirect(adminUrl('users/view/') . $id);
    }

    /**
     * Turn off a user's second factor, as an operator.
     *
     * The service has two doors on purpose: the user's own requires their password, and
     * this one does not — an operator resetting 2FA for somebody who lost their phone
     * cannot be asked for that person's password. {@see
     * \Pramnos\Auth\TwoFactorAuthService::disableForOperator()} is the named unchecked
     * path, so the checked one cannot be reached by passing an empty string.
     *
     * Recorded in the activity log, because switching off somebody's second factor is
     * exactly the action an audit needs to show.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function disabletwofactor(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id < 2) {
            $this->redirect(adminUrl('users'));

            return;
        }

        try {
            $service = new \Pramnos\Auth\TwoFactorAuthService();
            $service->disableForOperator($id);
            \Pramnos\Auth\ActivityLog::record($id, 'twofactor_disabled_by_operator', [
                'by' => (int) (\Pramnos\User\User::getCurrentUser()->userid ?? 0),
            ]);
            $_SESSION['users_success'] = 'Two-factor authentication disabled for this user.';
        } catch (\Throwable $ex) {
            $_SESSION['users_error'] = 'Could not disable two-factor: ' . $ex->getMessage();
        }

        $this->redirect(adminUrl('users/view/') . $id);
    }

    /**
     * Revoke one passkey.
     *
     * A passkey is a credential bound to a device. When the device is gone the credential
     * is not — it stays valid until somebody removes it, and the person who lost the
     * device is often the one who cannot sign in to remove it.
     *
     * The credential id comes from the query string and the user from the path, and both
     * are used in the `delete`: a request naming somebody else's credential deletes
     * nothing rather than deleting theirs.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function revokepasskey(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id           = (int) \Pramnos\Http\Request::staticGetOption();
        $credentialId = (int) \Pramnos\Http\Request::staticGet('credential', 0, 'get', 'int');

        if ($id < 2 || $credentialId < 1) {
            $this->redirect(adminUrl('users'));

            return;
        }

        try {
            $affected = \Pramnos\Database\Database::getInstance()->queryBuilder()
                ->table('authserver.passkey_credentials')
                ->where('id', $credentialId)
                ->where('userid', $id)
                ->delete();

            if ($affected) {
                \Pramnos\Auth\ActivityLog::record($id, 'passkey_revoked_by_operator', [
                    'credential' => $credentialId,
                    'by'         => (int) (\Pramnos\User\User::getCurrentUser()->userid ?? 0),
                ]);
            }
            $_SESSION['users_success'] = $affected
                ? 'Passkey revoked.'
                : 'That passkey does not belong to this user.';
        } catch (\Throwable $ex) {
            $_SESSION['users_error'] = 'Could not revoke the passkey: ' . $ex->getMessage();
        }

        $this->redirect(adminUrl('users/view/') . $id);
    }

    /**
     * The full activity log for one user, paged.
     *
     * The view shows the last ten, which answers "what happened recently" and not "what
     * happened". This is the whole of it, through the same server-side pipeline every
     * other list uses, with a filter under each column — an account with four thousand
     * sign-ins is exactly the one somebody is investigating.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function activity(mixed $id = null): mixed
    {
        $this->requireMinUserType($this->requiredUserType);
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id < 1) {
            $this->redirect(adminUrl('users'));

            return null;
        }

        $user = new User();
        $user->load($id);

        $doc        = Factory::getDocument();
        $doc->title = 'Activity — ' . htmlspecialchars((string) $user->username, ENT_QUOTES, 'UTF-8');

        $dt = new \Pramnos\Html\Datatable('dt-useractivity');
        $dt->source          = adminUrl('users/activitydata/') . $id;
        $dt->bootstrap       = false;
        $dt->footerTextSearch = true;
        $dt->addColumn('When',    true, true,  false)
           ->addColumn('Action',  true, true,  true, '', '', true, 'left', true)
           ->addColumn('IP',      true, true,  true, '', '', true, 'left', true)
           ->addColumn('Details', true, false, true, 'html', '', true, 'left', true);

        $view            = $this->getView('users');
        $view->user      = ['userid' => $id, 'username' => (string) $user->username];
        $view->datatable = $dt;

        return $view->display('activity');
    }

    /**
     * AJAX rows for {@see activity()}.
     *
     * The user id is in the path and goes into the `where`, so the endpoint cannot be
     * asked for somebody else's history by changing a POST field.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function activitydata(mixed $id = null): mixed
    {
        $this->requireMinUserType($this->requiredUserType);
        Factory::getDocument('json');

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id < 1) {
            return \Pramnos\Http\Response::json(['data' => [], 'recordsTotal' => 0, 'recordsFiltered' => 0]);
        }

        try {
            $result = \Pramnos\Html\Datatable\Datasource::getList(
                'authserver.user_activity_log',
                ['created_at', 'action', 'ip_address', 'details'],
                false,
                'userid = ' . $id
            );
        } catch (\Throwable) {
            // No table: the `auth` feature is off, and an empty list says so better than
            // a 500 does.
            return \Pramnos\Http\Response::json(['data' => [], 'recordsTotal' => 0, 'recordsFiltered' => 0]);
        }

        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        foreach ($result[$dataKey] as &$row) {
            foreach ([1, 2] as $column) {
                $row[$column] = htmlspecialchars((string) ($row[$column] ?? ''), ENT_QUOTES, 'UTF-8');
            }
            // The details are JSON as stored. Shown as text rather than parsed into a
            // table: what is in there is whatever the action recorded, and a screen that
            // assumes a shape hides the actions that do not have it.
            $row[3] = '<code class="pf-muted">'
                . htmlspecialchars((string) ($row[3] ?? ''), ENT_QUOTES, 'UTF-8') . '</code>';
            unset($row['DT_RowId']);
        }
        unset($row);

        return \Pramnos\Http\Response::json($result);
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
            $this->redirect(adminUrl('users'));
            return;
        }

        $user = new User();
        $user->load($id);

        if ((int) ($user->userid ?? 0) !== $id) {
            $_SESSION['users_error'] = 'User not found.';
            $this->redirect(adminUrl('users'));
            return;
        }

        $email = (string) ($user->email ?? '');
        if ($email === '') {
            $_SESSION['users_error'] = 'User has no email address — cannot send reset link.';
            $this->redirect(adminUrl('users'));
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

        $this->redirect(adminUrl('users'));
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

        // The sessions table tracks last activity in the `time` column (unix ts)
        // and marks terminated sessions with logout=1 — there is no `date` column,
        // so ordering by it silently returned no rows. Show active sessions first.
        $db = \Pramnos\Database\Database::getInstance();
        $sessionList = $db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('userid', $id)
            ->orderBy('time', 'desc')
            ->getAll();

        $view              = $this->getView('users');
        $view->action      = 'sessions';
        // Include userid so the breadcrumb can link the username crumb back to
        // the user's detail page (users/view/:id).
        $view->user        = ['userid' => (int) ($user->userid ?? 0), 'username' => (string) ($user->username ?? '')];
        $view->sessionList = $sessionList;
        return $view->display('sessions');
    }

    /**
     * List all tokens for a specific user.
     *
     * Token management was unified under the Tokens controller; this route is
     * kept as a backward-compatible redirect to `Tokens/userid/{id}`.
     *
     * @param string|int|null $id User ID.
     */
    public function tokens(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        $this->redirect(adminUrl('Tokens/userid/') . $id);
    }

    /**
     * Deactivate a specific token belonging to a user.
     * Kept for backward compatibility — delegates to Tokens/deactivate.
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

        $this->redirect(adminUrl('Tokens/userid/') . $userId);
    }

    /**
     * Delete (status=2) a specific token belonging to a user.
     * Kept for backward compatibility — delegates to Tokens/delete.
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

        $this->redirect(adminUrl('Tokens/userid/') . $userId);
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
