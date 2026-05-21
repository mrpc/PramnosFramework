<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the 8 monitoring/analytics views in the authserver schema.
 *
 * Column names used match the actual framework migration schemas:
 *   twofactor_attempts : userid, ip_address, code_used, user_agent,
 *                         attempt_time, success
 *   loginlockouts       : locktype, lookupvalue, failedattempts,
 *                         createdat (Unix int), lastfailedat (Unix int)
 *   user_activity_log   : userid, action, ip_address, created_at
 *   user_consents       : userid, consent_type, granted, granted_at
 *   user_privacy_settings: userid, updated_at
 *   usertokens          : tokenid, userid, applicationid, status (1=active), expires (Unix int)
 *
 * Views created:
 *   alert_high_failure_rate      — 2FA failure spikes in the last hour
 *   alert_suspicious_ips         — IPs with many recent lockout events
 *   daily_2fa_stats              — daily 2FA usage aggregate (continuous aggregate on TimescaleDB, materialized view on plain PG, regular VIEW on MySQL)
 *   failed_twofactor_summary     — users with 3+ 2FA failures in the last hour
 *   gdpr_compliance_report       — per-user consent and privacy summary
 *   geographic_analysis          — per-user recent login activity by IP
 *   oauth2_active_tokens         — active OAuth token counts by app
 *   recent_twofactor_attempts    — 2FA activity in the last 24 h
 *
 * @package PramnosFramework
 */
class CreateAuthserverViews extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 31;
    public array  $dependencies = [
        'create_authserver_schema',
        'create_applications_table',
        'create_usertokens_table',
        'create_user_twofactor_table',
        'create_twofactor_attempts_table',
        'create_loginlockout_table',
        'create_user_activity_log_table',
        'create_user_consents_table',
        'create_user_privacy_settings_table',
        'create_gdpr_requests_table',
    ];
    public $description = 'Creates all 8 monitoring/analytics views in the authserver schema';

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
        // alert_high_failure_rate — 2FA failures spike detection
        $this->DB()->query("DROP VIEW IF EXISTS authserver.alert_high_failure_rate CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.alert_high_failure_rate AS
            SELECT
                gen_random_uuid()::TEXT                     AS alert_id,
                'warning'                                   AS severity,
                'High 2FA failure rate detected'            AS message,
                COUNT(DISTINCT userid)                      AS affected_users,
                NOW()                                       AS trigger_time,
                NOW() + INTERVAL '1 hour'                   AS resolvable_at
            FROM authserver.twofactor_attempts
            WHERE attempt_time >= NOW() - INTERVAL '1 hour'
              AND success = 0
            HAVING COUNT(*) > 10
        ");

        // alert_suspicious_ips — IPs with many lockout events in the last hour
        $this->DB()->query("DROP VIEW IF EXISTS authserver.alert_suspicious_ips CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.alert_suspicious_ips AS
            SELECT
                lookupvalue                                 AS ip_address,
                SUM(failedattempts)                         AS attempt_count,
                CASE
                    WHEN SUM(failedattempts) > 50 THEN 100
                    WHEN SUM(failedattempts) > 20 THEN 70
                    WHEN SUM(failedattempts) > 10 THEN 40
                    ELSE 20
                END                                         AS suspicious_score,
                'High login failure count from this IP'     AS reason,
                MAX(TO_TIMESTAMP(lastfailedat))             AS last_seen,
                'investigate'                               AS recommended_action
            FROM authserver.loginlockouts
            WHERE locktype = 'ip'
              AND TO_TIMESTAMP(lastfailedat) >= NOW() - INTERVAL '1 hour'
            GROUP BY lookupvalue
            HAVING SUM(failedattempts) > 5
        ");

        // failed_twofactor_summary — users with 3+ 2FA failures in last hour
        $this->DB()->query("DROP VIEW IF EXISTS authserver.failed_twofactor_summary CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.failed_twofactor_summary AS
            SELECT
                userid,
                COUNT(*) FILTER (WHERE success = 0)        AS failed_attempts,
                MAX(attempt_time)                           AS last_failure_time,
                CASE
                    WHEN COUNT(*) FILTER (WHERE success = 0) >= 5
                    THEN 'consider_lockout'
                    WHEN COUNT(*) FILTER (WHERE success = 0) >= 3
                    THEN 'monitor'
                    ELSE 'normal'
                END                                        AS account_status_recommendation
            FROM authserver.twofactor_attempts
            WHERE attempt_time >= NOW() - INTERVAL '1 hour'
            GROUP BY userid
            HAVING COUNT(*) FILTER (WHERE success = 0) >= 3
        ");

        // gdpr_compliance_report — user consent and data processing summary
        $this->DB()->query("DROP VIEW IF EXISTS authserver.gdpr_compliance_report CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.gdpr_compliance_report AS
            SELECT
                u.userid,
                COUNT(DISTINCT uc.granted_at)               AS consents_given,
                365                                         AS data_retention_days,
                FALSE                                       AS deletion_requested,
                FALSE                                       AS export_requested,
                GREATEST(
                    MAX(uc.granted_at),
                    MAX(ups.updated_at)
                )                                           AS last_processing_date
            FROM public.users u
            LEFT JOIN authserver.user_consents uc
                   ON uc.userid = u.userid AND uc.granted = 1
            LEFT JOIN authserver.user_privacy_settings ups
                   ON ups.userid = u.userid
            GROUP BY u.userid
        ");

        // geographic_analysis — per-user login activity (by IP, no geo data yet)
        $this->DB()->query("DROP VIEW IF EXISTS authserver.geographic_analysis CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.geographic_analysis AS
            SELECT
                userid,
                ip_address                                  AS country_code,
                NULL::TEXT                                  AS city,
                MAX(created_at)                             AS last_login,
                COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '7 days')
                                                            AS login_count_7days,
                COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '30 days')
                                                            AS login_count_30days,
                FALSE                                       AS anomaly_flag
            FROM authserver.user_activity_log
            WHERE action = 'login'
            GROUP BY userid, ip_address
        ");

        // oauth2_active_tokens — active OAuth token counts per app
        $this->DB()->query("DROP VIEW IF EXISTS authserver.oauth2_active_tokens CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.oauth2_active_tokens AS
            SELECT
                applicationid                               AS appid,
                COUNT(*) FILTER (WHERE status = 1 AND expires > EXTRACT(EPOCH FROM NOW()))
                                                            AS token_count,
                json_build_object(
                    'active',   COUNT(*) FILTER (WHERE status = 1),
                    'expired',  COUNT(*) FILTER (WHERE expires <= EXTRACT(EPOCH FROM NOW())),
                    'revoked',  COUNT(*) FILTER (WHERE status <> 1)
                )                                           AS by_status
            FROM public.usertokens
            GROUP BY applicationid
        ");

        // recent_twofactor_attempts — 2FA activity last 24h
        $this->DB()->query("DROP VIEW IF EXISTS authserver.recent_twofactor_attempts CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.recent_twofactor_attempts AS
            SELECT
                userid,
                attempt_time                                AS attempt_timestamp,
                success,
                code_used                                   AS method,
                user_agent                                  AS device_fingerprint
            FROM authserver.twofactor_attempts
            WHERE attempt_time >= NOW() - INTERVAL '1 day'
        ");

        // daily_2fa_stats — continuous aggregate on TimescaleDB, materialized view on plain PG
        $schema = $this->DB()->schema();
        $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS authserver.daily_2fa_stats CASCADE");
        $schema->ifCapable(
            \Pramnos\Database\DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                // time_bucket() is required by TimescaleDB for continuous aggregates;
                // the source table (twofactor_attempts) is a hypertable partitioned on attempt_time.
                $schema->createContinuousAggregate(
                    'authserver.daily_2fa_stats',
                    "SELECT
                         time_bucket('1 day', attempt_time)          AS day,
                         COUNT(*)                                     AS total_2fa_attempts,
                         COUNT(*) FILTER (WHERE success = 1)          AS successful_completions,
                         COUNT(*) FILTER (WHERE success = 0)          AS failed_attempts,
                         NULL::NUMERIC                                AS avg_completion_time_seconds
                     FROM authserver.twofactor_attempts
                     GROUP BY time_bucket('1 day', attempt_time)"
                );
            },
            function () use ($schema) {
                $schema->createMaterializedView(
                    'authserver.daily_2fa_stats',
                    "SELECT
                         date_trunc('day', attempt_time)              AS day,
                         COUNT(*)                                     AS total_2fa_attempts,
                         COUNT(*) FILTER (WHERE success = 1)          AS successful_completions,
                         COUNT(*) FILTER (WHERE success = 0)          AS failed_attempts,
                         NULL::NUMERIC                                AS avg_completion_time_seconds
                     FROM authserver.twofactor_attempts
                     GROUP BY date_trunc('day', attempt_time)
                     WITH NO DATA"
                );
                $this->DB()->query(
                    "CREATE INDEX IF NOT EXISTS idx_authserver_daily_2fa_stats_day
                     ON authserver.daily_2fa_stats (day)"
                );
            }
        );
    }

    // ------------------------------------------------------------------ //
    // MySQL                                                                //
    // ------------------------------------------------------------------ //

    private function upMySQL(): void
    {
        // alert_high_failure_rate
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_alert_high_failure_rate` AS
            SELECT
                UUID()                                       AS alert_id,
                'warning'                                    AS severity,
                'High 2FA failure rate detected'             AS message,
                COUNT(DISTINCT userid)                       AS affected_users,
                NOW()                                        AS trigger_time,
                DATE_ADD(NOW(), INTERVAL 1 HOUR)             AS resolvable_at
            FROM `authserver_twofactor_attempts`
            WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
              AND success = 0
            HAVING COUNT(*) > 10
        ");

        // alert_suspicious_ips
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_alert_suspicious_ips` AS
            SELECT
                lookupvalue                                  AS ip_address,
                SUM(failedattempts)                          AS attempt_count,
                CASE
                    WHEN SUM(failedattempts) > 50 THEN 100
                    WHEN SUM(failedattempts) > 20 THEN 70
                    WHEN SUM(failedattempts) > 10 THEN 40
                    ELSE 20
                END                                          AS suspicious_score,
                'High login failure count from this IP'      AS reason,
                FROM_UNIXTIME(MAX(lastfailedat))              AS last_seen,
                'investigate'                                AS recommended_action
            FROM `authserver_loginlockouts`
            WHERE locktype = 'ip'
              AND FROM_UNIXTIME(lastfailedat) >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY lookupvalue
            HAVING SUM(failedattempts) > 5
        ");

        // failed_twofactor_summary
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_failed_twofactor_summary` AS
            SELECT
                userid,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed_attempts,
                MAX(attempt_time)                              AS last_failure_time,
                CASE
                    WHEN SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) >= 5
                    THEN 'consider_lockout'
                    WHEN SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) >= 3
                    THEN 'monitor'
                    ELSE 'normal'
                END AS account_status_recommendation
            FROM `authserver_twofactor_attempts`
            WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY userid
            HAVING SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) >= 3
        ");

        // gdpr_compliance_report
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_gdpr_compliance_report` AS
            SELECT
                u.userid,
                COUNT(DISTINCT uc.granted_at)                AS consents_given,
                365                                          AS data_retention_days,
                0                                            AS deletion_requested,
                0                                            AS export_requested,
                GREATEST(MAX(uc.granted_at), MAX(ups.updated_at))
                                                             AS last_processing_date
            FROM `users` u
            LEFT JOIN `authserver_user_consents` uc
                   ON uc.userid = u.userid AND uc.granted = 1
            LEFT JOIN `authserver_user_privacy_settings` ups
                   ON ups.userid = u.userid
            GROUP BY u.userid
        ");

        // geographic_analysis
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_geographic_analysis` AS
            SELECT
                userid,
                ip_address                                   AS country_code,
                NULL                                         AS city,
                MAX(created_at)                              AS last_login,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END)
                                                             AS login_count_7days,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END)
                                                             AS login_count_30days,
                0                                            AS anomaly_flag
            FROM `authserver_user_activity_log`
            WHERE action = 'login'
            GROUP BY userid, ip_address
        ");

        // oauth2_active_tokens
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_oauth2_active_tokens` AS
            SELECT
                applicationid                                AS appid,
                SUM(CASE WHEN status = 1 AND expires > UNIX_TIMESTAMP() THEN 1 ELSE 0 END)
                                                             AS token_count,
                NULL                                         AS by_status
            FROM `usertokens`
            GROUP BY applicationid
        ");

        // recent_twofactor_attempts
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_recent_twofactor_attempts` AS
            SELECT
                userid,
                attempt_time                                 AS attempt_timestamp,
                success,
                code_used                                    AS method,
                user_agent                                   AS device_fingerprint
            FROM `authserver_twofactor_attempts`
            WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");

        // daily_2fa_stats (regular VIEW on MySQL — no materialisation available)
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_daily_2fa_stats` AS
            SELECT
                DATE(attempt_time)                           AS `day`,
                COUNT(*)                                     AS total_2fa_attempts,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successful_completions,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed_attempts,
                NULL                                         AS avg_completion_time_seconds
            FROM `authserver_twofactor_attempts`
            GROUP BY DATE(attempt_time)
        ");
    }

    // ------------------------------------------------------------------ //
    // Rollback                                                             //
    // ------------------------------------------------------------------ //

    public function down(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->DB()->query("DROP MATERIALIZED VIEW IF EXISTS authserver.daily_2fa_stats");
            $this->DB()->query("DROP VIEW IF EXISTS authserver.recent_twofactor_attempts");
            $this->DB()->query("DROP VIEW IF EXISTS authserver.oauth2_active_tokens");
            $this->DB()->query("DROP VIEW IF EXISTS authserver.geographic_analysis");
            $this->DB()->query("DROP VIEW IF EXISTS authserver.gdpr_compliance_report");
            $this->DB()->query("DROP VIEW IF EXISTS authserver.failed_twofactor_summary");
            $this->DB()->query("DROP VIEW IF EXISTS authserver.alert_suspicious_ips");
            $this->DB()->query("DROP VIEW IF EXISTS authserver.alert_high_failure_rate");
        } else {
            foreach ([
                'authserver_daily_2fa_stats',
                'authserver_recent_twofactor_attempts',
                'authserver_oauth2_active_tokens',
                'authserver_geographic_analysis',
                'authserver_gdpr_compliance_report',
                'authserver_failed_twofactor_summary',
                'authserver_alert_suspicious_ips',
                'authserver_alert_high_failure_rate',
            ] as $view) {
                $this->DB()->query("DROP VIEW IF EXISTS `{$view}`");
            }
        }
    }
}
