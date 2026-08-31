<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver user_roles table — user-to-role assignments.
 *
 * Maps users to their assigned RBAC roles. A user may hold multiple roles, and an
 * assignment may carry an expiry timestamp for a temporary grant.
 *
 * **An assignment is not organisation-scoped.** This docblock and the table comment
 * used to say it could be, and there is no column for it: the row is (userid, roleid)
 * and nothing else. Organisation lives one level up, on `authserver.roles.<org
 * column>`, where NULL means a system-wide role.
 *
 * Read the rest of this before designing on it, because the shape is not what the
 * table names suggest: {@see \Pramnos\Auth\PermissionResolver} has no organisation
 * dimension at all. It scopes by *application* (`permissions.app_id`) and returns
 * every active role a user holds, whatever organisation that role names. So a role
 * scoped to organisation A grants its permissions everywhere, and membership in
 * `user_organizations` is recorded but never checked.
 *
 * Isolating one organisation's data from another's is therefore the application's
 * job today — through the ABAC `conditions` the resolver passes through unevaluated,
 * or a global scope on the models
 * ({@see \Pramnos\Application\Orm\Concerns\HasScopes}). Do not assume the
 * framework is enforcing it.
 *
 * Lives under the `auth` feature, not `authserver`: an application with users
 * needs somewhere to record who may do what, whether or not it ever runs an
 * OAuth server. The `authserver.` schema name is kept as-is — renaming it
 * would break every existing installation and buy nothing.
 *
 */
class CreateAuthserverUserRolesTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public array   $dependencies = ['create_authserver_roles_table'];
    public $description  = 'Creates the authserver.user_roles assignment table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.user_roles')) {
            return;
        }

        $schema->createTable('authserver.user_roles', function ($table) {
            // No mention of organisation scoping: there is no column for it, and the
            // comment saying otherwise was the only thing suggesting there was.
            // Existing installations keep the old comment — this migration is guarded
            // by hasTable() and will not re-run — so the docblock above is where the
            // correction actually reaches a reader.
            $table->comment('User-to-role assignments — one row per (user, role) pair; may carry an expiry for a temporary grant');

            $table->bigInteger('userid')
                ->comment('FK to users.userid — the user receiving the role');
            $table->integer('roleid')
                ->comment('FK to authserver.roles.roleid — the role being assigned');
            $table->bigInteger('granted_by')->nullable()
                ->comment('FK to users.userid of the administrator who assigned this role; NULL for system assignments');
            $table->timestamp('granted_at')->useCurrent()
                ->comment('Timestamp when the role was assigned');
            $table->timestamp('expires_at')->nullable()
                ->comment('Role assignment expiry; NULL = permanent');
            $table->boolean('is_active')->default(true)
                ->comment('Soft-delete flag — inactive assignments are excluded from permission checks');

            $table->primary(['userid', 'roleid']);

            $table->index(['userid', 'is_active'], 'idx_authserver_ur_userid');
            $table->index(['roleid'], 'idx_authserver_ur_roleid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.user_roles');
    }
}
