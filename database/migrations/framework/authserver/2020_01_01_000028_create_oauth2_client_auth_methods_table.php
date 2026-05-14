<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the oauth2_client_auth_methods table in the applications schema.
 *
 * Records which client authentication methods (per RFC 7591 / OIDC Core)
 * each OAuth2 application supports. Allows per-client flexibility in how
 * secrets are transmitted (client_secret_basic, client_secret_post,
 * private_key_jwt, none).
 *
 * On PostgreSQL: lives in the `applications` schema alongside other OAuth2
 * client management tables. On MySQL, the schema prefix is applications_.
 *
 * @package PramnosFramework
 */
class CreateOauth2ClientAuthMethodsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 20;
    public array  $dependencies = ['create_applications_schema', 'create_applications_table'];
    public $description  = 'Creates the applications.oauth2_client_auth_methods table';

    public function up(): void: void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        $schema = $db->schema();
        $t      = $schema->quoteTable('applications.oauth2_client_auth_methods');

        if ($schema->hasTable('applications.oauth2_client_auth_methods')) {
            return;
        }

        if ($caps->isPostgreSQL()) {
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (
                auth_method_id SERIAL PRIMARY KEY,
                appid          INTEGER      NOT NULL REFERENCES public.applications(appid) ON DELETE CASCADE,
                auth_method    VARCHAR(50)  NOT NULL
                    CHECK (auth_method IN ('client_secret_basic','client_secret_post','private_key_jwt','none')),
                is_primary     BOOLEAN      NOT NULL DEFAULT FALSE,
                is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
                created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (appid, auth_method)
            )");
            $db->query("CREATE INDEX IF NOT EXISTS idx_ocam_appid ON {$t} (appid)");
            $db->query("COMMENT ON TABLE {$t} IS 'Supported client authentication methods for OAuth2 applications'");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (
                `auth_method_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `appid`          INT UNSIGNED NOT NULL,
                `auth_method`    ENUM('client_secret_basic','client_secret_post','private_key_jwt','none') NOT NULL,
                `is_primary`     TINYINT(1) NOT NULL DEFAULT 0,
                `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`     DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_ocam_appid_method` (`appid`, `auth_method`),
                KEY `idx_ocam_appid` (`appid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('applications.oauth2_client_auth_methods');
    }
}
