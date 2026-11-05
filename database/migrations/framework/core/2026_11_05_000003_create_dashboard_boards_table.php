<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * The dashboards people make for themselves ({@see \Pramnos\Dashboard\UserBoards}).
 *
 * State, not history: a row is a board that exists now, with its name and who sees it in their
 * menu — the same `visibility` and `audience` as a preset. Its cards are each viewer's own
 * layout in `usersettings` (`dashboard.my-{boardid}`); its owner's is where a viewer starts.
 */
class CreateDashboardBoardsTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 62;
    public array  $dependencies = [];
    public $description = 'Creates dashboard_boards, the dashboards people make for themselves';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('pramnos.dashboard_boards')) {
            return;
        }

        $schema->createTable('pramnos.dashboard_boards', function ($table) {
            $table->comment('Dashboards people make for themselves, and who sees them');
            $table->increments('boardid');
            $table->string('name', 120)->comment('Its name, in the menu');
            $table->integer('ownerid')->comment('Who made it');
            $table->string('visibility', 16)->default('private')->comment('private, role, organization or everyone');
            $table->string('audience', 64)->nullable()->comment('The role or the organisation id it is shared with');
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
            $table->index(['ownerid'], 'idx_dashboard_boards_owner');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.dashboard_boards');
    }
}
