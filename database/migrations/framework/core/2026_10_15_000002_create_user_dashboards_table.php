<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Each person's dashboard layout, per area: which widgets, in what order, how wide.
 *
 * `Dashboard\Layouts` reads and writes it. A row is a choice somebody made; with no row the
 * default applies, so a person who never customised the dashboard has none, and "back to the
 * default" deletes theirs. JSON in a text column, because a layout of a dozen widgets does not
 * fit the 255 characters of a user setting.
 */
class CreateUserDashboardsTable extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 231;
    public $description = 'Creates user_dashboards, each person\'s dashboard layout per area';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('user_dashboards')) {
            return;
        }

        $schema->createTable('user_dashboards', function ($table) {
            $table->comment('Each person\'s dashboard layout per area; no row means the default');
            $table->integer('userid')->comment('Whose layout');
            $table->string('area', 50)->comment('Which dashboard: admin, or an area of the application\'s');
            $table->text('layout')->comment('JSON: [{id, size}, …] in order');
            $table->unsignedInteger('updated_at')->comment('Unix time it was last saved');
            $table->unique(['userid', 'area'], 'idx_user_dashboards_user_area');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('user_dashboards');
    }
}
