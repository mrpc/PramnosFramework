<?php

namespace Pramnos\Framework\Migrations\UserGroups;

use Pramnos\Database\Migration;

/**
 * When somebody joined a group, by whom and how: `userstogroups.addedat`, `addedby`,
 * `addedsource` — when the membership began (Unix seconds), the account that made it, and how
 * (`admin`, `bulk`, `rule`, the application's own word).
 *
 * State, not history: the row goes when the membership ends. Who joined and who left, and when, is
 * the changelog's (`group.joined` / `group.left`, see `Audience\Groups`). Null for memberships
 * older than this migration: nothing is guessed for them, and an application that knows from a
 * log of its own fills them itself. Only added, never rewritten: a table of tens of thousands of
 * memberships gains three nullable columns and an index.
 */
class AddMembershipHistoryToUsergroups extends Migration
{
    public string $feature     = 'usergroups';
    public string $scope       = 'framework';
    public int    $priority    = 55;
    public $description = 'Adds when, by whom and how to group memberships';

    /** Add the columns, where they are not there yet. */
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
    }

    /** Remove them again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('userstogroups') && $schema->hasColumn('userstogroups', 'addedat')) {
            $schema->table('userstogroups', function ($table) {
                $table->dropIndex('idx_userstogroups_group_added');
                $table->dropColumn(['addedat', 'addedby', 'addedsource']);
            });
        }
    }
}
