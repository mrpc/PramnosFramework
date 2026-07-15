<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Adds the audience (`app_id`) and ABAC (`conditions`) dimensions to the
 * authserver.permissions table (feature 4, Hybrid RBAC + ABAC).
 *
 *   - `app_id`     — the application (audience) a permission applies WITHIN.
 *                    NULL = global (applies to every app), which is exactly the
 *                    grain the table had before this migration, so every existing
 *                    row keeps its current meaning.
 *   - `conditions` — JSON ABAC predicate (e.g. {"location_id":[1,2]}) evaluated
 *                    at runtime by the consuming app. NULL = unconditional.
 *
 * Strictly additive & non-breaking (DB-safety §7):
 *   - both columns are NULLABLE (existing rows become NULL = today's behaviour);
 *   - NO hard foreign key on app_id (app-layer referential integrity; the
 *     applications table lives in a different schema on some installations);
 *   - the existing unique constraint (uq_authserver_perms_grant) and the
 *     effective_permissions view are left UNTOUCHED — app_id-scoped uniqueness
 *     and view/resolver work belong to the consuming layer (phase 4), where the
 *     MySQL-vs-PostgreSQL NULL semantics can be handled explicitly;
 *   - only a NEW non-unique lookup index is added.
 *
 * Current-date timestamp (§9) so it auto-runs on installations whose
 * migration_cutoff skips the 2020_01_01 baseline.
 */
class AddAudienceAndConditionsToPermissions extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 62;
    public array  $dependencies = ['create_authserver_permissions_table'];
    public $description  = 'Adds app_id (audience) and conditions (ABAC) columns to authserver.permissions';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasColumn('authserver.permissions', 'app_id')) {
            $schema->alterTable('authserver.permissions', function ($table) {
                $table->bigInteger('app_id')->nullable()
                    ->comment('Audience: applications.appid this permission applies within; NULL = global (all apps). No hard FK.');
            });
            // Separate index-only alter so the new column is guaranteed to exist first.
            $schema->alterTable('authserver.permissions', function ($table) {
                $table->index(
                    ['subject_type', 'subject_id', 'app_id', 'object_type', 'action'],
                    'idx_authserver_perms_audience'
                );
            });
        }

        if (!$schema->hasColumn('authserver.permissions', 'conditions')) {
            $schema->alterTable('authserver.permissions', function ($table) {
                $table->json('conditions')->nullable()
                    ->comment('ABAC predicate as JSON (e.g. {"location_id":[1,2]}); evaluated at runtime; NULL = unconditional');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('authserver.permissions', 'app_id')) {
            $schema->alterTable('authserver.permissions', function ($table) {
                $table->dropIndex('idx_authserver_perms_audience');
            });
            $schema->alterTable('authserver.permissions', function ($table) {
                $table->dropColumn('app_id');
            });
        }

        if ($schema->hasColumn('authserver.permissions', 'conditions')) {
            $schema->alterTable('authserver.permissions', function ($table) {
                $table->dropColumn('conditions');
            });
        }
    }
}
