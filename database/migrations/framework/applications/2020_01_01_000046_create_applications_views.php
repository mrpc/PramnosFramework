<?php

namespace Pramnos\Framework\Migrations\Applications;

use Pramnos\Database\Migration;

/**
 * Creates all monitoring/analytics views in the applications schema.
 *
 * Views created (10 total):
 *   api_performance_summary    — per-app response time aggregates (last 24h)
 *   application_health         — health status per app (healthy/degraded/unhealthy)
 *   rate_limit_status          — real-time rate-limit state per app
 *   slow_api_calls             — calls with avg_response_time > 5 000ms
 *   ip_violations              — IPs that violate ip_lock_enabled rules
 *   oauth2_active_tokens       — active OAuth token counts by app
 *   top_applications           — apps ranked by total request volume
 *
 * Three materialized views (PostgreSQL only; regular views on MySQL):
 *   application_stats_daily    — daily request aggregates
 *   application_stats_hourly   — hourly request aggregates
 *   usage_statistics           — 30-day aggregate per app
 *
 * On MySQL, each view is prefixed applications_ to simulate the schema.
 *
 * @package PramnosFramework
 */
class CreateApplicationsViews extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 30;
    public array  $dependencies = [
        'create_applications_schema',
        'create_applications_table',
        'create_application_settings_table',
        'create_application_stats_table',
    ];
    public $description = 'Creates all 10 monitoring/analytics views in the applications schema';

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
    // PostgreSQL                                                           //
    // ------------------------------------------------------------------ //

    private function upPostgreSQL(): void
    {
        // api_performance_summary — response time aggregates per app (last 24h)
        $this->DB()->query("
            CREATE OR REPLACE VIEW applications.api_performance_summary AS
            SELECT
                appid,
                ROUND(AVG(avg_response_time)::NUMERIC, 3)  AS avg_response_time,
                ROUND(MIN(min_response_time)::NUMERIC, 3)  AS min_response_time,
                ROUND(MAX(max_response_time)::NUMERIC, 3)  AS max_response_time,
                SUM(total_requests)                         AS total_requests,
                CASE WHEN SUM(total_requests) > 0
                     THEN ROUND(
                         (SUM(successful_requests) * 100.0 / SUM(total_requests))::NUMERIC, 2
                     )
                     ELSE 100.0
                END                                         AS success_rate
            FROM applications.application_stats
            WHERE time >= NOW() - INTERVAL '24 hours'
            GROUP BY appid
        ");

        // application_health — overall health status per app
        $this->DB()->query("
            CREATE OR REPLACE VIEW applications.application_health AS
            SELECT
                appid,
                CASE
                    WHEN avg_response_time > 5000 OR success_rate < 90  THEN 'unhealthy'
                    WHEN avg_response_time > 2000 OR success_rate < 99  THEN 'degraded'
                    ELSE 'healthy'
                END                           AS overall_status,
                ROUND((100.0 - success_rate)::NUMERIC, 2) AS error_rate,
                avg_response_time             AS avg_latency,
                total_requests                AS throughput,
                NOW()                         AS last_update
            FROM applications.api_performance_summary
        ");

        // rate_limit_status — current rate-limit window state per app
        $this->DB()->query("
            CREATE OR REPLACE VIEW applications.rate_limit_status AS
            SELECT
                s.appid,
                COALESCE(SUM(st.total_requests), 0)         AS requests_in_current_window,
                s.rate_limit_requests                       AS \"limit\",
                GREATEST(
                    0,
                    s.rate_limit_requests - COALESCE(SUM(st.total_requests)::BIGINT, 0)
                )                                           AS remaining,
                (date_trunc('second', NOW()) +
                    (s.rate_limit_window_seconds || ' seconds')::INTERVAL
                )                                           AS resets_at,
                COALESCE(SUM(st.total_requests), 0) >= s.rate_limit_requests
                                                            AS is_limited
            FROM applications.application_settings s
            LEFT JOIN applications.application_stats st
                   ON st.appid = s.appid
                  AND st.time  >= NOW() - (s.rate_limit_window_seconds || ' seconds')::INTERVAL
            GROUP BY s.appid, s.rate_limit_requests, s.rate_limit_window_seconds
        ");

        // slow_api_calls — entries where avg_response_time > 5 000ms
        $this->DB()->query("
            CREATE OR REPLACE VIEW applications.slow_api_calls AS
            SELECT
                appid,
                time                    AS \"timestamp\",
                avg_response_time       AS response_time,
                status_4xx + status_5xx AS error_count,
                total_requests,
                country_code
            FROM applications.application_stats
            WHERE avg_response_time > 5000
        ");

        // ip_violations — IPs found in blocked_ips list
        $this->DB()->query("
            CREATE OR REPLACE VIEW applications.ip_violations AS
            SELECT
                st.appid,
                st.country_code  AS ip_address,
                SUM(st.total_requests) AS violation_count,
                MIN(st.time)           AS first_attempt,
                MAX(st.time)           AS last_attempt,
                'blocked'              AS status
            FROM applications.application_stats st
            JOIN applications.application_settings s ON s.appid = st.appid
            WHERE s.ip_lock_enabled = TRUE
            GROUP BY st.appid, st.country_code
        ");

        // oauth2_active_tokens — active OAuth token summary by app
        $this->DB()->query("
            CREATE OR REPLACE VIEW applications.oauth2_active_tokens AS
            SELECT
                a.appid,
                COUNT(ut.tokenid) FILTER (WHERE ut.status = 1 AND ut.expires > EXTRACT(EPOCH FROM NOW()))
                                                                AS token_count,
                COUNT(ut.tokenid) FILTER (WHERE ut.expires <= EXTRACT(EPOCH FROM NOW()))
                                                                AS expired_count,
                COUNT(ut.tokenid) FILTER (WHERE ut.status <> 1) AS revoked_count,
                ROUND(
                    AVG((ut.expires - EXTRACT(EPOCH FROM NOW())) / 86400.0) FILTER (
                        WHERE ut.status = 1 AND ut.expires > EXTRACT(EPOCH FROM NOW())
                    )::NUMERIC, 2
                )                                               AS avg_expiry_days
            FROM public.applications a
            LEFT JOIN public.usertokens ut ON ut.applicationid = a.appid
            GROUP BY a.appid
        ");

        // top_applications — applications ranked by total request volume
        $this->DB()->query("
            CREATE OR REPLACE VIEW applications.top_applications AS
            SELECT
                ROW_NUMBER() OVER (ORDER BY SUM(total_requests) DESC) AS rank,
                appid,
                SUM(total_requests)                                    AS total_requests,
                SUM(successful_requests)                               AS successful_requests,
                ROUND(AVG(avg_response_time)::NUMERIC, 3)             AS avg_response_time
            FROM applications.application_stats
            GROUP BY appid
        ");

        // application_stats_daily — materialized daily aggregate
        $this->DB()->query("
            CREATE MATERIALIZED VIEW IF NOT EXISTS applications.application_stats_daily AS
            SELECT
                date_trunc('day', time)      AS date,
                appid,
                SUM(total_requests)          AS total_requests,
                SUM(successful_requests)     AS successful_requests,
                SUM(failed_requests)         AS failed_requests,
                ROUND(AVG(avg_response_time)::NUMERIC, 3) AS avg_response_time,
                SUM(status_2xx)              AS status_2xx,
                SUM(status_3xx)              AS status_3xx,
                SUM(status_4xx)              AS status_4xx,
                SUM(status_5xx)              AS status_5xx
            FROM applications.application_stats
            GROUP BY date_trunc('day', time), appid
            WITH NO DATA
        ");
        $this->DB()->query(
            "CREATE INDEX IF NOT EXISTS idx_app_stats_daily_appid_date
             ON applications.application_stats_daily (appid, date)"
        );

        // application_stats_hourly — materialized hourly aggregate
        $this->DB()->query("
            CREATE MATERIALIZED VIEW IF NOT EXISTS applications.application_stats_hourly AS
            SELECT
                date_trunc('hour', time)     AS hour,
                appid,
                SUM(total_requests)          AS total_requests,
                SUM(successful_requests)     AS successful_requests,
                ROUND(AVG(avg_response_time)::NUMERIC, 3) AS avg_response_time,
                SUM(status_4xx + status_5xx) AS error_count
            FROM applications.application_stats
            GROUP BY date_trunc('hour', time), appid
            WITH NO DATA
        ");
        $this->DB()->query(
            "CREATE INDEX IF NOT EXISTS idx_app_stats_hourly_appid_hour
             ON applications.application_stats_hourly (appid, hour)"
        );

        // usage_statistics — 30-day aggregate per app (materialized)
        $this->DB()->query("
            CREATE MATERIALIZED VIEW IF NOT EXISTS applications.usage_statistics AS
            SELECT
                appid,
                SUM(total_requests)       AS total_requests,
                SUM(unique_ips_approx)    AS unique_users,
                SUM(bytes_sent + bytes_received) AS bytes_transferred,
                json_agg(DISTINCT country_code) FILTER (WHERE country_code IS NOT NULL)
                                          AS top_country_codes,
                '30 days'::TEXT           AS period
            FROM applications.application_stats
            WHERE time >= NOW() - INTERVAL '30 days'
            GROUP BY appid
            WITH NO DATA
        ");
    }

    // ------------------------------------------------------------------ //
    // MySQL                                                                //
    // ------------------------------------------------------------------ //

    private function upMySQL(): void
    {
        // api_performance_summary
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_api_performance_summary` AS
            SELECT
                appid,
                ROUND(AVG(avg_response_time), 3)  AS avg_response_time,
                ROUND(MIN(min_response_time), 3)  AS min_response_time,
                ROUND(MAX(max_response_time), 3)  AS max_response_time,
                SUM(total_requests)               AS total_requests,
                CASE WHEN SUM(total_requests) > 0
                     THEN ROUND(SUM(successful_requests) * 100.0 / SUM(total_requests), 2)
                     ELSE 100.0
                END AS success_rate
            FROM `applications_application_stats`
            WHERE `time` >= NOW() - INTERVAL 24 HOUR
            GROUP BY appid
        ");

        // application_health
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_application_health` AS
            SELECT
                appid,
                CASE
                    WHEN avg_response_time > 5000 OR success_rate < 90  THEN 'unhealthy'
                    WHEN avg_response_time > 2000 OR success_rate < 99  THEN 'degraded'
                    ELSE 'healthy'
                END                                    AS overall_status,
                ROUND(100.0 - success_rate, 2)        AS error_rate,
                avg_response_time                      AS avg_latency,
                total_requests                         AS throughput,
                NOW()                                  AS last_update
            FROM `applications_api_performance_summary`
        ");

        // rate_limit_status
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_rate_limit_status` AS
            SELECT
                s.appid,
                COALESCE(SUM(st.total_requests), 0)  AS requests_in_current_window,
                s.rate_limit_requests                AS `limit`,
                GREATEST(0,
                    CAST(s.rate_limit_requests AS SIGNED) -
                    CAST(COALESCE(SUM(st.total_requests), 0) AS SIGNED)
                )                                    AS remaining,
                DATE_ADD(NOW(), INTERVAL s.rate_limit_window_seconds SECOND)
                                                     AS resets_at,
                COALESCE(SUM(st.total_requests), 0) >= s.rate_limit_requests
                                                     AS is_limited
            FROM `applications_application_settings` s
            LEFT JOIN `applications_application_stats` st
                   ON st.appid = s.appid
                  AND st.`time` >= DATE_SUB(NOW(), INTERVAL s.rate_limit_window_seconds SECOND)
            GROUP BY s.appid, s.rate_limit_requests, s.rate_limit_window_seconds
        ");

        // slow_api_calls
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_slow_api_calls` AS
            SELECT
                appid,
                `time`                            AS `timestamp`,
                avg_response_time                 AS response_time,
                status_4xx + status_5xx           AS error_count,
                total_requests,
                country_code
            FROM `applications_application_stats`
            WHERE avg_response_time > 5000
        ");

        // ip_violations
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_ip_violations` AS
            SELECT
                st.appid,
                st.country_code                    AS ip_address,
                SUM(st.total_requests)             AS violation_count,
                MIN(st.`time`)                     AS first_attempt,
                MAX(st.`time`)                     AS last_attempt,
                'blocked'                          AS status
            FROM `applications_application_stats` st
            JOIN `applications_application_settings` s ON s.appid = st.appid
            WHERE s.ip_lock_enabled = 1
            GROUP BY st.appid, st.country_code
        ");

        // oauth2_active_tokens
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_oauth2_active_tokens_summary` AS
            SELECT
                a.appid,
                COUNT(CASE WHEN ut.status = 1 AND ut.expires > UNIX_TIMESTAMP() THEN 1 END)
                    AS token_count,
                COUNT(CASE WHEN ut.expires <= UNIX_TIMESTAMP() THEN 1 END)
                    AS expired_count,
                COUNT(CASE WHEN ut.status <> 1 THEN 1 END)
                    AS revoked_count,
                ROUND(
                    AVG(CASE WHEN ut.status = 1 AND ut.expires > UNIX_TIMESTAMP()
                        THEN (ut.expires - UNIX_TIMESTAMP()) / 86400.0 END), 2
                ) AS avg_expiry_days
            FROM `applications` a
            LEFT JOIN `usertokens` ut ON ut.applicationid = a.appid
            GROUP BY a.appid
        ");

        // top_applications
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_top_applications` AS
            SELECT
                ROW_NUMBER() OVER (ORDER BY SUM(total_requests) DESC) AS `rank`,
                appid,
                SUM(total_requests)                      AS total_requests,
                SUM(successful_requests)                 AS successful_requests,
                ROUND(AVG(avg_response_time), 3)         AS avg_response_time
            FROM `applications_application_stats`
            GROUP BY appid
        ");

        // application_stats_daily (regular VIEW on MySQL — no MATERIALIZED support)
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_application_stats_daily` AS
            SELECT
                DATE(`time`)                             AS `date`,
                appid,
                SUM(total_requests)                      AS total_requests,
                SUM(successful_requests)                 AS successful_requests,
                SUM(failed_requests)                     AS failed_requests,
                ROUND(AVG(avg_response_time), 3)         AS avg_response_time,
                SUM(status_2xx)                          AS status_2xx,
                SUM(status_3xx)                          AS status_3xx,
                SUM(status_4xx)                          AS status_4xx,
                SUM(status_5xx)                          AS status_5xx
            FROM `applications_application_stats`
            GROUP BY DATE(`time`), appid
        ");

        // application_stats_hourly
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_application_stats_hourly` AS
            SELECT
                DATE_FORMAT(`time`, '%Y-%m-%d %H:00:00')  AS `hour`,
                appid,
                SUM(total_requests)                        AS total_requests,
                SUM(successful_requests)                   AS successful_requests,
                ROUND(AVG(avg_response_time), 3)           AS avg_response_time,
                SUM(status_4xx + status_5xx)               AS error_count
            FROM `applications_application_stats`
            GROUP BY DATE_FORMAT(`time`, '%Y-%m-%d %H:00:00'), appid
        ");

        // usage_statistics
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_usage_statistics` AS
            SELECT
                appid,
                SUM(total_requests)              AS total_requests,
                SUM(unique_ips_approx)           AS unique_users,
                SUM(bytes_sent + bytes_received) AS bytes_transferred,
                NULL                             AS top_country_codes,
                '30 days'                        AS period
            FROM `applications_application_stats`
            WHERE `time` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY appid
        ");
    }

    // ------------------------------------------------------------------ //
    // Rollback                                                             //
    // ------------------------------------------------------------------ //

    public function down(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS applications.usage_statistics");
            $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS applications.application_stats_hourly");
            $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS applications.application_stats_daily");
            $this->DB()->query("DROP VIEW IF EXISTS applications.top_applications");
            $this->DB()->query("DROP VIEW IF EXISTS applications.oauth2_active_tokens");
            $this->DB()->query("DROP VIEW IF EXISTS applications.ip_violations");
            $this->DB()->query("DROP VIEW IF EXISTS applications.slow_api_calls");
            $this->DB()->query("DROP VIEW IF EXISTS applications.rate_limit_status");
            $this->DB()->query("DROP VIEW IF EXISTS applications.application_health");
            $this->DB()->query("DROP VIEW IF EXISTS applications.api_performance_summary");
        } else {
            foreach ([
                'applications_usage_statistics',
                'applications_application_stats_hourly',
                'applications_application_stats_daily',
                'applications_top_applications',
                'applications_oauth2_active_tokens_summary',
                'applications_ip_violations',
                'applications_slow_api_calls',
                'applications_rate_limit_status',
                'applications_application_health',
                'applications_api_performance_summary',
            ] as $view) {
                $this->DB()->query("DROP VIEW IF EXISTS `{$view}`");
            }
        }
    }
}
