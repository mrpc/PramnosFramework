<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the jwt_replay_prevention table.
 *
 * Stores seen JWT jti (JWT ID) claims to prevent token replay attacks.
 * When a JWT access token is validated, its jti is checked against this
 * table; if already present the request is rejected. Rows are cleaned up
 * after the token's expiry time passes.
 *
 * On PostgreSQL: lives in the `authserver` schema.
 * On MySQL, the schema is translated to a prefix: authserver_jwt_replay_prevention.
 *
 * @package PramnosFramework
 */
class CreateJwtReplayPreventionTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 20;
    public array  $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the jwt_replay_prevention table to block token replay attacks';

    public function up(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $db->query("CREATE TABLE IF NOT EXISTS authserver.jwt_replay_prevention (
                jti        VARCHAR(255) PRIMARY KEY,
                expires_at TIMESTAMP    NOT NULL,
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->query('CREATE INDEX IF NOT EXISTS idx_jrp_expires ON authserver.jwt_replay_prevention (expires_at)');
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS `authserver_jwt_replay_prevention` (
                `jti`        VARCHAR(255) NOT NULL PRIMARY KEY,
                `expires_at` DATETIME     NOT NULL,
                `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_jrp_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public function down(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $db->query('DROP TABLE IF EXISTS authserver.jwt_replay_prevention');
        } else {
            $db->query('DROP TABLE IF EXISTS `authserver_jwt_replay_prevention`');
        }
    }
}
