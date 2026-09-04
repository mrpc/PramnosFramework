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
 * Both drop the old index before creating the new one, which is why this
 * migration checks for duplicate values first and declines when it finds any:
 * a failure halfway leaves the table with no index at all.
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

        /*
         * Check the data before dropping the index that is protecting it.
         *
         * A settings table that predates the constraint is exactly the table that
         * may hold two rows for one name — that is *why* the constraint is being
         * added. `CREATE UNIQUE INDEX` validates every row, so one duplicate
         * aborts it; and because the plain index is dropped first, the failure
         * leaves the installation with **neither** index on a column every
         * settings read uses.
         *
         * Declining rather than resolving. Keeping the highest id per name and
         * deleting the rest is a decision about somebody's configuration, and
         * two rows for `sitename` may well mean the wrong one has been in effect
         * for months — which is worth an operator's attention, not a migration's
         * tidying. The reason names the values so it can be acted on, and this
         * stays pending until it is.
         */
        $duplicates = $this->duplicateGroups('#PREFIX#settings', 'setting');

        if ($duplicates !== array()) {
            $named = array();
            foreach ($duplicates as $group) {
                $named[] = "'" . $group['value'] . "' (" . $group['rows'] . ' rows)';
            }

            $this->decline(
                'settings.setting has duplicate values, so a unique index cannot be'
                . ' created: ' . implode(', ', $named) . '.'
                . ' Decide which row is correct, delete the others, and run migrate'
                . ' again. Nothing was changed, and the existing index is untouched.'
            );

            return;
        }

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
