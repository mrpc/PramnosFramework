<?php

namespace Pramnos\Framework\Migrations\UserGroups;

use Pramnos\Database\Migration;

/**
 * Creates `authserver.group_roles` — roles held by a group, and so by everyone in it.
 *
 * A role given to a group counts for each member exactly as one given to the member directly:
 * active, not expired, and — when a check is scoped to an organisation — system-wide or that
 * organisation's, with the member belonging to it. `PermissionResolver` folds these in beside
 * `authserver.user_roles`.
 *
 * The same shape as `user_roles`, with `groupid` for `userid`, so an assignment can expire and
 * be switched off without being deleted. No foreign keys: the group table may be one an
 * installation built itself, with a `groupid` type this cannot know; the Groups screen and
 * `Role::delete()` remove the rows instead.
 */
class CreateAuthserverGroupRolesTable extends Migration
{
    public string  $feature      = 'usergroups';
    public string  $scope        = 'framework';
    public int     $priority     = 51;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates authserver.group_roles: roles held by a user group';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.group_roles')) {
            return;
        }

        $schema->createTable('authserver.group_roles', function ($table) {
            $table->comment('Group-to-role assignments — every member of the group holds the role');

            $table->integer('groupid')->unsigned()
                ->comment('usergroups.groupid of the group');
            $table->integer('roleid')
                ->comment('authserver.roles.roleid of the role');
            $table->bigInteger('granted_by')->nullable()
                ->comment('users.userid of whoever gave it');
            $table->timestamp('granted_at')->useCurrent()
                ->comment('When it was given');
            $table->timestamp('expires_at')->nullable()
                ->comment('When it stops counting; NULL = does not expire');
            $table->boolean('is_active')->default(true)
                ->comment('false = withdrawn, kept for the record');

            $table->primary(['groupid', 'roleid']);
            $table->index(['roleid'], 'idx_authserver_gr_roleid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.group_roles');
    }
}
