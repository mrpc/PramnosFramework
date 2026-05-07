<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the oauth2_client_auth_methods table.
 *
 * Records which client authentication methods (per RFC 7591 / OIDC Core)
 * each OAuth2 application supports. Allows per-client flexibility in how
 * secrets are transmitted (client_secret_basic, client_secret_post,
 * private_key_jwt, none).
 *
 * On PostgreSQL: lives in the `authserver` schema.
 * On MySQL: created in the default database.
 *
 * @package PramnosFramework
 */
class CreateOauth2ClientAuthMethodsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 20;
    public array  $dependencies = ['create_authserver_schema', 'create_applications_table'];
    public $description  = 'Creates the oauth2_client_auth_methods table';

    public function up(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $db->query("CREATE TABLE IF NOT EXISTS authserver.oauth2_client_auth_methods (
                id            SERIAL PRIMARY KEY,
                appid         INTEGER  NOT NULL,
                auth_method   VARCHAR(50) NOT NULL
                    CHECK (auth_method IN ('client_secret_basic','client_secret_post','private_key_jwt','none')),
                is_active     BOOLEAN  NOT NULL DEFAULT TRUE,
                created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (appid, auth_method)
            )");
            $db->query('CREATE INDEX IF NOT EXISTS idx_ocam_appid ON authserver.oauth2_client_auth_methods (appid)');
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS `#PREFIX#oauth2_client_auth_methods` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `appid`       INT UNSIGNED NOT NULL,
                `auth_method` ENUM('client_secret_basic','client_secret_post','private_key_jwt','none') NOT NULL,
                `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_ocam_appid_method` (`appid`, `auth_method`),
                KEY `idx_ocam_appid` (`appid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function down(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $db->query('DROP TABLE IF EXISTS authserver.oauth2_client_auth_methods');
        } else {
            $db->query('DROP TABLE IF EXISTS `#PREFIX#oauth2_client_auth_methods`');
        }
    }
}
