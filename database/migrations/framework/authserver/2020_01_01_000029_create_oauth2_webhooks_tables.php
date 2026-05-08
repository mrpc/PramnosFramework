<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the OAuth2 webhook infrastructure tables.
 *
 * Two tables:
 * - `oauth2_webhook_endpoints` — registered webhook URLs per application,
 *   including the event types they subscribe to and an HMAC secret.
 * - `oauth2_webhook_events` — delivery queue / audit log of webhook
 *   payloads sent (or pending to be sent) to each endpoint.
 *
 * On PostgreSQL: both tables live in the `authserver` schema.
 * On MySQL, the schema is translated to a prefix: authserver_oauth2_webhook_{endpoints,events}.
 *
 * @package PramnosFramework
 */
class CreateOauth2WebhooksTables extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 25;
    public array  $dependencies = ['create_authserver_schema', 'create_applications_table'];
    public $description  = 'Creates oauth2_webhook_endpoints and oauth2_webhook_events tables';

    public function up(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $db->query("CREATE TABLE IF NOT EXISTS authserver.oauth2_webhook_endpoints (
                endpoint_id  BIGSERIAL    PRIMARY KEY,
                appid        INTEGER      NOT NULL,
                url          VARCHAR(2000) NOT NULL,
                secret       VARCHAR(255) NOT NULL,
                events       JSONB        NOT NULL DEFAULT '[]',
                is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
                created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query('CREATE INDEX IF NOT EXISTS idx_oawe_appid    ON authserver.oauth2_webhook_endpoints (appid)');
            $db->query('CREATE INDEX IF NOT EXISTS idx_oawe_active   ON authserver.oauth2_webhook_endpoints (is_active)');

            $db->query("CREATE TABLE IF NOT EXISTS authserver.oauth2_webhook_events (
                event_id     BIGSERIAL    PRIMARY KEY,
                endpoint_id  BIGINT       NOT NULL REFERENCES authserver.oauth2_webhook_endpoints (endpoint_id) ON DELETE CASCADE,
                event_type   VARCHAR(100) NOT NULL,
                payload      JSONB        NOT NULL DEFAULT '{}',
                delivered    BOOLEAN      NOT NULL DEFAULT FALSE,
                attempts     SMALLINT     NOT NULL DEFAULT 0,
                last_attempt TIMESTAMP,
                created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query('CREATE INDEX IF NOT EXISTS idx_oawev_endpoint  ON authserver.oauth2_webhook_events (endpoint_id)');
            $db->query('CREATE INDEX IF NOT EXISTS idx_oawev_delivered ON authserver.oauth2_webhook_events (delivered)');
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS `authserver_oauth2_webhook_endpoints` (
                `endpoint_id`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `appid`        INT    UNSIGNED NOT NULL,
                `url`          TEXT            NOT NULL,
                `secret`       VARCHAR(255)    NOT NULL,
                `events`       JSON            NOT NULL,
                `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
                `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_oawe_appid`  (`appid`),
                KEY `idx_oawe_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->query("CREATE TABLE IF NOT EXISTS `authserver_oauth2_webhook_events` (
                `event_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `endpoint_id`  BIGINT UNSIGNED NOT NULL,
                `event_type`   VARCHAR(100)    NOT NULL,
                `payload`      JSON            NOT NULL,
                `delivered`    TINYINT(1)      NOT NULL DEFAULT 0,
                `attempts`     SMALLINT        NOT NULL DEFAULT 0,
                `last_attempt` DATETIME,
                `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_oawev_endpoint`  (`endpoint_id`),
                KEY `idx_oawev_delivered` (`delivered`),
                CONSTRAINT `fk_oawev_endpoint` FOREIGN KEY (`endpoint_id`)
                    REFERENCES `authserver_oauth2_webhook_endpoints` (`endpoint_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function down(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $db->query('DROP TABLE IF EXISTS authserver.oauth2_webhook_events CASCADE');
            $db->query('DROP TABLE IF EXISTS authserver.oauth2_webhook_endpoints CASCADE');
        } else {
            $db->query('DROP TABLE IF EXISTS `authserver_oauth2_webhook_events`');
            $db->query('DROP TABLE IF EXISTS `authserver_oauth2_webhook_endpoints`');
        }
    }
}
