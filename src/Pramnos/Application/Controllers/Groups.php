<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;

/**
 * `/admin/Groups` — user groups, the optional `usergroups` feature.
 *
 * A group is a named set of accounts. Two things read membership: `Permissions`, which grants
 * to a group as well as to a user (`User::getGroups()`), and the mass-message screen, which
 * offers a group as an audience. This screen is where groups are made and filled.
 *
 * Found by the framework's controller fallback, so a project that enables the feature later
 * (`project:reconfigure --enable-feature=usergroups`) has the screen without scaffolding
 * anything. An application class named `Groups` replaces it.
 *
 * Actions:
 *   - display()              — every group, with its member count, in a datatable
 *   - data()                 — POST: a page of the datatable's rows, as JSON
 *   - members($id)           — POST: a page of a group's members, as JSON
 *   - view($id)              — one group and its members
 *   - edit($id)              — create (no id) or edit form
 *   - save()                 — POST: create or update
 *   - delete($id)            — POST: the group and its memberships; refused while in use
 *   - addmember($id)         — POST: a member, by username, email or id
 *   - removemember($id)      — POST: a member, by id
 *   - addrole($id)           — POST: a role the group holds, so every member holds it
 *   - removerole($id)        — POST: withdraw it (kept, switched off, as user_roles does)
 */
class Groups extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.groups';

    /** One record of this screen, for the side panel: see Controller::$assistSubject. */
    protected string $assistSubject = 'group';

    /** The usertype floor; 0 is the administration area's — {@see \Pramnos\Auth\AdminAccess::defaultUsertype()}, the `super` role unless set. */
    protected int $requiredUserType = 0;

    /** The SQL-cache category `User::getGroups()` reads under, flushed on every membership change. */
    public const CACHE_CATEGORY = 'usergroups';

    /** Roles held by a group; `PermissionResolver` counts them for every member. */
    public const ROLES_TABLE = 'authserver.group_roles';

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'display', 'data', 'members', 'view', 'edit', 'save', 'delete', 'addmember', 'removemember', 'addrole', 'removerole',
        ]);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['save', 'delete', 'addmember', 'removemember', 'addrole', 'removerole']);
        parent::__construct($application);
    }

    // ── Screens ──────────────────────────────────────────────────────────────

    /**
     * Every group, with its number, its order and how many members it has, in a datatable whose
     * rows {@see data()} answers — by the order first, as the groups were arranged.
     */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }

        \Pramnos\Framework\Factory::getDocument()->title = 'Groups';

        $table             = new \Pramnos\Html\Datatable('dt-groups');
        $table->source     = adminUrl('Groups/data');
        $table->bootstrap  = false;
        $table->sortColumn = 3;
        $table->sortOrder  = 'asc';
        $table->addColumn('#', true, true, false)->addColumn('Name', true, true, false)->addColumn('Description', true, true, false)
            ->addColumn('Order', true, true, false)->addColumn('Members', true, true, false);

        $view = $this->getView('groups');
        $view->datatable = $table;

        return $view->display();
    }

    /**
     * The groups, a page at a time: searched by number, name and description, sorted by any
     * column, the members counted in one query.
     */
    public function data(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument('json');
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return \Pramnos\Http\Response::json(\Pramnos\Html\Datatable\ArraySource::answer(
            self::all(),
            [0 => 'id', 1 => 'name', 2 => 'description', 3 => 'orderKey', 4 => 'members'],
            static fn (array $row): string => '#' . $row['id'] . ' ' . $row['name'] . ' ' . $row['description'],
            static fn (array $page): array => array_map(static fn (array $row): array => [
                (string) $row['id'],
                '<a href="' . $e(adminUrl('Groups/view/') . $row['id']) . '">' . $e($row['name']) . '</a>',
                $e($row['description']),
                $row['order'] === null ? '' : (string) $row['order'],
                (string) $row['members'],
            ], $page),
        ));
    }

    /**
     * Every group with its member count, in one query, by its order and then its name — a group
     * with no order after those with one. `orderKey` is what sorts by the order.
     *
     * @return list<array{id: int, name: string, description: string, order: ?int, orderKey: int, members: int}>
     */
    public static function all(): array
    {
        $rows = array_map(static fn (array $row): array => ['id' => (int) $row['groupid'], 'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''), 'order' => $row['order'] === null ? null : (int) $row['order'],
            'orderKey' => $row['order'] === null ? PHP_INT_MAX : (int) $row['order'], 'members' => (int) $row['members']],
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#usergroups AS usergroups')
                ->leftJoin('#PREFIX#userstogroups AS userstogroups', 'userstogroups.groupid', '=', 'usergroups.groupid')
                ->select(['usergroups.groupid', 'usergroups.name', 'usergroups.description', 'usergroups.order', new \Pramnos\Database\Expression('COUNT(userstogroups.userid) AS members')])
                ->groupBy(['usergroups.groupid', 'usergroups.name', 'usergroups.description', 'usergroups.order'])->orderBy('usergroups.name')->getAllOrFail());
        usort($rows, static fn (array $a, array $b): int => $a['orderKey'] <=> $b['orderKey']);

        return $rows;
    }

    /**
     * One group and the accounts in it.
     */
    public function view(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }

        $group = $this->group((int) \Pramnos\Http\Request::staticGetOption());
        if ($group === null) {
            return $this->gone();
        }

        \Pramnos\Framework\Factory::getDocument()->title = (string) $group['name'];

        $members             = new \Pramnos\Html\Datatable('dt-group-members');
        $members->source     = adminUrl('Groups/members/') . (int) $group['groupid'];
        $members->bootstrap  = false;
        $members->sortColumn = 1;
        $members->sortOrder  = 'asc';
        $members->exportName = t('Members of %s', (string) $group['name']);
        foreach (['#', t('Username'), t('Full name'), t('Email address'), t('Joined the group'), t('Added by'), ''] as $i => $label) {
            $members->addColumn(htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), true, $i < 6, false);
        }

        $view = $this->getView('groups');
        $view->group      = $group;
        $view->members    = $members;
        $view->count      = $this->memberCount((int) $group['groupid']);
        $view->uses       = \Pramnos\Audience\Groups::uses((int) $group['groupid']);
        $view->membersUrl = self::membersUrl((int) $group['groupid']);

        // Its roles, and the ones it could be given. Absent without the roles store.
        // Not shown where the application says it gives groups no roles (`audience.group_roles`).
        $view->rolesEnabled   = ($this->application->applicationInfo['audience']['group_roles'] ?? true) !== false && $this->rolesEnabled();
        $view->roles          = [];
        $view->availableRoles = [];
        if ($view->rolesEnabled) {
            $held = [];
            foreach ($this->db()->queryBuilder()->table(self::ROLES_TABLE)
                ->where('groupid', (int) $group['groupid'])->where('is_active', true)->get() as $row) {
                $held[(int) $row['roleid']] = $row;
            }
            foreach ($this->db()->queryBuilder()->table('authserver.roles')
                ->select(['roleid', 'role_name', 'description'])->where('is_active', true)
                ->orderBy('role_name', 'asc')->get() as $role) {
                if (isset($held[(int) $role['roleid']])) {
                    $view->roles[] = $role + ['expires_at' => $held[(int) $role['roleid']]['expires_at'] ?? null];
                } else {
                    $view->availableRoles[] = $role;
                }
            }
        }

        // No roles anywhere to give or hold: the section would only say so.
        $view->rolesEnabled = $view->rolesEnabled && ($view->roles !== [] || $view->availableRoles !== []);

        return $view->display('view');
    }

    /**
     * A group's members, a page at a time, for its page's datatable: searched by number, username,
     * name and email, sorted by any column but the last. Paged in the database, so a group of tens
     * of thousands reads one page.
     */
    public function members(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument('json');
        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $query   = $this->db()->queryBuilder()->table('#PREFIX#userstogroups AS userstogroups')
            ->join('#PREFIX#users AS users', 'users.userid', '=', 'userstogroups.userid')
            ->where('userstogroups.groupid', $id);
        $history = \Pramnos\Audience\Groups::keepsHistory();
        $total   = (clone $query)->count();
        $search  = trim((string) (is_array($_POST['search'] ?? null) ? ($_POST['search']['value'] ?? '') : ($_POST['search'] ?? '')));
        if ($search !== '') {
            $like = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($w) use ($like, $search): void {
                foreach (['users.username', 'users.email', 'users.firstname', 'users.lastname'] as $column) {
                    $w->orWhereRaw('LOWER(' . $column . ') LIKE ?', [$like]);
                }
                if (ctype_digit(ltrim($search, '#'))) {
                    $w->orWhere('users.userid', (int) ltrim($search, '#'));
                }
            });
        }
        $filtered = $search === '' ? $total : (clone $query)->count();
        $columns  = [0 => 'users.userid', 1 => 'users.username', 2 => 'users.lastname', 3 => 'users.email']
            + ($history ? [4 => 'userstogroups.addedat', 5 => 'adder.username'] : []);
        if ($history) {
            $query->leftJoin('#PREFIX#users AS adder', 'adder.userid', '=', 'userstogroups.addedby');
        }
        foreach ((array) ($_POST['order'] ?? []) as $o) {
            if (isset($columns[(int) ($o['column'] ?? -1)])) {
                $query->orderBy($columns[(int) $o['column']], ($o['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
            }
        }
        $length = (int) ($_POST['length'] ?? 50);
        if ($length !== -1) {
            $query->limit(max(1, $length))->offset(max(0, (int) ($_POST['start'] ?? 0)));
        }
        $e     = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $token = \Pramnos\Http\Session::getInstance()->getTokenField();
        $rows  = array_map(static fn (array $m): array => [
            (string) (int) $m['userid'],
            '<a href="' . $e(\Pramnos\Html\AudienceBuilder::personUrl((int) $m['userid'])) . '">' . $e((string) $m['username']) . '</a>',
            '<span class="pf-nowrap">' . $e(trim($m['firstname'] . ' ' . $m['lastname'])) . '</span>',
            $e((string) $m['email']),
            ($m['addedat'] ?? null) !== null ? '<span class="pf-nowrap">' . $e(date('d/m/Y H:i', (int) $m['addedat'])) . '</span>' : '<span class="pf-zero">—</span>',
            ($m['addername'] ?? null) !== null ? $e((string) $m['addername'])
                : (($m['addedsource'] ?? '') !== '' && ($m['addedsource'] ?? null) !== null ? '<span class="pf-muted">' . $e((string) $m['addedsource']) . '</span>' : '<span class="pf-zero">—</span>'),
            '<form method="post" action="' . $e(adminUrl('Groups/removemember/') . $id) . '" style="display:inline;margin:0">' . $token
                . '<input type="hidden" name="userid" value="' . (int) $m['userid'] . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="' . $e(t('Remove this member from the group?')) . '">' . $e(t('Remove from the group')) . '</button></form>',
        ], $query->select(array_merge(['users.userid', 'users.username', 'users.email', 'users.firstname', 'users.lastname'],
            $history ? ['userstogroups.addedat', 'userstogroups.addedsource', 'adder.username AS addername'] : []))->getAllOrFail());

        return \Pramnos\Http\Response::json(['draw' => (int) ($_POST['draw'] ?? 1), 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $rows]);
    }

    /**
     * Where a group's name leads, wherever the administration shows one — the library, a person's
     * card: its page, or with `'audience' => ['group_link' => 'members']` the application's own
     * list of its members (`group_members_url`), where there is one.
     */
    public static function linkFor(int $id): string
    {
        $app = \Pramnos\Application\Application::currentInstance();
        if (is_object($app) && ($app->applicationInfo['audience']['group_link'] ?? 'page') === 'members') {
            $members = self::membersUrl($id);
            if ($members !== null) {
                return $members;
            }
        }

        return adminUrl('Groups/view/') . $id;
    }

    /**
     * Where an application lists a group's members with its own tools — `audience.group_members_url`
     * in app.php, `{id}` for the group — or null where it has none.
     */
    public static function membersUrl(int $id): ?string
    {
        $app      = \Pramnos\Application\Application::currentInstance();
        $template = is_object($app) ? (string) ($app->applicationInfo['audience']['group_members_url'] ?? '') : '';

        return $template === '' ? null : str_replace('{id}', (string) $id, $template);
    }

    /**
     * The form: a new group with no id, an existing one with.
     */
    public function edit(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }

        $id    = (int) \Pramnos\Http\Request::staticGetOption();
        $group = null;
        if ($id > 0) {
            $group = $this->group($id);
            if ($group === null) {
                return $this->gone();
            }
        }

        \Pramnos\Framework\Factory::getDocument()->title = $group === null ? 'New group' : 'Edit group';

        $view = $this->getView('groups');
        $view->group = $group;

        return $view->display('edit');
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    public function save(): void
    {
        if ($this->refused()) {
            return;
        }

        $id          = (int) ($_POST['groupid'] ?? 0);
        $name        = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($name === '' || mb_strlen($name) > 80) {
            $this->addError($name === '' ? t('A group needs a name.') : t('A group name is at most 80 characters.'));
            $this->redirect(adminUrl('Groups/edit/') . ($id > 0 ? $id : ''));
            return;
        }

        $order = trim((string) ($_POST['order'] ?? ''));
        if ($order !== '' && (!preg_match('/^-?\d+$/', $order) || (int) $order < -127 || (int) $order > 127)) {
            $this->addError(t('The order is a whole number from -127 to 127, or empty.'));
            $this->redirect(adminUrl('Groups/edit/') . ($id > 0 ? $id : ''));
            return;
        }

        $fields = ['name' => $name, 'description' => $description, 'order' => $order === '' ? null : (int) $order];
        $table  = $this->db()->queryBuilder()->table('#PREFIX#usergroups');

        if ($id > 0) {
            if ($this->group($id) === null) {
                $this->gone();
                return;
            }
            $table->where('groupid', $id)->update($fields);
        } else {
            $table->insert($fields);
            $id = (int) $this->db()->getInsertId();
        }

        $this->addMessage(t('The group is saved.'));
        $this->redirect(adminUrl('Groups/view/') . $id);
    }

    /**
     * The group, and its memberships with it.
     *
     * Deleted here rather than left to a foreign key: an installation that built these
     * tables itself may not have one, and a membership row pointing at no group would
     * still grant whatever that group id is given next.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($this->group($id) === null) {
            $this->gone();
            return;
        }
        $uses = \Pramnos\Audience\Groups::uses($id);
        if ($uses !== []) {
            $this->addError(t('This group is in use, so it is not deleted: %s.', implode(', ', array_column($uses, 'name'))));
            $this->redirect(adminUrl('Groups/view/') . $id);
            return;
        }

        // Told before the rows go: the members are found from them.
        if ($this->holdsRoles($id)) {
            $this->permissionsChangedFor($this->memberIds($id), ['operation' => 'group_deleted', 'groupid' => $id]);
        }
        if ($this->rolesEnabled()) {
            $this->db()->queryBuilder()->table(self::ROLES_TABLE)->where('groupid', $id)->delete();
        }
        $this->db()->queryBuilder()->table('#PREFIX#userstogroups')->where('groupid', $id)->delete();
        $this->db()->queryBuilder()->table('#PREFIX#usergroups')->where('groupid', $id)->delete();
        \Pramnos\Audience\Groups::forget($id);
        $this->db()->cacheflush(self::CACHE_CATEGORY);

        $this->addMessage(t('Group deleted.'));
        $this->redirect(adminUrl('Groups'));
    }

    /**
     * Add an account, found by username, email or id — whichever the operator has to hand.
     */
    public function addmember(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }

        $id    = (int) \Pramnos\Http\Request::staticGetOption();
        $group = $this->group($id);
        if ($group === null) {
            $this->gone();
            return;
        }

        $userId = $this->findUser(trim((string) ($_POST['user'] ?? '')));
        if ($userId === null) {
            $this->addError(t('No account matches that username, email or id.'));
            $this->redirect(adminUrl('Groups/view/') . $id);
            return;
        }

        // One path with the bulk action and rules: the cache, the permissions and `group.joined`.
        if (\Pramnos\Audience\Groups::add($id, $userId, $this->operator()) === 0) {
            $this->addMessage(t('Already a member.'));
        } else {
            $this->addMessage(t('Member added.'));
        }

        $this->redirect(adminUrl('Groups/view/') . $id);
    }

    public function removemember(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }

        $id     = (int) \Pramnos\Http\Request::staticGetOption();
        $userId = (int) ($_POST['userid'] ?? 0);
        if ($this->group($id) === null) {
            $this->gone();
            return;
        }

        \Pramnos\Audience\Groups::remove($id, $userId, $this->operator());

        $this->addMessage(t('Member removed.'));
        $this->redirect(adminUrl('Groups/view/') . $id);
    }

    /**
     * Give the group a role, and so every member.
     *
     * A withdrawn assignment is switched back on rather than duplicated, as
     * `Role::assignTo()` does for a user.
     */
    public function addrole(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }

        $id     = (int) \Pramnos\Http\Request::staticGetOption();
        $roleId = (int) ($_POST['roleid'] ?? 0);
        if ($this->group($id) === null) {
            $this->gone();
            return;
        }
        if (!$this->rolesEnabled() || !$this->activeRoleExists($roleId)) {
            $this->addError(t('That role does not exist or is switched off.'));
            $this->redirect(adminUrl('Groups/view/') . $id);
            return;
        }

        $grantedBy = \Pramnos\User\User::current()?->userid;
        $existing  = $this->db()->queryBuilder()->table(self::ROLES_TABLE)
            ->where('groupid', $id)->where('roleid', $roleId)->first();
        if ($existing && $existing->numRows > 0) {
            $this->db()->queryBuilder()->table(self::ROLES_TABLE)
                ->where('groupid', $id)->where('roleid', $roleId)
                ->update(['is_active' => true, 'granted_by' => $grantedBy]);
        } else {
            $this->db()->queryBuilder()->table(self::ROLES_TABLE)->insert([
                'groupid' => $id, 'roleid' => $roleId, 'granted_by' => $grantedBy, 'is_active' => true,
            ]);
        }

        $this->permissionsChangedFor($this->memberIds($id), [
            'operation' => 'group_role_assigned', 'groupid' => $id, 'roleid' => $roleId,
        ]);
        $this->addMessage(t('Role given to the group.'));
        $this->redirect(adminUrl('Groups/view/') . $id);
    }

    /**
     * Withdraw a role from the group. Switched off, not deleted, so the record stays.
     */
    public function removerole(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }

        $id     = (int) \Pramnos\Http\Request::staticGetOption();
        $roleId = (int) ($_POST['roleid'] ?? 0);
        if ($this->group($id) === null) {
            $this->gone();
            return;
        }

        if ($this->rolesEnabled()) {
            $this->db()->queryBuilder()->table(self::ROLES_TABLE)
                ->where('groupid', $id)->where('roleid', $roleId)->update(['is_active' => false]);
            $this->permissionsChangedFor($this->memberIds($id), [
                'operation' => 'group_role_revoked', 'groupid' => $id, 'roleid' => $roleId,
            ]);
        }

        $this->addMessage(t('Role withdrawn from the group.'));
        $this->redirect(adminUrl('Groups/view/') . $id);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** Whether roles can be given to groups here: both the table and the roles store exist. */
    protected function rolesEnabled(): bool
    {
        $schema = $this->db()->schema();

        return $schema->hasTable(self::ROLES_TABLE) && $schema->hasTable('authserver.roles');
    }

    /** Whether the group holds any active role — whether a membership change changes permissions. */
    protected function holdsRoles(int $groupId): bool
    {
        if (!$this->rolesEnabled()) {
            return false;
        }

        return $this->db()->queryBuilder()->table(self::ROLES_TABLE)
            ->where('groupid', $groupId)->where('is_active', true)->count() > 0;
    }

    protected function activeRoleExists(int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }

        return $this->db()->queryBuilder()->table('authserver.roles')
            ->where('roleid', $roleId)->where('is_active', true)->count() > 0;
    }

    /** @return list<int> */
    protected function memberIds(int $groupId): array
    {
        $ids = [];
        foreach ($this->db()->queryBuilder()->table('#PREFIX#userstogroups')
            ->select(['userid'])->where('groupid', $groupId)->get() as $row) {
            $ids[] = (int) $row['userid'];
        }

        return $ids;
    }

    /**
     * Tell the applications these users use that their permissions changed.
     *
     * Per user, as `Role::assignTo()` does: the event carries a user, and the applications
     * to tell are found from that user's authorisations.
     *
     * @param list<int> $userIds
     * @param array<string, mixed> $context
     */
    protected function permissionsChangedFor(array $userIds, array $context): void
    {
        foreach (array_unique($userIds) as $userId) {
            \Pramnos\Auth\WebhookService::permissionsChanged('user', (int) $userId, $context);
        }
    }

    // ── Lookups ──────────────────────────────────────────────────────────────

    /** The signed-in account's id, recorded as who changed a membership. */
    protected function operator(): ?int
    {
        $user = \Pramnos\User\User::current();

        return $user ? (int) $user->userid : null;
    }

    /**
     * Whether this request stops here: below the screen's floor, or no groups here.
     *
     * The check is here as well as on the menu item, because a URL can be typed. Without the
     * feature or its tables, the answer is a page that says so rather than a database error.
     */
    protected function refused(): bool
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return true;
        }

        if (!$this->groupsHere()) {
            $this->addError(t('User groups are not enabled on this site.'));
            $this->redirect(adminUrl(''));
            return true;
        }

        return false;
    }

    /**
     * Whether groups are here: the `usergroups` feature, or its tables made some other way — an
     * application that had them before the feature, and so does not enable it to have its
     * migrations make them again.
     */
    protected function groupsHere(): bool
    {
        return \Pramnos\Application\FeatureRegistry::isEnabled('usergroups') || $this->db()->schema()->hasTable('usergroups');
    }

    /** @return array<string, mixed>|null */
    protected function group(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $result = $this->db()->queryBuilder()->table('#PREFIX#usergroups')->where('groupid', $id)->first();

        return $result && $result->numRows > 0 ? $result->fields : null;
    }

    protected function memberCount(int $groupId): int
    {
        return (int) $this->db()->queryBuilder()->table('#PREFIX#userstogroups')
            ->where('groupid', $groupId)->count();
    }

    /**
     * An account by username or email (case-insensitively) or by id.
     */
    protected function findUser(string $needle): ?int
    {
        if ($needle === '') {
            return null;
        }

        $query = $this->db()->queryBuilder()->table('#PREFIX#users')->select(['userid']);
        if (ctype_digit($needle)) {
            $query->where('userid', (int) $needle);
        } else {
            $query->whereRaw('(LOWER(username) = ? OR LOWER(email) = ?)', [mb_strtolower($needle), mb_strtolower($needle)]);
        }
        $result = $query->first();
        $userId = $result && $result->numRows > 0 ? (int) $result->fields['userid'] : 0;

        // 0 is the guest and 1 the system account; neither belongs in a group.
        return $userId > 1 ? $userId : null;
    }

    protected function gone(): mixed
    {
        $this->addError(t('That group no longer exists.'));
        $this->redirect(adminUrl('Groups'));

        return null;
    }

    protected function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
