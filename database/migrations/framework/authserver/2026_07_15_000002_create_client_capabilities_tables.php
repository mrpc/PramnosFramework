<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the client capabilities / manifest tables.
 *
 * Resource servers declare what they expose — Resources, the Scopes (action
 * vocabulary) available on each Resource, and the ABAC Condition keys they
 * support (e.g. `location_id`) — by pushing a JSON manifest to the auth server
 * (feature 2, "Capabilities Manifest & CI/CD Push"). These tables are the
 * server-side registry that manifest sync (CapabilitiesSyncService) writes to.
 *
 * Four tables (all in the authserver schema; on MySQL the `authserver_` prefix):
 *   - client_resources             — one row per declared resource per client
 *   - client_resource_scopes       — the action-vocabulary per resource (D3)
 *   - client_supported_conditions  — declared ABAC condition keys per client
 *   - client_manifest              — last-synced MD5 hash per client (short-circuit)
 *
 * Non-breaking (framework rule §8, DB-safety §3): additive only, each table
 * guarded by hasTable(); NO hard foreign keys (referential integrity is enforced
 * at the app layer — the applications table lives in a different schema in some
 * installations). Soft delete via is_active: a resource/scope/condition removed
 * from a later manifest is flagged is_active = false, never hard-deleted, so it
 * never cascades away existing user policies.
 *
 * Current-date timestamp (§9) so it auto-runs on installations whose
 * migration_cutoff skips the 2020_01_01 baseline.
 */
class CreateClientCapabilitiesTables extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 61;
    public array  $dependencies = ['create_authserver_schema', 'create_applications_table'];
    public $description  = 'Creates client capabilities/manifest tables (resources, scopes, conditions, manifest hash)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('authserver.client_resources')) {
            $schema->createTable('authserver.client_resources', function ($table) {
                $table->comment('Resources a client (resource server) declares via its capabilities manifest');

                $table->bigIncrements('id')->comment('Auto-increment primary key');
                $table->integer('applicationid')
                    ->comment('Client application (applications.appid) that declared this resource — no hard FK');
                $table->string('resource_name', 100)
                    ->comment('Resource identifier, e.g. "consumptions", "reports"');
                $table->text('description')->nullable()
                    ->comment('Optional human-readable description from the manifest');
                $table->boolean('is_active')->default(true)
                    ->comment('Soft-delete flag: false = removed from a later manifest, kept for existing policies');
                $table->timestamp('created_at')->nullable()
                    ->comment('When the resource was first synced');
                $table->timestamp('updated_at')->nullable()
                    ->comment('When the resource was last synced');

                $table->unique(['applicationid', 'resource_name'], 'uq_client_resources_app_name');
                $table->index(['applicationid'], 'idx_client_resources_app');
            });
        }

        if (!$schema->hasTable('authserver.client_resource_scopes')) {
            $schema->createTable('authserver.client_resource_scopes', function ($table) {
                $table->comment('Scope (action) vocabulary available on a declared resource (D3: populates permissions.action)');

                $table->bigIncrements('id')->comment('Auto-increment primary key');
                $table->bigInteger('resource_id')
                    ->comment('Owning client_resources.id — no hard FK (app-layer integrity)');
                $table->string('scope_name', 100)
                    ->comment('Action verb valid for the resource, e.g. "read", "export", "delete"');
                $table->text('description')->nullable()
                    ->comment('Optional human-readable description from the manifest');
                $table->boolean('is_active')->default(true)
                    ->comment('Soft-delete flag: false = removed from a later manifest');

                $table->unique(['resource_id', 'scope_name'], 'uq_client_resource_scopes_res_name');
                $table->index(['resource_id'], 'idx_client_resource_scopes_res');
            });
        }

        if (!$schema->hasTable('authserver.client_supported_conditions')) {
            $schema->createTable('authserver.client_supported_conditions', function ($table) {
                $table->comment('ABAC condition keys a client declares it can evaluate (e.g. location_id)');

                $table->bigIncrements('id')->comment('Auto-increment primary key');
                $table->integer('applicationid')
                    ->comment('Client application (applications.appid) — no hard FK');
                $table->string('condition_key', 100)
                    ->comment('Condition attribute name, e.g. "location_id"');
                $table->string('value_type', 20)->default('string')
                    ->comment('Expected value type: string | int | list');
                $table->text('description')->nullable()
                    ->comment('Optional human-readable description from the manifest');
                $table->boolean('is_active')->default(true)
                    ->comment('Soft-delete flag: false = removed from a later manifest');

                $table->unique(['applicationid', 'condition_key'], 'uq_client_conditions_app_key');
                $table->index(['applicationid'], 'idx_client_conditions_app');
            });
        }

        if (!$schema->hasTable('authserver.client_manifest')) {
            $schema->createTable('authserver.client_manifest', function ($table) {
                $table->comment('Last-synced capabilities manifest hash per client (MD5 short-circuit)');

                $table->bigIncrements('id')->comment('Auto-increment primary key');
                $table->integer('applicationid')
                    ->comment('Client application (applications.appid) — one manifest per client');
                $table->string('manifest_hash', 32)
                    ->comment('MD5 hex of the last synced manifest body; unchanged hash short-circuits sync');
                $table->timestamp('synced_at')->nullable()
                    ->comment('When the manifest was last synced');
                $table->bigInteger('synced_by')->nullable()
                    ->comment('userid (or system account) that pushed the manifest');

                $table->unique(['applicationid'], 'uq_client_manifest_app');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('authserver.client_manifest');
        $schema->dropTableIfExists('authserver.client_supported_conditions');
        $schema->dropTableIfExists('authserver.client_resource_scopes');
        $schema->dropTableIfExists('authserver.client_resources');
    }
}
