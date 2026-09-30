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
 *   - display()              — every group, with its member count
 *   - view($id)              — one group and its members
 *   - edit($id)              — create (no id) or edit form
 *   - save()                 — POST: create or update
 *   - delete($id)            — POST: the group and its memberships
 *   - addmember($id)         — POST: a member, by username, email or id
 *   - removemember($id)      — POST: a member, by id
 *   - addrole($id)           — POST: a role the group holds, so every member holds it
 *   - removerole($id)        — POST: withdraw it (kept, switched off, as user_roles does)
 */
class Groups extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.groups';

    protected int $requiredUserType = 80;

    /** The SQL-cache category `User::getGroups()` reads under, flushed on every membership change. */
    public const CACHE_CATEGORY = 'usergroups';

    /** Roles held by a group; `PermissionResolver` counts them for every member. */
    public const ROLES_TABLE = 'authserver.group_roles';

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'display', 'view', 'edit', 'save', 'delete', 'addmember', 'removemember', 'addrole', 'removerole',
        ]);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['save', 'delete', 'addmember', 'removemember', 'addrole', 'removerole']);
        parent::__construct($application);
    }

    // ── Screens ──────────────────────────────────────────────────────────────

    /**
     * Every group, with how many members it has.
     */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }

        \Pramnos\Framework\Factory::getDocument()->title = 'Groups';

        $groups = [];
        $result = $this->db()->queryBuilder()->table('#PREFIX#usergroups')
            ->orderBy('name', 'asc')->get();
        foreach ($result as $row) {
            $row['members'] = $this->memberCount((int) $row['groupid']);
            $groups[] = $row;
        }

        $view = $this->getView('groups');
        $view->groups = $groups;

        return $view->display();
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

        \Pramnos\Framework\Factory::getDocument()->title = 'Group: ' . $group['name'];

        $members = [];
        $ids     = $this->db()->queryBuilder()->table('#PREFIX#userstogroups')
            ->where('groupid', (int) $group['groupid'])->get();
        $userIds = [];
        foreach ($ids as $row) {
            $userIds[] = (int) $row['userid'];
        }
        if ($userIds !== []) {
            $users = $this->db()->queryBuilder()->table('#PREFIX#users')
                ->select(['userid', 'username', 'email', 'firstname', 'lastname'])
                ->whereIn('userid', $userIds)->orderBy('username', 'asc')->get();
            foreach ($users as $row) {
                $members[] = $row;
            }
        }

        $view = $this->getView('groups');
        $view->group   = $group;
        $view->members = $members;

        // Its roles, and the ones it could be given. Absent without the roles store.
        $view->rolesEnabled   = $this->rolesEnabled();
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

        return $view->display('view');
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
            $this->addError($name === '' ? 'A group needs a name.' : 'A group name is at most 80 characters.');
            $this->redirect(adminUrl('Groups/edit/') . ($id > 0 ? $id : ''));
            return;
        }

        $fields = ['name' => $name, 'description' => $description];
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

        $this->addMessage('Saved.');
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

        // Told before the rows go: the members are found from them.
        if ($this->holdsRoles($id)) {
            $this->permissionsChangedFor($this->memberIds($id), ['operation' => 'group_deleted', 'groupid' => $id]);
        }
        if ($this->rolesEnabled()) {
            $this->db()->queryBuilder()->table(self::ROLES_TABLE)->where('groupid', $id)->delete();
        }
        $this->db()->queryBuilder()->table('#PREFIX#userstogroups')->where('groupid', $id)->delete();
        $this->db()->queryBuilder()->table('#PREFIX#usergroups')->where('groupid', $id)->delete();
        $this->db()->cacheflush(self::CACHE_CATEGORY);

        $this->addMessage('Group deleted.');
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
            $this->addError('No account matches that username, email or id.');
            $this->redirect(adminUrl('Groups/view/') . $id);
            return;
        }

        $exists = $this->db()->queryBuilder()->table('#PREFIX#userstogroups')
            ->where('groupid', $id)->where('userid', $userId)->first();
        if ($exists && $exists->numRows > 0) {
            $this->addMessage('Already a member.');
        } else {
            $this->db()->queryBuilder()->table('#PREFIX#userstogroups')
                ->insert(['userid' => $userId, 'groupid' => $id]);
            $this->db()->cacheflush(self::CACHE_CATEGORY);
            if ($this->holdsRoles($id)) {
                $this->permissionsChangedFor([$userId], ['operation' => 'group_joined', 'groupid' => $id]);
            }
            $this->addMessage('Member added.');
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

        $this->db()->queryBuilder()->table('#PREFIX#userstogroups')
            ->where('groupid', $id)->where('userid', $userId)->delete();
        $this->db()->cacheflush(self::CACHE_CATEGORY);
        if ($userId > 1 && $this->holdsRoles($id)) {
            $this->permissionsChangedFor([$userId], ['operation' => 'group_left', 'groupid' => $id]);
        }

        $this->addMessage('Member removed.');
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
            $this->addError('That role does not exist or is switched off.');
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
        $this->addMessage('Role given to the group.');
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

        $this->addMessage('Role withdrawn from the group.');
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

    /**
     * Whether this request stops here: below the screen's floor, or the feature is off.
     *
     * The feature check is here as well as on the menu item, because a URL can be typed.
     * With the feature off the tables may not exist, and the answer is a page that says
     * so rather than a database error.
     */
    protected function refused(): bool
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return true;
        }

        if (!\Pramnos\Application\FeatureRegistry::isEnabled('usergroups')) {
            $this->addError('User groups are not enabled on this site.');
            $this->redirect(adminUrl(''));
            return true;
        }

        return false;
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
        $this->addError('That group no longer exists.');
        $this->redirect(adminUrl('Groups'));

        return null;
    }

    protected function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
