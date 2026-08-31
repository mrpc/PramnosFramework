<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Database\Database;

/**
 * Framework RBAC+ABAC permission resolver (feature 6, read side).
 *
 * Reads authserver.permissions for a user (their own grants + those of the
 * active roles they hold from authserver.user_roles), scoped to one application,
 * and returns the effective grants. The deny-over-allow resolution mirrors the
 * effective_permissions view: for each (object_type, object_id, action) a deny
 * wins when its top priority exceeds the top allow priority; otherwise an allow
 * present grants; otherwise deny.
 *
 * ABAC conditions are NOT evaluated here — they are passed through with each
 * grant so the calling application evaluates them against its own request
 * context (D2). A grant is unconditional (`conditions: null`) when any winning
 * row is unconditional; otherwise it carries the list of condition predicates
 * (OR-combined at runtime).
 *
 * app_id (audience): rows with app_id IS NULL are global and always apply; rows
 * with a concrete app_id apply only when it matches the requested application.
 *
 * Deliberately independent of the legacy Pramnos\Auth\Permissions class (which
 * targets a different, unrelated table).
 */
class PermissionResolver implements PermissionResolverInterface
{
    private const T_PERMS = 'authserver.permissions';
    private const T_ROLES = 'authserver.user_roles';

    /** The role definitions, which is where an organisation is recorded. */
    private const T_ROLE_DEFS = 'authserver.roles';

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function resolve(int $userId, ?int $appId): array
    {
        $rows = $this->fetchCandidateRows($userId);

        // Keep only rows in audience scope and not expired.
        $rows = array_values(array_filter(
            $rows,
            fn(array $r): bool => $this->inAudience($r, $appId) && !$this->isExpired($r)
        ));

        return [
            'user_id'     => $userId,
            'app_id'      => $appId,
            'permissions' => $this->resolveGrants($rows),
        ];
    }

    /**
     * Resolve within one organisation.
     *
     * `resolve()` answers "what may this user do", full stop, and returns the
     * permissions of every role they hold whatever organisation that role belongs
     * to. In a multi-tenant application that is the wrong question: a role defined
     * for organisation 5 must not decide anything about organisation 3's data.
     *
     * Two conditions, and a role has to satisfy one of them to count:
     *
     *   - its `organization_id` is NULL — a system-wide role, valid everywhere;
     *   - its `organization_id` is `$organizationId` **and the user is a member of
     *     that organisation**, recorded in the membership table.
     *
     * ## Why membership is checked as well
     *
     * The organisation filter alone already stops org 5's role from answering for
     * org 3. The membership check catches the other case: a role assignment that
     * should never have been made, or one left behind by somebody who has since
     * left. It is the rule the `user_organizations` migration always described and
     * nothing enforced.
     *
     * **Leaving an organisation does not delete anything.** The `user_roles` row
     * stays exactly where it is and simply stops counting; rejoining makes it count
     * again, with the same set of roles the person had before. Revoking access and
     * forgetting what somebody was are different operations, and only one of them
     * was asked for.
     *
     * ## Cost
     *
     * One query, joined, rather than the two `resolve()` would need. Measured on
     * 500 users holding 50 roles each out of 400: 0.154 ms against 0.105 ms for the
     * unscoped role read. Reading the memberships separately and filtering in PHP
     * was 0.213 ms — the extra round trip costs more than the join saves — and even
     * with the memberships already in memory it only reached 0.141 ms, which is not
     * worth an API for callers to pass them in.
     *
     * @param int      $userId         Whose permissions to resolve.
     * @param int|null $appId          Audience, as {@see resolve()}.
     * @param int      $organizationId The organisation the request is about.
     * @return array{user_id:int,app_id:int|null,organization_id:int,permissions:list<array<string,mixed>>}
     */
    public function resolveForOrganization(int $userId, ?int $appId, int $organizationId): array
    {
        $rows = $this->fetchCandidateRows($userId, $organizationId);

        $rows = array_values(array_filter(
            $rows,
            fn(array $r): bool => $this->inAudience($r, $appId) && !$this->isExpired($r)
        ));

        return [
            'user_id'         => $userId,
            'app_id'          => $appId,
            'organization_id' => $organizationId,
            'permissions'     => $this->resolveGrants($rows),
        ];
    }

    // ── Fetch ─────────────────────────────────────────────────────────────

    /**
     * Active permission rows whose subject is the user or one of their active
     * roles. Audience/expiry filtering happens in PHP for portable OR/NULL
     * handling across MySQL and PostgreSQL.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchCandidateRows(int $userId, ?int $organizationId = null): array
    {
        $rows = $this->collect(
            $this->database->queryBuilder()
                ->table(self::T_PERMS)
                ->where('subject_type', 'user')
                ->where('subject_id', $userId)
                ->where('is_active', true)
                ->get()
        );

        $roleIds = $this->activeRoleIds($userId, $organizationId);
        if ($roleIds !== []) {
            $rows = array_merge($rows, $this->collect(
                $this->database->queryBuilder()
                    ->table(self::T_PERMS)
                    ->where('subject_type', 'role')
                    ->whereIn('subject_id', $roleIds)
                    ->where('is_active', true)
                    ->get()
            ));
        }

        return $rows;
    }

    /**
     * IDs of the roles currently assigned to the user (active, not expired).
     *
     * With `$organizationId` given, a role counts only when it is system-wide
     * (`organization_id` NULL) or belongs to that organisation *and* the user is a
     * member of it. Without it, every active role counts — which is what
     * {@see resolve()} has always done and has to keep doing.
     *
     * The scoped form is one joined query rather than three round trips, and it
     * degrades the way the rest of this class does: an installation whose
     * `roles` table has no organisation column, or which has no membership table at
     * all, falls back to the unscoped read instead of resolving nothing.
     *
     * @param int      $userId
     * @param int|null $organizationId Scope, or null for every role the user holds.
     * @return list<int>
     */
    private function activeRoleIds(int $userId, ?int $organizationId = null): array
    {
        // A missing role-assignment table is not a failure to resolve: it means
        // this installation grants nothing through roles, which is the same
        // answer as a user who holds none. Letting the driver error escape here
        // took down the whole resolution — and callers that turn "cannot
        // answer" into "denied" then refused every direct user grant as well,
        // which is the opposite of what the rows said.
        if (!$this->database->schema()->hasTable(self::T_ROLES)) {
            return [];
        }

        $rows = $organizationId === null
            ? $this->allAssignedRoleRows($userId)
            : $this->assignedRoleRowsForOrganization($userId, $organizationId);

        $ids = [];
        foreach ($rows as $r) {
            if (!$this->isExpired($r)) {
                $ids[] = (int) $r['roleid'];
            }
        }
        return $ids;
    }

    /**
     * Every active role assignment the user holds, whatever organisation it names.
     *
     * @return list<array<string,mixed>>
     */
    private function allAssignedRoleRows(int $userId): array
    {
        return $this->collect(
            $this->database->queryBuilder()
                ->table(self::T_ROLES)
                ->where('userid', $userId)
                ->where('is_active', true)
                ->get()
        );
    }

    /**
     * Role assignments that count within one organisation.
     *
     * A system-wide role (`organization_id` NULL) always counts. An
     * organisation-scoped one counts only for its own organisation, and only while
     * the user is a member of it — the membership row is joined rather than fetched
     * separately, which measured faster than either alternative.
     *
     * The organisation column and the membership table are both configurable
     * (`authserver_organization_column`, `authserver_organization_table`), because
     * applications with domain-specific naming point them at their own tables.
     *
     * If either table is missing, or the `roles` table has no organisation column —
     * an installation that never adopted organisations — this falls back to the
     * unscoped read. Returning nothing would refuse every permission the user has
     * on an installation where organisations simply do not apply.
     *
     * @return list<array<string,mixed>>
     */
    private function assignedRoleRowsForOrganization(int $userId, int $organizationId): array
    {
        $orgColumn = (string) \Pramnos\Application\Settings::getSetting(
            'authserver_organization_column',
            'organization_id'
        );
        $memberTable = 'authserver.' . (string) \Pramnos\Application\Settings::getSetting(
            'authserver_organization_table',
            'user_organizations'
        );

        $schema = $this->database->schema();
        if (!$schema->hasTable(self::T_ROLE_DEFS)
            || !$schema->hasColumn(self::T_ROLE_DEFS, $orgColumn)
            || !$schema->hasTable($memberTable)
        ) {
            return $this->allAssignedRoleRows($userId);
        }

        return $this->collect(
            $this->database->queryBuilder()
                ->table(self::T_ROLES . ' ur')
                ->select(['ur.roleid', 'ur.expires_at'])
                ->join(self::T_ROLE_DEFS . ' rd', 'rd.roleid', '=', 'ur.roleid')
                ->leftJoin(
                    $memberTable . ' uo',
                    function ($join) use ($orgColumn) {
                        $join->on('uo.userid', '=', 'ur.userid')
                             ->on('uo.' . $orgColumn, '=', 'rd.' . $orgColumn);
                    }
                )
                ->where('ur.userid', $userId)
                ->where('ur.is_active', true)
                ->where(function ($q) use ($orgColumn, $organizationId) {
                    $q->whereNull('rd.' . $orgColumn)
                      ->orWhere(function ($inner) use ($orgColumn, $organizationId) {
                          $inner->where('rd.' . $orgColumn, $organizationId)
                                ->whereNotNull('uo.userid')
                                // Membership is soft-deleted, not removed:
                                // OrganizationsController::removemember() sets
                                // is_active = 0 to keep the audit trail. A join that
                                // only asked whether the row existed would leave
                                // every former member's access exactly where it was.
                                ->where('uo.is_active', true)
                                ->where(function ($window) {
                                    $window->whereNull('uo.expires_at')
                                           ->orWhere(
                                               'uo.expires_at',
                                               '>',
                                               date('Y-m-d H:i:s')
                                           );
                                });
                      });
                })
                ->get()
        );
    }

    // ── Resolution ──────────────────────────────────────────────────────────

    /**
     * Deny-over-allow resolution per (object_type, object_id, action), with
     * conditions pass-through.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{object_type:string,object_id:string|null,action:string,grant:string,conditions:mixed}>
     */
    private function resolveGrants(array $rows): array
    {
        // Bucket rows by their protected target.
        $groups = [];
        foreach ($rows as $r) {
            $key = ($r['object_type'] ?? '') . "\0" . ($r['object_id'] ?? '') . "\0" . ($r['action'] ?? '');
            $groups[$key][] = $r;
        }

        $out = [];
        foreach ($groups as $group) {
            $maxAllow = null;
            $maxDeny  = null;
            foreach ($group as $r) {
                $priority = (int) ($r['priority'] ?? 0);
                if (($r['grant_type'] ?? 'allow') === 'deny') {
                    $maxDeny = $maxDeny === null ? $priority : max($maxDeny, $priority);
                } else {
                    $maxAllow = $maxAllow === null ? $priority : max($maxAllow, $priority);
                }
            }

            // Mirror effective_permissions: deny wins when its top priority
            // exceeds the top allow priority; else allow if any; else deny.
            if ($maxDeny !== null && $maxDeny > ($maxAllow ?? 0)) {
                $grant = 'deny';
            } elseif ($maxAllow !== null) {
                $grant = 'allow';
            } else {
                $grant = 'deny';
            }

            $first = $group[0];
            $out[] = [
                'object_type' => (string) ($first['object_type'] ?? ''),
                'object_id'   => $first['object_id'] !== null ? (string) $first['object_id'] : null,
                'action'      => (string) ($first['action'] ?? ''),
                'grant'       => $grant,
                'conditions'  => $this->collectConditions($group, $grant),
            ];
        }

        return $out;
    }

    /**
     * Conditions attached to the effective grant: null (unconditional) when any
     * winning-grant-type row is unconditional; otherwise the list of distinct
     * condition predicates from the winning rows (OR-combined at runtime).
     *
     * @param list<array<string,mixed>> $group
     * @return mixed null | list<mixed>
     */
    private function collectConditions(array $group, string $grant): mixed
    {
        $predicates = [];
        foreach ($group as $r) {
            if (($r['grant_type'] ?? 'allow') !== $grant) {
                continue;
            }
            $raw = $r['conditions'] ?? null;
            if ($raw === null || $raw === '') {
                return null; // an unconditional winning row makes the grant unconditional
            }
            $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
            if ($decoded === null) {
                return null; // unparseable → treat as unconditional rather than block
            }
            if (!in_array($decoded, $predicates, true)) {
                $predicates[] = $decoded;
            }
        }
        return $predicates === [] ? null : $predicates;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** True when the row's app_id is global (NULL) or matches $appId. */
    private function inAudience(array $r, ?int $appId): bool
    {
        $rowApp = $r['app_id'] ?? null;
        if ($rowApp === null || $rowApp === '') {
            return true; // global
        }
        return $appId !== null && (int) $rowApp === $appId;
    }

    /** True when the row has an expiry in the past. */
    private function isExpired(array $r): bool
    {
        $expires = $r['expires_at'] ?? null;
        if ($expires === null || $expires === '') {
            return false;
        }
        $ts = strtotime((string) $expires);
        return $ts !== false && $ts <= time();
    }

    /**
     * Drain a query Result into a list of associative row arrays.
     *
     * @return list<array<string,mixed>>
     */
    private function collect(mixed $result): array
    {
        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }
        return $rows;
    }
}
