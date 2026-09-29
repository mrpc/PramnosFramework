<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Application\Settings;
use Pramnos\Database\Migration;

/**
 * One deny-versus-allow rule on every database, and an organisation trigger that lets a role go.
 *
 * ## The rule
 *
 * For one subject, object and action, the higher priority wins, and a **deny wins a tie**. The
 * `effective_permissions` view and `PermissionResolver` both apply it, so the stored priority is
 * what an administrator typed, on MySQL and on PostgreSQL alike.
 *
 * Before this, PostgreSQL had a trigger that added 1000 to a deny's priority on every INSERT
 * *and every UPDATE*, and MySQL had nothing: the same rows decided differently depending on the
 * database, and a deny row grew by 1000 each time anything on it was edited. The trigger is
 * dropped, and the PostgreSQL deny rows it inflated are brought back with `priority % 1000` —
 * exact for any priority typed below 1000, which is every value the bundled screens offer.
 *
 * ## The organisation trigger
 *
 * `check_user_org_membership()` refused any write to a `user_roles` row whose user is not a
 * member of the role's organisation — including the UPDATE that deactivates it, so the role of
 * someone who had already left could not be taken away. A row being made inactive grants
 * nothing and is let through.
 */
class UnifyAuthserverDenyRule extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 76;
    public array  $dependencies = [
        'create_authserver_effective_permissions_view',
        'create_authserver_rbac_functions',
    ];
    public $description = 'Deny wins a priority tie on every database; drops the +1000 trigger; org trigger allows deactivation';

    public function up(): void
    {
        $this->createView('>=');

        if (!$this->application->database->schema()->getCapabilities()->isPostgreSQL()) {
            return;
        }

        $db = $this->application->database;
        $db->query('DROP TRIGGER IF EXISTS trigger_set_permission_priority ON authserver.permissions');
        $db->query('DROP FUNCTION IF EXISTS authserver.set_permission_priority()');

        // An arithmetic UPDATE on PostgreSQL rows only the dropped trigger could have inflated.
        $db->query(
            "UPDATE authserver.permissions SET priority = priority % 1000
             WHERE grant_type = 'deny' AND priority >= 1000"
        );

        $this->createMembershipTrigger(true);
    }

    public function down(): void
    {
        $this->createView('>');

        if (!$this->application->database->schema()->getCapabilities()->isPostgreSQL()) {
            return;
        }

        $db = $this->application->database;
        $db->query(
            "CREATE OR REPLACE FUNCTION authserver.set_permission_priority()
             RETURNS TRIGGER AS \$\$
             BEGIN
                 IF NEW.grant_type = 'deny' THEN
                     NEW.priority = COALESCE(NEW.priority, 0) + 1000;
                 END IF;
                 RETURN NEW;
             END;
             \$\$ LANGUAGE plpgsql"
        );
        $db->query('DROP TRIGGER IF EXISTS trigger_set_permission_priority ON authserver.permissions');
        $db->query(
            'CREATE TRIGGER trigger_set_permission_priority
                 BEFORE INSERT OR UPDATE ON authserver.permissions
                 FOR EACH ROW
                 EXECUTE FUNCTION authserver.set_permission_priority()'
        );

        $this->createMembershipTrigger(false);
    }

    /** The view, with `>=` (deny wins a tie) or the older `>`. DDL: stays raw. */
    private function createView(string $comparison): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();
        $active = $schema->getCapabilities()->isPostgreSQL() ? 'TRUE' : '1';
        $now    = $schema->getCapabilities()->isPostgreSQL() ? 'CURRENT_TIMESTAMP' : 'NOW()';

        $db->query(
            'CREATE OR REPLACE VIEW ' . $schema->quoteTable('authserver.effective_permissions') . " AS
             SELECT
                 subject_type,
                 subject_id,
                 object_type,
                 object_id,
                 action,
                 CASE
                     WHEN MAX(CASE WHEN grant_type = 'deny'  THEN priority END)
                          {$comparison} COALESCE(MAX(CASE WHEN grant_type = 'allow' THEN priority END), 0)
                         THEN 'deny'
                     WHEN MAX(CASE WHEN grant_type = 'allow' THEN 1 END) = 1
                         THEN 'allow'
                     ELSE 'deny'
                 END AS effective_grant
             FROM " . $schema->quoteTable('authserver.permissions') . "
             WHERE is_active = {$active}
               AND (expires_at IS NULL OR expires_at > {$now})
             GROUP BY subject_type, subject_id, object_type, object_id, action"
        );
    }

    /** PL/pgSQL: stays raw. `$letInactiveThrough` is the fix; false restores the older body. */
    private function createMembershipTrigger(bool $letInactiveThrough): void
    {
        $orgTable  = Settings::getSetting('authserver_organization_table', 'user_organizations');
        $orgColumn = Settings::getSetting('authserver_organization_column', 'organization_id');
        $skip      = $letInactiveThrough
            ? 'IF NEW.is_active IS FALSE THEN RETURN NEW; END IF;'
            : '';

        $this->application->database->query(
            "CREATE OR REPLACE FUNCTION authserver.check_user_org_membership()
             RETURNS TRIGGER AS \$\$
             DECLARE
                 role_org_id INTEGER;
             BEGIN
                 {$skip}

                 SELECT {$orgColumn} INTO role_org_id
                 FROM authserver.roles
                 WHERE roleid = NEW.roleid;

                 IF role_org_id IS NOT NULL THEN
                     IF NOT EXISTS (
                         SELECT 1 FROM authserver.{$orgTable}
                         WHERE userid = NEW.userid
                           AND {$orgColumn} = role_org_id
                           AND is_active = TRUE
                           AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                     ) THEN
                         RAISE EXCEPTION
                             'User % cannot be assigned role % — not a member of organisation %',
                             NEW.userid, NEW.roleid, role_org_id;
                     END IF;
                 END IF;

                 RETURN NEW;
             END;
             \$\$ LANGUAGE plpgsql"
        );
    }
}
