<?php

namespace Pramnos\Framework\Migrations\UserGroups;

use Pramnos\Database\Migration;

/**
 * When somebody joined a group, by whom and how — and who joined and left when.
 *
 * - `userstogroups.addedat`, `addedby`, `addedsource`: for a membership, when it began (Unix
 *   seconds), the account that made it, and how (`admin`, `bulk`, `rule`, the application's own
 *   word). Null for memberships older than this migration: nothing is guessed for them, and an
 *   application that knows from a log of its own fills them itself.
 * - `usergroup_log`: one row per person joining or leaving a group — what counts who entered and
 *   who left a group over a week, as `segment_members` does for a segment.
 *
 * Only added, never rewritten: a table of tens of thousands of memberships gains three nullable
 * columns and an index.
 */
class AddMembershipHistoryToUsergroups extends Migration
{
    public string $feature     = 'usergroups';
    public string $scope       = 'framework';
    public int    $priority    = 55;
    public $description = 'Adds when and by whom to group memberships, and a log of joining and leaving';

    /** Add the columns and the log, where they are not there yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('userstogroups') && !$schema->hasColumn('userstogroups', 'addedat')) {
            $schema->table('userstogroups', function ($table) {
                $table->unsignedInteger('addedat')->nullable()->comment('When the membership began; null when older than its record');
                $table->bigInteger('addedby')->nullable()->comment('The account that made it');
                $table->string('addedsource', 20)->nullable()->comment('How: admin, bulk, rule, or the application\'s own word');
                $table->index(['groupid', 'addedat'], 'idx_userstogroups_group_added');
            });
        }

        if (!$schema->hasTable('usergroup_log')) {
            $schema->createTable('usergroup_log', function ($table) {
                $table->comment('Somebody joining or leaving a group: what counts who came and went');
                $table->bigIncrements('id')->comment('Auto-increment identifier');
                $table->integer('groupid')->comment('The group');
                $table->bigInteger('userid')->comment('Who joined or left');
                $table->string('action', 3)->comment('in | out');
                $table->unsignedInteger('createdat')->comment('When');
                $table->bigInteger('actor')->nullable()->comment('The account that did it');
                $table->string('source', 20)->default('')->comment('How: admin, bulk, rule, or the application\'s own word');
                $table->index(['groupid', 'createdat'], 'idx_usergroup_log_group');
                $table->index(['userid'], 'idx_usergroup_log_user');
            });
        }
    }

    /** Remove them again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('usergroup_log');
        if ($schema->hasTable('userstogroups') && $schema->hasColumn('userstogroups', 'addedat')) {
            $schema->table('userstogroups', function ($table) {
                $table->dropIndex('idx_userstogroups_group_added');
                $table->dropColumn(['addedat', 'addedby', 'addedsource']);
            });
        }
    }
}
