<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the daily_activity_summary view over user_activity_log.
 *
 * On TimescaleDB: created as a continuous aggregate (auto-refreshed by the
 * TimescaleDB background worker). Provides real-time bucketed aggregates
 * without a manual REFRESH MATERIALIZED VIEW step.
 *
 * On MySQL: created as a plain VIEW (no materialisation; computed on each query).
 * On plain PostgreSQL: created as a MATERIALIZED VIEW (must be refreshed manually).
 *
 * The view aggregates: per (day, userid, action) — activity count, distinct IPs,
 * first and last activity timestamp. Keeping `action` in the GROUP BY allows
 * callers to filter or pivot by action type without losing the time-series benefit.
 *
 * Requires: user_activity_log (migration 000021).
 *
 */
class CreateDailyActivitySummaryView extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 125;
    public array   $dependencies = ['create_user_activity_log_table'];
    public $description  = 'Creates the authserver.daily_activity_summary continuous aggregate / materialized view';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        // Requires authserver.user_activity_log to exist
        if (!$schema->hasTable('authserver.user_activity_log')) {
            return;
        }

        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            // TimescaleDB: continuous aggregate — auto-refreshed by background worker
            function () use ($schema) {
                $schema->createContinuousAggregate(
                    'authserver.daily_activity_summary',
                    "SELECT
                         time_bucket('1 day', created_at)  AS day,
                         userid,
                         action,
                         COUNT(*)                          AS activity_count,
                         COUNT(DISTINCT ip_address)        AS unique_ips,
                         MIN(created_at)                   AS first_activity,
                         MAX(created_at)                   AS last_activity
                     FROM authserver.user_activity_log
                     GROUP BY time_bucket('1 day', created_at), userid, action"
                );
                $schema->addContinuousAggregatePolicy(
                    'authserver.daily_activity_summary',
                    '1 month',
                    '1 hour',
                    '1 hour'
                );
            },
            // Fallback: materialized view on PostgreSQL, plain view on MySQL
            function () use ($schema) {
                // quoteTable() handles the schema→prefix translation per backend:
                // MySQL → `authserver_user_activity_log`, PostgreSQL → "authserver"."user_activity_log"
                $activityLog = $schema->quoteTable('authserver.user_activity_log');

                $sql = "SELECT
                            DATE(created_at)           AS day,
                            userid,
                            action,
                            COUNT(*)                   AS activity_count,
                            COUNT(DISTINCT ip_address) AS unique_ips,
                            MIN(created_at)            AS first_activity,
                            MAX(created_at)            AS last_activity
                        FROM {$activityLog}
                        GROUP BY DATE(created_at), userid, action";

                $schema->createMaterializedView('authserver.daily_activity_summary', $sql);
            }
        );
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () {
                $this->application->database->query(
                    'DROP MATERIALIZED VIEW IF EXISTS authserver.daily_activity_summary CASCADE'
                );
            },
            function () use ($schema) {
                $schema->dropView('authserver.daily_activity_summary');
            }
        );
    }
}
