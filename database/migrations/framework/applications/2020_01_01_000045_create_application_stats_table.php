<?php

namespace Pramnos\Framework\Migrations\Applications;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the applications.application_stats time-series table.
 *
 * Records per-application API metrics: request counts, response times,
 * HTTP status buckets, rate-limit stats, and data transfer.
 *
 * On TimescaleDB: converted to a hypertable partitioned on `time` with
 *   14-day chunks; compression policy activates after 30 days.
 *
 * On PostgreSQL (non-TimescaleDB): plain table with the same columns.
 *
 * On MySQL: lives in the default database as
 *   applications_application_stats; same columns, regular table.
 *
 * @package PramnosFramework
 */
class CreateApplicationStatsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 29;
    public array  $dependencies = [
        'create_applications_schema',
        'create_applications_table',
        'create_application_settings_table',
    ];
    public $description = 'Creates applications.application_stats — time-series API metrics (hypertable on TimescaleDB)';

    public function up(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->upPostgreSQL($caps);
        } else {
            $this->upMySQL();
        }
    }

    // ------------------------------------------------------------------ //
    // PostgreSQL / TimescaleDB                                             //
    // ------------------------------------------------------------------ //

    private function upPostgreSQL(object $caps): void
    {
        $this->DB()->query("
            CREATE TABLE IF NOT EXISTS applications.application_stats (
                time                    TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
                appid                   INTEGER         NOT NULL
                                            REFERENCES public.applications(appid)
                                            ON DELETE CASCADE ON UPDATE CASCADE,

                -- Request counts
                total_requests          BIGINT          NOT NULL DEFAULT 0,
                successful_requests     BIGINT          NOT NULL DEFAULT 0,
                failed_requests         BIGINT          NOT NULL DEFAULT 0,

                -- Response time (milliseconds, 3 decimal places)
                avg_response_time       NUMERIC(10,3),
                min_response_time       NUMERIC(10,3),
                max_response_time       NUMERIC(10,3),

                -- HTTP status buckets
                status_2xx              BIGINT          NOT NULL DEFAULT 0,
                status_3xx              BIGINT          NOT NULL DEFAULT 0,
                status_4xx              BIGINT          NOT NULL DEFAULT 0,
                status_5xx              BIGINT          NOT NULL DEFAULT 0,

                -- Rate limiting
                rate_limited_requests   BIGINT          NOT NULL DEFAULT 0,
                rate_limit_violations   INTEGER         NOT NULL DEFAULT 0,

                -- Data transfer (bytes)
                bytes_sent              BIGINT          NOT NULL DEFAULT 0,
                bytes_received          BIGINT          NOT NULL DEFAULT 0,

                -- Unique IPs (approximation via application-level HLL)
                unique_ips_approx       INTEGER         NOT NULL DEFAULT 0,

                -- Geographic
                country_code            CHAR(2)
            )
        ");

        $this->DB()->query(
            "CREATE INDEX IF NOT EXISTS idx_application_stats_appid_time
             ON applications.application_stats (appid, time DESC)"
        );
        $this->DB()->query(
            "CREATE INDEX IF NOT EXISTS idx_application_stats_country_time
             ON applications.application_stats (country_code, time DESC)"
        );

        // Convert to hypertable on TimescaleDB
        if ($caps->supports(DatabaseCapabilities::TIMESCALEDB)) {
            $this->DB()->query(
                "SELECT create_hypertable(
                    'applications.application_stats', 'time',
                    chunk_time_interval => INTERVAL '14 days',
                    if_not_exists => TRUE
                )"
            );

            $this->DB()->query("
                ALTER TABLE applications.application_stats
                SET (
                    timescaledb.compress,
                    timescaledb.compress_orderby = 'time DESC',
                    timescaledb.compress_segmentby = 'appid'
                )
            ");

            $this->DB()->query(
                "SELECT add_compression_policy(
                    'applications.application_stats',
                    INTERVAL '60 days',
                    if_not_exists => TRUE
                )"
            );
            $this->DB()->query(
                "SELECT add_retention_policy(
                    'applications.application_stats',
                    INTERVAL '3 years',
                    if_not_exists => TRUE
                )"
            );
        }
    }

    // ------------------------------------------------------------------ //
    // MySQL                                                                //
    // ------------------------------------------------------------------ //

    private function upMySQL(): void
    {
        $this->DB()->query("
            CREATE TABLE IF NOT EXISTS `applications_application_stats` (
                `time`                  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `appid`                 INT UNSIGNED    NOT NULL,

                `total_requests`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `successful_requests`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `failed_requests`       BIGINT UNSIGNED NOT NULL DEFAULT 0,

                `avg_response_time`     DECIMAL(10,3),
                `min_response_time`     DECIMAL(10,3),
                `max_response_time`     DECIMAL(10,3),

                `status_2xx`            BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `status_3xx`            BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `status_4xx`            BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `status_5xx`            BIGINT UNSIGNED NOT NULL DEFAULT 0,

                `rate_limited_requests` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `rate_limit_violations` INT UNSIGNED    NOT NULL DEFAULT 0,

                `bytes_sent`            BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `bytes_received`        BIGINT UNSIGNED NOT NULL DEFAULT 0,

                `unique_ips_approx`     INT UNSIGNED    NOT NULL DEFAULT 0,

                `country_code`          CHAR(2),

                KEY `idx_application_stats_appid_time` (`appid`, `time`),
                KEY `idx_application_stats_country_time` (`country_code`, `time`),
                CONSTRAINT `fk_appstats_appid`
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
            if ($caps->supports(DatabaseCapabilities::TIMESCALEDB)) {
                $this->DB()->query(
                    "SELECT remove_retention_policy(
                        'applications.application_stats', if_exists => TRUE
                    )"
                );
                $this->DB()->query(
                    "SELECT remove_compression_policy(
                        'applications.application_stats', if_exists => TRUE
                    )"
                );
            }
            $this->DB()->query("DROP TABLE IF EXISTS applications.application_stats");
        } else {
            $this->DB()->query("DROP TABLE IF EXISTS `applications_application_stats`");
        }
    }
}
