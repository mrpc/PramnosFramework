<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Named dashboard layouts people save and share ({@see \Pramnos\Dashboard\Presets}).
 *
 * State, not history: a row is a preset that exists now, and it goes when it is deleted. Applying
 * one copies its layout into the person's own `usersettings` row, so nothing here follows them.
 * `visibility` is who sees it — `private`, `role` (from the `audience` role up), `organization`
 * (members of the `audience` organisation) or `everyone` with the board — and `default_for_role`
 * makes it what a new board starts from for that role.
 */
class CreateDashboardPresetsTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 61;
    public array  $dependencies = [];
    public $description = 'Creates dashboard_presets, the dashboard layouts people save and share';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('pramnos.dashboard_presets')) {
            return;
        }

        $schema->createTable('pramnos.dashboard_presets', function ($table) {
            $table->comment('Dashboard layouts people save and share');
            $table->increments('presetid');
            $table->string('board', 64)->comment('The board it is for: admin, or a name app.php lists');
            $table->string('name', 120)->comment('What it is called');
            $table->text('layout')->comment('The layout, as JSON: [{id, size, settings}, ...]');
            $table->integer('ownerid')->comment('Who saved it');
            $table->string('visibility', 16)->default('private')->comment('private, role, organization or everyone');
            $table->string('audience', 64)->nullable()->comment('The role or the organisation id it is shared with');
            $table->string('default_for_role', 32)->nullable()->comment('A role a new board starts from it for');
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
            $table->index(['board'], 'idx_dashboard_presets_board');
            $table->index(['ownerid'], 'idx_dashboard_presets_owner');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.dashboard_presets');
    }
}
