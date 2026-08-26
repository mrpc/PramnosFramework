<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Database\Database;

/**
 * Syncs a client's capabilities manifest into the server-side registry.
 *
 * Resource servers declare what they expose — Resources, the Scopes (action
 * vocabulary) per Resource, and the ABAC Condition keys they support — by
 * pushing a JSON manifest (feature 2). This service applies that manifest to
 * the four capabilities tables with three guarantees:
 *
 *   1. **MD5 short-circuit** — an unchanged manifest (same hash as the last
 *      sync) is a no-op, avoiding needless writes.
 *   2. **Upsert** — resources / scopes / conditions present in the manifest are
 *      inserted or refreshed and marked is_active = true.
 *   3. **Soft delete** — anything previously synced but ABSENT from the new
 *      manifest is flagged is_active = false, never hard-deleted, so existing
 *      user policies that reference it are preserved (feature 3).
 *
 * Manifest shape (both scope forms accepted):
 *   [
 *     'resources' => [
 *        ['name' => 'consumptions', 'description' => '…',
 *         'scopes' => ['read', 'export', ['name' => 'delete', 'description' => '…']]],
 *     ],
 *     'conditions' => [
 *        ['key' => 'location_id', 'value_type' => 'int', 'description' => '…'],
 *     ],
 *   ]
 *
 * All table access goes through QueryBuilder::table(), which resolves the
 * authserver schema (PostgreSQL) or the authserver_ prefix (MySQL) automatically.
 *
 * Extension seam: overriding this service (or its individual sync* methods)
 * lets an app layer (e.g. an application auth layer licensing) filter which declared
 * capabilities are actually made available, without forking the framework.
 */
class CapabilitiesSyncService
{
    private const T_RESOURCES  = 'authserver.client_resources';
    private const T_SCOPES     = 'authserver.client_resource_scopes';
    private const T_CONDITIONS = 'authserver.client_supported_conditions';
    private const T_MANIFEST   = 'authserver.client_manifest';

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Deterministic MD5 of a manifest, independent of key/element order.
     *
     * Normalising before hashing means a re-ordered but semantically identical
     * manifest yields the same hash and short-circuits the sync.
     */
    public function hashManifest(array $manifest): string
    {
        return md5(json_encode($this->normalize($manifest)));
    }

    /**
     * Sync a manifest for one application.
     *
     * @param int   $applicationId applications.appid of the resource server
     * @param array $manifest      decoded manifest (see class doc-block)
     * @param int|null $syncedBy   userid / system account that pushed it
     * @return array{status:string,resources:int,scopes:int,conditions:int,deactivated:int}
     *         status is 'unchanged' (hash match, no-op) or 'synced'.
     */
    public function sync(int $applicationId, array $manifest, ?int $syncedBy = null): array
    {
        $hash = $this->hashManifest($manifest);

        // 1. MD5 short-circuit — nothing to do if the manifest is unchanged.
        if ($this->currentHash($applicationId) === $hash) {
            return [
                'status'      => 'unchanged',
                'resources'   => 0,
                'scopes'      => 0,
                'conditions'  => 0,
                'deactivated' => 0,
            ];
        }

        $resources  = $this->asList($manifest['resources']  ?? [], 'name');
        $conditions = $this->asList($manifest['conditions'] ?? [], 'key');

        $counts = ['resources' => 0, 'scopes' => 0, 'conditions' => 0, 'deactivated' => 0];

        // 2. Upsert resources + their scopes.
        $seenResources = [];
        foreach ($resources as $resource) {
            $name = (string) ($resource['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $seenResources[] = $name;
            $resourceId = $this->upsertResource($applicationId, $name, $resource['description'] ?? null);
            $counts['resources']++;

            $counts['scopes']      += $this->syncScopes($resourceId, $this->asList($resource['scopes'] ?? []));
            $counts['deactivated'] += $this->deactivateMissingScopes($resourceId, $this->scopeNames($resource['scopes'] ?? []));
        }

        // 3. Upsert conditions.
        $seenConditions = [];
        foreach ($conditions as $condition) {
            $key = (string) ($condition['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $seenConditions[] = $key;
            $this->upsertCondition(
                $applicationId,
                $key,
                (string) ($condition['value_type'] ?? 'string'),
                $condition['description'] ?? null
            );
            $counts['conditions']++;
        }

        // 4. Soft-delete anything no longer declared.
        $counts['deactivated'] += $this->deactivateMissingResources($applicationId, $seenResources);
        $counts['deactivated'] += $this->deactivateMissingConditions($applicationId, $seenConditions);

        // 5. Record the new hash so the next identical push short-circuits.
        $this->storeHash($applicationId, $hash, $syncedBy);

        return ['status' => 'synced'] + $counts;
    }

    // ── Resources ──────────────────────────────────────────────────────────

    /** Insert or refresh a resource row; returns its id. */
    private function upsertResource(int $applicationId, string $name, ?string $description): int
    {
        $now      = date('Y-m-d H:i:s');
        $existing = $this->database->queryBuilder()
            ->table(self::T_RESOURCES)
            ->where('applicationid', $applicationId)
            ->where('resource_name', $name)
            ->first();

        if ($existing && $existing->numRows > 0) {
            $id = (int) $existing->fields['id'];
            $this->database->queryBuilder()
                ->table(self::T_RESOURCES)
                ->where('id', $id)
                ->update([
                    'description' => $description,
                    'is_active'   => true,
                    'updated_at'  => $now,
                ]);
            return $id;
        }

        $this->database->queryBuilder()
            ->table(self::T_RESOURCES)
            ->insert([
                'applicationid' => $applicationId,
                'resource_name' => $name,
                'description'   => $description,
                'is_active'     => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

        $row = $this->database->queryBuilder()
            ->table(self::T_RESOURCES)
            ->where('applicationid', $applicationId)
            ->where('resource_name', $name)
            ->first();

        return (int) $row->fields['id'];
    }

    /** Soft-delete active resources of $applicationId not in $keep. Returns count. */
    private function deactivateMissingResources(int $applicationId, array $keep): int
    {
        $rows = $this->database->queryBuilder()
            ->table(self::T_RESOURCES)
            ->where('applicationid', $applicationId)
            ->where('is_active', true)
            ->get();

        $count = 0;
        while ($rows && $rows->fetch()) {
            if (!in_array($rows->fields['resource_name'], $keep, true)) {
                $this->database->queryBuilder()
                    ->table(self::T_RESOURCES)
                    ->where('id', (int) $rows->fields['id'])
                    ->update(['is_active' => false]);
                $count++;
            }
        }
        return $count;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    /** Upsert the scope vocabulary of a resource. Returns number upserted. */
    private function syncScopes(int $resourceId, array $scopes): int
    {
        $count = 0;
        foreach ($scopes as $scope) {
            $name = is_array($scope) ? (string) ($scope['name'] ?? '') : (string) $scope;
            if ($name === '') {
                continue;
            }
            $description = is_array($scope) ? ($scope['description'] ?? null) : null;

            $existing = $this->database->queryBuilder()
                ->table(self::T_SCOPES)
                ->where('resource_id', $resourceId)
                ->where('scope_name', $name)
                ->first();

            if ($existing && $existing->numRows > 0) {
                $this->database->queryBuilder()
                    ->table(self::T_SCOPES)
                    ->where('id', (int) $existing->fields['id'])
                    ->update(['description' => $description, 'is_active' => true]);
            } else {
                $this->database->queryBuilder()
                    ->table(self::T_SCOPES)
                    ->insert([
                        'resource_id' => $resourceId,
                        'scope_name'  => $name,
                        'description' => $description,
                        'is_active'   => true,
                    ]);
            }
            $count++;
        }
        return $count;
    }

    /** Soft-delete active scopes of $resourceId not in $keep. Returns count. */
    private function deactivateMissingScopes(int $resourceId, array $keep): int
    {
        $rows = $this->database->queryBuilder()
            ->table(self::T_SCOPES)
            ->where('resource_id', $resourceId)
            ->where('is_active', true)
            ->get();

        $count = 0;
        while ($rows && $rows->fetch()) {
            if (!in_array($rows->fields['scope_name'], $keep, true)) {
                $this->database->queryBuilder()
                    ->table(self::T_SCOPES)
                    ->where('id', (int) $rows->fields['id'])
                    ->update(['is_active' => false]);
                $count++;
            }
        }
        return $count;
    }

    // ── Conditions ───────────────────────────────────────────────────────────

    /** Insert or refresh a supported-condition row. */
    private function upsertCondition(int $applicationId, string $key, string $valueType, ?string $description): void
    {
        $existing = $this->database->queryBuilder()
            ->table(self::T_CONDITIONS)
            ->where('applicationid', $applicationId)
            ->where('condition_key', $key)
            ->first();

        if ($existing && $existing->numRows > 0) {
            $this->database->queryBuilder()
                ->table(self::T_CONDITIONS)
                ->where('id', (int) $existing->fields['id'])
                ->update([
                    'value_type'  => $valueType,
                    'description' => $description,
                    'is_active'   => true,
                ]);
            return;
        }

        $this->database->queryBuilder()
            ->table(self::T_CONDITIONS)
            ->insert([
                'applicationid' => $applicationId,
                'condition_key' => $key,
                'value_type'    => $valueType,
                'description'   => $description,
                'is_active'     => true,
            ]);
    }

    /** Soft-delete active conditions of $applicationId not in $keep. Returns count. */
    private function deactivateMissingConditions(int $applicationId, array $keep): int
    {
        $rows = $this->database->queryBuilder()
            ->table(self::T_CONDITIONS)
            ->where('applicationid', $applicationId)
            ->where('is_active', true)
            ->get();

        $count = 0;
        while ($rows && $rows->fetch()) {
            if (!in_array($rows->fields['condition_key'], $keep, true)) {
                $this->database->queryBuilder()
                    ->table(self::T_CONDITIONS)
                    ->where('id', (int) $rows->fields['id'])
                    ->update(['is_active' => false]);
                $count++;
            }
        }
        return $count;
    }

    // ── Manifest hash ────────────────────────────────────────────────────────

    /** Current stored manifest hash for $applicationId, or '' if none. */
    public function currentHash(int $applicationId): string
    {
        $row = $this->database->queryBuilder()
            ->table(self::T_MANIFEST)
            ->where('applicationid', $applicationId)
            ->first();

        return ($row && $row->numRows > 0) ? (string) $row->fields['manifest_hash'] : '';
    }

    /**
     * What an application has declared, for a person to read.
     *
     * The write side of this RFC existed and the read side did not: an application
     * could push its resources, scopes and conditions, and nothing anywhere could
     * show an operator what had arrived. Central permission control without that
     * is not control — a grant refers to a resource name, and the only way to know
     * which names exist was to query the tables by hand.
     *
     * Soft-deleted rows are included, flagged rather than hidden. A resource an
     * application stopped declaring is exactly what somebody is looking for when a
     * permission referring to it has stopped working, and dropping it from this
     * list would hide the answer.
     *
     * @param int $applicationId The `applications.appid`
     * @return array{
     *     hash: string,
     *     synced_at: ?string,
     *     resources: list<array{name: string, description: ?string, is_active: bool, scopes: list<array{name: string, description: ?string, is_active: bool}>}>,
     *     conditions: list<array{key: string, value_type: string, description: ?string, is_active: bool}>
     * }
     */
    public function describe(int $applicationId): array
    {
        $manifest = $this->database->queryBuilder()
            ->table(self::T_MANIFEST)
            ->where('applicationid', $applicationId)
            ->first();

        $resources = [];
        $resourceRows = $this->database->queryBuilder()
            ->table(self::T_RESOURCES)
            ->where('applicationid', $applicationId)
            ->orderBy('resource_name')
            ->getAll();

        foreach ($resourceRows as $row) {
            $scopes = [];
            $scopeRows = $this->database->queryBuilder()
                ->table(self::T_SCOPES)
                ->where('resource_id', (int) $row['id'])
                ->orderBy('scope_name')
                ->getAll();

            foreach ($scopeRows as $scope) {
                $scopes[] = [
                    'name'        => (string) $scope['scope_name'],
                    'description' => $scope['description'] ?? null,
                    'is_active'   => (bool) $scope['is_active'],
                ];
            }

            $resources[] = [
                'name'        => (string) $row['resource_name'],
                'description' => $row['description'] ?? null,
                'is_active'   => (bool) $row['is_active'],
                'scopes'      => $scopes,
            ];
        }

        $conditions = [];
        $conditionRows = $this->database->queryBuilder()
            ->table(self::T_CONDITIONS)
            ->where('applicationid', $applicationId)
            ->orderBy('condition_key')
            ->getAll();

        foreach ($conditionRows as $row) {
            $conditions[] = [
                'key'         => (string) $row['condition_key'],
                'value_type'  => (string) ($row['value_type'] ?? 'string'),
                'description' => $row['description'] ?? null,
                'is_active'   => (bool) $row['is_active'],
            ];
        }

        // `?? ''` rather than a bare index: a row can come back from a fixture or a
        // partially-migrated schema without the column, and a warning on an admin
        // page is a worse answer than "no manifest recorded".
        return [
            'hash'       => ($manifest && $manifest->numRows > 0)
                ? (string) ($manifest->fields['manifest_hash'] ?? '') : '',
            'synced_at'  => ($manifest && $manifest->numRows > 0)
                ? ($manifest->fields['synced_at'] ?? null) : null,
            'resources'  => $resources,
            'conditions' => $conditions,
        ];
    }

    /** Insert or update the stored manifest hash for $applicationId. */
    private function storeHash(int $applicationId, string $hash, ?int $syncedBy): void
    {
        $now      = date('Y-m-d H:i:s');
        $existing = $this->database->queryBuilder()
            ->table(self::T_MANIFEST)
            ->where('applicationid', $applicationId)
            ->first();

        if ($existing && $existing->numRows > 0) {
            $this->database->queryBuilder()
                ->table(self::T_MANIFEST)
                ->where('applicationid', $applicationId)
                ->update(['manifest_hash' => $hash, 'synced_at' => $now, 'synced_by' => $syncedBy]);
            return;
        }

        $this->database->queryBuilder()
            ->table(self::T_MANIFEST)
            ->insert([
                'applicationid' => $applicationId,
                'manifest_hash' => $hash,
                'synced_at'     => $now,
                'synced_by'     => $syncedBy,
            ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Normalise a manifest into a canonical, order-independent structure. */
    private function normalize(array $manifest): array
    {
        $resources = [];
        foreach ($this->asList($manifest['resources'] ?? []) as $resource) {
            $name = (string) ($resource['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $scopes = $this->scopeNames($resource['scopes'] ?? []);
            sort($scopes);
            $resources[$name] = [
                'description' => (string) ($resource['description'] ?? ''),
                'scopes'      => $scopes,
            ];
        }
        ksort($resources);

        $conditions = [];
        foreach ($this->asList($manifest['conditions'] ?? []) as $condition) {
            $key = (string) ($condition['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $conditions[$key] = [
                'value_type'  => (string) ($condition['value_type'] ?? 'string'),
                'description' => (string) ($condition['description'] ?? ''),
            ];
        }
        ksort($conditions);

        return ['resources' => $resources, 'conditions' => $conditions];
    }

    /** Flatten a scopes entry to a list of scope-name strings. */
    private function scopeNames(mixed $scopes): array
    {
        $names = [];
        foreach ($this->asList($scopes) as $scope) {
            $name = is_array($scope) ? (string) ($scope['name'] ?? '') : (string) $scope;
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Normalise a manifest section into a list of entries that each name itself.
     *
     * The published manifest format keys its entries by name:
     *
     * ```json
     * "resources": { "invoices": { "description": "…", "scopes": { "read": "View" } } }
     * ```
     *
     * This used to be `array_values()`, which throws the keys away — so every
     * entry arrived with no `name`, the loops above skipped it, and a correct
     * manifest synced **zero** resources while answering `200 {"status":"synced"}`.
     * Scopes were worse than skipped: `{"read": "View invoices"}` became the
     * string `"View invoices"`, so the scope was created under its own
     * description and an application asking for `read` would never match it.
     *
     * Both shapes are accepted. A list whose entries carry `name` (or `key`) is
     * passed through unchanged, so anything written against the old behaviour
     * keeps working; a keyed map takes its name from the key, and a scalar value
     * is read as the description, which is what the scope form is.
     *
     * @param string $keyName Where the name goes: `name` for resources and
     *                        scopes, `key` for conditions.
     * @return list<array<string, mixed>|string>
     */
    private function asList(mixed $value, string $keyName = 'name'): array
    {
        if (!is_array($value)) {
            return [];
        }

        $entries = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                // Already a list: the entry names itself, or it names nothing and
                // the caller skips it — either way not this method's business.
                $entries[] = $entry;
                continue;
            }

            if (is_array($entry)) {
                // An explicit name inside wins over the key, so a manifest that
                // says both is not silently contradicted.
                $entry[$keyName] = (string) ($entry[$keyName] ?? $key);
                $entries[] = $entry;
                continue;
            }

            $entries[] = [$keyName => $key, 'description' => (string) $entry];
        }

        return $entries;
    }
}
