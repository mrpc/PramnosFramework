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
 * @package     PramnosFramework
 * @subpackage  Database\Inspector
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
                $r = $this->db->query(
                    "SELECT pid, usename, datname, application_name,
                            client_addr::text AS client_addr,
                            state, wait_event_type, wait_event,
                            to_char(backend_start, 'YYYY-MM-DD HH24:MI:SS') AS backend_start,
                            EXTRACT(EPOCH FROM (now() - query_start))::int AS duration_sec,
                            left(query, 200) AS query
                     FROM pg_stat_activity
                     WHERE datname = current_database() AND pid <> pg_backend_pid()
                     ORDER BY duration_sec DESC NULLS LAST
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
                     FROM information_schema.tables
                     WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
                       AND table_type = 'BASE TABLE'
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
