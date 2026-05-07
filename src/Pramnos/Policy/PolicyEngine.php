<?php

namespace Pramnos\Policy;

use Pramnos\Application\Application;
use Pramnos\Database\Database;

/**
 * Executes framework policies stored in the `framework_policies` table.
 *
 * On backends where TimescaleDB native policies are available (TimescaleDB),
 * the engine is a no-op — native policies handle scheduling internally.
 * On MySQL and plain PostgreSQL, the engine reads due policies from the table
 * and executes the appropriate SQL for each policy type.
 *
 * ## Policy types
 *
 * | Type               | Action |
 * |---|---|
 * | `retention`        | Deletes rows older than `config.interval` from `config.time_column`. |
 * | `aggregate_refresh`| Re-populates a materialized view or cache table. |
 * | `compression`      | No-op on MySQL/PG (no native compression). |
 * | `cache_rebuild`    | Truncates and re-fills a cache/summary table via a SELECT query. |
 *
 * ## Usage
 *
 * The Policy Engine is invoked by the `service:policy-engine` CLI command.
 * It can also be called programmatically:
 *
 * ```php
 * $engine = new PolicyEngine($app);
 * $results = $engine->run();
 * foreach ($results as $result) {
 *     echo $result['policy_type'] . ': ' . $result['status'] . "\n";
 * }
 * ```
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Policy
 */
class PolicyEngine
{
    private Database $db;

    public function __construct(private readonly Application $app)
    {
        $this->db = $app->database;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Runs all due, enabled policies.
     *
     * On TimescaleDB this returns an empty array immediately (native policies
     * handle their own scheduling).
     *
     * @return array<int, array{policyid: int, policy_type: string, target: string, status: string, error: string|null}>
     */
    public function run(): array
    {
        if ($this->isTimescaleDb()) {
            return [];
        }

        $due     = $this->getDuePolicies();
        $results = [];

        foreach ($due as $policy) {
            $result = $this->executePolicy($policy);
            $results[] = $result;
            $this->updateHistory($policy, $result);
        }

        return $results;
    }

    /**
     * Loads all enabled policies regardless of next_run.
     *
     * @return PolicyRecord[]
     */
    public function getAllEnabled(): array
    {
        return $this->loadPolicies(onlyDue: false);
    }

    /**
     * Registers a new policy in the framework_policies table.
     *
     * @param string               $type   Policy type (retention, aggregate_refresh, etc.)
     * @param string               $target Table or view name.
     * @param array<string, mixed> $config Type-specific configuration.
     * @return int The new policyid.
     */
    public function register(string $type, string $target, array $config = []): int
    {
        $configJson = json_encode($config);
        $now        = date('Y-m-d H:i:s');

        $table = $this->policyTable();

        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            $result = $this->db->query(
                "INSERT INTO {$table} (policy_type, target, config, enabled, created_at)
                 VALUES ($1, $2, $3, TRUE, NOW())
                 RETURNING policyid",
                [$type, $target, $configJson]
            );
            if ($result && $result->fetch()) {
                return (int) $result->fields['policyid'];
            }
        } else {
            $this->db->query(
                "INSERT INTO {$table} (policy_type, target, config, enabled, created_at)
                 VALUES (?, ?, ?, 1, ?)",
                [$type, $target, $configJson, $now]
            );
            return (int) $this->db->query('SELECT LAST_INSERT_ID() AS id')->fields['id'];
        }

        return 0;
    }

    /**
     * Enables or disables a policy by ID.
     */
    public function setEnabled(int $policyId, bool $enabled): void
    {
        $value = $enabled ? 1 : 0;

        $table = $this->policyTable();

        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            $this->db->query(
                "UPDATE {$table} SET enabled = $1 WHERE policyid = $2",
                [$enabled ? 'TRUE' : 'FALSE', $policyId]
            );
        } else {
            $this->db->query(
                "UPDATE {$table} SET `enabled` = ? WHERE `policyid` = ?",
                [$value, $policyId]
            );
        }
    }

    /**
     * Removes a policy by ID.
     */
    public function remove(int $policyId): void
    {
        $table = $this->policyTable();

        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            $this->db->query("DELETE FROM {$table} WHERE policyid = $1", [$policyId]);
        } else {
            $this->db->query("DELETE FROM {$table} WHERE `policyid` = ?", [$policyId]);
        }
    }

    // =========================================================================
    // Internal execution
    // =========================================================================

    /**
     * @return PolicyRecord[]
     */
    private function getDuePolicies(): array
    {
        return $this->loadPolicies(onlyDue: true);
    }

    /**
     * @return PolicyRecord[]
     */
    private function loadPolicies(bool $onlyDue): array
    {
        $now = date('Y-m-d H:i:s');

        $table = $this->policyTable();

        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            $whereNextRun = $onlyDue ? 'AND (next_run IS NULL OR next_run <= NOW())' : '';
            $sql    = "SELECT * FROM {$table} WHERE enabled = TRUE {$whereNextRun} ORDER BY policyid ASC";
            $result = $this->db->query($sql);
        } else {
            $whereNextRun = $onlyDue ? 'AND (next_run IS NULL OR next_run <= ?)' : '';
            $params = $onlyDue ? [$now] : [];
            $sql    = "SELECT * FROM {$table} WHERE `enabled` = 1 {$whereNextRun} ORDER BY `policyid` ASC";
            $result = $this->db->query($sql, $params);
        }

        if (!$result) {
            return [];
        }

        $records = [];
        while ($result->fetch()) {
            $records[] = PolicyRecord::fromRow($result->fields);
        }

        return $records;
    }

    /**
     * @return array{policyid: int, policy_type: string, target: string, status: string, error: string|null}
     */
    private function executePolicy(PolicyRecord $policy): array
    {
        try {
            match ($policy->policyType) {
                'retention'         => $this->executeRetention($policy),
                'aggregate_refresh' => $this->executeAggregateRefresh($policy),
                'compression'       => null, // no-op on non-TimescaleDB backends
                'cache_rebuild'     => $this->executeCacheRebuild($policy),
                default             => throw new \RuntimeException(
                    "Unknown policy type '{$policy->policyType}'"
                ),
            };

            return [
                'policyid'    => $policy->policyid,
                'policy_type' => $policy->policyType,
                'target'      => $policy->target,
                'status'      => 'ok',
                'error'       => null,
            ];
        } catch (\Throwable $e) {
            return [
                'policyid'    => $policy->policyid,
                'policy_type' => $policy->policyType,
                'target'      => $policy->target,
                'status'      => 'error',
                'error'       => $e->getMessage(),
            ];
        }
    }

    private function executeRetention(PolicyRecord $policy): void
    {
        $interval   = $policy->config['interval']    ?? '30 days';
        $timeColumn = $policy->config['time_column'] ?? 'created_at';

        // Sanitise identifiers (table name and column name must be plain identifiers)
        $target     = $this->quoteIdentifier($policy->target);
        $timeColumn = $this->quoteIdentifier($timeColumn);

        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            $this->db->query(
                "DELETE FROM {$target}
                 WHERE {$timeColumn} < NOW() - INTERVAL '{$interval}'"
            );
        } else {
            // MySQL INTERVAL syntax: INTERVAL 30 DAY (no quotes, space-separated)
            $mysqlInterval = $this->toMySQLInterval($interval);
            $this->db->query(
                "DELETE FROM {$target}
                 WHERE {$timeColumn} < DATE_SUB(NOW(), INTERVAL {$mysqlInterval})"
            );
        }
    }

    private function executeAggregateRefresh(PolicyRecord $policy): void
    {
        $view   = $this->quoteIdentifier($policy->target);
        $source = isset($policy->config['source'])
            ? $this->quoteIdentifier($policy->config['source'])
            : null;

        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            $this->db->query("REFRESH MATERIALIZED VIEW {$view}");
        } else {
            // MySQL: truncate cache table and reload from source
            if ($source !== null) {
                $this->db->query("TRUNCATE {$view}");
                $this->db->query("INSERT INTO {$view} SELECT * FROM {$source}");
            }
            // If no source, nothing to do — the app must provide a custom handler
        }
    }

    private function executeCacheRebuild(PolicyRecord $policy): void
    {
        $view   = $this->quoteIdentifier($policy->target);
        $source = isset($policy->config['source'])
            ? $this->quoteIdentifier($policy->config['source'])
            : null;

        if ($source !== null) {
            $this->db->query("TRUNCATE {$view}");
            $this->db->query("INSERT INTO {$view} SELECT * FROM {$source}");
        }
    }

    private function updateHistory(PolicyRecord $policy, array $result): void
    {
        $now    = date('Y-m-d H:i:s');
        $status = $result['status'] === 'ok' ? 'ok' : null;
        $error  = $result['error'];
        $id     = $policy->policyid;

        $table = $this->policyTable();

        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            $this->db->query(
                "UPDATE {$table}
                 SET last_run = NOW(), next_run = NULL, last_result = $1, last_error = $2
                 WHERE policyid = $3",
                [$status, $error, $id]
            );
        } else {
            $this->db->query(
                "UPDATE {$table}
                 SET `last_run` = ?, `next_run` = NULL, `last_result` = ?, `last_error` = ?
                 WHERE `policyid` = ?",
                [$now, $status, $error, $id]
            );
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function isTimescaleDb(): bool
    {
        return $this->db->type === 'timescaledb';
    }

    /**
     * Returns the framework_policies table reference appropriate for the backend.
     * PostgreSQL: pramnos.framework_policies  (dedicated schema, no backticks)
     * MySQL:      `framework_policies`        (default database, backtick-quoted)
     */
    private function policyTable(): string
    {
        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            return 'pramnos.framework_policies';
        }
        return '`framework_policies`';
    }

    private function quoteIdentifier(string $name): string
    {
        // Only allow simple identifiers (letters, digits, underscores, dots for schema.table)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid SQL identifier: '{$name}'"
            );
        }

        if ($this->db->type === 'postgresql' || $this->db->type === 'timescaledb') {
            return '"' . str_replace('"', '""', $name) . '"';
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Converts a PostgreSQL interval string like '30 days' or '2 weeks' to a
     * MySQL-style interval token like '30 DAY' or '14 DAY'.
     */
    private function toMySQLInterval(string $pgInterval): string
    {
        // Common simple patterns: '30 days', '7 day', '2 weeks', '1 month', '1 hour'
        if (preg_match('/^(\d+)\s+(second|minute|hour|day|week|month|year)s?$/i', $pgInterval, $m)) {
            $n    = (int) $m[1];
            $unit = strtoupper($m[2]);

            // Normalise weeks to days
            if ($unit === 'WEEK') {
                return ($n * 7) . ' DAY';
            }

            return "{$n} {$unit}";
        }

        // Fallback: pass through as-is and hope MySQL accepts it
        return $pgInterval;
    }
}
