<?php

namespace Pramnos\Framework\Migrations\Applications;

use Pramnos\Database\Migration;

/**
 * Creates the applications.application_settings table.
 *
 * Stores per-application configuration: rate limiting, pagination, IP lock,
 * HTTPS enforcement, and CORS settings.  Includes an updated_at trigger so
 * every UPDATE automatically refreshes the timestamp.
 *
 * On PostgreSQL: lives in the `applications` schema as
 *   applications.application_settings; allowed_ips / blocked_ips are INET[],
 *   cors_origins is TEXT[]; updated_at is kept current via a PL/pgSQL trigger.
 *
 * On MySQL: lives in the default database as
 *   applications_application_settings; array columns are stored as JSON;
 *   updated_at is maintained by ON UPDATE CURRENT_TIMESTAMP.
 *
 */
class CreateApplicationSettingsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 28;
    public array  $dependencies = [
        'create_applications_schema',
        'create_applications_table',
    ];
    public $description = 'Creates applications.application_settings — per-app rate limit, CORS, and IP-lock config';

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
        $this->DB()->query("
            CREATE TABLE IF NOT EXISTS applications.application_settings (
                id                         SERIAL        PRIMARY KEY,
                appid                      INTEGER       NOT NULL
                                               REFERENCES public.applications(appid)
                                               ON DELETE CASCADE ON UPDATE CASCADE,

                -- Rate limiting
                rate_limit_requests        INTEGER       NOT NULL DEFAULT 1000
                                               CHECK (rate_limit_requests > 0),
                rate_limit_window_seconds  INTEGER       NOT NULL DEFAULT 3600
                                               CHECK (rate_limit_window_seconds > 0),
                rate_limit_burst           INTEGER       NOT NULL DEFAULT 100
                                               CHECK (rate_limit_burst >= 0),

                -- Pagination
                enforce_pagination         BOOLEAN       NOT NULL DEFAULT TRUE,
                max_page_size              INTEGER       NOT NULL DEFAULT 100
                                               CHECK (max_page_size > 0),
                default_page_size          INTEGER       NOT NULL DEFAULT 20
                                               CHECK (default_page_size > 0),

                -- IP lock (PostgreSQL INET[] arrays)
                ip_lock_enabled            BOOLEAN       NOT NULL DEFAULT FALSE,
                allowed_ips                INET[],
                blocked_ips                INET[],

                -- HTTPS / CORS
                require_https              BOOLEAN       NOT NULL DEFAULT TRUE,
                cors_enabled               BOOLEAN       NOT NULL DEFAULT FALSE,
                cors_origins               TEXT[],

                -- Timestamps
                created_at                 TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                 TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT idx_application_settings_appid UNIQUE (appid)
            )
        ");

        $this->DB()->query(
            "CREATE INDEX IF NOT EXISTS idx_application_settings_updated_at
             ON applications.application_settings (updated_at)"
        );

        // Trigger function to keep updated_at current
        $this->DB()->query("
            CREATE OR REPLACE FUNCTION applications.update_application_settings_timestamp()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            \$\$
        ");

        $this->DB()->query("
            DROP TRIGGER IF EXISTS trg_update_application_settings_timestamp
                ON applications.application_settings
        ");

        $this->DB()->query("
            CREATE TRIGGER trg_update_application_settings_timestamp
            BEFORE UPDATE ON applications.application_settings
            FOR EACH ROW
            EXECUTE FUNCTION applications.update_application_settings_timestamp()
        ");
    }

    // ------------------------------------------------------------------ //
    // MySQL                                                                //
    // ------------------------------------------------------------------ //

    private function upMySQL(): void
    {
        $this->DB()->query("
            CREATE TABLE IF NOT EXISTS `applications_application_settings` (
                `id`                        INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `appid`                     INT UNSIGNED    NOT NULL,

                `rate_limit_requests`       INT UNSIGNED    NOT NULL DEFAULT 1000,
                `rate_limit_window_seconds` INT UNSIGNED    NOT NULL DEFAULT 3600,
                `rate_limit_burst`          INT UNSIGNED    NOT NULL DEFAULT 100,

                `enforce_pagination`        TINYINT(1)      NOT NULL DEFAULT 1,
                `max_page_size`             INT UNSIGNED    NOT NULL DEFAULT 100,
                `default_page_size`         INT UNSIGNED    NOT NULL DEFAULT 20,

                `ip_lock_enabled`           TINYINT(1)      NOT NULL DEFAULT 0,
                `allowed_ips`               JSON,
                `blocked_ips`               JSON,

                `require_https`             TINYINT(1)      NOT NULL DEFAULT 1,
                `cors_enabled`              TINYINT(1)      NOT NULL DEFAULT 0,
                `cors_origins`              JSON,

                `created_at`                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT `idx_application_settings_appid` UNIQUE (`appid`),
                KEY `idx_application_settings_updated_at` (`updated_at`),
                CONSTRAINT `fk_appsettings_appid`
                    FOREIGN KEY (`appid`) REFERENCES `applications` (`appid`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // ------------------------------------------------------------------ //
    // Rollback                                                             //
    // ------------------------------------------------------------------ //

    public function down(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->DB()->query(
                "DROP TRIGGER IF EXISTS trg_update_application_settings_timestamp
                 ON applications.application_settings"
            );
            $this->DB()->query(
                "DROP FUNCTION IF EXISTS applications.update_application_settings_timestamp()"
            );
            $this->DB()->query(
                "DROP TABLE IF EXISTS applications.application_settings"
            );
        } else {
            $this->DB()->query(
                "DROP TABLE IF EXISTS `applications_application_settings`"
            );
        }
    }
}
