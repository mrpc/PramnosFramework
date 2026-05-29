<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the device_authorizations table — RFC 8628 Device Authorization Grant.
 *
 * The Device Authorization Grant allows OAuth2 clients running on input-
 * constrained devices (smart TVs, IoT devices, printers) to obtain user
 * authorization by displaying a short user_code that the user enters on
 * a secondary device (phone or computer).
 *
 * On PostgreSQL, the table lives in the `authserver` schema.
 * On MySQL, the schema is translated to a prefix: authserver_device_authorizations.
 *
 */
class CreateDeviceAuthorizationsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 20;
    public array  $dependencies = ['create_authserver_schema', 'create_applications_table'];
    public $description  = 'Creates the device_authorizations table (RFC 8628 Device Grant)';

    public function up(): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();
        $caps   = $schema->getCapabilities();
        $t      = $schema->quoteTable('authserver.device_authorizations');

        if ($schema->hasTable('authserver.device_authorizations')) {
            return;
        }

        if ($caps->isPostgreSQL()) {
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (
                device_authorization_id BIGSERIAL PRIMARY KEY,
                device_code             VARCHAR(128) NOT NULL UNIQUE,
                user_code               VARCHAR(10)  NOT NULL UNIQUE,
                verification_uri        VARCHAR(500) NOT NULL,
                verification_uri_complete VARCHAR(600),
                expires_at              TIMESTAMP    NOT NULL,
                interval_seconds        SMALLINT     NOT NULL DEFAULT 5,
                scope                   TEXT,
                client_id               VARCHAR(255) NOT NULL,
                status                  VARCHAR(20)  NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','approved','denied','expired')),
                userid                  BIGINT,
                created_at              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query("CREATE INDEX IF NOT EXISTS idx_devauth_user_code ON {$t} (user_code)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_devauth_expires   ON {$t} (expires_at)");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (
                `device_authorization_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `device_code`             VARCHAR(128) NOT NULL,
                `user_code`               VARCHAR(10)  NOT NULL,
                `verification_uri`        VARCHAR(500) NOT NULL,
                `verification_uri_complete` VARCHAR(600),
                `expires_at`              DATETIME     NOT NULL,
                `interval_seconds`        TINYINT UNSIGNED NOT NULL DEFAULT 5,
                `scope`                   TEXT,
                `client_id`               VARCHAR(255) NOT NULL,
                `status`                  ENUM('pending','approved','denied','expired') NOT NULL DEFAULT 'pending',
                `userid`                  BIGINT,
                `created_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_devauth_device_code` (`device_code`),
                UNIQUE KEY `uq_devauth_user_code`   (`user_code`),
                KEY `idx_devauth_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function down(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        $db->schema()->dropTableIfExists('authserver.device_authorizations');
    }
}
