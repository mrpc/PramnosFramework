<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver roles table — RBAC role definitions.
 *
 * Roles group permissions and can optionally be scoped to a DEYA (utility
 * organisation). A role with deyaid=NULL is a system-wide role applicable to
 * all organisations.
 *
 * PostgreSQL: created in the `authserver` schema.
 * MySQL: created in the default schema with no schema prefix.
 *
 * @package PramnosFramework
 */
class CreateAuthserverRolesTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 20;
    public array   $dependencies = ['create_authserver_schema'];
    public string  $description  = 'Creates the authserver.roles RBAC roles table';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $caps   = $schema->getCapabilities();

        $tableName = $caps->isPostgreSQL() ? 'authserver.roles' : 'authserver_roles';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->createTable($tableName, function ($table) {
            $table->comment('RBAC role definitions — roles group permissions and can be scoped to an organisation (deyaid)');

            $table->increments('roleid')
                ->comment('Auto-increment role identifier');
            $table->string('role_name', 100)
                ->comment('Unique role name used in code (e.g. "admin", "operator", "viewer")');
            $table->text('description')->nullable()
                ->comment('Human-readable description of what this role grants');
            $table->integer('deyaid')->nullable()
                ->comment('FK to DEYA organisation — NULL = system-wide role valid for all organisations');
            $table->timestamp('created_at')->useCurrent()
                ->comment('Timestamp when the role was created');
            $table->boolean('is_active')->default(true)
                ->comment('Whether this role can currently be assigned to users');

            $table->index(['role_name'], 'idx_authserver_roles_name');
            $table->index(['deyaid'], 'idx_authserver_roles_deyaid');
            $table->index(['is_active'], 'idx_authserver_roles_active');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $caps   = $schema->getCapabilities();
        $tableName = $caps->isPostgreSQL() ? 'authserver.roles' : 'authserver_roles';
        $schema->dropTableIfExists($tableName);
    }
}
