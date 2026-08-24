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
 * organization_context links an event to a specific organisation.
 * NULL for cross-organisation events.
 *
 * On TimescaleDB this is a hypertable partitioned by event_timestamp, with
 * compression after 90 days and **no retention policy**: an audit trail is the one
 * table where dropping old rows on the framework's initiative would be the wrong
 * default. An installation that wants one adds it deliberately.
 *
 * ## Existing installations are not converted
 *
 * The `hasTable()` guard below returns before anything here runs, so a database that
 * already has this table keeps exactly what it has — a plain table, an `integer`
 * `auditid`, whatever columns it was created with. That is deliberate rather than
 * incidental: converting a live audit table means dropping and rebuilding its primary
 * key and rewriting every row into chunks, under lock, on a table other things hold
 * foreign keys into.
 *
 * For the same reason this table is **not** declared in {@see \Pramnos\Database\HypertableRegistry}.
 * A declaration there is what `timescale:ensure` reads, and it would convert exactly
 * the installations this guard exists to leave alone. The cost is that `timescale:ensure`
 * will not report drift on this table; the alternative was rewriting somebody's audit
 * log because they ran a maintenance command.
 *
 */
class CreateAuthserverAuditLogTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_authserver_permissions_table', 'create_organizations_table'];
    public $description  = 'Creates the authserver.audit_log generic event audit table (hypertable + compression on TimescaleDB)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.audit_log')) {
            return;
        }

        $schema->createTable('authserver.audit_log', function ($table) {
            $table->comment('Generic event audit trail — polymorphic actor/target/object model for RBAC, OAuth, and application events');

            $table->bigIncrements('auditid')
                ->comment('Auto-increment event identifier (part of composite PK with event_timestamp for TimescaleDB compatibility)');
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

            // Composite PK: required for TimescaleDB hypertables (the partition key must be
            // part of every unique constraint). On MySQL and plain PostgreSQL it is simply
            // a composite primary key — functionally correct either way, and the same
            // shape tokenactions has used since it was written.
            $table->primary(['auditid', 'event_timestamp']);

            $table->index(['actor_userid'],               'idx_audit_actor');
            $table->index(['event_type'],                 'idx_audit_event_type');
            $table->index(['target_type', 'target_id'],   'idx_audit_target');
            $table->index(['event_timestamp'],             'idx_audit_timestamp');
            $table->index(['organization_context'],        'idx_audit_organization');
        });

        // Only reached on a database that did not already have the table — see the
        // class docblock. Each of these is a documented no-op without TimescaleDB, so
        // MySQL and plain PostgreSQL get the plain table above and nothing else.
        $schema->createHypertable('authserver.audit_log', 'event_timestamp', [
            'chunk_time_interval' => '7 days',
            'if_not_exists'       => true,
        ]);

        // segmentby on event_type: a handful of distinct values, and the column every
        // "what happened to X" query filters on, so a batch that cannot match is skipped
        // without being decompressed. Putting the high-cardinality target_id here instead
        // would produce one segment per entity and compress almost nothing.
        $schema->enableCompression('authserver.audit_log', [
            'segmentby' => 'event_type',
            'orderby'   => 'event_timestamp DESC',
        ]);

        // 90 days: an audit trail is read recently and kept for ever, so compression is
        // the only thing that makes "for ever" affordable. No retention policy follows
        // it, and that omission is the point.
        $schema->addCompressionPolicy('authserver.audit_log', '90 days');
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.audit_log');
    }
}
