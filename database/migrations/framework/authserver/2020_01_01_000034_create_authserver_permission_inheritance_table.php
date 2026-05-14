<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver permission_inheritance table.
 *
 * Defines hierarchical relationships between resource objects so that
 * permissions cascade from parent to child. For example, if a user has
 * read access to a zone, and a location inherits from that zone, the user
 * can also read the location without a separate explicit permission grant.
 *
 * Three inheritance modes:
 * - full: child inherits all actions from the parent (create/read/update/delete)
 * - read_only: child inherits only read access from the parent
 * - custom: the application resolves non-standard inheritance rules
 *
 * Inheritance is resolved by check_permission_with_inheritance() on PostgreSQL.
 * On MySQL, application code must perform the inheritance walk manually.
 *
 * @package PramnosFramework
 */
class CreateAuthserverPermissionInheritanceTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 65;
    public array   $dependencies = ['create_authserver_role_templates_table'];
    public $description  = 'Creates the authserver.permission_inheritance hierarchy table';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.permission_inheritance')) {
            return;
        }

        $schema->createTable('authserver.permission_inheritance', function ($table) {
            $table->comment('Object hierarchy for permission inheritance — child objects inherit parent permissions');

            $table->increments('inheritanceid')
                ->comment('Auto-increment relationship identifier');
            $table->string('child_object_type', 50)
                ->comment('Type of the child resource that inherits permissions (e.g. "location")');
            $table->string('child_object_id', 100)
                ->comment('ID of the specific child resource instance');
            $table->string('parent_object_type', 50)
                ->comment('Type of the parent resource whose permissions are inherited (e.g. "zone")');
            $table->string('parent_object_id', 100)
                ->comment('ID of the specific parent resource instance');
            $table->string('inheritance_type', 20)->default('full')
                ->comment('Inheritance mode: full | read_only | custom');
            $table->boolean('is_active')->default(true)
                ->comment('Whether this inheritance relationship is currently in effect');
            $table->timestamp('created_at')->useCurrent()
                ->comment('When this inheritance relationship was defined');
            $table->bigInteger('created_by')->nullable()
                ->comment('FK to users.userid of who defined this inheritance relationship');

            $table->index(['child_object_type', 'child_object_id'], 'idx_authserver_pi_child');
            $table->index(['parent_object_type', 'parent_object_id'], 'idx_authserver_pi_parent');
            $table->index(['is_active'], 'idx_authserver_pi_active');
        });
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.permission_inheritance');
    }
}
