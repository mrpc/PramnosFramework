<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver role_templates table.
 *
 * Role templates bundle a set of permission templates so that a complete
 * access profile can be applied in one call (apply_role_template()). Creating
 * a role from a template creates the role row and then applies each listed
 * permission template to it automatically.
 *
 * The permission_templateids column stores a JSON array of integer template IDs.
 * On PostgreSQL the column is TEXT containing a JSON array; the native
 * INTEGER[] type is not used here to maintain cross-database compatibility with
 * MySQL. The PL/pgSQL apply_role_template() function iterates this array.
 *
 */
class CreateAuthserverRoleTemplatesTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 60;
    public array   $dependencies = ['create_authserver_permission_templates_table'];
    public $description  = 'Creates the authserver.role_templates role blueprint table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.role_templates')) {
            return;
        }

        $schema->createTable('authserver.role_templates', function ($table) {
            $table->comment('Role blueprints that bundle permission templates — apply once to create a complete role with permissions');

            $table->increments('role_templateid')
                ->comment('Auto-increment role template identifier');
            $table->string('template_name', 100)
                ->comment('Unique human-readable name (e.g. "org_administrator")');
            $table->text('description')->nullable()
                ->comment('Description of what type of role this template creates');
            $table->string('suggested_role_name', 100)->nullable()
                ->comment('Default role name suggested when instantiating this template');
            $table->text('permission_templateids')->nullable()
                ->comment('JSON array of permission_templates.templateid values to apply when using this role template');
            $table->boolean('is_system_template')->default(false)
                ->comment('TRUE for system-wide role templates; FALSE for organisation-scoped templates');
            $table->boolean('is_active')->default(true)
                ->comment('Whether this template is currently available to apply');
            $table->timestamp('created_at')->useCurrent()
                ->comment('When this template was created');
            $table->bigInteger('created_by')->nullable()
                ->comment('FK to users.userid of who created this template');

            $table->index(['template_name'], 'idx_authserver_rt_name');
            $table->index(['is_system_template', 'is_active'], 'idx_authserver_rt_system');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.role_templates');
    }
}
