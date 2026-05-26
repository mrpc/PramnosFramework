<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Adds a UNIQUE constraint on settings.setting so that upsert queries work
 * correctly on PostgreSQL.
 *
 * The original CreateSettingsTable migration added only a plain index on the
 * `setting` column. Settings::setSetting() relies on row-level uniqueness, so
 * we retroactively enforce it here.
 *
 * MySQL: replaces idx_settings_name with a UNIQUE index.
 * PostgreSQL: drops the plain index and creates a UNIQUE index.
 *
 * @package PramnosFramework
 */
class AddUniqueConstraintToSettingsTable extends Migration
{
    public string $feature      = 'core';
    public string $scope        = 'framework';
    public int    $priority     = 21;
    public array  $dependencies = ['create_settings_table'];
    public $description = 'Adds UNIQUE constraint on settings.setting column';

    public function up(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->DB()->query('DROP INDEX IF EXISTS idx_settings_name');
            $this->DB()->query(
                'CREATE UNIQUE INDEX IF NOT EXISTS uq_settings_name
                 ON "#PREFIX#settings" (setting)'
            );
        } else {
            // Silently ignore if the plain index doesn't exist.
            try {
                $this->DB()->query('ALTER TABLE `#PREFIX#settings` DROP INDEX `idx_settings_name`');
            } catch (\Exception) {
            }
            $this->DB()->query(
                'ALTER TABLE `#PREFIX#settings` ADD UNIQUE INDEX `uq_settings_name` (`setting`)'
            );
        }
    }

    public function down(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();

        if ($caps->isPostgreSQL()) {
            $this->DB()->query('DROP INDEX IF EXISTS uq_settings_name');
            $this->DB()->query('CREATE INDEX IF NOT EXISTS idx_settings_name ON "#PREFIX#settings" (setting)');
        } else {
            try {
                $this->DB()->query('ALTER TABLE `#PREFIX#settings` DROP INDEX `uq_settings_name`');
            } catch (\Exception) {
            }
            $this->DB()->query('ALTER TABLE `#PREFIX#settings` ADD INDEX `idx_settings_name` (`setting`)');
        }
    }
}
