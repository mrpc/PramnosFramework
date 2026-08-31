<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\Model;
use Pramnos\Application\Settings;

/**
 * An RBAC role, and who holds it.
 *
 * `authserver.roles` and `authserver.user_roles` shipped with the `auth` feature's
 * migrations from the start, and `PermissionsController` can grant a permission to
 * role 7 — but nothing in the framework could create role 7 or give it to anybody.
 * The tables existed with no way to reach them, which is why the membership rule
 * their comments described had never been enforced anywhere: there was no write
 * path to enforce it in.
 *
 * ## The organisation rule
 *
 * A role either belongs to an organisation or does not:
 *
 *   - `organization_id` NULL — a **system-wide** role, assignable to anyone;
 *   - `organization_id` set — an **organisation's** role, assignable only to a
 *     member of that organisation.
 *
 * {@see assignTo()} refuses the second case for a non-member. That is the rule the
 * `user_organizations` migration always described, now with somewhere to live.
 *
 * ## What leaving an organisation does
 *
 * Nothing, on purpose. Removing somebody from an organisation does not touch their
 * role assignments: the rows stay, stop counting (see
 * {@see PermissionResolver::resolveForOrganization()}), and start counting again if
 * the person rejoins — with exactly the set they had before. Revoking access and
 * forgetting what somebody was are different operations, and only the first is what
 * "remove from organisation" means.
 *
 * So this class has no cascade, and a later change that adds one would be undoing a
 * decision rather than tidying up an oversight.
 */
class Role extends Model
{
    /** @var string */
    protected $_primaryKey = 'roleid';

    /** @var string */
    protected $_dbtable = 'authserver.roles';

    /** @var int Auto-increment role identifier */
    public $roleid = 0;

    /** @var string Unique name used in code, e.g. "operator" */
    public $role_name = '';

    /** @var string|null Human-readable description of what the role grants */
    public $description = null;

    /** @var int|null Owning organisation; NULL is a system-wide role */
    public $organization_id = null;

    /** @var string|null Creation timestamp */
    public $created_at = null;

    /** @var int 1 = assignable */
    public $is_active = 1;

    /** @var string The reason the last write refused, or '' */
    protected string $lastError = '';

    /**
     * @param \Pramnos\Application\Controller $controller The controller in scope.
     * @param string                          $name       Model name; defaults to
     *                                                    the class's short name.
     * @param int                             $roleid     Load this role on
     *                                                    construction; 0 for a new one.
     */
    public function __construct(
        \Pramnos\Application\Controller $controller,
        string $name = '',
        int $roleid = 0
    ) {
        parent::__construct($controller, $name);

        if ($roleid === 0) {
            $this->_isnew = 1;
        } else {
            $this->load($roleid);
        }
    }

    /**
     * Load a role by its primary key.
     *
     * @param int $roleid
     * @return static
     */
    public function load(int $roleid): static
    {
        return parent::_load($roleid);
    }

    /**
     * Persist this role.
     *
     * @return static
     */
    public function save(): static
    {
        return parent::_save();
    }

    /**
     * Delete this role, and with it every assignment of it.
     *
     * The assignments go because the thing they point at is gone — unlike a
     * membership ending, where the role still exists and the person may come back to
     * it. A `user_roles` row naming a deleted role is not history anybody can read.
     *
     * @param int|null $roleid Defaults to this instance's key.
     * @return bool
     */
    public function delete(?int $roleid = null): bool
    {
        $roleid = $roleid ?? (int) $this->roleid;

        if ($roleid <= 0) {
            $this->lastError = 'A role is required.';
            return false;
        }

        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table(self::assignmentTable())
            ->where('roleid', $roleid)
            ->delete();

        return (bool) parent::_delete($roleid);
    }

    /**
     * Why the last {@see assignTo()} or {@see revokeFrom()} returned false.
     *
     * A message for the operator, not for the log: "this user is not a member of
     * the organisation" is something an admin screen can act on, where a bare
     * `false` sends them looking for a bug.
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    // ── Assignment ────────────────────────────────────────────────────────────

    /**
     * Give this role to a user.
     *
     * Refuses when the role belongs to an organisation the user is not an active
     * member of — the one rule that makes an organisation-scoped role mean
     * anything. A system-wide role has no such condition.
     *
     * Idempotent: assigning a role somebody already holds re-activates the existing
     * row rather than failing on the primary key, which is what an admin screen
     * pressing "add" twice should do.
     *
     * @param int         $userId    Who receives the role.
     * @param int|null    $grantedBy The administrator making the grant, for audit.
     * @param string|null $expiresAt `Y-m-d H:i:s` for a temporary grant, or null.
     * @return bool False when refused; {@see getLastError()} says why.
     */
    public function assignTo(int $userId, ?int $grantedBy = null, ?string $expiresAt = null): bool
    {
        $this->lastError = '';

        if ($userId <= 0 || (int) $this->roleid <= 0) {
            $this->lastError = 'A role and a user are both required.';
            return false;
        }

        if (!$this->isAssignableTo($userId)) {
            $this->lastError = 'This role belongs to an organisation the user is not '
                . 'a member of. Add them to the organisation first.';
            return false;
        }

        $database = \Pramnos\Framework\Factory::getDatabase();

        $database->queryBuilder()
            ->table(self::assignmentTable())
            ->upsert(
                [
                    'userid'     => $userId,
                    'roleid'     => (int) $this->roleid,
                    'granted_by' => $grantedBy,
                    'expires_at' => $expiresAt,
                    'is_active'  => 1,
                ],
                ['userid', 'roleid'],
                ['granted_by', 'expires_at', 'is_active']
            );

        return true;
    }

    /**
     * Take this role away from a user.
     *
     * Deactivates the row rather than deleting it, matching how membership is
     * removed elsewhere in the framework: the audit trail of who held what is worth
     * more than the row it costs.
     *
     * @param int $userId
     * @return bool
     */
    public function revokeFrom(int $userId): bool
    {
        $this->lastError = '';

        if ($userId <= 0 || (int) $this->roleid <= 0) {
            $this->lastError = 'A role and a user are both required.';
            return false;
        }

        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table(self::assignmentTable())
            ->where('userid', $userId)
            ->where('roleid', (int) $this->roleid)
            ->update(['is_active' => 0]);

        return true;
    }

    /**
     * May this user be given this role?
     *
     * True for a system-wide role. For an organisation's role, true only while the
     * user has an active, unexpired membership of that organisation — the same
     * three conditions {@see PermissionResolver::resolveForOrganization()} reads, so
     * the screen cannot grant something the resolver would then ignore.
     *
     * @param int $userId
     * @return bool
     */
    public function isAssignableTo(int $userId): bool
    {
        if ($this->organization_id === null || (int) $this->organization_id <= 0) {
            return true;
        }

        return $this->isMemberOfOwningOrganization($userId);
    }

    /**
     * Is the user an active, unexpired member of this role's organisation?
     *
     * A missing membership table means the installation never adopted
     * organisations. Treating that as "not a member" would refuse every assignment
     * on such an installation, so it reads as no restriction — the same way the
     * resolver falls back when the organisation columns are absent.
     *
     * @param int $userId
     * @return bool
     */
    public function isMemberOfOwningOrganization(int $userId): bool
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $table    = self::membershipTable();

        if (!$database->schema()->hasTable($table)) {
            return true;
        }

        $row = $database->queryBuilder()
            ->table($table)
            ->where('userid', $userId)
            ->where(self::organizationColumn(), (int) $this->organization_id)
            ->where('is_active', true)
            ->first();

        if (!$row || $row->numRows === 0) {
            return false;
        }

        $expires = $row->fields['expires_at'] ?? null;

        return $expires === null || $expires === '' || strtotime((string) $expires) > time();
    }

    /**
     * The users holding this role, as `[userid => granted_at]`.
     *
     * Inactive assignments are left out: they are history, and a members screen
     * showing them would offer to remove people who are already gone.
     *
     * @return array<int, string>
     */
    public function holders(): array
    {
        if ((int) $this->roleid <= 0) {
            return [];
        }

        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table(self::assignmentTable())
            ->select(['userid', 'granted_at'])
            ->where('roleid', (int) $this->roleid)
            ->where('is_active', true)
            ->get();

        $holders = [];
        while ($result && $result->fetch()) {
            $holders[(int) $result->fields['userid']] = (string) ($result->fields['granted_at'] ?? '');
        }

        return $holders;
    }

    // ── Table names ───────────────────────────────────────────────────────────

    /** The role-assignment table. */
    public static function assignmentTable(): string
    {
        return 'authserver.user_roles';
    }

    /**
     * The membership table, respecting `authserver_organization_table`.
     *
     * Applications with domain-specific naming point it at their own table; the
     * default is `authserver.user_organizations`.
     */
    public static function membershipTable(): string
    {
        $setting = (string) Settings::getSetting('authserver_organization_table', '');

        return $setting !== ''
            ? 'authserver.' . $setting
            : 'authserver.user_organizations';
    }

    /** The organisation column, respecting `authserver_organization_column`. */
    public static function organizationColumn(): string
    {
        return (string) Settings::getSetting('authserver_organization_column', 'organization_id');
    }
}
