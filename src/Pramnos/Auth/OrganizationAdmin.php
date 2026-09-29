<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Somebody who manages one organisation — its members, its invitations, who holds its roles —
 * and nothing beyond it.
 *
 * **Who.** Whoever is allowed `manage` on the object `organization` with the organisation's id,
 * in `authserver.permissions`: granted to the person, or to a role of that organisation they
 * hold. No separate flag — deny, expiry, priority and the audit trail are the permission
 * system's own. A superuser manages every organisation.
 *
 * ```php
 * Permissions::getInstance()->allow($userId, 'organization', 'manage', (string) $orgId);
 * OrganizationAdmin::manages($user, $orgId);   // true
 * ```
 *
 * **What, and only in that organisation.**
 *
 * - Add somebody by address: an account is made a member at once, an address with no account is
 *   sent an invitation into the organisation. Either may be given one of its roles.
 * - Remove a member. Their role assignments stay and stop counting, as leaving always does.
 * - Give or take one of the organisation's own roles — never a system-wide one, never another
 *   organisation's, and never a role that allows something the manager is not allowed
 *   themselves. Roles are made and edited by an administrator, not here.
 * - See and withdraw the organisation's invitations.
 *
 * Every change is written to the affected account's activity log and announced with
 * `permissions_changed`. {@see \Pramnos\Application\Controllers\Organization} is the screen and the
 * JSON over this.
 */
class OrganizationAdmin
{
    public const OBJECT = 'organization';
    public const ACTION = 'manage';

    public function __construct(private int $actorId)
    {
    }

    // ── Who ───────────────────────────────────────────────────────────────────

    /** Does this person manage this organisation? */
    public static function manages(?object $user, int $organizationId): bool
    {
        if ($user === null || (int) ($user->userid ?? 0) < 2 || $organizationId <= 0) {
            return false;
        }
        if ((int) ($user->usertype ?? 0) >= AdminAccess::superuserUsertype()) {
            return true;
        }

        return self::decide(self::grants((int) $user->userid, $organizationId), self::OBJECT, (string) $organizationId, self::ACTION);
    }

    /**
     * The organisations this person manages, as `id => name`.
     *
     * ponytail: one resolution per active organisation; an installation with thousands wants the
     * grants read once and matched against the ids.
     *
     * @return array<int, string>
     */
    public static function managedBy(object $user): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->schema()->hasTable('organizations')) {
            return [];
        }

        $result = $db->queryBuilder()->table('organizations')
            ->select(['organization_id', 'name'])->where('is_active', 1)->orderBy('name')->get();
        $managed = [];
        while ($result && $result->fetch()) {
            $id = (int) $result->fields['organization_id'];
            if (self::manages($user, $id)) {
                $managed[$id] = (string) $result->fields['name'];
            }
        }

        return $managed;
    }

    // ── What is there ─────────────────────────────────────────────────────────

    /** The organisation's name, or `#id` when there is none to read. */
    public static function nameOf(int $organizationId): string
    {
        $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('organizations')
            ->select(['name'])->where('organization_id', $organizationId)->first();

        return $row && $row->numRows > 0 ? (string) $row->fields['name'] : '#' . $organizationId;
    }

    /**
     * The active members, with the organisation's roles each holds.
     *
     * @return list<array{userid: int, username: string, email: string, name: string,
     *                    roles: list<array{roleid: int, role_name: string, counts: bool}>}>
     */
    public function members(int $organizationId): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $column = Role::organizationColumn();
        $result = $db->queryBuilder()->table(Role::membershipTable())
            ->select(['userid'])->where($column, $organizationId)->where('is_active', true)->get();
        $ids = [];
        while ($result && $result->fetch()) {
            $ids[] = (int) $result->fields['userid'];
        }
        if ($ids === []) {
            return [];
        }

        $users = $db->queryBuilder()->table('#PREFIX#users')
            ->select(['userid', 'username', 'email', 'firstname', 'lastname'])
            ->whereIn('userid', $ids)->orderBy('username')->get();
        $members = [];
        while ($users && $users->fetch()) {
            $id = (int) $users->fields['userid'];
            $members[] = [
                'userid'   => $id,
                'username' => (string) $users->fields['username'],
                'email'    => (string) $users->fields['email'],
                'name'     => trim($users->fields['firstname'] . ' ' . $users->fields['lastname']),
                'roles'    => array_values(array_map(
                    static fn (array $r): array => ['roleid' => $r['roleid'], 'role_name' => $r['role_name'], 'counts' => $r['counts']],
                    array_filter(Role::heldBy($id), static fn (array $r): bool => $r['organization_id'] === $organizationId)
                )),
            ];
        }

        return $members;
    }

    /**
     * The organisation's active roles, each with whether this manager may give it.
     *
     * @return list<array{roleid: int, role_name: string, grantable: bool}>
     */
    public function roles(int $organizationId): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $column = Role::organizationColumn();
        if (!$db->schema()->hasTable('authserver.roles') || !$db->schema()->hasColumn('authserver.roles', $column)) {
            return [];
        }

        $result = $db->queryBuilder()->table('authserver.roles')
            ->select(['roleid', 'role_name'])->where($column, $organizationId)->where('is_active', true)->orderBy('role_name')->get();
        $roles = [];
        while ($result && $result->fetch()) {
            $id      = (int) $result->fields['roleid'];
            $roles[] = ['roleid' => $id, 'role_name' => (string) $result->fields['role_name'], 'grantable' => $this->mayGive($id, $organizationId)];
        }

        return $roles;
    }

    /** @return list<array<string, mixed>> The organisation's invitations, newest first, with their state. */
    public function invitations(int $organizationId): array
    {
        return (new Invitations())->all($organizationId);
    }

    // ── What may be done ──────────────────────────────────────────────────────

    /**
     * Add somebody by address: a member at once if they have an account, an invitation if not.
     *
     * @return 'added'|'invited'
     * @throws \InvalidArgumentException With a sentence for the screen
     * @throws InvitationException When the invitation cannot be made
     */
    public function add(int $organizationId, string $email, ?int $roleId = null): string
    {
        $email = Invitations::normalizeEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('That is not an email address.');
        }
        if ($roleId !== null) {
            $this->assertGivable($roleId, $organizationId);
        }

        $userId = $this->accountFor($email);
        if ($userId === null) {
            (new Invitations())->invite($email, [
                'invitedBy' => $this->actorId, 'organizationId' => $organizationId, 'roleId' => $roleId,
            ]);

            return 'invited';
        }

        $column = Role::organizationColumn();
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(Role::membershipTable())->upsert(
            ['userid' => $userId, $column => $organizationId, 'granted_by' => $this->actorId, 'is_active' => 1],
            ['userid', $column],
            ['granted_by', 'is_active']
        );
        $this->record($userId, 'organization_member_added', $organizationId);
        WebhookService::permissionsChanged('user', $userId, ['operation' => 'membership_added', 'organization_id' => $organizationId]);

        if ($roleId !== null) {
            $this->giveRole($organizationId, $roleId, $userId);
        }

        return 'added';
    }

    /** Take a member out. Their assignments stay, and stop counting. */
    public function remove(int $organizationId, int $userId): bool
    {
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(Role::membershipTable())
            ->where('userid', $userId)->where(Role::organizationColumn(), $organizationId)->where('is_active', true)
            ->update(['is_active' => 0]);
        if (!is_object($result) || (int) $result->getAffectedRows() < 1) {
            return false;
        }

        $this->record($userId, 'organization_member_removed', $organizationId);
        WebhookService::permissionsChanged('user', $userId, ['operation' => 'membership_removed', 'organization_id' => $organizationId]);

        return true;
    }

    /**
     * Give a member one of the organisation's roles.
     *
     * @throws \InvalidArgumentException With a sentence for the screen
     */
    public function giveRole(int $organizationId, int $roleId, int $userId): void
    {
        $this->assertGivable($roleId, $organizationId);
        $role = $this->role($roleId);
        if (!$role->assignTo($userId, $this->actorId)) {
            throw new \InvalidArgumentException($role->getLastError());
        }
        $this->record($userId, 'organization_role_given', $organizationId, $roleId);
    }

    /**
     * Take one of the organisation's roles from a member.
     *
     * @throws \InvalidArgumentException With a sentence for the screen
     */
    public function takeRole(int $organizationId, int $roleId, int $userId): void
    {
        $this->assertGivable($roleId, $organizationId);
        $this->role($roleId)->revokeFrom($userId);
        $this->record($userId, 'organization_role_taken', $organizationId, $roleId);
    }

    /** Withdraw a waiting invitation into this organisation. */
    public function withdraw(int $organizationId, int $invitationId): bool
    {
        foreach ($this->invitations($organizationId) as $invitation) {
            if ((int) $invitation['invitation_id'] === $invitationId) {
                return (new Invitations())->revoke($invitationId);
            }
        }

        return false;
    }

    /**
     * May this manager give this role?
     *
     * It has to be the organisation's own, and everything it allows the manager has to be allowed
     * themselves — handing out more than you hold would go round every other check. A superuser
     * may give any of the organisation's roles.
     */
    public function mayGive(int $roleId, int $organizationId): bool
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $column = Role::organizationColumn();
        $row    = $db->queryBuilder()->table('authserver.roles')->select([$column, 'is_active'])->where('roleid', $roleId)->first();
        if (!$row || $row->numRows === 0 || (int) ($row->fields[$column] ?? 0) !== $organizationId || !(bool) $row->fields['is_active']) {
            return false;
        }

        $actor = $db->queryBuilder()->table('#PREFIX#users')->select(['usertype'])->where('userid', $this->actorId)->first();
        if ($actor && $actor->numRows > 0 && (int) $actor->fields['usertype'] >= AdminAccess::superuserUsertype()) {
            return true;
        }

        $mine   = self::grants($this->actorId, $organizationId);
        $result = $db->queryBuilder()->table('authserver.permissions')
            ->select(['object_type', 'object_id', 'action'])
            ->where('subject_type', 'role')->where('subject_id', $roleId)
            ->where('grant_type', 'allow')->where('is_active', true)->get();
        while ($result && $result->fetch()) {
            if (!self::decide($mine, (string) $result->fields['object_type'], (string) ($result->fields['object_id'] ?? ''), (string) $result->fields['action'])) {
                return false;
            }
        }

        return true;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> What this person may do within the organisation. */
    private static function grants(int $userId, int $organizationId): array
    {
        try {
            return (new PermissionResolver(\Pramnos\Framework\Factory::getDatabase()))
                ->resolveForOrganization($userId, null, $organizationId)['permissions'];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Whether the grants allow an action on an object — the exact one, or a wildcard (`*`, or an
     * empty object id meaning every object). A deny that matches decides.
     *
     * @param list<array<string, mixed>> $grants
     */
    private static function decide(array $grants, string $objectType, string $objectId, string $action): bool
    {
        $allowed = false;
        foreach ($grants as $grant) {
            if (($grant['object_type'] ?? '') !== $objectType) {
                continue;
            }
            $id = (string) ($grant['object_id'] ?? '');
            if ($id !== $objectId && $id !== '*' && $id !== '') {
                continue;
            }
            if (!in_array((string) ($grant['action'] ?? ''), [$action, '*'], true)) {
                continue;
            }
            if (($grant['grant'] ?? '') === 'deny') {
                return false;
            }
            $allowed = $allowed || (($grant['grant'] ?? '') === 'allow' && ($grant['conditions'] ?? null) === null);
        }

        return $allowed;
    }

    /** @throws \InvalidArgumentException */
    private function assertGivable(int $roleId, int $organizationId): void
    {
        if (!$this->mayGive($roleId, $organizationId)) {
            throw new \InvalidArgumentException(
                'That role is not one of this organisation\'s, or it allows something you are not allowed yourself.'
            );
        }
    }

    private function role(int $roleId): Role
    {
        $controller = (new \ReflectionClass(\Pramnos\Application\Controller::class))->newInstanceWithoutConstructor();
        $role = new Role($controller);
        $role->load($roleId);

        return $role;
    }

    private function accountFor(string $email): ?int
    {
        $row = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
            ->select(['userid'])->whereRaw('LOWER(email) = ?', [$email])->first();

        return $row && $row->numRows > 0 ? (int) $row->fields['userid'] : null;
    }

    private function record(int $userId, string $event, int $organizationId, ?int $roleId = null): void
    {
        try {
            ActivityLog::record($userId, $event, array_filter([
                'organization_id' => $organizationId, 'roleid' => $roleId, 'by' => $this->actorId,
            ], static fn ($v): bool => $v !== null));
        } catch (\Throwable) {
            // The record is best-effort; the change it describes has been made.
        }
    }
}
