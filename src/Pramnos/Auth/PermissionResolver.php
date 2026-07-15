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

    // ── Fetch ─────────────────────────────────────────────────────────────

    /**
     * Active permission rows whose subject is the user or one of their active
     * roles. Audience/expiry filtering happens in PHP for portable OR/NULL
     * handling across MySQL and PostgreSQL.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchCandidateRows(int $userId): array
    {
        $rows = $this->collect(
            $this->database->queryBuilder()
                ->table(self::T_PERMS)
                ->where('subject_type', 'user')
                ->where('subject_id', $userId)
                ->where('is_active', true)
                ->get()
        );

        $roleIds = $this->activeRoleIds($userId);
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
     * @return list<int>
     */
    private function activeRoleIds(int $userId): array
    {
        $rows = $this->collect(
            $this->database->queryBuilder()
                ->table(self::T_ROLES)
                ->where('userid', $userId)
                ->where('is_active', true)
                ->get()
        );

        $ids = [];
        foreach ($rows as $r) {
            if (!$this->isExpired($r)) {
                $ids[] = (int) $r['roleid'];
            }
        }
        return $ids;
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
