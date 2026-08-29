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
            // Per-user settings and per-user permissions, edited where the user is.
            'types',
            'savesetting', 'deletesetting', 'grantpermission', 'revokepermission',
            'notify', 'sendnotification', 'signinalerts',
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
        $view->records      = $this->userRecords($id, (string) $user->email);
        return $view->display('view');
    }

    /**
     * The permission rows granted directly to one user.
     *
     * Only the direct grants: the resolver also answers from usertype and from group
     * membership, and a screen that mixed the two would offer a "revoke" button for a
     * permission this user does not have a row for. What can be removed here is what was
     * granted here.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function userPermissions(int $userId): array
    {
        if ($userId < 2) {
            return [];
        }

        try {
            $result = \Pramnos\Database\Database::getInstance()->queryBuilder()
                ->table('authserver.permissions')
                ->where('subject_type', 'user')
                ->where('subject_id', $userId)
                ->orderBy('object_type')
                ->orderBy('action')
                ->get();

            return $result ? (array) $result->fetchAll() : [];
        } catch (\Throwable) {
            // The table ships with the `authserver` feature; without it there are no
            // per-user grants to show, which is not an error.
            return [];
        }
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
    protected function userRecords(int $userId, string $email = ''): array
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

            // Whether this account is told about a sign-in from a device it has not
            // used, and whether that is its own choice or the site's policy.
            'signInAlerts' => [
                'policy'  => (string) (\Pramnos\Application\Settings::getSetting(
                    \Pramnos\Auth\NewSignInAlert::POLICY_SETTING
                ) ?: 'optin'),
                'enabled' => \Pramnos\Auth\NewSignInAlert::isEnabledFor($userId),
            ],

            // The mail this account was actually sent. An operator answering "I never got
            // the code" has otherwise no way to tell a mail that was never queued from one
            // that was queued and refused, and the mail log is indexed by address rather
            // than by account, so it is not a screen anybody thinks to cross-reference.
            //
            // Matched case-insensitively, which is not pedantry: on PostgreSQL `=` is
            // case-sensitive, so a mail addressed to `Name@example.com` while the account
            // says `name@example.com` was invisible here — and an empty panel is
            // indistinguishable from an account nothing was ever sent to. MySQL's default
            // collation folds case already, so this could only ever show up on one engine.
            //
            // Still the *current* address, which is the limit worth knowing: mail sent to an
            // address this account used before it was changed does not appear. Which is why
            // the panel names the address it matched on rather than saying "this address":
            // a zero nobody can check is the shape of every "the screen is broken" report.
            'emails' => $email === '' ? [] : $rows(fn ($qb) => $qb->table('#PREFIX#mails')
                ->whereRaw('LOWER(tomail) = ?', [strtolower($email)])
                ->orderBy('date', 'desc')->limit(10)->get()),
            'emailCount' => $email === '' ? 0 : $count(fn ($qb) => $qb->table('#PREFIX#mails')
                ->whereRaw('LOWER(tomail) = ?', [strtolower($email)])->count()),
            'emailAddress' => $email,

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
     * What each user type is, and what it may do by default.
     *
     * A reference screen, because the answer was previously spread across twelve
     * controllers' `requiredUserType` declarations, the administration area's own floor in
     * `app.php`, and nothing that named any of it. An operator deciding which type to give
     * somebody had no document to read.
     *
     * It renders the registry, so an application that declared its own types, tones or
     * capabilities sees its own answer — this screen cannot fall out of step with the
     * behaviour, because it is reading what the behaviour reads.
     */
    public function types(): mixed
    {
        $this->requireMinUserType($this->requiredUserType);

        $doc        = Factory::getDocument();
        $doc->title = 'User types';

        $view               = $this->getView('users');
        $view->types        = \Pramnos\User\UserTypes::labels();
        $view->tones        = \Pramnos\User\UserTypes::tones();
        $view->capabilities = \Pramnos\User\UserTypes::capabilityMap();
        // Built here and assigned once. Assigning into `$view->resolved[...]` element by
        // element is an *indirect modification of an overloaded property*: the view stores
        // its data through `__set`, so each write went to a temporary copy and was
        // discarded. PHP says so as a notice, which nobody reads on a rendered page — the
        // column simply came out empty for every band.
        $resolved = [];
        foreach (\Pramnos\User\UserTypes::labels() as $floor => $label) {
            $resolved[$floor] = \Pramnos\User\UserTypes::capabilities((int) $floor);
        }
        $view->resolved = $resolved;
        // The floor the administration area itself applies, which is a different decision
        // from any type's capabilities and the one people conflate with them.
        $view->areaFloor = \Pramnos\Http\AdminArea::minUserType();

        return $view->display('types');
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
        /**
         * Every column the framework's own schema gives a user.
         *
         * The form had eight of them. The rest — phone, mobile, language, timezone —
         * exist in `users`, are written by the account screens the user sees, and an
         * operator correcting a typo in somebody's phone number had no way to do it.
         */
        $view->user            = [
            'userid'    => (int) ($user->userid ?? 0),
            'username'  => (string) ($user->username ?? ''),
            'email'     => (string) ($user->email ?? ''),
            'usertype'  => (int) ($user->usertype ?? 1),
            'firstname' => (string) ($user->firstname ?? ''),
            'lastname'  => (string) ($user->lastname ?? ''),
            'phone'     => (string) ($user->phone ?? ''),
            'mobile'    => (string) ($user->mobile ?? ''),
            'language'  => (string) ($user->language ?? ''),
            'timezone'  => (string) ($user->timezone ?? ''),
            'active'    => (int) ($user->active ?? 1),
            'validated' => (int) ($user->validated ?? 1),
        ];
        $view->isNew   = $isNew;

        // The two things an operator edits *about* an account rather than *on* it: the
        // switches an application keeps per user, and what this user may do.
        $view->settings    = $isNew ? [] : $user->listSettings();
        $view->permissions = $isNew ? [] : $this->userPermissions($id);
        $view->usertypes   = \Pramnos\User\UserTypes::labels();

        $view->error   = $_SESSION['users_error'] ?? '';
        $view->success = $_SESSION['users_success'] ?? '';
        unset($_SESSION['users_error'], $_SESSION['users_success']);
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

        // The contact and locale columns the schema has always carried. Only written when
        // the form sent them, so a subclass rendering a shorter form does not blank a
        // column it never showed.
        foreach (['phone', 'mobile', 'language', 'timezone'] as $field) {
            if (array_key_exists($field, $_POST)) {
                $user->$field = trim(strip_tags((string) $_POST[$field]));
            }
        }

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
     * Turn new-sign-in alerts on or off for one account.
     *
     * The preference is the user's own — it lives beside their other account settings —
     * and an operator needs to be able to set it too: the person asking "why did I not get
     * an email when somebody logged in as me" cannot be walked through a settings screen
     * over the phone as reliably as it can simply be turned on.
     *
     * A no-op when the site's policy is `always` or `off`, and the screen says so rather
     * than offering a switch that decides nothing.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function signinalerts(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $enabled = (string) \Pramnos\Http\Request::staticGet('enabled', '', 'get') === '1';

        if ($id < 2) {
            $this->redirect(adminUrl('users'));

            return;
        }

        try {
            \Pramnos\Auth\NewSignInAlert::setEnabledFor($id, $enabled);
            \Pramnos\Auth\ActivityLog::record($id, 'signin_alerts_' . ($enabled ? 'enabled' : 'disabled'), [
                'by' => (int) (User::getCurrentUser()->userid ?? 0),
            ]);
            $_SESSION['users_success'] = $enabled
                ? 'New-sign-in alerts turned on for this account.'
                : 'New-sign-in alerts turned off for this account.';
        } catch (\Throwable $ex) {
            $_SESSION['users_error'] = 'Could not change that: ' . $ex->getMessage();
        }

        $this->redirect(adminUrl('users/view/') . $id);
    }

    /**
     * The form for sending one user a message.
     *
     * An operator looking at an account frequently needs to *say* something to it — "your
     * export is ready", "we reset your second factor", "your account is locked because".
     * Before this the only route was the operator's own mail client, which leaves no
     * record on the account and uses whatever address they happen to type.
     *
     * Three channels, and the screen only offers the ones this account can actually receive:
     * mail needs a valid address, the in-app record needs the notifications table, and push
     * needs a VAPID pair **and** at least one browser subscribed. Offering a channel that
     * silently delivers nothing is the failure this screen exists to prevent — an operator
     * pressing Send and being told "sent" is entitled to believe it.
     *
     * The mail options are the ones the mail infrastructure has: a wrapper, an unsubscribe
     * list, open/click tracking, a Gmail action. They are here because this is where they get
     * *tried*: a template nobody has ever rendered and an action nobody has ever seen arrive
     * are both things you find out about from a real message, not from a test.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function notify(mixed $id = null): mixed
    {
        $this->requireMinUserType($this->requiredUserType);
        $id = (int) \Pramnos\Http\Request::staticGetOption();

        $user = new User();
        if ($id > 1) {
            $user->load($id);
        }
        if ((int) $user->userid < 2) {
            $this->redirect(adminUrl('users'));

            return null;
        }

        $doc        = Factory::getDocument();
        $doc->title = 'Message ' . htmlspecialchars((string) $user->username, ENT_QUOTES, 'UTF-8');

        $view       = $this->getView('users');
        $view->user = [
            'userid'    => (int) $user->userid,
            'username'  => (string) $user->username,
            'email'     => (string) $user->email,
            'firstname' => (string) $user->firstname,
            'lastname'  => (string) $user->lastname,
        ];

        $view->channels  = $this->sendChannels($user);
        $view->templates = $this->mailTemplates();
        $view->lists     = $this->mailLists();
        $view->tracking  = \Pramnos\Email\Tracking::enabled();

        return $view->display('notify');
    }

    /**
     * Which channels can actually reach this account, and why the others cannot.
     *
     * The reason travels with the answer because an operator who sees a disabled option asks
     * why, and "no browser has subscribed" and "this installation has no key pair" are two
     * completely different problems — one is the user's, one is the installation's.
     *
     * @return array<string, array{available: bool, reason: string}>
     */
    protected function sendChannels(User $user): array
    {
        $address = (string) $user->email;
        $mail    = $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;

        $pushKeys  = \Pramnos\Push\Vapid::configured();
        $pushRows  = $pushKeys ? \Pramnos\Push\Subscriptions::forUser((int) $user->userid) : [];

        return [
            'mail' => [
                'available' => $mail,
                'reason'    => $mail ? '' : 'This account has no usable email address.',
            ],
            'database' => [
                'available' => true,
                'reason'    => '',
            ],
            'push' => [
                'available' => $pushKeys && $pushRows !== [],
                'reason'    => !$pushKeys
                    ? 'This installation has no VAPID key pair — run `push:vapid-generate`.'
                    : ($pushRows === []
                        ? 'No browser has subscribed to notifications for this account.'
                        : ''),
            ],
        ];
    }

    /**
     * The mail wrappers this installation can render, by name.
     *
     * Read from the directories rather than configured, because a wrapper *is* a file: one
     * dropped into `app/emails` is available immediately, and a list that had to be maintained
     * beside it would be wrong the first time somebody added one.
     *
     * @return list<string>
     */
    protected function mailTemplates(): array
    {
        $names = [];

        foreach (\Pramnos\Email\EmailTheme::directories() as $directory) {
            foreach ((array) @glob($directory . DIRECTORY_SEPARATOR . '*.html.php') as $file) {
                $names[basename((string) $file, '.html.php')] = true;
            }
        }

        ksort($names);

        return array_keys($names);
    }

    /**
     * The unsubscribe lists this installation has records for.
     *
     * Every list anybody has ever opted out of, plus `all`. There is no registry of lists — a
     * list is whatever name a sender used — so the opt-out records are the only evidence of
     * which names are real, and a free-text field beside them is what allows a new one.
     *
     * @return list<string>
     */
    protected function mailLists(): array
    {
        $lists = [\Pramnos\Email\Unsubscribe::LIST_ALL];

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#emailoptouts')
                ->select('list')
                ->groupBy('list')
                ->get();

            while ($result && $result->fetch()) {
                $name = trim((string) ($result->fields['list'] ?? ''));

                if ($name !== '' && !in_array($name, $lists, true)) {
                    $lists[] = $name;
                }
            }
        } catch (\Throwable) {
            // No records yet, or no table. `all` is still a real list.
        }

        return $lists;
    }

    /**
     * Send the message from {@see notify()}, on the channels that were ticked.
     *
     * Recorded in the activity log — what was sent to an account, and by whom, is part of
     * that account's history, and an operator who has to ask "did anybody tell them?" is
     * asking a question the log should answer. The record names the channels, because "we
     * emailed them" and "we left a note in the app" are different answers to that question.
     *
     * The body is sent as text: an operator writing to one user is writing a sentence, not
     * building a template, and accepting markup here would make every message a place to
     * paste something that renders in somebody's mail client. Line breaks survive.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function sendnotification(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $subject = trim(strip_tags((string) \Pramnos\Http\Request::staticGet('subject', '', 'post')));
        $message = trim(strip_tags((string) \Pramnos\Http\Request::staticGet('message', '', 'post')));

        $user = new User();
        if ($id > 1) {
            $user->load($id);
        }
        if ((int) $user->userid < 2) {
            $this->redirect(adminUrl('users'));

            return;
        }

        if ($subject === '' || $message === '') {
            $_SESSION['users_error'] = 'A message needs a subject and a body.';
            $this->redirect(adminUrl('users/notify/') . $id);

            return;
        }

        $available = $this->sendChannels($user);
        $posted    = \Pramnos\Http\Request::staticGet('channels', null, 'post');

        /*
         * A form that names no channels at all means mail.
         *
         * Not a convenience: the previous version of this screen had one channel and posted no
         * `channels` field, and an application that published that view still posts nothing.
         * Read as "chose nothing", every such form would stop working the day the framework was
         * updated, with a message about channels nobody had ever seen a field for.
         */
        $requested = $posted === null ? ['mail'] : (array) $posted;
        $channels  = [];
        $refused   = [];

        foreach (['mail', 'database', 'push'] as $channel) {
            if (!in_array($channel, $requested, true)) {
                continue;
            }

            if ($available[$channel]['available'] ?? false) {
                $channels[] = $channel;

                continue;
            }

            $reason = trim((string) ($available[$channel]['reason'] ?? ''));

            if ($reason !== '') {
                $refused[] = $reason;
            }
        }

        if ($channels === []) {
            /*
             * Refused rather than sent to nothing.
             *
             * A tick on a channel this account cannot receive is dropped above, so "everything
             * I asked for was unavailable" reaches here as an empty list — and reporting that
             * as a successful send is how an operator concludes somebody was told.
             *
             * The channel's own reason is reported, not a summary of it: "no browser has
             * subscribed" and "this installation has no key pair" need different people to do
             * different things, and «could not send» tells neither of them which.
             */
            $_SESSION['users_error'] = $refused === []
                ? 'Choose at least one way to reach this account.'
                : implode(' ', array_unique($refused));
            $this->redirect(adminUrl($refused === [] ? 'users/notify/' : 'users/view/') . $id);

            return;
        }

        $operator = User::getCurrentUser();

        try {
            $notification = $this->composeMessage($subject, $message, $channels);

            (new \Pramnos\Notification\Notifier())->sendNow($user, $notification);

            \Pramnos\Auth\ActivityLog::record($id, 'operator_message_sent', [
                'subject'  => $subject,
                'by'       => $operator ? (int) $operator->userid : 0,
                'channels' => $channels,
                'sent'     => true,
            ]);

            $_SESSION['users_success'] = 'Message sent on ' . implode(', ', $channels) . '.';
        } catch (\Throwable $ex) {
            $_SESSION['users_error'] = 'Could not send: ' . $ex->getMessage();
        }

        $this->redirect(adminUrl('users/view/') . $id);
    }

    /**
     * Build the notification from the form, with whichever mail options were asked for.
     *
     * `sendNow()` rather than `send()`: an operator who pressed Send is standing in front of
     * the screen and needs to know whether it worked, and a queued message answers that
     * question minutes later on a page nobody is looking at.
     *
     * @param list<string> $channels
     */
    protected function composeMessage(string $subject, string $message, array $channels): \Pramnos\Notification\Message
    {
        $body = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $notification = (new \Pramnos\Notification\Message($subject, $body))->to(...$channels);

        $link = trim((string) \Pramnos\Http\Request::staticGet('link', '', 'post'));

        if ($link !== '' && filter_var($link, FILTER_VALIDATE_URL) !== false) {
            $notification->link($link);
        }

        $template = (string) \Pramnos\Http\Request::staticGet('template', '__default__', 'post');

        if ($template !== '__default__') {
            // `''` is a real answer here — "no wrapper for this one" — so it cannot be
            // conflated with "not specified", which is why the default is a sentinel.
            $notification->template($template);
        }

        $list = trim((string) \Pramnos\Http\Request::staticGet('list', '', 'post'));

        if ($list !== '') {
            $notification->list($list);
        }

        if ((string) \Pramnos\Http\Request::staticGet('tracking', '', 'post') !== '') {
            $notification->track();
        }

        $action = trim((string) \Pramnos\Http\Request::staticGet('action_type', '', 'post'));
        $name   = trim((string) \Pramnos\Http\Request::staticGet('action_name', '', 'post'));
        $url    = trim((string) \Pramnos\Http\Request::staticGet('action_url', '', 'post'));

        if ($action !== '' && $name !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            $notification->action(match ($action) {
                'confirm' => \Pramnos\Email\Actions::confirm($name, $url),
                'save'    => \Pramnos\Email\Actions::save($name, $url),
                default   => \Pramnos\Email\Actions::view($name, $url),
            });
        }

        return $notification;
    }

    /**
     * Create or replace one per-user setting.
     *
     * The switches an application keeps about an account — a feature flag, a quota, a
     * default — live in `usersettings`, and an operator answering "why is this account
     * behaving like that" has to be able to see and change them. Before this the only
     * way in was a database client.
     *
     * The value is taken as text and stored as JSON: text that *is* JSON is stored as the
     * structure it describes, so a list stays a list, and anything else is stored as a
     * string. That is what lets one form field serve a flag, a number and a list without
     * asking the operator to know which is which.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function savesetting(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $setting = trim((string) \Pramnos\Http\Request::staticGet('setting', '', 'post'));
        $raw     = (string) \Pramnos\Http\Request::staticGet('value', '', 'post');

        if ($id < 2 || $setting === '') {
            $_SESSION['users_error'] = 'A setting needs a name.';
            $this->redirect(adminUrl('users/edit/') . max(0, $id));

            return;
        }

        // Text that is JSON is stored as the structure; anything else as a string. A
        // form field cannot ask which, and guessing wrong in this direction is harmless:
        // `"1"` and `1` read the same for a flag, and a mistyped object stays readable.
        $decoded = json_decode($raw, true);
        $value   = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;

        $user = new User();
        $user->load($id);
        $operator = User::getCurrentUser();

        $user->setSetting($setting, $value, $operator ? (int) $operator->userid : null)
            ? $_SESSION['users_success'] = 'Setting saved.'
            : $_SESSION['users_error']   = 'Could not save that setting.';

        $this->redirect(adminUrl('users/edit/') . $id);
    }

    /**
     * Remove one per-user setting.
     *
     * Deleted rather than blanked: no row means the application's own default applies
     * again, and a null value means somebody deliberately set it to nothing. An operator
     * undoing a change wants the first.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function deletesetting(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $setting = (string) \Pramnos\Http\Request::staticGet('setting', '', 'get');

        if ($id < 2 || $setting === '') {
            $this->redirect(adminUrl('users'));

            return;
        }

        $user = new User();
        $user->load($id);
        $user->deleteSetting($setting);
        $_SESSION['users_success'] = 'Setting removed.';

        $this->redirect(adminUrl('users/edit/') . $id);
    }

    /**
     * Grant one permission to this user.
     *
     * The permission store has always been able to hold this — `authserver.permissions`,
     * subject/object/action — and the only screen that wrote to it was the permissions
     * list, which asks for a user id in a field. Granting from the user's own screen is
     * the direction an operator actually works in: they are looking at a person and
     * deciding what that person may do.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function grantpermission(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id         = (int) \Pramnos\Http\Request::staticGetOption();
        $objectType = trim((string) \Pramnos\Http\Request::staticGet('object_type', '', 'post'));
        $action     = trim((string) \Pramnos\Http\Request::staticGet('action', '', 'post'));
        $objectId   = trim((string) \Pramnos\Http\Request::staticGet('object_id', '', 'post'));
        $grantType  = \Pramnos\Http\Request::staticGet('grant_type', 'allow', 'post') === 'deny'
            ? 'deny'
            : 'allow';

        if ($id < 2 || $objectType === '' || $action === '') {
            $_SESSION['users_error'] = 'A permission needs an object type and an action.';
            $this->redirect(adminUrl('users/edit/') . max(0, $id));

            return;
        }

        $operator = User::getCurrentUser();

        try {
            \Pramnos\Database\Database::getInstance()->queryBuilder()
                ->table('authserver.permissions')
                ->insert([
                    'subject_type' => 'user',
                    'subject_id'   => $id,
                    'object_type'  => $objectType,
                    'object_id'    => $objectId !== '' ? $objectId : null,
                    'action'       => $action,
                    // A deny is not decoration: the resolver treats an explicit deny as
                    // final, which is how one account is excluded from something its
                    // usertype otherwise allows.
                    'grant_type'   => $grantType,
                    'priority'     => max(0, (int) \Pramnos\Http\Request::staticGet('priority', 100, 'post', 'int')),
                    'granted_by'   => $operator ? (int) $operator->userid : null,
                ]);
            \Pramnos\Auth\ActivityLog::record($id, 'permission_granted', [
                'object_type' => $objectType,
                'action'      => $action,
                'grant_type'  => $grantType,
                'by'          => $operator ? (int) $operator->userid : 0,
            ]);
            $_SESSION['users_success'] = 'Permission ' . $grantType . 'ed.';
        } catch (\Throwable $ex) {
            $_SESSION['users_error'] = 'Could not save that permission: ' . $ex->getMessage();
        }

        $this->redirect(adminUrl('users/edit/') . $id);
    }

    /**
     * Remove one permission row from this user.
     *
     * By its own id, and matched on the user as well: a request naming somebody else's
     * grant removes nothing rather than removing theirs.
     *
     * @param string|int|null $id User ID (resolved via Request::staticGetOption)
     */
    public function revokepermission(mixed $id = null): void
    {
        $this->requireMinUserType($this->requiredUserType);
        $id           = (int) \Pramnos\Http\Request::staticGetOption();
        $permissionId = (int) \Pramnos\Http\Request::staticGet('permission', 0, 'get', 'int');

        if ($id < 2 || $permissionId < 1) {
            $this->redirect(adminUrl('users'));

            return;
        }

        try {
            $affected = \Pramnos\Database\Database::getInstance()->queryBuilder()
                ->table('authserver.permissions')
                ->where('permissionid', $permissionId)
                ->where('subject_type', 'user')
                ->where('subject_id', $id)
                ->delete();

            if ($affected) {
                \Pramnos\Auth\ActivityLog::record($id, 'permission_revoked', [
                    'permission' => $permissionId,
                    'by'         => (int) (User::getCurrentUser()->userid ?? 0),
                ]);
            }
            $_SESSION['users_success'] = $affected
                ? 'Permission removed.'
                : 'That permission does not belong to this user.';
        } catch (\Throwable $ex) {
            $_SESSION['users_error'] = 'Could not remove that permission: ' . $ex->getMessage();
        }

        $this->redirect(adminUrl('users/edit/') . $id);
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

        /**
         * With no id, every session — which is the screen the dashboard's "Active users
         * (now)" figure comes from.
         *
         * It used to filter on `userid = $id` unconditionally, so opening it without one
         * asked for `userid = 0` and answered with guests, under the title "Sessions: ".
         * The number on the dashboard therefore had no screen behind it at all: the only
         * route in was a per-account link, and "who is on the site right now" — the question
         * an operator opens a dashboard to ask — could not be answered anywhere.
         */
        $db      = \Pramnos\Database\Database::getInstance();
        $query   = $db->queryBuilder()->table('#PREFIX#sessions');

        if ($id > 0) {
            $query->where('userid', $id);
        }

        // The sessions table tracks last activity in the `time` column (unix ts)
        // and marks terminated sessions with logout=1 — there is no `date` column,
        // so ordering by it silently returned no rows. Show active sessions first.
        $sessionList = $query->orderBy('time', 'desc')->limit($id > 0 ? 200 : 500)->getAll();

        $doc->title = $id > 0
            ? 'Sessions: ' . htmlspecialchars($user->username ?? '', ENT_QUOTES, 'UTF-8')
            : 'Active sessions';

        $view              = $this->getView('users');
        $view->action      = 'sessions';
        // Include userid so the breadcrumb can link the username crumb back to
        // the user's detail page (users/view/:id).
        $view->user        = ['userid' => (int) ($user->userid ?? 0), 'username' => (string) ($user->username ?? '')];
        $view->sessionList = $sessionList;
        // Whether this is one account's list or the whole site's, which decides both the
        // heading and whether the table needs a column saying whose session each row is.
        $view->scopedToUser = $id > 0;
        // The window the dashboard's "now" figure uses, so the screen and the number cannot
        // disagree about what "active" means.
        $view->activeWindow = \Pramnos\Application\Statistics\ActiveUsersService::WINDOW_NOW;

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

}
