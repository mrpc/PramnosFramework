<?php

declare(strict_types=1);

namespace Pramnos\Application\Statistics;

/**
 * Collects database server metrics using backend-specific queries.
 *
 * PostgreSQL metrics come from pg_stat_database and pg_stat_activity.
 * MySQL metrics come from information_schema and SHOW STATUS variables.
 *
 * All queries are wrapped in try/catch so a missing pg_monitor role or
 * restricted MySQL user degrades gracefully (returns null for the metric).
 *
 * @package     PramnosFramework
 * @subpackage  Application\Statistics
 */
class DatabaseStatsService
{
    private \Pramnos\Database\Database $db;

    public function __construct(?\Pramnos\Database\Database $db = null)
    {
        $this->db = $db ?? \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * Returns database server metrics. The exact keys depend on the backend type.
     *
     * Common keys (present for both backends):
     *   - `type`                (string) 'mysql' | 'postgresql'
     *   - `db_size_bytes`       (int|null)
     *   - `connections_total`   (int|null)
     *   - `connections_active`  (int|null)
     *   - `cache_hit_ratio`     (float|null) — 0–100 percent
     *
     * PostgreSQL-only:
     *   - `xact_commit`    (int|null)
     *   - `xact_rollback`  (int|null)
     *
     * MySQL-only:
     *   - `queries`  (int|null)
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        if ($this->db->type === 'postgresql') {
            return $this->getPostgreSQLStats();
        }

        return $this->getMySQLStats();
    }

    // ── Backend implementations ───────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function getPostgreSQLStats(): array
    {
        $stats = ['type' => 'postgresql'];

        try {
            $r = $this->db->query(
                'SELECT pg_database_size(current_database()) AS db_size'
            );
            $stats['db_size_bytes'] = ($r && $r->numRows > 0)
                ? (int) $r->fields['db_size']
                : null;
        } catch (\Exception $e) {
            $stats['db_size_bytes'] = null;
        }

        try {
            $r = $this->db->query(
                "SELECT count(*) AS total,
                        sum(CASE WHEN state = 'active' THEN 1 ELSE 0 END) AS active
                 FROM pg_stat_activity
                 WHERE datname = current_database()"
            );
            if ($r && $r->numRows > 0) {
                $stats['connections_total']  = (int) $r->fields['total'];
                $stats['connections_active'] = (int) $r->fields['active'];
            } else {
                $stats['connections_total']  = null;
                $stats['connections_active'] = null;
            }
        } catch (\Exception $e) {
            $stats['connections_total']  = null;
            $stats['connections_active'] = null;
        }

        try {
            $r = $this->db->query(
                'SELECT blks_hit, blks_read, xact_commit, xact_rollback
                 FROM pg_stat_database
                 WHERE datname = current_database()'
            );
            if ($r && $r->numRows > 0) {
                $hits  = (int) $r->fields['blks_hit'];
                $reads = (int) $r->fields['blks_read'];
                $stats['cache_hit_ratio']  = ($hits + $reads > 0)
                    ? round($hits / ($hits + $reads) * 100, 2)
                    : null;
                $stats['xact_commit']   = (int) $r->fields['xact_commit'];
                $stats['xact_rollback'] = (int) $r->fields['xact_rollback'];
            } else {
                $stats['cache_hit_ratio'] = null;
                $stats['xact_commit']     = null;
                $stats['xact_rollback']   = null;
            }
        } catch (\Exception $e) {
            $stats['cache_hit_ratio'] = null;
            $stats['xact_commit']     = null;
            $stats['xact_rollback']   = null;
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMySQLStats(): array
    {
        $stats = ['type' => 'mysql'];

        try {
            $r = $this->db->query(
                'SELECT SUM(data_length + index_length) AS db_size
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()'
            );
            $stats['db_size_bytes'] = ($r && $r->numRows > 0)
                ? (int) ($r->fields['db_size'] ?? 0)
                : null;
        } catch (\Exception $e) {
            $stats['db_size_bytes'] = null;
        }

        try {
            $statusVars = [
                'Threads_connected',
                'Threads_running',
                'Queries',
                'Innodb_buffer_pool_reads',
                'Innodb_buffer_pool_read_requests',
            ];
            $collected = [];
            foreach ($statusVars as $var) {
                $r = $this->db->query("SHOW STATUS LIKE '" . addslashes($var) . "'");
                if ($r && $r->numRows > 0) {
                    $collected[(string) $r->fields['Variable_name']] = $r->fields['Value'];
                }
            }

            $stats['connections_total']  = isset($collected['Threads_connected'])
                ? (int) $collected['Threads_connected'] : null;
            $stats['connections_active'] = isset($collected['Threads_running'])
                ? (int) $collected['Threads_running'] : null;
            $stats['queries']            = isset($collected['Queries'])
                ? (int) $collected['Queries'] : null;

            $poolReads    = (int) ($collected['Innodb_buffer_pool_reads']         ?? 0);
            $poolRequests = (int) ($collected['Innodb_buffer_pool_read_requests'] ?? 0);
            $stats['cache_hit_ratio'] = ($poolRequests > 0)
                ? round((1 - $poolReads / $poolRequests) * 100, 2)
                : null;

        } catch (\Exception $e) {
            $stats['connections_total']  = null;
            $stats['connections_active'] = null;
            $stats['queries']            = null;
            $stats['cache_hit_ratio']    = null;
        }

        return $stats;
    }
}
