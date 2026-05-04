<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver audit_log table — full audit trail for permission changes.
 *
 * Every change to roles, permissions, or user-role assignments is recorded here
 * with the before/after state. Immutable once written; supports compliance and
 * forensic investigation of permission escalations.
 *
 * @package PramnosFramework
 */
class CreateAuthserverAuditLogTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_authserver_permissions_table'];
    public $description  = 'Creates the authserver.audit_log permission change history table';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $caps   = $schema->getCapabilities();

        $tableName = $caps->isPostgreSQL() ? 'authserver.audit_log' : 'authserver_audit_log';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->createTable($tableName, function ($table) {
            $table->comment('Immutable audit trail for all RBAC changes — permission grants/revocations, role assignments');

            $table->bigIncrements('logid')
                ->comment('Auto-increment log entry identifier');
            $table->string('action_type', 50)
                ->comment('Type of change: grant_permission | revoke_permission | assign_role | remove_role | create_role | update_role | delete_role');
            $table->bigInteger('performed_by')->nullable()
                ->comment('FK to users.userid of the administrator who made the change; NULL for system actions');
            $table->bigInteger('target_userid')->nullable()
                ->comment('FK to users.userid of the user affected by the change; NULL for role-only changes');
            $table->integer('target_roleid')->nullable()
                ->comment('FK to authserver.roles.roleid affected by the change; NULL for user-specific changes');
            $table->jsonb('before_state')->nullable()
                ->comment('JSON snapshot of the record state before the change; NULL for creation events');
            $table->jsonb('after_state')->nullable()
                ->comment('JSON snapshot of the record state after the change; NULL for deletion events');
            $table->string('ip_address', 45)->nullable()
                ->comment('IPv4 or IPv6 address of the client that triggered the change');
            $table->text('notes')->nullable()
                ->comment('Optional free-form justification or ticket reference for the change');
            $table->timestampTz('created_at')->useCurrent()
                ->comment('Timestamp when the audit event was recorded');

            $table->index(['performed_by', 'created_at'], 'idx_authserver_audit_by');
            $table->index(['target_userid', 'created_at'], 'idx_authserver_audit_user');
            $table->index(['action_type', 'created_at'], 'idx_authserver_audit_type');
            $table->index(['created_at'], 'idx_authserver_audit_time');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $caps   = $schema->getCapabilities();
        $tableName = $caps->isPostgreSQL() ? 'authserver.audit_log' : 'authserver_audit_log';
        $schema->dropTableIfExists($tableName);
    }
}
