<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the user_privacy_settings table — per-user GDPR privacy preference flags.
 *
 * One row per user (userid is UNIQUE + FK to users). The primary key is a serial
 * `id` to match Urbanwater production and simplify cross-table references.
 *
 * Columns: share_usage_analytics, marketing_emails (boolean, default false).
 * The old `data_processing` column was removed — it does not exist in Urbanwater.
 *
 */
class CreateUserPrivacySettingsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 105;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.user_privacy_settings GDPR preference table';

    public function up(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if ($db->schema()->hasTable('authserver.user_privacy_settings')) {
            return;
        }

        $t = $db->schema()->quoteTable('authserver.user_privacy_settings');

        if ($caps->isPostgreSQL()) {
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (
                id                    SERIAL PRIMARY KEY,
                userid                BIGINT       NOT NULL,
                share_usage_analytics BOOLEAN      DEFAULT FALSE,
                marketing_emails      BOOLEAN      DEFAULT FALSE,
                updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                UNIQUE (userid),
                CONSTRAINT fk_user_privacy_userid
                    FOREIGN KEY (userid) REFERENCES public.users(userid) ON DELETE CASCADE
            )");
            $db->query("CREATE INDEX IF NOT EXISTS idx_user_privacy_userid ON {$t} (userid)");
            $db->query("COMMENT ON TABLE {$t} IS 'Per-user GDPR privacy preferences — one row per user; created on first privacy settings update'");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS {$t} (
                `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `userid`                BIGINT       NOT NULL,
                `share_usage_analytics` TINYINT(1)   DEFAULT 0,
                `marketing_emails`      TINYINT(1)   DEFAULT 0,
                `updated_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_user_privacy_userid` (`userid`),
                KEY `idx_user_privacy_userid` (`userid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-user GDPR privacy preferences — one row per user'");
        }
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.user_privacy_settings');
    }
}
