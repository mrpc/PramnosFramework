<?php

namespace Pramnos\Framework\Migrations\Applications;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates all monitoring/analytics views in the applications schema.
 *
 * Views created (11 total):
 *   api_performance_summary    — per-app response time aggregates (last 24h)
 *   application_health         — health status per app (healthy/degraded/unhealthy)
 *   rate_limit_status          — real-time rate-limit state per app
 *   slow_api_calls             — calls with avg_response_time > 5 000ms
 *   ip_violations              — IPs that violate ip_lock_enabled rules
 *   oauth2_active_tokens       — active OAuth token counts by app
 *   oauth2_webhook_status      — per-endpoint delivery stats (sent/failed/pending)
 *   top_applications           — apps ranked by total request volume
 *   usage_statistics           — live multi-CTE: token activity, OAuth grants, webhook health, activity_level
 *
 * Two continuous aggregates (PostgreSQL only; regular views on MySQL):
 *   application_stats_daily    — daily request aggregates (continuous aggregate on TimescaleDB)
 *   application_stats_hourly   — hourly request aggregates (continuous aggregate on TimescaleDB)
 *
 * On MySQL, each view is prefixed applications_ to simulate the schema.
 *
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
        'create_usertokens_table',
        'create_oauth2_webhooks_tables',
        'create_oauth2_application_grants_table',
    ];
    public $description = 'Creates all 11 monitoring/analytics views in the applications schema';

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
        // CASCADE also drops application_health which depends on this view.
        $this->DB()->query("DROP VIEW IF EXISTS applications.api_performance_summary CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.api_performance_summary AS
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
        $this->DB()->query("DROP VIEW IF EXISTS applications.application_health CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.application_health AS
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
        $this->DB()->query("DROP VIEW IF EXISTS applications.rate_limit_status CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.rate_limit_status AS
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
        $this->DB()->query("DROP VIEW IF EXISTS applications.slow_api_calls CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.slow_api_calls AS
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
        $this->DB()->query("DROP VIEW IF EXISTS applications.ip_violations CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.ip_violations AS
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
        $this->DB()->query("DROP VIEW IF EXISTS applications.oauth2_active_tokens CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.oauth2_active_tokens AS
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

        // oauth2_webhook_status — per-endpoint delivery statistics
        $this->DB()->query("DROP VIEW IF EXISTS applications.oauth2_webhook_status CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.oauth2_webhook_status AS
            SELECT
                wep.webhook_id,
                wep.appid,
                a.name                                                          AS app_name,
                wep.webhook_type,
                wep.endpoint_url,
                wep.is_active,
                COUNT(we.event_id)                                              AS total_events,
                COUNT(CASE WHEN we.status = 'sent'    THEN 1 END)              AS successful_events,
                COUNT(CASE WHEN we.status = 'failed'  THEN 1 END)              AS failed_events,
                COUNT(CASE WHEN we.status = 'pending' THEN 1 END)              AS pending_events,
                MAX(we.sent_at)                                                 AS last_successful_delivery,
                AVG(CASE WHEN we.status = 'sent' THEN we.attempts END)         AS avg_attempts_for_success
            FROM applications.oauth2_webhook_endpoints wep
            JOIN public.applications a ON wep.appid = a.appid
            LEFT JOIN applications.oauth2_webhook_events we ON wep.webhook_id = we.webhook_id
            GROUP BY wep.webhook_id, wep.appid, a.name,
                     wep.webhook_type, wep.endpoint_url, wep.is_active
        ");

        // top_applications — applications ranked by total request volume
        $this->DB()->query("DROP VIEW IF EXISTS applications.top_applications CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.top_applications AS
            SELECT
                ROW_NUMBER() OVER (ORDER BY SUM(total_requests) DESC) AS rank,
                appid,
                SUM(total_requests)                                    AS total_requests,
                SUM(successful_requests)                               AS successful_requests,
                ROUND(AVG(avg_response_time)::NUMERIC, 3)             AS avg_response_time
            FROM applications.application_stats
            GROUP BY appid
        ");

        // application_stats_daily — continuous aggregate on TimescaleDB, matview on plain PG
        $schema = $this->DB()->schema();
        $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS applications.application_stats_daily CASCADE");
        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createContinuousAggregate(
                    'applications.application_stats_daily',
                    "SELECT
                         time_bucket('1 day', time)                    AS bucket,
                         appid,
                         SUM(total_requests)                           AS total_requests,
                         SUM(successful_requests)                      AS successful_requests,
                         SUM(failed_requests)                          AS failed_requests,
                         AVG(avg_response_time)                        AS avg_response_time,
                         MIN(min_response_time)                        AS min_response_time,
                         MAX(max_response_time)                        AS max_response_time,
                         SUM(status_2xx)                               AS status_2xx,
                         SUM(status_3xx)                               AS status_3xx,
                         SUM(status_4xx)                               AS status_4xx,
                         SUM(status_5xx)                               AS status_5xx,
                         SUM(rate_limited_requests)                    AS rate_limited_requests,
                         SUM(rate_limit_violations)                    AS rate_limit_violations,
                         SUM(bytes_sent)                               AS bytes_sent,
                         SUM(bytes_received)                           AS bytes_received,
                         COUNT(DISTINCT country_code)                  AS countries_count
                     FROM applications.application_stats
                     GROUP BY time_bucket('1 day', time), appid"
                );
                $schema->addContinuousAggregatePolicy(
                    'applications.application_stats_daily',
                    '3 days',
                    '1 day',
                    '1 day'
                );
            },
            function () use ($schema) {
                $schema->createMaterializedView(
                    'applications.application_stats_daily',
                    "SELECT
                         date_trunc('day', time)                        AS bucket,
                         appid,
                         SUM(total_requests)                           AS total_requests,
                         SUM(successful_requests)                      AS successful_requests,
                         SUM(failed_requests)                          AS failed_requests,
                         AVG(avg_response_time)                        AS avg_response_time,
                         MIN(min_response_time)                        AS min_response_time,
                         MAX(max_response_time)                        AS max_response_time,
                         SUM(status_2xx)                               AS status_2xx,
                         SUM(status_3xx)                               AS status_3xx,
                         SUM(status_4xx)                               AS status_4xx,
                         SUM(status_5xx)                               AS status_5xx,
                         SUM(rate_limited_requests)                    AS rate_limited_requests,
                         SUM(rate_limit_violations)                    AS rate_limit_violations,
                         SUM(bytes_sent)                               AS bytes_sent,
                         SUM(bytes_received)                           AS bytes_received,
                         COUNT(DISTINCT country_code)                  AS countries_count
                     FROM applications.application_stats
                     GROUP BY date_trunc('day', time), appid
                     WITH NO DATA"
                );
                $this->DB()->query(
                    "CREATE INDEX IF NOT EXISTS idx_app_stats_daily_appid_bucket
                     ON applications.application_stats_daily (appid, bucket)"
                );
            }
        );

        // application_stats_hourly — continuous aggregate on TimescaleDB, matview on plain PG
        $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS applications.application_stats_hourly CASCADE");
        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createContinuousAggregate(
                    'applications.application_stats_hourly',
                    "SELECT
                         time_bucket('1 hour', time)                   AS bucket,
                         appid,
                         SUM(total_requests)                           AS total_requests,
                         SUM(successful_requests)                      AS successful_requests,
                         SUM(failed_requests)                          AS failed_requests,
                         AVG(avg_response_time)                        AS avg_response_time,
                         MIN(min_response_time)                        AS min_response_time,
                         MAX(max_response_time)                        AS max_response_time,
                         SUM(status_2xx)                               AS status_2xx,
                         SUM(status_3xx)                               AS status_3xx,
                         SUM(status_4xx)                               AS status_4xx,
                         SUM(status_5xx)                               AS status_5xx,
                         SUM(rate_limited_requests)                    AS rate_limited_requests,
                         SUM(rate_limit_violations)                    AS rate_limit_violations,
                         SUM(bytes_sent)                               AS bytes_sent,
                         SUM(bytes_received)                           AS bytes_received
                     FROM applications.application_stats
                     GROUP BY time_bucket('1 hour', time), appid"
                );
                $schema->addContinuousAggregatePolicy(
                    'applications.application_stats_hourly',
                    '3 hours',
                    '1 hour',
                    '1 hour'
                );
            },
            function () use ($schema) {
                $schema->createMaterializedView(
                    'applications.application_stats_hourly',
                    "SELECT
                         date_trunc('hour', time)                       AS bucket,
                         appid,
                         SUM(total_requests)                           AS total_requests,
                         SUM(successful_requests)                      AS successful_requests,
                         SUM(failed_requests)                          AS failed_requests,
                         AVG(avg_response_time)                        AS avg_response_time,
                         MIN(min_response_time)                        AS min_response_time,
                         MAX(max_response_time)                        AS max_response_time,
                         SUM(status_2xx)                               AS status_2xx,
                         SUM(status_3xx)                               AS status_3xx,
                         SUM(status_4xx)                               AS status_4xx,
                         SUM(status_5xx)                               AS status_5xx,
                         SUM(rate_limited_requests)                    AS rate_limited_requests,
                         SUM(rate_limit_violations)                    AS rate_limit_violations,
                         SUM(bytes_sent)                               AS bytes_sent,
                         SUM(bytes_received)                           AS bytes_received
                     FROM applications.application_stats
                     GROUP BY date_trunc('hour', time), appid
                     WITH NO DATA"
                );
                $this->DB()->query(
                    "CREATE INDEX IF NOT EXISTS idx_app_stats_hourly_appid_bucket
                     ON applications.application_stats_hourly (appid, bucket)"
                );
            }
        );

        // usage_statistics — live multi-CTE view: token activity, OAuth config, webhook health
        $this->DB()->query("DROP VIEW IF EXISTS applications.usage_statistics CASCADE");
        $this->DB()->query("
            CREATE VIEW applications.usage_statistics AS
            WITH token_stats AS (
                SELECT
                    ut.applicationid,
                    COUNT(*)                                                          AS active_tokens_total,
                    COUNT(DISTINCT ut.userid)                                         AS active_users_total,
                    COUNT(*) FILTER (WHERE ut.tokentype IN ('access_token', 'auth')) AS access_tokens,
                    COUNT(*) FILTER (WHERE ut.tokentype = 'refresh_token')            AS refresh_tokens,
                    COUNT(*) FILTER (WHERE ut.tokentype = 'auth_code')                AS auth_codes,
                    MIN(TO_TIMESTAMP(ut.created::DOUBLE PRECISION))                   AS first_token_created,
                    MAX(TO_TIMESTAMP(ut.created::DOUBLE PRECISION))                   AS latest_token_created,
                    AVG(ut.expires - ut.created)                                      AS avg_token_lifetime_seconds,
                    COUNT(*) FILTER (WHERE ut.created::NUMERIC >= EXTRACT(EPOCH FROM NOW() - INTERVAL '24 hours'))  AS tokens_created_24h,
                    COUNT(*) FILTER (WHERE ut.created::NUMERIC >= EXTRACT(EPOCH FROM NOW() - INTERVAL '7 days'))    AS tokens_created_7d,
                    COUNT(*) FILTER (WHERE ut.created::NUMERIC >= EXTRACT(EPOCH FROM NOW() - INTERVAL '30 days'))   AS tokens_created_30d,
                    COUNT(DISTINCT ut.userid) FILTER (WHERE ut.lastused::NUMERIC >= EXTRACT(EPOCH FROM NOW() - INTERVAL '24 hours')) AS active_users_24h,
                    COUNT(DISTINCT ut.userid) FILTER (WHERE ut.lastused::NUMERIC >= EXTRACT(EPOCH FROM NOW() - INTERVAL '7 days'))   AS active_users_7d,
                    COUNT(DISTINCT ut.userid) FILTER (WHERE ut.lastused::NUMERIC >= EXTRACT(EPOCH FROM NOW() - INTERVAL '30 days'))  AS active_users_30d
                FROM public.usertokens ut
                WHERE ut.status = 1 AND ut.expires::NUMERIC > EXTRACT(EPOCH FROM NOW())
                GROUP BY ut.applicationid
            ),
            historical_stats AS (
                SELECT
                    ut.applicationid,
                    COUNT(*)                                                                         AS total_tokens_ever,
                    COUNT(DISTINCT ut.userid)                                                        AS total_users_ever,
                    COUNT(*) FILTER (WHERE ut.status = 3)                                            AS revoked_tokens,
                    COUNT(*) FILTER (WHERE ut.expires::NUMERIC <= EXTRACT(EPOCH FROM NOW()) AND ut.status IN (0, 1)) AS expired_tokens,
                    MAX(TO_TIMESTAMP(ut.lastused::DOUBLE PRECISION)) FILTER (WHERE ut.lastused > 0) AS last_token_activity,
                    AVG(CASE WHEN ut.lastused > 0 THEN ut.lastused - ut.created ELSE NULL::INTEGER END) AS avg_token_usage_duration
                FROM public.usertokens ut
                GROUP BY ut.applicationid
            ),
            oauth_config AS (
                SELECT
                    ag.appid,
                    ARRAY_AGG(ag.grant_type ORDER BY ag.grant_type) FILTER (WHERE ag.is_enabled = TRUE) AS enabled_grant_types,
                    COUNT(*) FILTER (WHERE ag.grant_type = 'authorization_code' AND ag.is_enabled = TRUE) > 0 AS supports_authorization_code,
                    COUNT(*) FILTER (WHERE ag.grant_type = 'client_credentials'  AND ag.is_enabled = TRUE) > 0 AS supports_client_credentials,
                    COUNT(*) FILTER (WHERE ag.grant_type = 'refresh_token'       AND ag.is_enabled = TRUE) > 0 AS supports_refresh_token
                FROM applications.oauth2_application_grants ag
                GROUP BY ag.appid
            ),
            webhook_stats AS (
                SELECT
                    wep.appid,
                    COUNT(DISTINCT wep.webhook_type)                                  AS configured_webhook_types,
                    COUNT(*) FILTER (WHERE wep.is_active = TRUE)                      AS active_webhooks,
                    COALESCE(SUM((SELECT COUNT(*) FROM applications.oauth2_webhook_events ev WHERE ev.webhook_id = wep.webhook_id)), 0) AS total_webhook_events,
                    COALESCE(SUM((SELECT COUNT(*) FROM applications.oauth2_webhook_events ev WHERE ev.webhook_id = wep.webhook_id AND ev.status = 'sent')), 0) AS successful_webhook_events
                FROM applications.oauth2_webhook_endpoints wep
                GROUP BY wep.appid
            )
            SELECT
                a.appid,
                a.name                                                               AS application_name,
                a.apikey                                                             AS client_id,
                a.accesstype,
                a.scope                                                              AS allowed_scopes,
                a.callback                                                           AS redirect_uri,
                COALESCE(ts.active_tokens_total,  0)                                AS active_tokens,
                COALESCE(ts.active_users_total,   0)                                AS active_users,
                COALESCE(ts.access_tokens,        0)                                AS active_access_tokens,
                COALESCE(ts.refresh_tokens,       0)                                AS active_refresh_tokens,
                COALESCE(ts.auth_codes,           0)                                AS active_auth_codes,
                COALESCE(ts.tokens_created_24h,   0)                                AS new_tokens_24h,
                COALESCE(ts.tokens_created_7d,    0)                                AS new_tokens_7d,
                COALESCE(ts.tokens_created_30d,   0)                                AS new_tokens_30d,
                COALESCE(ts.active_users_24h,     0)                                AS active_users_24h,
                COALESCE(ts.active_users_7d,      0)                                AS active_users_7d,
                COALESCE(ts.active_users_30d,     0)                                AS active_users_30d,
                COALESCE(hs.total_tokens_ever,    0)                                AS total_tokens_ever,
                COALESCE(hs.total_users_ever,     0)                                AS total_users_ever,
                COALESCE(hs.revoked_tokens,       0)                                AS revoked_tokens,
                COALESCE(hs.expired_tokens,       0)                                AS expired_tokens,
                ts.first_token_created,
                ts.latest_token_created,
                hs.last_token_activity,
                ROUND(COALESCE(ts.avg_token_lifetime_seconds, 0) / 3600.0, 2)       AS avg_token_lifetime_hours,
                ROUND(COALESCE(hs.avg_token_usage_duration,   0) / 3600.0, 2)       AS avg_token_usage_duration_hours,
                COALESCE(oc.enabled_grant_types, ARRAY[]::VARCHAR[])                AS enabled_grant_types,
                COALESCE(oc.supports_authorization_code, FALSE)                     AS supports_authorization_code,
                COALESCE(oc.supports_client_credentials,  FALSE)                    AS supports_client_credentials,
                COALESCE(oc.supports_refresh_token,       FALSE)                    AS supports_refresh_token,
                COALESCE(ws.configured_webhook_types,     0)                        AS configured_webhook_types,
                COALESCE(ws.active_webhooks,              0)                        AS active_webhooks,
                COALESCE(ws.total_webhook_events,         0)                        AS total_webhook_events,
                COALESCE(ws.successful_webhook_events,    0)                        AS successful_webhook_events,
                CASE WHEN ws.total_webhook_events > 0
                     THEN ROUND(ws.successful_webhook_events / ws.total_webhook_events * 100.0, 2)
                     ELSE NULL
                END                                                                  AS webhook_success_rate_percent,
                CASE
                    WHEN COALESCE(ts.active_users_total, 0) = 0  THEN 'Inactive'
                    WHEN COALESCE(ts.active_users_24h,   0) > 0  THEN 'Highly Active'
                    WHEN COALESCE(ts.active_users_7d,    0) > 0  THEN 'Active'
                    WHEN COALESCE(ts.active_users_30d,   0) > 0  THEN 'Low Activity'
                    ELSE 'Dormant'
                END                                                                  AS activity_level,
                CASE WHEN a.accesstype = 1 THEN 'OAuth2 Application' ELSE 'Legacy Application' END AS application_type,
                NOW()                                                                AS stats_updated_at
            FROM public.applications a
            LEFT JOIN token_stats    ts ON a.appid = ts.applicationid
            LEFT JOIN historical_stats hs ON a.appid = hs.applicationid
            LEFT JOIN oauth_config   oc ON a.appid = oc.appid
            LEFT JOIN webhook_stats  ws ON a.appid = ws.appid
            ORDER BY COALESCE(ts.active_users_total, 0) DESC,
                     COALESCE(ts.active_tokens_total, 0) DESC
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

        // oauth2_webhook_status
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_oauth2_webhook_status` AS
            SELECT
                wep.webhook_id,
                wep.appid,
                a.name                                                           AS app_name,
                wep.webhook_type,
                wep.endpoint_url,
                wep.is_active,
                COUNT(we.event_id)                                               AS total_events,
                SUM(CASE WHEN we.status = 'sent'    THEN 1 ELSE 0 END)          AS successful_events,
                SUM(CASE WHEN we.status = 'failed'  THEN 1 ELSE 0 END)          AS failed_events,
                SUM(CASE WHEN we.status = 'pending' THEN 1 ELSE 0 END)          AS pending_events,
                MAX(we.sent_at)                                                  AS last_successful_delivery,
                AVG(CASE WHEN we.status = 'sent' THEN we.attempts END)          AS avg_attempts_for_success
            FROM `applications_oauth2_webhook_endpoints` wep
            JOIN `applications` a ON wep.appid = a.appid
            LEFT JOIN `applications_oauth2_webhook_events` we ON wep.webhook_id = we.webhook_id
            GROUP BY wep.webhook_id, wep.appid, a.name,
                     wep.webhook_type, wep.endpoint_url, wep.is_active
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
                DATE(`time`)                              AS bucket,
                appid,
                SUM(total_requests)                       AS total_requests,
                SUM(successful_requests)                  AS successful_requests,
                SUM(failed_requests)                      AS failed_requests,
                AVG(avg_response_time)                    AS avg_response_time,
                MIN(min_response_time)                    AS min_response_time,
                MAX(max_response_time)                    AS max_response_time,
                SUM(status_2xx)                           AS status_2xx,
                SUM(status_3xx)                           AS status_3xx,
                SUM(status_4xx)                           AS status_4xx,
                SUM(status_5xx)                           AS status_5xx,
                SUM(rate_limited_requests)                AS rate_limited_requests,
                SUM(rate_limit_violations)                AS rate_limit_violations,
                SUM(bytes_sent)                           AS bytes_sent,
                SUM(bytes_received)                       AS bytes_received,
                COUNT(DISTINCT country_code)              AS countries_count
            FROM `applications_application_stats`
            GROUP BY DATE(`time`), appid
        ");

        // application_stats_hourly
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_application_stats_hourly` AS
            SELECT
                DATE_FORMAT(`time`, '%Y-%m-%d %H:00:00')  AS bucket,
                appid,
                SUM(total_requests)                        AS total_requests,
                SUM(successful_requests)                   AS successful_requests,
                SUM(failed_requests)                       AS failed_requests,
                AVG(avg_response_time)                     AS avg_response_time,
                MIN(min_response_time)                     AS min_response_time,
                MAX(max_response_time)                     AS max_response_time,
                SUM(status_2xx)                            AS status_2xx,
                SUM(status_3xx)                            AS status_3xx,
                SUM(status_4xx)                            AS status_4xx,
                SUM(status_5xx)                            AS status_5xx,
                SUM(rate_limited_requests)                 AS rate_limited_requests,
                SUM(rate_limit_violations)                 AS rate_limit_violations,
                SUM(bytes_sent)                            AS bytes_sent,
                SUM(bytes_received)                        AS bytes_received
            FROM `applications_application_stats`
            GROUP BY DATE_FORMAT(`time`, '%Y-%m-%d %H:00:00'), appid
        ");

        // usage_statistics — live multi-CTE view: token activity, OAuth config, webhook health
        $this->DB()->query("
            CREATE OR REPLACE VIEW `applications_usage_statistics` AS
            WITH token_stats AS (
                SELECT
                    ut.applicationid,
                    COUNT(*)                                                                               AS active_tokens_total,
                    COUNT(DISTINCT ut.userid)                                                               AS active_users_total,
                    SUM(CASE WHEN ut.tokentype IN ('access_token','auth') THEN 1 ELSE 0 END)               AS access_tokens,
                    SUM(CASE WHEN ut.tokentype = 'refresh_token'          THEN 1 ELSE 0 END)               AS refresh_tokens,
                    SUM(CASE WHEN ut.tokentype = 'auth_code'              THEN 1 ELSE 0 END)               AS auth_codes,
                    MIN(FROM_UNIXTIME(ut.created))                                                         AS first_token_created,
                    MAX(FROM_UNIXTIME(ut.created))                                                         AS latest_token_created,
                    AVG(ut.expires - ut.created)                                                            AS avg_token_lifetime_seconds,
                    SUM(CASE WHEN ut.created >= UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR)  THEN 1 ELSE 0 END) AS tokens_created_24h,
                    SUM(CASE WHEN ut.created >= UNIX_TIMESTAMP(NOW() - INTERVAL 7 DAY)    THEN 1 ELSE 0 END) AS tokens_created_7d,
                    SUM(CASE WHEN ut.created >= UNIX_TIMESTAMP(NOW() - INTERVAL 30 DAY)   THEN 1 ELSE 0 END) AS tokens_created_30d,
                    COUNT(DISTINCT CASE WHEN ut.lastused >= UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR) THEN ut.userid END) AS active_users_24h,
                    COUNT(DISTINCT CASE WHEN ut.lastused >= UNIX_TIMESTAMP(NOW() - INTERVAL 7 DAY)   THEN ut.userid END) AS active_users_7d,
                    COUNT(DISTINCT CASE WHEN ut.lastused >= UNIX_TIMESTAMP(NOW() - INTERVAL 30 DAY)  THEN ut.userid END) AS active_users_30d
                FROM `usertokens` ut
                WHERE ut.status = 1 AND ut.expires > UNIX_TIMESTAMP()
                GROUP BY ut.applicationid
            ),
            historical_stats AS (
                SELECT
                    ut.applicationid,
                    COUNT(*)                                                                           AS total_tokens_ever,
                    COUNT(DISTINCT ut.userid)                                                          AS total_users_ever,
                    SUM(CASE WHEN ut.status = 3 THEN 1 ELSE 0 END)                                    AS revoked_tokens,
                    SUM(CASE WHEN ut.expires <= UNIX_TIMESTAMP() AND ut.status IN (0,1) THEN 1 ELSE 0 END) AS expired_tokens,
                    MAX(CASE WHEN ut.lastused > 0 THEN FROM_UNIXTIME(ut.lastused) END)                AS last_token_activity,
                    AVG(CASE WHEN ut.lastused > 0 THEN ut.lastused - ut.created END)                   AS avg_token_usage_duration
                FROM `usertokens` ut
                GROUP BY ut.applicationid
            ),
            oauth_config AS (
                SELECT
                    ag.appid,
                    GROUP_CONCAT(CASE WHEN ag.is_enabled = 1 THEN ag.grant_type END ORDER BY ag.grant_type) AS enabled_grant_types,
                    MAX(CASE WHEN ag.grant_type = 'authorization_code' AND ag.is_enabled = 1 THEN 1 ELSE 0 END) AS supports_authorization_code,
                    MAX(CASE WHEN ag.grant_type = 'client_credentials'  AND ag.is_enabled = 1 THEN 1 ELSE 0 END) AS supports_client_credentials,
                    MAX(CASE WHEN ag.grant_type = 'refresh_token'       AND ag.is_enabled = 1 THEN 1 ELSE 0 END) AS supports_refresh_token
                FROM `applications_oauth2_application_grants` ag
                GROUP BY ag.appid
            ),
            webhook_stats AS (
                SELECT
                    wep.appid,
                    COUNT(DISTINCT wep.webhook_type)                              AS configured_webhook_types,
                    SUM(CASE WHEN wep.is_active = 1 THEN 1 ELSE 0 END)           AS active_webhooks,
                    COALESCE(SUM(ev_counts.total), 0)                             AS total_webhook_events,
                    COALESCE(SUM(ev_counts.sent),  0)                             AS successful_webhook_events
                FROM `applications_oauth2_webhook_endpoints` wep
                LEFT JOIN (
                    SELECT ev.webhook_id,
                           COUNT(*)                                               AS total,
                           SUM(CASE WHEN ev.status = 'sent' THEN 1 ELSE 0 END)   AS sent
                    FROM `applications_oauth2_webhook_events` ev
                    GROUP BY ev.webhook_id
                ) ev_counts ON ev_counts.webhook_id = wep.webhook_id
                GROUP BY wep.appid
            )
            SELECT
                a.appid,
                a.name                                                            AS application_name,
                a.apikey                                                          AS client_id,
                a.accesstype,
                a.scope                                                           AS allowed_scopes,
                a.callback                                                        AS redirect_uri,
                COALESCE(ts.active_tokens_total,  0)                             AS active_tokens,
                COALESCE(ts.active_users_total,   0)                             AS active_users,
                COALESCE(ts.access_tokens,        0)                             AS active_access_tokens,
                COALESCE(ts.refresh_tokens,       0)                             AS active_refresh_tokens,
                COALESCE(ts.auth_codes,           0)                             AS active_auth_codes,
                COALESCE(ts.tokens_created_24h,   0)                             AS new_tokens_24h,
                COALESCE(ts.tokens_created_7d,    0)                             AS new_tokens_7d,
                COALESCE(ts.tokens_created_30d,   0)                             AS new_tokens_30d,
                COALESCE(ts.active_users_24h,     0)                             AS active_users_24h,
                COALESCE(ts.active_users_7d,      0)                             AS active_users_7d,
                COALESCE(ts.active_users_30d,     0)                             AS active_users_30d,
                COALESCE(hs.total_tokens_ever,    0)                             AS total_tokens_ever,
                COALESCE(hs.total_users_ever,     0)                             AS total_users_ever,
                COALESCE(hs.revoked_tokens,       0)                             AS revoked_tokens,
                COALESCE(hs.expired_tokens,       0)                             AS expired_tokens,
                ts.first_token_created,
                ts.latest_token_created,
                hs.last_token_activity,
                ROUND(COALESCE(ts.avg_token_lifetime_seconds, 0) / 3600.0, 2)    AS avg_token_lifetime_hours,
                ROUND(COALESCE(hs.avg_token_usage_duration,   0) / 3600.0, 2)    AS avg_token_usage_duration_hours,
                COALESCE(oc.enabled_grant_types, '')                             AS enabled_grant_types,
                COALESCE(oc.supports_authorization_code, 0)                      AS supports_authorization_code,
                COALESCE(oc.supports_client_credentials,  0)                     AS supports_client_credentials,
                COALESCE(oc.supports_refresh_token,       0)                     AS supports_refresh_token,
                COALESCE(ws.configured_webhook_types,     0)                     AS configured_webhook_types,
                COALESCE(ws.active_webhooks,              0)                     AS active_webhooks,
                COALESCE(ws.total_webhook_events,         0)                     AS total_webhook_events,
                COALESCE(ws.successful_webhook_events,    0)                     AS successful_webhook_events,
                CASE WHEN ws.total_webhook_events > 0
                     THEN ROUND(ws.successful_webhook_events / ws.total_webhook_events * 100.0, 2)
                     ELSE NULL
                END                                                               AS webhook_success_rate_percent,
                CASE
                    WHEN COALESCE(ts.active_users_total, 0) = 0 THEN 'Inactive'
                    WHEN COALESCE(ts.active_users_24h,   0) > 0 THEN 'Highly Active'
                    WHEN COALESCE(ts.active_users_7d,    0) > 0 THEN 'Active'
                    WHEN COALESCE(ts.active_users_30d,   0) > 0 THEN 'Low Activity'
                    ELSE 'Dormant'
                END                                                               AS activity_level,
                CASE WHEN a.accesstype = 1 THEN 'OAuth2 Application' ELSE 'Legacy Application' END AS application_type,
                NOW()                                                             AS stats_updated_at
            FROM `applications` a
            LEFT JOIN token_stats     ts ON a.appid = ts.applicationid
            LEFT JOIN historical_stats hs ON a.appid = hs.applicationid
            LEFT JOIN oauth_config    oc ON a.appid = oc.appid
            LEFT JOIN webhook_stats   ws ON a.appid = ws.appid
            ORDER BY COALESCE(ts.active_users_total, 0) DESC,
                     COALESCE(ts.active_tokens_total, 0) DESC
        ");
    }

    // ------------------------------------------------------------------ //
    // Rollback                                                             //
    // ------------------------------------------------------------------ //

    public function down(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->DB()->query("DROP VIEW IF EXISTS applications.usage_statistics");
            $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS applications.application_stats_hourly");
            $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS applications.application_stats_daily");
            $this->DB()->query("DROP VIEW IF EXISTS applications.top_applications");
            $this->DB()->query("DROP VIEW IF EXISTS applications.oauth2_webhook_status");
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
                'applications_oauth2_webhook_status',
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
