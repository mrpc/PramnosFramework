<?php

declare(strict_types=1);

namespace Pramnos\Database\Inspector;

use Pramnos\Database\Database;

/**
 * Queries database-engine internals for admin/ops dashboards.
 *
 * Covers: active process list, table sizes, streaming replication status,
 * and public-schema view definitions.  All methods are safe to call on any
 * supported database type; they return empty arrays when the feature is not
 * available (e.g. replication on a standalone instance, views on MySQL).
 *
 */
class DatabaseInspector
{
    public function __construct(private readonly Database $db) {}

    /**
     * Returns active database processes / queries.
     *
     * PostgreSQL: queries pg_stat_activity, includes datname, client_addr,
     * backend_start, and duration_sec.
     * MySQL/MariaDB: executes SHOW PROCESSLIST.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProcessList(): array
    {
        try {
            if ($this->db->type === 'postgresql') {
                /*
                 * `active_sec` and `idle_sec`, because `state` decides which number means
                 * anything.
                 *
                 * `duration_sec` was `now() - query_start` for every row, idle ones included —
                 * where it measures time since the connection last ran anything, not work in
                 * progress. A pooled connection sitting idle for three hours was reported as
                 * running for 194 minutes, in red, so the screen looked like a stuck query every
                 * time somebody opened it. Two of those and nobody reads the column again.
                 *
                 * `active_sec` is the running query's own age and is null unless the backend is
                 * running one. `idle_sec` is how long it has been idle. `duration_sec` stays for
                 * anything already reading it.
                 */
                $r = $this->db->query(
                    "SELECT pid, usename, datname, application_name,
                            client_addr::text AS client_addr,
                            state, wait_event_type, wait_event,
                            to_char(backend_start, 'YYYY-MM-DD HH24:MI:SS') AS backend_start,
                            CASE WHEN state = 'active'
                                THEN EXTRACT(EPOCH FROM (now() - query_start))::int
                            END AS active_sec,
                            CASE WHEN state <> 'active'
                                THEN EXTRACT(EPOCH FROM (now() - state_change))::int
                            END AS idle_sec,
                            EXTRACT(EPOCH FROM (now() - query_start))::int AS duration_sec,
                            left(query, 200) AS query
                     FROM pg_stat_activity
                     WHERE datname = current_database() AND pid <> pg_backend_pid()
                     ORDER BY (state = 'active') DESC, duration_sec DESC NULLS LAST
                     LIMIT 50"
                );
            } else {
                $r = $this->db->query('SHOW PROCESSLIST');
            }
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Returns table sizes sorted by total bytes descending (top 30).
     *
     * PostgreSQL: uses pg_total_relation_size / pg_relation_size.
     * MySQL/MariaDB: uses information_schema.tables.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Table sizes, largest first.
     *
     * **TimescaleDB chunks are excluded.** A hypertable's storage lives in `_timescaledb_internal`
     * as one table per chunk — `_hyper_7_15_chunk` and forty like it — and they are not tables
     * anybody put anything in: they are the extension's own partitioning, named after nothing a
     * person recognises. Listed, they crowd out the tables somebody was looking for, and they
     * double-count the storage the hypertable already reports. The Timescale section is where
     * chunks belong, counted rather than named.
     *
     * The PostgreSQL query reads `pg_tables`, not `information_schema.tables`.
     * It selected `schemaname` and `tablename` — which are `pg_tables`' column
     * names — from `information_schema.tables`, which calls them `table_schema`
     * and `table_name`. So the statement was invalid, the query failed, and this
     * returned an empty array on **every** PostgreSQL project: the database
     * dashboard's main table, which is the reason the page exists, had never
     * listed anything.
     *
     * It failed silently because a failed query and an empty result are the same
     * thing to the caller — `($r && $r->numRows > 0) ? … : []` — and an empty
     * table list on a dashboard reads as a page still loading, or as a database
     * with nothing in it.
     *
     * `pg_tables` also drops the `table_type = 'BASE TABLE'` filter, which was
     * doing that job: it only lists tables.
     */
    public function getTableSizes(): array
    {
        try {
            if ($this->db->type === 'postgresql') {
                $r = $this->db->query(
                    "SELECT schemaname, tablename AS table_name,
                            pg_total_relation_size(quote_ident(schemaname)||'.'||quote_ident(tablename)) AS total_bytes,
                            pg_relation_size(quote_ident(schemaname)||'.'||quote_ident(tablename)) AS data_bytes,
                            pg_total_relation_size(quote_ident(schemaname)||'.'||quote_ident(tablename))
                              - pg_relation_size(quote_ident(schemaname)||'.'||quote_ident(tablename)) AS index_bytes,
                            (SELECT reltuples::bigint FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                             WHERE n.nspname = schemaname AND c.relname = tablename) AS row_estimate
                     FROM pg_tables
                     WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
                       AND schemaname NOT LIKE '\\_timescaledb\\_%'
                     ORDER BY total_bytes DESC
                     LIMIT 30"
                );
            } else {
                $r = $this->db->query(
                    "SELECT table_name, data_length AS data_bytes, index_length AS index_bytes,
                            data_length + index_length AS total_bytes, table_rows AS row_estimate
                     FROM information_schema.tables
                     WHERE table_schema = DATABASE()
                     ORDER BY total_bytes DESC
                     LIMIT 30"
                );
            }
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Returns PostgreSQL streaming replication status rows.
     * Always returns an empty array on non-PostgreSQL databases or when no
     * standbys are connected.
     *
     * Row keys: client_addr, state, sync_state, lag_sec (int).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReplicationStatus(): array
    {
        if ($this->db->type !== 'postgresql') {
            return [];
        }
        try {
            $r = $this->db->query(
                "SELECT client_addr::text AS client_addr, state, sync_state,
                        EXTRACT(EPOCH FROM write_lag)::int AS lag_sec
                 FROM pg_stat_replication
                 ORDER BY client_addr"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * End one backend, by pid.
     *
     * The one destructive thing on an otherwise read-only screen, and it is here rather than on
     * the administration one deliberately: cancelling somebody's query is a developer's action
     * against a development database, and the panel is already behind a development-mode lock
     * and a usertype floor.
     *
     * `pg_terminate_backend`, not `pg_cancel_backend`: cancel asks the query to stop and a
     * backend stuck in a lock wait ignores it, which is exactly the backend somebody is trying
     * to end. The connection dies with it, which is the honest cost and why the button asks
     * first.
     *
     * Refuses to terminate **this** connection — `pg_backend_pid()` is excluded from the process
     * list anyway, and a screen that could kill the request rendering it would answer with a
     * broken pipe.
     */
    public function killProcess(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }

        try {
            if ($this->db->type === 'postgresql') {
                $r = $this->db->query(
                    "SELECT pg_terminate_backend({$pid}) AS killed
                     WHERE {$pid} <> pg_backend_pid()"
                );

                return $r !== false && $r->numRows > 0;
            }

            $this->db->query("KILL {$pid}");

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Which indexes are earning their keep, and which tables are being read the hard way.
     *
     * The developer's question about a database, and the one no screen here asked. An index
     * nobody scans costs a write on every insert and update and buys nothing; a table with
     * millions of sequential scans and no index scans is a query somebody wrote before the data
     * grew. Both are invisible from table sizes, which is what every screen showed instead.
     *
     * PostgreSQL only, because `pg_stat_user_tables` and `pg_stat_user_indexes` have no MySQL
     * equivalent worth pretending about — `SHOW INDEX` says a index exists, not whether anything
     * has ever used it.
     *
     * Row keys: table_name, index_name, scans, size_bytes, seq_scan, idx_scan.
     *
     * @return array{unused: array<int, array<string, mixed>>, scanned: array<int, array<string, mixed>>}
     */
    public function getIndexUsage(): array
    {
        $empty = ['unused' => [], 'scanned' => []];

        if ($this->db->type !== 'postgresql') {
            return $empty;
        }

        try {
            /*
             * Primary keys and unique constraints are excluded from "unused".
             *
             * They are not there to be scanned — they are there to make a duplicate impossible —
             * so listing them as dead weight is telling somebody to drop the thing holding their
             * data together. `indisprimary` and `indisunique` are what separates them.
             */
            $unused = $this->db->query(
                "SELECT s.relname AS table_name, s.indexrelname AS index_name,
                        s.idx_scan AS scans,
                        pg_relation_size(s.indexrelid) AS size_bytes
                 FROM pg_stat_user_indexes s
                 JOIN pg_index i ON i.indexrelid = s.indexrelid
                 WHERE s.idx_scan = 0
                   AND NOT i.indisprimary
                   AND NOT i.indisunique
                   AND pg_relation_size(s.indexrelid) > 16384
                 ORDER BY pg_relation_size(s.indexrelid) DESC
                 LIMIT 20"
            );

            $scanned = $this->db->query(
                "SELECT relname AS table_name, seq_scan, seq_tup_read, idx_scan,
                        n_live_tup AS row_estimate
                 FROM pg_stat_user_tables
                 WHERE seq_scan > 0 AND n_live_tup > 1000
                 ORDER BY seq_tup_read DESC
                 LIMIT 20"
            );

            return [
                'unused'  => ($unused && $unused->numRows > 0) ? $unused->fetchAll() : [],
                'scanned' => ($scanned && $scanned->numRows > 0) ? $scanned->fetchAll() : [],
            ];
        } catch (\Exception) {
            return $empty;
        }
    }

    /**
     * The statements this database spends its time on, when it is able to say.
     *
     * `pg_stat_statements` is an extension and is usually not installed, so this answers with
     * `available => false` rather than with nothing: "the extension is not installed" is a
     * different fact from "no slow queries", and a screen that showed an empty table for both
     * would be telling somebody their database is fine when it has never been asked.
     *
     * @return array{available: bool, rows: array<int, array<string, mixed>>}
     */
    public function getSlowStatements(int $limit = 15): array
    {
        if ($this->db->type !== 'postgresql') {
            return ['available' => false, 'rows' => []];
        }

        try {
            $extension = $this->db->query(
                "SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'"
            );

            if (!$extension || $extension->numRows === 0) {
                return ['available' => false, 'rows' => []];
            }

            $rows = $this->db->query(
                "SELECT calls,
                        ROUND(total_exec_time::numeric, 1) AS total_ms,
                        ROUND(mean_exec_time::numeric, 2) AS mean_ms,
                        rows,
                        left(query, 300) AS query
                 FROM pg_stat_statements
                 ORDER BY total_exec_time DESC
                 LIMIT " . max(1, min(100, $limit))
            );

            return [
                'available' => true,
                'rows'      => ($rows && $rows->numRows > 0) ? $rows->fetchAll() : [],
            ];
        } catch (\Exception) {
            /*
             * Installed and unreadable is not the same as absent.
             *
             * `pg_stat_statements` requires `pg_read_all_stats` or superuser, and an application
             * role usually has neither. Reported as available-with-nothing rather than as
             * unavailable, so the screen says the extension is there and this connection cannot
             * read it — which is a fixable thing, unlike "not installed".
             */
            return ['available' => true, 'rows' => []];
        }
    }

    /**
     * Returns view definitions from the public schema (PostgreSQL only).
     *
     * Row keys: view_name, view_definition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPublicViews(): array
    {
        if ($this->db->type !== 'postgresql') {
            return [];
        }
        try {
            $r = $this->db->query(
                "SELECT table_name AS view_name, view_definition
                 FROM information_schema.views
                 WHERE table_schema = 'public'
                 ORDER BY table_name"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception) {
            return [];
        }
    }
}
