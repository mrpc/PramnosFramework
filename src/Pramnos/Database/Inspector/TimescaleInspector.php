<?php

declare(strict_types=1);

namespace Pramnos\Database\Inspector;

use Pramnos\Database\Database;

/**
 * Queries TimescaleDB extension metadata for admin/ops dashboards.
 *
 * Detects TimescaleDB via two mechanisms:
 *   1. Database::$timescale === true (set when config type = 'timescaledb')
 *   2. Live query of pg_extension (works regardless of config)
 *
 * Every individual query is wrapped in its own try/catch so that a column
 * missing in an older TimescaleDB version does not suppress the rest of the
 * data — in particular, ts_version is always preserved if the extension is
 * detected.
 *
 * Returns empty/null on non-PostgreSQL databases or when TimescaleDB is not
 * installed.
 *
 * @package     PramnosFramework
 * @subpackage  Database\Inspector
 */
class TimescaleInspector
{
    public function __construct(private readonly Database $db) {}

    /**
     * Returns all TimescaleDB metadata needed for the dashboard.
     *
     * @return array{
     *   ts_version: string|null,
     *   hypertables: array,
     *   aggregates: array,
     *   jobs: array,
     *   jobHistory: array,
     *   chunkCount: int
     * }
     */
    public function getData(): array
    {
        $empty = [
            'ts_version' => null,
            'hypertables' => [],
            'aggregates'  => [],
            'jobs'        => [],
            'jobHistory'  => [],
            'chunkCount'  => 0,
        ];

        if ($this->db->type !== 'postgresql') {
            return $empty;
        }

        $tsVersion = $this->detectVersion();
        if ($tsVersion === null) {
            return $empty;
        }

        return [
            'ts_version'  => $tsVersion,
            'hypertables' => $this->getHypertables(),
            'aggregates'  => $this->getContinuousAggregates(),
            'jobs'        => $this->getScheduledJobs(),
            'jobHistory'  => $this->getJobHistory(),
            'chunkCount'  => $this->getChunkCount(),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns the installed TimescaleDB version string, or null if not present.
     */
    private function detectVersion(): ?string
    {
        // Fast path: config explicitly marks this as a TimescaleDB connection.
        // Still verify via pg_extension so we have the actual version string.
        try {
            $r = $this->db->query(
                "SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'"
            );
            if ($r && $r->numRows > 0) {
                return (string) $r->fields['extversion'];
            }
        } catch (\Exception) {
        }

        // If the extension row is missing but the connection is flagged as
        // TimescaleDB (unusual — could happen during an upgrade), return a
        // generic marker so callers know TS is in use.
        if ($this->db->timescale) {
            return 'unknown';
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getHypertables(): array
    {
        try {
            $r = $this->db->query(
                "SELECT hypertable_name, num_chunks, num_dimensions,
                        compression_enabled, tablespaces
                 FROM timescaledb_information.hypertables
                 ORDER BY hypertable_name"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Queries continuous aggregates with extended metadata.
     * Falls back to a minimal query if the extended columns are unavailable
     * (older TimescaleDB versions may not have materialization_hypertable_schema).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getContinuousAggregates(): array
    {
        try {
            $r = $this->db->query(
                "SELECT view_schema, view_name,
                        materialization_hypertable_schema AS materialization_schema,
                        materialization_hypertable_name  AS materialization_name,
                        CASE WHEN materialized_only THEN 'Yes' ELSE 'No' END AS materialized_only,
                        compression_enabled
                 FROM timescaledb_information.continuous_aggregates
                 ORDER BY view_name"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
        }

        // Fallback: minimal query without version-specific columns.
        try {
            $r = $this->db->query(
                "SELECT view_schema, view_name,
                        materialization_hypertable_name AS materialization_name,
                        compression_enabled
                 FROM timescaledb_information.continuous_aggregates
                 ORDER BY view_name"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getScheduledJobs(): array
    {
        try {
            $r = $this->db->query(
                "SELECT job_id, proc_schema, proc_name, schedule_interval::text,
                        last_run_started_at::text    AS last_run_started_at,
                        last_successful_finish::text AS last_successful_finish,
                        last_run_status, next_start::text AS next_start
                 FROM timescaledb_information.jobs
                 ORDER BY job_id"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Returns the last 200 job execution history records.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getJobHistory(): array
    {
        try {
            $r = $this->db->query(
                "SELECT job_id, start_time::text AS start_time,
                        finish_time::text AS finish_time,
                        succeeded::text   AS succeeded,
                        proc_schema, proc_name, err_message
                 FROM timescaledb_information.job_history
                 ORDER BY start_time DESC
                 LIMIT 200"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Returns the total number of chunks across all hypertables.
     */
    private function getChunkCount(): int
    {
        try {
            $r = $this->db->query(
                "SELECT COUNT(*) AS total FROM timescaledb_information.chunks"
            );
            return ($r && $r->numRows > 0) ? (int) $r->fields['total'] : 0;
        } catch (\Exception) {
            return 0;
        }
    }
}
