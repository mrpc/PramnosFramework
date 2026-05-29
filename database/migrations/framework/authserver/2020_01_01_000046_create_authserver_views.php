<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates 8 monitoring/analytics views in the authserver schema.
 * View logic matches the Urbanwater production schema exactly.
 *
 * Source tables:
 *   twofactor_attempts    : userid, ip_address, success (boolean), attempt_time
 *   user_activity_log     : userid, action, ip_address, created_at
 *   user_consents         : userid, granted_at, id
 *   user_privacy_settings : userid, share_usage_analytics, marketing_emails, updated_at
 *   usertokens            : tokenid, userid, applicationid, status, expires (Unix int), ipaddress
 *   applications          : appid, name, apikey
 *   users                 : userid, username, email, lasttermsagreed, validated
 *
 * Views created:
 *   alert_high_failure_rate   — 2FA failure rate > 20 % in the last hour (HAVING guard)
 *   alert_suspicious_ips      — IPs with ≥3 distinct users AND ≥10 failures in last hour
 *   daily_2fa_stats           — daily aggregate (continuous agg on TimescaleDB, matview on plain PG, VIEW on MySQL)
 *   failed_twofactor_summary  — ip+user pairs with ≥3 failures in last hour
 *   gdpr_compliance_report    — per-user GDPR consent/activity summary
 *   geographic_analysis       — 2FA attempts grouped by /8 subnet, last 7 days
 *   oauth2_active_tokens      — active OAuth2 tokens with client name and user info
 *   recent_twofactor_attempts — 2FA activity last 24 h with SUCCESS/FAILED status label
 *
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
        // alert_high_failure_rate — 2FA failure rate spike in the last hour
        $this->DB()->query("DROP VIEW IF EXISTS authserver.alert_high_failure_rate CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.alert_high_failure_rate AS
            SELECT
                'HIGH_FAILURE_RATE'::TEXT                   AS alert_type,
                CURRENT_TIMESTAMP                           AS alert_time,
                COUNT(*)                                    AS total_attempts,
                COUNT(*) FILTER (WHERE success = false)     AS failed_attempts,
                ROUND(
                    COUNT(*) FILTER (WHERE success = false)::NUMERIC
                    / COUNT(*)::NUMERIC * 100, 2
                )                                           AS failure_rate_percent
            FROM authserver.twofactor_attempts
            WHERE attempt_time >= CURRENT_TIMESTAMP - INTERVAL '1 hour'
            HAVING COUNT(*) > 10
               AND COUNT(*) FILTER (WHERE success = false)::NUMERIC
                   / COUNT(*)::NUMERIC > 0.2
        ");

        // alert_suspicious_ips — IPs with many distinct users + failures in the last hour
        $this->DB()->query("DROP VIEW IF EXISTS authserver.alert_suspicious_ips CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.alert_suspicious_ips AS
            SELECT
                'SUSPICIOUS_IP'::TEXT                       AS alert_type,
                ip_address,
                COUNT(DISTINCT userid)                      AS unique_users,
                COUNT(*)                                    AS total_attempts,
                COUNT(*) FILTER (WHERE success = false)     AS failed_attempts
            FROM authserver.twofactor_attempts
            WHERE attempt_time >= CURRENT_TIMESTAMP - INTERVAL '1 hour'
            GROUP BY ip_address
            HAVING COUNT(DISTINCT userid) >= 3
               AND COUNT(*) FILTER (WHERE success = false) >= 10
        ");

        // failed_twofactor_summary — IPs+users with 3+ failures in the last hour
        $this->DB()->query("DROP VIEW IF EXISTS authserver.failed_twofactor_summary CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.failed_twofactor_summary AS
            SELECT
                ip_address,
                userid,
                COUNT(*)                                    AS failed_attempts,
                MAX(attempt_time)                           AS last_attempt,
                MIN(attempt_time)                           AS first_attempt
            FROM authserver.twofactor_attempts
            WHERE success = false
              AND attempt_time >= CURRENT_TIMESTAMP - INTERVAL '1 hour'
            GROUP BY ip_address, userid
            HAVING COUNT(*) >= 3
            ORDER BY COUNT(*) DESC, MAX(attempt_time) DESC
        ");

        // gdpr_compliance_report — per-user GDPR consent and activity summary
        $this->DB()->query("DROP VIEW IF EXISTS authserver.gdpr_compliance_report CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.gdpr_compliance_report AS
            SELECT
                u.userid,
                u.username,
                u.email,
                CASE WHEN u.lasttermsagreed > 0 THEN TRUE ELSE FALSE END
                                                            AS gdpr_consent_given,
                TO_TIMESTAMP(u.lasttermsagreed::FLOAT)      AS gdpr_consent_date,
                u.validated                                 AS email_verification_status,
                ups.share_usage_analytics,
                ups.marketing_emails,
                ups.updated_at                              AS privacy_settings_updated,
                COUNT(DISTINCT a.apikey)                    AS authorized_apps_count,
                COUNT(ual.id)                               AS total_activities,
                MAX(ual.created_at)                         AS last_activity,
                COUNT(DISTINCT uc.id)                       AS total_consents,
                (SELECT COUNT(*) FROM authserver.user_activity_log
                  WHERE userid = u.userid
                    AND created_at >= NOW() - INTERVAL '30 days') AS recent_activity_30d,
                (SELECT COUNT(*) FROM authserver.user_activity_log
                  WHERE userid = u.userid
                    AND created_at >= NOW() - INTERVAL '7 days')  AS recent_activity_7d
            FROM public.users u
            LEFT JOIN authserver.user_privacy_settings ups  ON u.userid = ups.userid
            LEFT JOIN public.usertokens ut
                   ON u.userid = ut.userid
                  AND ut.expires > EXTRACT(EPOCH FROM NOW())::INTEGER
            LEFT JOIN public.applications a                 ON ut.applicationid = a.appid
            LEFT JOIN authserver.user_activity_log ual      ON u.userid = ual.userid
            LEFT JOIN authserver.user_consents uc           ON u.userid = uc.userid
            GROUP BY u.userid, u.username, u.email,
                     u.lasttermsagreed, u.validated,
                     ups.share_usage_analytics, ups.marketing_emails, ups.updated_at
        ");

        // geographic_analysis — 2FA attempt volume grouped by /8 subnet, last 7 days
        // ip_address is VARCHAR in the framework (inet in Urbanwater), so HOST() is not needed.
        $this->DB()->query("DROP VIEW IF EXISTS authserver.geographic_analysis CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.geographic_analysis AS
            SELECT
                SPLIT_PART(ip_address, '.', 1) || '.x.x.x'
                                                            AS ip_network,
                COUNT(*)                                    AS attempts,
                COUNT(*) FILTER (WHERE success = false)     AS failed_attempts,
                COUNT(DISTINCT userid)                      AS unique_users,
                MIN(attempt_time)                           AS first_seen,
                MAX(attempt_time)                           AS last_seen
            FROM authserver.twofactor_attempts
            WHERE attempt_time >= CURRENT_TIMESTAMP - INTERVAL '7 days'
            GROUP BY SPLIT_PART(ip_address, '.', 1)
            ORDER BY COUNT(*) DESC
        ");

        // oauth2_active_tokens — active OAuth2 tokens with client and user info
        $this->DB()->query("DROP VIEW IF EXISTS authserver.oauth2_active_tokens CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.oauth2_active_tokens AS
            SELECT
                ut.tokenid,
                ut.userid,
                ut.tokentype,
                ut.token,
                ut.created,
                ut.expires,
                ut.lastused,
                ut.scope,
                ut.ipaddress,
                a.name                                      AS client_name,
                a.apikey                                    AS client_id,
                u.username,
                u.email
            FROM public.usertokens ut
            JOIN public.applications a  ON ut.applicationid = a.appid
            LEFT JOIN public.users u    ON ut.userid = u.userid
            WHERE ut.status = 1
              AND ut.expires::NUMERIC > EXTRACT(EPOCH FROM NOW())
              AND ut.tokentype = ANY(ARRAY['access_token','refresh_token','auth_code'])
        ");

        // recent_twofactor_attempts — 2FA activity last 24 h
        $this->DB()->query("DROP VIEW IF EXISTS authserver.recent_twofactor_attempts CASCADE");
        $this->DB()->query("
            CREATE VIEW authserver.recent_twofactor_attempts AS
            SELECT
                userid,
                ip_address,
                success,
                attempt_time,
                CASE WHEN success THEN 'SUCCESS'::TEXT ELSE 'FAILED'::TEXT END
                                                            AS status
            FROM authserver.twofactor_attempts
            WHERE attempt_time >= CURRENT_TIMESTAMP - INTERVAL '24 hours'
            ORDER BY attempt_time DESC
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
                         time_bucket('1 day', attempt_time)               AS day,
                         COUNT(*)                                          AS total_attempts,
                         COUNT(*) FILTER (WHERE success = true)            AS successful_attempts,
                         COUNT(*) FILTER (WHERE success = false)           AS failed_attempts,
                         COUNT(DISTINCT userid)                            AS unique_users,
                         COUNT(DISTINCT ip_address)                        AS unique_ips
                     FROM authserver.twofactor_attempts
                     GROUP BY time_bucket('1 day', attempt_time)"
                );
                $schema->addContinuousAggregatePolicy(
                    'authserver.daily_2fa_stats',
                    '1 month',
                    '1 hour',
                    '1 hour'
                );
            },
            function () use ($schema) {
                $schema->createMaterializedView(
                    'authserver.daily_2fa_stats',
                    "SELECT
                         date_trunc('day', attempt_time)                   AS day,
                         COUNT(*)                                          AS total_attempts,
                         COUNT(*) FILTER (WHERE success = true)            AS successful_attempts,
                         COUNT(*) FILTER (WHERE success = false)           AS failed_attempts,
                         COUNT(DISTINCT userid)                            AS unique_users,
                         COUNT(DISTINCT ip_address)                        AS unique_ips
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
        // alert_high_failure_rate — 2FA failure rate spike in the last hour
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_alert_high_failure_rate` AS
            SELECT
                'HIGH_FAILURE_RATE'                          AS alert_type,
                NOW()                                        AS alert_time,
                COUNT(*)                                     AS total_attempts,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END)
                                                             AS failed_attempts,
                ROUND(SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END)
                      / COUNT(*) * 100, 2)                   AS failure_rate_percent
            FROM `authserver_twofactor_attempts`
            WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            HAVING COUNT(*) > 10
               AND SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) / COUNT(*) > 0.2
        ");

        // alert_suspicious_ips — IPs with many distinct users + failures in the last hour
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_alert_suspicious_ips` AS
            SELECT
                'SUSPICIOUS_IP'                              AS alert_type,
                ip_address,
                COUNT(DISTINCT userid)                       AS unique_users,
                COUNT(*)                                     AS total_attempts,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END)
                                                             AS failed_attempts
            FROM `authserver_twofactor_attempts`
            WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY ip_address
            HAVING COUNT(DISTINCT userid) >= 3
               AND SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) >= 10
        ");

        // failed_twofactor_summary — IPs+users with 3+ failures in the last hour
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_failed_twofactor_summary` AS
            SELECT
                ip_address,
                userid,
                COUNT(*)                                     AS failed_attempts,
                MAX(attempt_time)                            AS last_attempt,
                MIN(attempt_time)                            AS first_attempt
            FROM `authserver_twofactor_attempts`
            WHERE success = FALSE
              AND attempt_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY ip_address, userid
            HAVING COUNT(*) >= 3
            ORDER BY COUNT(*) DESC, MAX(attempt_time) DESC
        ");

        // gdpr_compliance_report — per-user GDPR consent and activity summary
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_gdpr_compliance_report` AS
            SELECT
                u.userid,
                u.username,
                u.email,
                CASE WHEN u.lasttermsagreed > 0 THEN TRUE ELSE FALSE END
                                                             AS gdpr_consent_given,
                FROM_UNIXTIME(u.lasttermsagreed)             AS gdpr_consent_date,
                u.validated                                  AS email_verification_status,
                ups.share_usage_analytics,
                ups.marketing_emails,
                ups.updated_at                               AS privacy_settings_updated,
                COUNT(DISTINCT a.apikey)                     AS authorized_apps_count,
                COUNT(ual.id)                                AS total_activities,
                MAX(ual.created_at)                          AS last_activity,
                COUNT(DISTINCT uc.id)                        AS total_consents,
                (SELECT COUNT(*) FROM `authserver_user_activity_log`
                  WHERE userid = u.userid
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent_activity_30d,
                (SELECT COUNT(*) FROM `authserver_user_activity_log`
                  WHERE userid = u.userid
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))  AS recent_activity_7d
            FROM `users` u
            LEFT JOIN `authserver_user_privacy_settings` ups ON u.userid = ups.userid
            LEFT JOIN `usertokens` ut
                   ON u.userid = ut.userid AND ut.expires > UNIX_TIMESTAMP()
            LEFT JOIN `applications` a                       ON ut.applicationid = a.appid
            LEFT JOIN `authserver_user_activity_log` ual     ON u.userid = ual.userid
            LEFT JOIN `authserver_user_consents` uc          ON u.userid = uc.userid
            GROUP BY u.userid, u.username, u.email,
                     u.lasttermsagreed, u.validated,
                     ups.share_usage_analytics, ups.marketing_emails, ups.updated_at
        ");

        // geographic_analysis — 2FA attempt volume grouped by /8 subnet, last 7 days
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_geographic_analysis` AS
            SELECT
                CONCAT(SUBSTRING_INDEX(ip_address, '.', 1), '.x.x.x')
                                                             AS ip_network,
                COUNT(*)                                     AS attempts,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END)
                                                             AS failed_attempts,
                COUNT(DISTINCT userid)                       AS unique_users,
                MIN(attempt_time)                            AS first_seen,
                MAX(attempt_time)                            AS last_seen
            FROM `authserver_twofactor_attempts`
            WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY CONCAT(SUBSTRING_INDEX(ip_address, '.', 1), '.x.x.x')
            ORDER BY COUNT(*) DESC
        ");

        // oauth2_active_tokens — active OAuth2 tokens with client and user info
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_oauth2_active_tokens` AS
            SELECT
                ut.tokenid,
                ut.userid,
                ut.tokentype,
                ut.token,
                ut.created,
                ut.expires,
                ut.lastused,
                ut.scope,
                ut.ipaddress,
                a.name                                       AS client_name,
                a.apikey                                     AS client_id,
                u.username,
                u.email
            FROM `usertokens` ut
            JOIN `applications` a  ON ut.applicationid = a.appid
            LEFT JOIN `users` u    ON ut.userid = u.userid
            WHERE ut.status = 1
              AND ut.expires > UNIX_TIMESTAMP()
              AND ut.tokentype IN ('access_token', 'refresh_token', 'auth_code')
        ");

        // recent_twofactor_attempts — 2FA activity last 24 h
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_recent_twofactor_attempts` AS
            SELECT
                userid,
                ip_address,
                success,
                attempt_time,
                CASE WHEN success THEN 'SUCCESS' ELSE 'FAILED' END
                                                             AS status
            FROM `authserver_twofactor_attempts`
            WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY attempt_time DESC
        ");

        // daily_2fa_stats (regular VIEW on MySQL — no materialisation available)
        $this->DB()->query("
            CREATE OR REPLACE VIEW `authserver_daily_2fa_stats` AS
            SELECT
                DATE(attempt_time)                           AS `day`,
                COUNT(*)                                     AS total_attempts,
                SUM(CASE WHEN success = TRUE  THEN 1 ELSE 0 END) AS successful_attempts,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) AS failed_attempts,
                COUNT(DISTINCT userid)                       AS unique_users,
                COUNT(DISTINCT ip_address)                   AS unique_ips
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
