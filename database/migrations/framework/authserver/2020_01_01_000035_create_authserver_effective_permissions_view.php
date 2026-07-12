<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver.effective_permissions view.
 *
 * Aggregates all active, non-expired rows in authserver.permissions and resolves
 * the effective grant (allow | deny) for each (subject_type, subject_id,
 * object_type, object_id, action) combination using deny-takes-priority logic:
 *
 *   - If any deny row has a higher priority than all allow rows → deny
 *   - If at least one allow row exists and no deny dominates → allow
 *   - Otherwise → deny (implicit default-deny)
 *
 * On PostgreSQL/TimescaleDB: created as a regular VIEW in the authserver schema.
 * On MySQL: named authserver_effective_permissions (schema-as-prefix convention
 * handled by quoteTable/SchemaBuilder).
 *
 * Used by check_permission_with_inheritance() (PostgreSQL) and application-level
 * permission checks on all backends.
 *
 */
class CreateAuthserverEffectivePermissionsView extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 70;
    public array   $dependencies = ['create_authserver_permission_inheritance_table'];
    public $description  = 'Creates the authserver.effective_permissions deny-takes-priority view';

    public function up(): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();
        $caps   = $schema->getCapabilities();

        $viewRef  = $schema->quoteTable('authserver.effective_permissions');
        $permsRef = $schema->quoteTable('authserver.permissions');

        if ($caps->isPostgreSQL()) {
            $db->query(
                "CREATE OR REPLACE VIEW {$viewRef} AS
                 SELECT
                     subject_type,
                     subject_id,
                     object_type,
                     object_id,
                     action,
                     CASE
                         WHEN MAX(CASE WHEN grant_type = 'deny'  THEN priority END)
                              > COALESCE(MAX(CASE WHEN grant_type = 'allow' THEN priority END), 0)
                             THEN 'deny'
                         WHEN MAX(CASE WHEN grant_type = 'allow' THEN 1 END) = 1
                             THEN 'allow'
                         ELSE 'deny'
                     END AS effective_grant
                 FROM {$permsRef}
                 WHERE is_active = TRUE
                   AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                 GROUP BY subject_type, subject_id, object_type, object_id, action"
            );
        } else {
            $db->query(
                "CREATE OR REPLACE VIEW {$viewRef} AS
                 SELECT
                     subject_type,
                     subject_id,
                     object_type,
                     object_id,
                     action,
                     CASE
                         WHEN MAX(CASE WHEN grant_type = 'deny'  THEN priority END)
                              > COALESCE(MAX(CASE WHEN grant_type = 'allow' THEN priority END), 0)
                             THEN 'deny'
                         WHEN MAX(CASE WHEN grant_type = 'allow' THEN 1 END) = 1
                             THEN 'allow'
                         ELSE 'deny'
                     END AS effective_grant
                 FROM {$permsRef}
                 WHERE is_active = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                 GROUP BY subject_type, subject_id, object_type, object_id, action"
            );
        }
    }

    public function down(): void
    {
        $this->application->database->schema()->dropView('authserver.effective_permissions');
    }
}
