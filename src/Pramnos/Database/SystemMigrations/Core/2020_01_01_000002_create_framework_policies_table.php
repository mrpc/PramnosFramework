<?php

namespace Pramnos\Database\SystemMigrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates the framework_policies table used by the Policy Engine daemon to
 * store and manage scheduled retention / aggregate-refresh / compression
 * policies on database backends that do not have a native scheduler
 * (MySQL and plain PostgreSQL).
 *
 * The table is part of the 'core' feature and is always created when the
 * framework runs its system migrations.
 *
 * @package PramnosFramework
 */
class CreateFrameworkPoliciesTable extends Migration
{
    public string $feature      = 'core';
    public string $scope        = 'framework';
    public int    $priority     = 10;
    public string $description  = 'Creates the framework_policies table for the Policy Engine';

    public function up(): void
    {
        $db = $this->application->database;

        if ($db->type === 'postgresql' || $db->type === 'timescaledb') {
            $db->query("
                CREATE TABLE IF NOT EXISTS framework_policies (
                    policyid    SERIAL          PRIMARY KEY,
                    policy_type VARCHAR(50)     NOT NULL,
                    target      VARCHAR(255)    NOT NULL,
                    config      JSON            NOT NULL DEFAULT '{}',
                    enabled     BOOLEAN         NOT NULL DEFAULT TRUE,
                    last_run    TIMESTAMPTZ     NULL,
                    next_run    TIMESTAMPTZ     NULL,
                    last_result TEXT            NULL,
                    last_error  TEXT            NULL,
                    created_at  TIMESTAMPTZ     NOT NULL DEFAULT NOW()
                )
            ");
            $db->query("
                CREATE INDEX IF NOT EXISTS idx_framework_policies_type_enabled
                ON framework_policies (policy_type, enabled)
            ");
            $db->query("
                CREATE INDEX IF NOT EXISTS idx_framework_policies_next_run
                ON framework_policies (next_run)
                WHERE enabled = TRUE
            ");
        } else {
            // MySQL
            $db->query("
                CREATE TABLE IF NOT EXISTS `framework_policies` (
                    `policyid`    INT             NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `policy_type` VARCHAR(50)     NOT NULL,
                    `target`      VARCHAR(255)    NOT NULL,
                    `config`      JSON            NOT NULL,
                    `enabled`     TINYINT(1)      NOT NULL DEFAULT 1,
                    `last_run`    DATETIME        NULL,
                    `next_run`    DATETIME        NULL,
                    `last_result` TEXT            NULL,
                    `last_error`  TEXT            NULL,
                    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_type_enabled` (`policy_type`, `enabled`),
                    INDEX `idx_next_run` (`next_run`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    public function down(): void
    {
        $db = $this->application->database;

        if ($db->type === 'postgresql' || $db->type === 'timescaledb') {
            $db->query('DROP TABLE IF EXISTS framework_policies CASCADE');
        } else {
            $db->query('DROP TABLE IF EXISTS `framework_policies`');
        }
    }
}
