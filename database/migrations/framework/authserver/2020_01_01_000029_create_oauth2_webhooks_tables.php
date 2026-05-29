<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the OAuth2 webhook infrastructure in the applications schema.
 *
 * Two tables + one PL/pgSQL helper function (PostgreSQL only):
 *
 * - `applications.oauth2_webhook_endpoints` — registered webhook URLs per
 *   application, including the event type they subscribe to, HMAC secret,
 *   retry settings, and timeout.
 *
 * - `applications.oauth2_webhook_events` — delivery queue / audit log:
 *   one row per event-to-endpoint pair, with status lifecycle
 *   (pending → sent | failed) and exponential-backoff retry metadata.
 *
 * - `applications.create_webhook_event(type, user_id, payload, ...)` —
 *   queues one event row per active endpoint that subscribes to the given
 *   event type. Called by Auth Server PL/pgSQL triggers and PHP services.
 *
 * On MySQL: tables live in the default database with the applications_ prefix.
 * The create_webhook_event function is PostgreSQL-only; MySQL applications
 * must implement equivalent fanout logic in PHP.
 *
 */
class CreateOauth2WebhooksTables extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 25;
    public array  $dependencies = ['create_applications_schema', 'create_applications_table'];
    public $description  = 'Creates applications.oauth2_webhook_endpoints/events tables and create_webhook_event() function';

    public function up(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        $schema  = $db->schema();
        $tEndp   = $schema->quoteTable('applications.oauth2_webhook_endpoints');
        $tEvents = $schema->quoteTable('applications.oauth2_webhook_events');

        if ($caps->isPostgreSQL()) {
            $db->query("CREATE TABLE IF NOT EXISTS {$tEndp} (
                webhook_id       SERIAL      PRIMARY KEY,
                appid            INTEGER     NOT NULL REFERENCES public.applications(appid) ON DELETE CASCADE,
                endpoint_url     VARCHAR(512) NOT NULL,
                webhook_type     VARCHAR(50) NOT NULL
                    CHECK (webhook_type IN (
                        'user_deauthorized', 'token_revoked', 'gdpr_request',
                        'user_profile_changed', 'device_deauthorized',
                        'account_deleted', 'scope_changed'
                    )),
                secret_key       VARCHAR(255) NOT NULL,
                is_active        BOOLEAN     NOT NULL DEFAULT TRUE,
                retry_count      INTEGER     NOT NULL DEFAULT 3,
                timeout_seconds  INTEGER     NOT NULL DEFAULT 30,
                created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (appid, webhook_type)
            )");
            $db->query("COMMENT ON TABLE {$tEndp} IS 'Webhook endpoints for OAuth2 applications to receive event notifications'");
            $db->query("CREATE INDEX IF NOT EXISTS idx_owhe_appid  ON {$tEndp} (appid)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_owhe_type   ON {$tEndp} (webhook_type)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_owhe_active ON {$tEndp} (is_active)");

            $db->query("CREATE TABLE IF NOT EXISTS {$tEvents} (
                event_id        SERIAL      PRIMARY KEY,
                webhook_id      INTEGER     NOT NULL REFERENCES {$tEndp}(webhook_id) ON DELETE CASCADE,
                event_type      VARCHAR(50) NOT NULL,
                user_id         INTEGER     REFERENCES public.users(userid) ON DELETE SET NULL,
                device_code     VARCHAR(128),
                token_id        INTEGER,
                payload         JSONB       NOT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'sent', 'failed', 'cancelled')),
                attempts        INTEGER     NOT NULL DEFAULT 0,
                max_attempts    INTEGER     NOT NULL DEFAULT 3,
                next_attempt_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_error      TEXT,
                sent_at         TIMESTAMP,
                created_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query("COMMENT ON TABLE {$tEvents} IS 'Delivery queue and audit log for OAuth2 webhook events'");
            $db->query("CREATE INDEX IF NOT EXISTS idx_owev_status       ON {$tEvents} (status)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_owev_next_attempt ON {$tEvents} (next_attempt_at)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_owev_webhook_id   ON {$tEvents} (webhook_id)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_owev_user_id      ON {$tEvents} (user_id)");

            // PL/pgSQL helper: fan out one event row per active endpoint of the requested type
            $db->query(
                "CREATE OR REPLACE FUNCTION applications.create_webhook_event(
                     p_webhook_type VARCHAR(50),
                     p_user_id      INTEGER,
                     p_payload      JSONB,
                     p_device_code  VARCHAR(128) DEFAULT NULL,
                     p_token_id     INTEGER      DEFAULT NULL
                 ) RETURNS VOID AS \$\$
                 DECLARE
                     webhook_rec RECORD;
                 BEGIN
                     FOR webhook_rec IN
                         SELECT webhook_id, retry_count
                         FROM applications.oauth2_webhook_endpoints
                         WHERE webhook_type = p_webhook_type
                           AND is_active = TRUE
                     LOOP
                         INSERT INTO applications.oauth2_webhook_events (
                             webhook_id, event_type, user_id, device_code, token_id,
                             payload, max_attempts, next_attempt_at
                         ) VALUES (
                             webhook_rec.webhook_id, p_webhook_type, p_user_id,
                             p_device_code, p_token_id, p_payload,
                             webhook_rec.retry_count, CURRENT_TIMESTAMP
                         );
                     END LOOP;
                 END;
                 \$\$ LANGUAGE plpgsql"
            );
            $db->query("COMMENT ON FUNCTION applications.create_webhook_event IS 'Queues one event row per active endpoint subscribed to the given webhook type'");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS {$tEndp} (
                `webhook_id`      INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `appid`           INT UNSIGNED  NOT NULL,
                `endpoint_url`    VARCHAR(512)  NOT NULL,
                `webhook_type`    VARCHAR(50)   NOT NULL,
                `secret_key`      VARCHAR(255)  NOT NULL,
                `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
                `retry_count`     INT           NOT NULL DEFAULT 3,
                `timeout_seconds` INT           NOT NULL DEFAULT 30,
                `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_owhe_appid_type` (`appid`, `webhook_type`),
                KEY `idx_owhe_appid`  (`appid`),
                KEY `idx_owhe_type`   (`webhook_type`),
                KEY `idx_owhe_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->query("CREATE TABLE IF NOT EXISTS {$tEvents} (
                `event_id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `webhook_id`      INT UNSIGNED    NOT NULL,
                `event_type`      VARCHAR(50)     NOT NULL,
                `user_id`         BIGINT UNSIGNED,
                `device_code`     VARCHAR(128),
                `token_id`        INT UNSIGNED,
                `payload`         JSON            NOT NULL,
                `status`          VARCHAR(20)     NOT NULL DEFAULT 'pending',
                `attempts`        INT             NOT NULL DEFAULT 0,
                `max_attempts`    INT             NOT NULL DEFAULT 3,
                `next_attempt_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_error`      TEXT,
                `sent_at`         DATETIME,
                `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_owev_status`       (`status`),
                KEY `idx_owev_next_attempt` (`next_attempt_at`),
                KEY `idx_owev_webhook_id`   (`webhook_id`),
                KEY `idx_owev_user_id`      (`user_id`),
                CONSTRAINT `fk_owev_webhook` FOREIGN KEY (`webhook_id`)
                    REFERENCES {$tEndp} (`webhook_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function down(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        $schema = $db->schema();

        if ($caps->isPostgreSQL()) {
            $db->query('DROP FUNCTION IF EXISTS applications.create_webhook_event(VARCHAR, INTEGER, JSONB, VARCHAR, INTEGER) CASCADE');
        }

        $schema->dropTableIfExists('applications.oauth2_webhook_events');
        $schema->dropTableIfExists('applications.oauth2_webhook_endpoints');
    }
}
