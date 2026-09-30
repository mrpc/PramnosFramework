<?php

namespace Pramnos\Framework\Migrations\UserGroups;

use Pramnos\Database\Migration;

/**
 * Creates `usergroups` and `userstogroups` — the optional user groups feature.
 *
 * Groups existed before this migration did. `User::getGroups()` reads the membership table,
 * `Permissions` grants to a group through it, and the mass-message screen offers a group as an
 * audience; but no migration created either table, so on a stock installation the feature was
 * simply absent. An installation that built them by hand keeps its own: each table is created
 * only when it is missing.
 *
 * One migration for both, because of one foreign key. A hand-built `usergroups` has
 * `groupid mediumint unsigned`, and MySQL refuses a key between columns of different types — so
 * `userstogroups.groupid` references `usergroups` only when this migration created both. On an
 * installation that already had the groups table, membership rows are removed with their group by
 * `GroupsController::delete()` instead.
 */
class CreateUsergroupsTables extends Migration
{
    public string  $feature      = 'usergroups';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates usergroups and userstogroups for the user groups feature';

    public function up(): void
    {
        $schema        = $this->application->database->schema();
        $createdGroups = false;

        if (!$schema->hasTable('usergroups')) {
            $schema->createTable('usergroups', function ($table) {
                $table->comment('User groups — named sets of accounts that permissions and mail can address');

                $table->increments('groupid')
                    ->comment('Auto-increment group identifier');
                $table->string('name', 80)
                    ->comment('Group name, shown in the admin area and in audience pickers');
                $table->text('description')->nullable()
                    ->comment('What the group is for');
                $table->smallInteger('order')->nullable()
                    ->comment('Display order; NULL sorts by name');
            });
            $createdGroups = true;
        }

        if (!$schema->hasTable('userstogroups')) {
            $schema->createTable('userstogroups', function ($table) use ($createdGroups) {
                $table->comment('Group membership — one row per user per group');

                $table->bigInteger('userid')
                    ->comment('users.userid of the member');
                // Unsigned, to match `increments()` — MySQL refuses a key between the two otherwise.
                $table->integer('groupid')->unsigned()
                    ->comment('usergroups.groupid of the group');

                $table->primary(['userid', 'groupid']);
                $table->index(['groupid'], 'idx_userstogroups_groupid');

                $table->foreign('userid')
                    ->references('userid')
                    ->on('#PREFIX#users')
                    ->onDelete('cascade')
                    ->onUpdate('cascade')
                    ->name('fk_userstogroups_userid');

                if ($createdGroups) {
                    $table->foreign('groupid')
                        ->references('groupid')
                        ->on('#PREFIX#usergroups')
                        ->onDelete('cascade')
                        ->onUpdate('cascade')
                        ->name('fk_userstogroups_groupid');
                }
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('userstogroups');
        $schema->dropTableIfExists('usergroups');
    }
}
