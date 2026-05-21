<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver permission_templates table.
 *
 * Permission templates are reusable blueprints that capture a specific
 * permission grant (subject type, object type, object_id pattern, action,
 * grant_type, priority). Applying a template to a user, role, or organisation
 * creates actual permission rows from the template values — useful for
 * setting up standard access patterns without manual per-row inserts.
 *
 * The object_id_pattern may contain placeholders such as `{organization_id}` that
 * are resolved at apply-time by the apply_permission_template() function.
 * The placeholder name matches authserver_organization_column Setting (default: organization_id).
 *
 * @package PramnosFramework
 */
class CreateAuthserverPermissionTemplatesTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 55;
    public array   $dependencies = ['create_authserver_audit_log_table'];
    public $description  = 'Creates the authserver.permission_templates reusable permission blueprint table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.permission_templates')) {
            return;
        }

        $schema->createTable('authserver.permission_templates', function ($table) {
            $table->comment('Reusable permission blueprints — applied to users/roles/orgs to create actual permission rows');

            $table->increments('templateid')
                ->comment('Auto-increment template identifier');
            $table->string('template_name', 100)
                ->comment('Unique human-readable name (e.g. "org_admin_read_all")');
            $table->text('description')->nullable()
                ->comment('What this template provides');
            $table->string('template_type', 20)
                ->comment('Intended target: role_template | org_template | user_template');
            $table->string('object_type', 50)
                ->comment('Resource type this template applies to (e.g. "organization", "device", "report")');
            $table->string('object_id_pattern', 100)->nullable()
                ->comment('Object ID with optional placeholders, e.g. "{organization_id}" or "*" for all');
            $table->string('action', 20)
                ->comment('Action being permitted or denied: create | read | update | delete');
            $table->string('grant_type', 10)->default('allow')
                ->comment('Whether this template grants allow or deny permission');
            $table->integer('priority')->default(0)
                ->comment('Default priority for permissions created from this template');
            $table->boolean('is_active')->default(true)
                ->comment('Whether this template is currently available to apply');
            $table->timestamp('created_at')->useCurrent()
                ->comment('When this template was created');
            $table->bigInteger('created_by')->nullable()
                ->comment('FK to users.userid of who created this template');

            $table->index(['template_name'], 'idx_authserver_pt_name');
            $table->index(['template_type', 'is_active'], 'idx_authserver_pt_type');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.permission_templates');
    }
}
