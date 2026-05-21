<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver.audit_log table — generic event audit trail.
 *
 * Each row records one auditable event with a polymorphic actor/target/object
 * model. The actor (who did it) and target/object (what was affected) are
 * stored as type+id string pairs so that the same table handles RBAC events,
 * OAuth actions, consent changes, and application-level events without schema
 * changes. ip_address and other request metadata go in the metadata JSONB field.
 *
 * organization_context links an event to a specific organisation (the framework
 * equivalent of Urbanwater's deya_context). NULL for cross-organisation events.
 *
 * This is a regular table (not a TimescaleDB hypertable). For high-volume
 * time-series logging, prefer user_activity_log or tokenactions.
 *
 * @package PramnosFramework
 */
class CreateAuthserverAuditLogTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_authserver_permissions_table', 'create_organizations_table'];
    public $description  = 'Creates the authserver.audit_log generic event audit table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.audit_log')) {
            return;
        }

        $schema->createTable('authserver.audit_log', function ($table) {
            $table->comment('Generic event audit trail — polymorphic actor/target/object model for RBAC, OAuth, and application events');

            $table->increments('auditid')
                ->comment('Auto-increment event identifier');
            $table->string('event_type', 50)
                ->comment('Auditable event type (e.g. grant_permission, revoke_permission, assign_role, token_issued, consent_granted)');
            $table->bigInteger('actor_userid')->nullable()
                ->comment('FK to users.userid — who triggered the event; NULL for system-initiated events');
            $table->string('actor_type', 20)->nullable()->default('user')
                ->comment('Type of actor: user | system | service | oauth_client');
            $table->string('target_type', 50)->nullable()
                ->comment('Type of primary entity affected (e.g. user, role, permission, application, token)');
            $table->string('target_id', 100)->nullable()
                ->comment('String identifier of the primary affected entity; matches target_type (e.g. userid, roleid, appid)');
            $table->string('object_type', 50)->nullable()
                ->comment('Type of secondary object involved in the event (e.g. scope, grant_type, consent_type)');
            $table->string('object_id', 100)->nullable()
                ->comment('String identifier of the secondary object; matches object_type');
            $table->jsonb('old_values')->nullable()
                ->comment('JSON snapshot of the record state before the change; NULL for creation events');
            $table->jsonb('new_values')->nullable()
                ->comment('JSON snapshot of the record state after the change; NULL for deletion events');
            $table->jsonb('metadata')->nullable()
                ->comment('Additional context: ip_address, user_agent, request_id, channel, notes, etc.');
            $table->timestampTz('event_timestamp')->useCurrent()
                ->comment('Timestamp when the event occurred');
            $table->integer('organization_context')->nullable()
                ->comment('FK to organizations.organization_id — limits event scope to a specific organisation; NULL for global events');

            $table->index(['actor_userid'],               'idx_audit_actor');
            $table->index(['event_type'],                 'idx_audit_event_type');
            $table->index(['target_type', 'target_id'],   'idx_audit_target');
            $table->index(['event_timestamp'],             'idx_audit_timestamp');
            $table->index(['organization_context'],        'idx_audit_organization');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.audit_log');
    }
}
