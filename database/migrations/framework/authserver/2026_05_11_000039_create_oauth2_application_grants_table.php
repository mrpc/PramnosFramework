<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the applications.oauth2_application_grants table, two read-only
 * views, and the authserver.cleanup_expired_oauth2_tokens() helper function.
 *
 * oauth2_application_grants
 *   Stores which OAuth2 grant types each application is allowed to use.
 *   One row per (appid, grant_type) pair with an is_enabled flag so individual
 *   grants can be suspended without removing them permanently.
 *
 * oauth2_application_permissions (VIEW)
 *   Aggregates an application's full OAuth2 profile (allowed scopes, redirect
 *   URI, grant types) into a single row for quick authorisation decisions.
 *   Uses array_agg on PostgreSQL, GROUP_CONCAT on MySQL.
 *
 * oauth2_active_tokens (VIEW)
 *   Joins active OAuth2 tokens with their issuing application and the owning
 *   user — useful for dashboards, audits, and revocation lookups.
 *
 * cleanup_expired_oauth2_tokens() (PostgreSQL only)
 *   Deletes access_token / refresh_token / auth_code rows that have been
 *   expired for more than 7 days. Designed to be called from a cron job or
 *   the Policy Engine daemon.
 *
 * On MySQL: table lives in the default database as
 *   `applications_oauth2_application_grants`, views as
 *   `applications_oauth2_application_permissions` and
 *   `applications_oauth2_active_tokens`.
 *
 */
class CreateOauth2ApplicationGrantsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 27;
    public array  $dependencies = [
        'create_applications_schema',
        'create_applications_table',
        'create_usertokens_table',
    ];
    public $description = 'Creates applications.oauth2_application_grants, oauth2_application_permissions/active_tokens views, and cleanup function';

    public function up(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->upPostgreSQL($db);
        } else {
            $this->upMySQL($db);
        }
    }

    // ------------------------------------------------------------------ //
    // PostgreSQL                                                           //
    // ------------------------------------------------------------------ //

    private function upPostgreSQL($db): void
    {
        // --- oauth2_application_grants ---
        $db->query("CREATE TABLE IF NOT EXISTS applications.oauth2_application_grants (
            grant_id    SERIAL      PRIMARY KEY,
            appid       INTEGER     NOT NULL
                            REFERENCES public.applications(appid) ON DELETE CASCADE,
            grant_type  VARCHAR(50) NOT NULL
                            CHECK (grant_type IN (
                                'authorization_code', 'client_credentials',
                                'refresh_token', 'device_code',
                                'password', 'exchange_token'
                            )),
            is_enabled  BOOLEAN     NOT NULL DEFAULT TRUE,
            created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (appid, grant_type)
        )");

        $db->query("COMMENT ON TABLE applications.oauth2_application_grants IS
            'OAuth2 grant types enabled per application — drives authorisation server policy'");

        $db->query("CREATE INDEX IF NOT EXISTS idx_oauth2_grants_appid
            ON applications.oauth2_application_grants (appid)");
        $db->query("CREATE INDEX IF NOT EXISTS idx_oauth2_grants_enabled
            ON applications.oauth2_application_grants (appid, grant_type)
            WHERE is_enabled = TRUE");

        // --- oauth2_application_permissions VIEW ---
        $db->query("CREATE OR REPLACE VIEW applications.oauth2_application_permissions AS
            SELECT
                a.appid,
                a.name         AS client_name,
                a.apikey       AS client_id,
                a.scope        AS allowed_scopes,
                a.callback     AS redirect_uri,
                a.accesstype,
                array_agg(ag.grant_type ORDER BY ag.grant_type)
                    FILTER (WHERE ag.is_enabled = TRUE) AS allowed_grants
            FROM public.applications a
            LEFT JOIN applications.oauth2_application_grants ag
                   ON a.appid = ag.appid AND ag.is_enabled = TRUE
            WHERE a.accesstype = 1
            GROUP BY a.appid, a.name, a.apikey, a.scope, a.callback, a.accesstype");

        $db->query("COMMENT ON VIEW applications.oauth2_application_permissions IS
            'Full OAuth2 profile for each application: scopes, redirect URI, allowed grant types'");

        // --- oauth2_active_tokens VIEW ---
        $db->query("CREATE OR REPLACE VIEW applications.oauth2_active_tokens AS
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
                a.name   AS client_name,
                a.apikey AS client_id,
                u.username,
                u.email
            FROM public.usertokens ut
            JOIN public.applications a ON ut.applicationid = a.appid
            LEFT JOIN public.users u ON ut.userid = u.userid
            WHERE ut.status = 1
              AND ut.expires > EXTRACT(EPOCH FROM NOW())
              AND ut.tokentype IN ('access_token', 'refresh_token', 'auth_code')");

        $db->query("COMMENT ON VIEW applications.oauth2_active_tokens IS
            'Active OAuth2 tokens with issuing application and owner user information'");

        // --- cleanup_expired_oauth2_tokens() ---
        $db->query("CREATE OR REPLACE FUNCTION authserver.cleanup_expired_oauth2_tokens()
RETURNS INTEGER AS \$\$
DECLARE
    deleted_count INTEGER;
BEGIN
    -- Remove tokens expired for more than 7 days to keep the table lean.
    -- Tokens within the 7-day window are kept so revocation webhooks / audits
    -- can still reference them after they expire.
    DELETE FROM public.usertokens
    WHERE tokentype IN ('access_token', 'refresh_token', 'auth_code')
      AND expires < EXTRACT(EPOCH FROM NOW()) - (7 * 24 * 3600);

    GET DIAGNOSTICS deleted_count = ROW_COUNT;

    RETURN deleted_count;
END;
\$\$ LANGUAGE plpgsql");

        $db->query("COMMENT ON FUNCTION authserver.cleanup_expired_oauth2_tokens() IS
            'Deletes OAuth2 tokens (access/refresh/auth_code) expired for more than 7 days'");
    }

    // ------------------------------------------------------------------ //
    // MySQL                                                                //
    // ------------------------------------------------------------------ //

    private function upMySQL($db): void
    {
        // --- applications_oauth2_application_grants ---
        $db->query("CREATE TABLE IF NOT EXISTS `applications_oauth2_application_grants` (
            `grant_id`    INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `appid`       INT UNSIGNED    NOT NULL,
            `grant_type`  VARCHAR(50)     NOT NULL,
            `is_enabled`  TINYINT(1)      NOT NULL DEFAULT 1,
            `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_oauth2_grants_app_type` (`appid`, `grant_type`),
            KEY `idx_oauth2_grants_appid` (`appid`),
            CONSTRAINT `chk_oauth2_grant_type` CHECK (`grant_type` IN (
                'authorization_code', 'client_credentials',
                'refresh_token', 'device_code',
                'password', 'exchange_token'
            )),
            CONSTRAINT `fk_oauth2_grants_appid`
                FOREIGN KEY (`appid`) REFERENCES `applications` (`appid`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='OAuth2 grant types enabled per application'");

        // --- applications_oauth2_application_permissions VIEW ---
        // MySQL GROUP_CONCAT replaces PostgreSQL array_agg
        $db->query("CREATE OR REPLACE VIEW `applications_oauth2_application_permissions` AS
            SELECT
                a.appid,
                a.name         AS client_name,
                a.apikey       AS client_id,
                a.scope        AS allowed_scopes,
                a.callback     AS redirect_uri,
                a.accesstype,
                GROUP_CONCAT(
                    CASE WHEN ag.is_enabled = 1 THEN ag.grant_type END
                    ORDER BY ag.grant_type SEPARATOR ','
                ) AS allowed_grants
            FROM `applications` a
            LEFT JOIN `applications_oauth2_application_grants` ag
                   ON a.appid = ag.appid AND ag.is_enabled = 1
            WHERE a.accesstype = 1
            GROUP BY a.appid, a.name, a.apikey, a.scope, a.callback, a.accesstype");

        // --- applications_oauth2_active_tokens VIEW ---
        $db->query("CREATE OR REPLACE VIEW `applications_oauth2_active_tokens` AS
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
                a.name   AS client_name,
                a.apikey AS client_id,
                u.username,
                u.email
            FROM `usertokens` ut
            JOIN `applications` a ON ut.applicationid = a.appid
            LEFT JOIN `users` u ON ut.userid = u.userid
            WHERE ut.status = 1
              AND ut.expires > UNIX_TIMESTAMP()
              AND ut.tokentype IN ('access_token', 'refresh_token', 'auth_code')");
    }

    // ------------------------------------------------------------------ //
    // down                                                                 //
    // ------------------------------------------------------------------ //

    public function down(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $db->query("DROP VIEW IF EXISTS applications.oauth2_active_tokens");
            $db->query("DROP VIEW IF EXISTS applications.oauth2_application_permissions");
            $db->query("DROP FUNCTION IF EXISTS authserver.cleanup_expired_oauth2_tokens()");
            $db->query("DROP TABLE IF EXISTS applications.oauth2_application_grants");
        } else {
            $db->query("DROP VIEW IF EXISTS `applications_oauth2_active_tokens`");
            $db->query("DROP VIEW IF EXISTS `applications_oauth2_application_permissions`");
            $db->query("DROP TABLE IF EXISTS `applications_oauth2_application_grants`");
        }
    }
}
