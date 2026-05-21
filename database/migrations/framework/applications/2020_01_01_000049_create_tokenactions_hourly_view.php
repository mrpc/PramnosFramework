<?php

namespace Pramnos\Framework\Migrations\Applications;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the applications.tokenactions_hourly continuous aggregate / view.
 *
 * Aggregates public.tokenactions into hourly buckets per (tokenid, urlid, method,
 * return_status). Provides pre-computed performance statistics — request counts,
 * response time percentiles, and HTTP status class breakdowns — without requiring
 * a full scan of the raw tokenactions hypertable.
 *
 * On TimescaleDB: a continuous aggregate (auto-refreshed hourly; 3h lookback, 1h
 * end-offset so in-flight rows within the last hour are excluded).
 * On plain PostgreSQL: a MATERIALIZED VIEW (refresh manually or via cron).
 * On MySQL: a plain VIEW (computed on each query; no percentile support).
 *
 * Requires: create_tokenactions_table, create_applications_schema.
 *
 * @package PramnosFramework
 */
class CreateTokenactionsHourlyView extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 31;
    public array  $dependencies = [
        'create_applications_schema',
        'create_tokenactions_table',
    ];
    public $description = 'Creates applications.tokenactions_hourly continuous aggregate / view';

    public function up(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->upPostgreSQL();
        } else {
            $this->upMySQL();
        }
    }

    // ------------------------------------------------------------------ //
    // PostgreSQL / TimescaleDB                                             //
    // ------------------------------------------------------------------ //

    private function upPostgreSQL(): void
    {
        $schema = $this->DB()->schema();

        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                // source: public.tokenactions is a hypertable partitioned on action_time.
                $schema->createContinuousAggregate(
                    'applications.tokenactions_hourly',
                    "SELECT
                         time_bucket('1 hour', action_time)                                     AS bucket,
                         tokenid,
                         urlid,
                         method,
                         return_status,
                         COUNT(*)                                                                AS request_count,
                         AVG(execution_time_ms)                                                 AS avg_execution_time,
                         MIN(execution_time_ms)                                                 AS min_execution_time,
                         MAX(execution_time_ms)                                                 AS max_execution_time,
                         percentile_cont(0.5) WITHIN GROUP (ORDER BY execution_time_ms::float)  AS median_execution_time,
                         percentile_cont(0.95) WITHIN GROUP (ORDER BY execution_time_ms::float) AS p95_execution_time,
                         COUNT(*) FILTER (WHERE return_status >= 200 AND return_status <= 299)   AS success_count,
                         COUNT(*) FILTER (WHERE return_status >= 400 AND return_status <= 499)   AS client_error_count,
                         COUNT(*) FILTER (WHERE return_status >= 500 AND return_status <= 599)   AS server_error_count
                     FROM public.tokenactions
                     WHERE action_time IS NOT NULL
                     GROUP BY time_bucket('1 hour', action_time), tokenid, urlid, method, return_status"
                );
                $schema->addContinuousAggregatePolicy(
                    'applications.tokenactions_hourly',
                    '3 hours',
                    '1 hour',
                    '1 hour'
                );
            },
            function () use ($schema) {
                // plain PostgreSQL: percentile_cont requires ordering; use NULL for p50/p95.
                $schema->createMaterializedView(
                    'applications.tokenactions_hourly',
                    "SELECT
                         date_trunc('hour', action_time)                                        AS bucket,
                         tokenid,
                         urlid,
                         method,
                         return_status,
                         COUNT(*)                                                               AS request_count,
                         AVG(execution_time_ms)                                                AS avg_execution_time,
                         MIN(execution_time_ms)                                                AS min_execution_time,
                         MAX(execution_time_ms)                                                AS max_execution_time,
                         NULL::DOUBLE PRECISION                                                AS median_execution_time,
                         NULL::DOUBLE PRECISION                                                AS p95_execution_time,
                         COUNT(*) FILTER (WHERE return_status >= 200 AND return_status <= 299) AS success_count,
                         COUNT(*) FILTER (WHERE return_status >= 400 AND return_status <= 499) AS client_error_count,
                         COUNT(*) FILTER (WHERE return_status >= 500 AND return_status <= 599) AS server_error_count
                     FROM public.tokenactions
                     WHERE action_time IS NOT NULL
                     GROUP BY date_trunc('hour', action_time), tokenid, urlid, method, return_status
                     WITH NO DATA"
                );
                $this->DB()->query(
                    "CREATE INDEX IF NOT EXISTS idx_tokenactions_hourly_bucket_token
                     ON applications.tokenactions_hourly (bucket, tokenid)"
                );
            }
        );
    }

    // ------------------------------------------------------------------ //
    // MySQL                                                                //
    // ------------------------------------------------------------------ //

    private function upMySQL(): void
    {
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_tokenactions_hourly` AS
            SELECT
                DATE_FORMAT(action_time, '%Y-%m-%d %H:00:00')                      AS bucket,
                tokenid,
                urlid,
                method,
                return_status,
                COUNT(*)                                                             AS request_count,
                AVG(execution_time_ms)                                              AS avg_execution_time,
                MIN(execution_time_ms)                                              AS min_execution_time,
                MAX(execution_time_ms)                                              AS max_execution_time,
                NULL                                                                AS median_execution_time,
                NULL                                                                AS p95_execution_time,
                SUM(CASE WHEN return_status >= 200 AND return_status <= 299 THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN return_status >= 400 AND return_status <= 499 THEN 1 ELSE 0 END) AS client_error_count,
                SUM(CASE WHEN return_status >= 500 AND return_status <= 599 THEN 1 ELSE 0 END) AS server_error_count
            FROM `tokenactions`
            WHERE action_time IS NOT NULL
            GROUP BY DATE_FORMAT(action_time, '%Y-%m-%d %H:00:00'), tokenid, urlid, method, return_status
        ");
    }

    // ------------------------------------------------------------------ //
    // Rollback                                                             //
    // ------------------------------------------------------------------ //

    public function down(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isMySQL()) {
            $this->DB()->query("DROP VIEW IF EXISTS `applications_tokenactions_hourly`");
        } else {
            $this->DB()->query(
                'DROP MATERIALIZED VIEW IF EXISTS applications.tokenactions_hourly CASCADE'
            );
        }
    }
}
