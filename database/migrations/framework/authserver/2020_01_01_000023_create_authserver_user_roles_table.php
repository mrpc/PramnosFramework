<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver user_roles table — user-to-role assignments.
 *
 * Maps users to their assigned RBAC roles. A user may hold multiple roles.
 * Assignments can be scoped to an organisation (deyaid) and may carry an
 * expiry timestamp for temporary role grants.
 *
 * @package PramnosFramework
 */
class CreateAuthserverUserRolesTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public array   $dependencies = ['create_authserver_roles_table'];
    public $description  = 'Creates the authserver.user_roles assignment table';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $caps   = $schema->getCapabilities();

        $tableName   = $caps->isPostgreSQL() ? 'authserver.user_roles' : 'authserver_user_roles';
        $rolesTable  = $caps->isPostgreSQL() ? 'authserver.roles'      : 'authserver_roles';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->createTable($tableName, function ($table) use ($rolesTable) {
            $table->comment('User-to-role assignments — one row per (user, role) pair; supports temporary and org-scoped grants');

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
        $schema = $this->application->database->schema();
        $caps   = $schema->getCapabilities();
        $tableName = $caps->isPostgreSQL() ? 'authserver.user_roles' : 'authserver_user_roles';
        $schema->dropTableIfExists($tableName);
    }
}
