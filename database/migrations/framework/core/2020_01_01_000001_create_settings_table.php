<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates the settings table for application-level key/value configuration.
 *
 * Settings are loaded at runtime by the framework's Settings subsystem and
 * cached for the request lifetime. Rows with delete=0 are considered permanent
 * and are never removed by automated cleanup processes.
 *
 * @package PramnosFramework
 */
class CreateSettingsTable extends Migration
{
    public string  $feature      = 'core';
    public string  $scope        = 'framework';
    public int     $priority     = 20;
    public $description  = 'Creates the settings table';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('settings')) {
            return;
        }

        $schema->createTable('settings', function ($table) {
            $table->comment('Application key/value configuration store');

            $table->increments('setting_id')
                ->comment('Auto-increment primary key');
            $table->string('setting', 128)->default('')
                ->comment('Setting name/key (unique per application logic, not enforced at DB level)');
            $table->text('value')
                ->comment('Setting value (may contain serialised arrays or JSON)');
            $table->tinyInteger('delete')->default(1)
                ->comment('1 = can be deleted by cleanup; 0 = permanent setting, never auto-deleted');

            $table->index(['setting'], 'idx_settings_name');
        });
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('settings');
    }
}
