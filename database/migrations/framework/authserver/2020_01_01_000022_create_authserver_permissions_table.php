<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver permissions table — fine-grained RBAC permission grants.
 *
 * Each row grants (or denies) a subject (user/role/application) the ability to
 * perform an action on an object type. Deny entries take priority over allow
 * entries: a deny_count > 0 in the effective_permissions view means no access,
 * regardless of allow_count.
 *
 * The `priority` column affects tie-breaking when multiple permissions apply to
 * the same subject+object+action combination. Deny entries automatically receive
 * priority + 1000 to ensure they dominate.
 *
 * @package PramnosFramework
 */
class CreateAuthserverPermissionsTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 30;
    public array   $dependencies = ['create_authserver_roles_table'];
    public $description  = 'Creates the authserver.permissions RBAC permissions table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.permissions')) {
            return;
        }

        $schema->createTable('authserver.permissions', function ($table) {
            $table->comment('Fine-grained RBAC permissions — deny entries take absolute priority over allow entries');

            $table->increments('permissionid')
                ->comment('Auto-increment permission record identifier');
            $table->string('subject_type', 20)
                ->comment('Subject kind: user | role | application');
            $table->bigInteger('subject_id')
                ->comment('Identifier of the subject: userid, roleid, or applicationid depending on subject_type');
            $table->string('object_type', 50)
                ->comment('Type of the protected resource (e.g. "invoice", "device", "report")');
            $table->string('object_id', 100)->nullable()
                ->comment('Specific object instance ID — VARCHAR to support wildcards ("*") and non-integer IDs; NULL = all objects of object_type');
            $table->string('action', 20)
                ->comment('Permitted or denied action: create | read | update | delete | * (wildcard = all actions)');
            $table->string('grant_type', 5)->default('allow')
                ->comment('Permission disposition: allow | deny — deny entries take priority regardless of allow count');
            $table->integer('priority')->default(100)
                ->comment('Tie-breaking priority when multiple rules match; deny entries are stored with priority+1000');
            $table->bigInteger('granted_by')->nullable()
                ->comment('FK to users.userid of the administrator who created this permission entry');
            $table->timestamp('granted_at')->useCurrent()
                ->comment('Timestamp when this permission was granted');
            $table->timestamp('expires_at')->nullable()
                ->comment('Permission expiry timestamp; NULL = permanent');
            $table->boolean('is_active')->default(true)
                ->comment('Soft-delete flag — inactive permissions are ignored by the policy engine');
            $table->text('description')->nullable()
                ->comment('Optional human-readable note explaining why this permission was granted');

            // Unique constraint required for ON CONFLICT DO NOTHING in apply_permission_template()
            $table->unique(
                ['subject_type', 'subject_id', 'object_type', 'object_id', 'action', 'grant_type'],
                'uq_authserver_perms_grant'
            );

            $table->index(['subject_type', 'subject_id'], 'idx_authserver_perms_subject');
            $table->index(['object_type', 'object_id'], 'idx_authserver_perms_object');
            $table->index(['subject_type', 'subject_id', 'object_type', 'action'], 'idx_authserver_perms_lookup');
            $table->index(['is_active', 'expires_at'], 'idx_authserver_perms_active');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.permissions');
    }
}
