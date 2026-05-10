<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Application\Settings;
use Pramnos\Database\Migration;

/**
 * Creates the 7 PL/pgSQL RBAC helper functions in the authserver schema.
 *
 * These functions are PostgreSQL-specific and are silently skipped on MySQL.
 * MySQL applications must implement equivalent logic in PHP.
 *
 * The organisation table name and column name are read from Settings at
 * migration time so that applications can use domain-specific naming:
 *   - authserver_organization_table  (default: user_organizations)
 *   - authserver_organization_column (default: organization_id)
 *
 * Functions created:
 *  1. set_permission_priority()     — trigger fn: deny entries get priority+1000
 *  2. check_user_deya_membership()  — trigger fn: org-scoped role assignment guard
 *  3. apply_permission_template()   — apply a permission template to a subject
 *  4. apply_role_template()         — create a role from a role template
 *  5. log_audit_event()             — helper to insert an audit_log row
 *  6. check_permission_with_inheritance() — traverse permission_inheritance graph
 *  7. get_user_effective_permissions()    — return TABLE of all permissions for a user
 *
 * Triggers:
 *  - trigger_set_permission_priority  (BEFORE INSERT OR UPDATE ON authserver.permissions)
 *  - trigger_check_user_deya_membership (BEFORE INSERT OR UPDATE ON authserver.user_roles)
 *
 * @package PramnosFramework
 */
class CreateAuthserverRbacFunctions extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 75;
    public array   $dependencies = [
        'create_authserver_effective_permissions_view',
        'create_authserver_user_roles_table',
        'create_authserver_user_organizations_table',
    ];
    public $description  = 'Creates 7 PL/pgSQL RBAC helper functions and 2 triggers (PostgreSQL only)';

    public function up(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if (!$caps->isPostgreSQL()) {
            return;
        }

        $db = $this->application->database;

        $orgTable  = Settings::getSetting('authserver_organization_table', 'user_organizations');
        $orgColumn = Settings::getSetting('authserver_organization_column', 'organization_id');

        // The placeholder used in object_id_pattern values, e.g. "{organization_id}"
        $orgPlaceholder = '{' . $orgColumn . '}';

        // 1. Auto-prioritise deny permissions (priority + 1000)
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

        $db->query(
            "DROP TRIGGER IF EXISTS trigger_set_permission_priority
             ON authserver.permissions"
        );
        $db->query(
            "CREATE TRIGGER trigger_set_permission_priority
                 BEFORE INSERT OR UPDATE ON authserver.permissions
                 FOR EACH ROW
                 EXECUTE FUNCTION authserver.set_permission_priority()"
        );

        // 2. Enforce organisation membership before assigning an organisation-scoped role
        $db->query(
            "CREATE OR REPLACE FUNCTION authserver.check_user_deya_membership()
             RETURNS TRIGGER AS \$\$
             DECLARE
                 role_org_id INTEGER;
             BEGIN
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

        $db->query(
            "DROP TRIGGER IF EXISTS trigger_check_user_deya_membership
             ON authserver.user_roles"
        );
        $db->query(
            "CREATE TRIGGER trigger_check_user_deya_membership
                 BEFORE INSERT OR UPDATE ON authserver.user_roles
                 FOR EACH ROW
                 EXECUTE FUNCTION authserver.check_user_deya_membership()"
        );

        // 3. Apply a single permission template to a subject
        $db->query(
            "CREATE OR REPLACE FUNCTION authserver.apply_permission_template(
                 p_templateid     INTEGER,
                 p_subject_type   VARCHAR(20),
                 p_subject_id     INTEGER,
                 p_context_org_id INTEGER DEFAULT NULL,
                 p_granted_by     INTEGER DEFAULT NULL
             ) RETURNS INTEGER AS \$\$
             DECLARE
                 tmpl            RECORD;
                 resolved_obj_id VARCHAR(100);
                 created_count   INTEGER := 0;
             BEGIN
                 SELECT * INTO tmpl
                 FROM authserver.permission_templates
                 WHERE templateid = p_templateid AND is_active = TRUE;

                 IF NOT FOUND THEN
                     RAISE EXCEPTION 'Permission template % not found or inactive', p_templateid;
                 END IF;

                 resolved_obj_id := tmpl.object_id_pattern;
                 IF p_context_org_id IS NOT NULL THEN
                     resolved_obj_id := REPLACE(resolved_obj_id, '{$orgPlaceholder}', p_context_org_id::TEXT);
                 END IF;

                 INSERT INTO authserver.permissions (
                     subject_type, subject_id, object_type, object_id,
                     action, grant_type, priority, granted_by, description
                 ) VALUES (
                     p_subject_type, p_subject_id, tmpl.object_type, resolved_obj_id,
                     tmpl.action, tmpl.grant_type, tmpl.priority, p_granted_by,
                     'Auto-generated from template: ' || tmpl.template_name
                 )
                 ON CONFLICT (subject_type, subject_id, object_type, object_id, action, grant_type)
                 DO NOTHING;

                 GET DIAGNOSTICS created_count = ROW_COUNT;
                 RETURN created_count;
             END;
             \$\$ LANGUAGE plpgsql"
        );

        // 4. Create a role from a role template (with all permission templates applied)
        $db->query(
            "CREATE OR REPLACE FUNCTION authserver.apply_role_template(
                 p_role_templateid INTEGER,
                 p_role_name       VARCHAR(100),
                 p_org_id          INTEGER DEFAULT NULL,
                 p_created_by      INTEGER DEFAULT NULL
             ) RETURNS INTEGER AS \$\$
             DECLARE
                 tmpl               RECORD;
                 new_roleid         INTEGER;
                 template_id_str    TEXT;
                 template_id        INTEGER;
                 permissions_created INTEGER := 0;
                 tmpl_ids           TEXT[];
             BEGIN
                 SELECT * INTO tmpl
                 FROM authserver.role_templates
                 WHERE role_templateid = p_role_templateid AND is_active = TRUE;

                 IF NOT FOUND THEN
                     RAISE EXCEPTION 'Role template % not found or inactive', p_role_templateid;
                 END IF;

                 INSERT INTO authserver.roles (role_name, description, {$orgColumn}, created_at)
                 VALUES (
                     p_role_name,
                     'Created from template: ' || tmpl.template_name,
                     p_org_id,
                     CURRENT_TIMESTAMP
                 )
                 RETURNING roleid INTO new_roleid;

                 -- permission_templateids is stored as JSON array in TEXT
                 IF tmpl.permission_templateids IS NOT NULL THEN
                     SELECT array_agg(val)
                     INTO tmpl_ids
                     FROM json_array_elements_text(tmpl.permission_templateids::json) AS val;

                     FOREACH template_id_str IN ARRAY tmpl_ids
                     LOOP
                         template_id := template_id_str::INTEGER;
                         permissions_created := permissions_created +
                             authserver.apply_permission_template(
                                 template_id, 'role', new_roleid, p_org_id, p_created_by
                             );
                     END LOOP;
                 END IF;

                 RETURN new_roleid;
             END;
             \$\$ LANGUAGE plpgsql"
        );

        // 5. Insert an audit_log row
        $db->query(
            "CREATE OR REPLACE FUNCTION authserver.log_audit_event(
                 p_action_type   VARCHAR(50),
                 p_performed_by  BIGINT,
                 p_target_userid BIGINT  DEFAULT NULL,
                 p_target_roleid INTEGER DEFAULT NULL,
                 p_before_state  TEXT    DEFAULT NULL,
                 p_after_state   TEXT    DEFAULT NULL,
                 p_ip_address    VARCHAR(45) DEFAULT NULL,
                 p_notes         TEXT    DEFAULT NULL
             ) RETURNS BIGINT AS \$\$
             DECLARE
                 new_logid BIGINT;
             BEGIN
                 INSERT INTO authserver.audit_log (
                     action_type, performed_by, target_userid, target_roleid,
                     before_state, after_state, ip_address, notes
                 ) VALUES (
                     p_action_type, p_performed_by, p_target_userid, p_target_roleid,
                     p_before_state::jsonb, p_after_state::jsonb, p_ip_address, p_notes
                 )
                 RETURNING logid INTO new_logid;

                 RETURN new_logid;
             END;
             \$\$ LANGUAGE plpgsql"
        );

        // 6. Check permission with object-hierarchy inheritance
        $db->query(
            "CREATE OR REPLACE FUNCTION authserver.check_permission_with_inheritance(
                 p_subject_type VARCHAR(20),
                 p_subject_id   INTEGER,
                 p_object_type  VARCHAR(50),
                 p_object_id    VARCHAR(100),
                 p_action       VARCHAR(20)
             ) RETURNS BOOLEAN AS \$\$
             DECLARE
                 direct_grant    TEXT;
                 inherited_grant TEXT;
                 parent          RECORD;
             BEGIN
                 -- Direct permission check
                 SELECT effective_grant INTO direct_grant
                 FROM authserver.effective_permissions
                 WHERE subject_type = p_subject_type
                   AND subject_id   = p_subject_id
                   AND object_type  = p_object_type
                   AND object_id    = p_object_id
                   AND action       = p_action;

                 IF direct_grant IS NOT NULL THEN
                     RETURN direct_grant = 'allow';
                 END IF;

                 -- Walk the inheritance graph
                 FOR parent IN
                     SELECT parent_object_type, parent_object_id, inheritance_type
                     FROM authserver.permission_inheritance
                     WHERE child_object_type = p_object_type
                       AND child_object_id   = p_object_id
                       AND is_active         = TRUE
                 LOOP
                     SELECT effective_grant INTO inherited_grant
                     FROM authserver.effective_permissions
                     WHERE subject_type = p_subject_type
                       AND subject_id   = p_subject_id
                       AND object_type  = parent.parent_object_type
                       AND object_id    = parent.parent_object_id
                       AND action       = CASE
                               WHEN parent.inheritance_type = 'read_only'
                                    AND p_action <> 'read' THEN 'read'
                               ELSE p_action
                           END;

                     IF inherited_grant = 'allow' THEN
                         -- read_only inheritance: only propagate 'read'
                         IF parent.inheritance_type = 'read_only' AND p_action <> 'read' THEN
                             CONTINUE;
                         END IF;
                         RETURN TRUE;
                     END IF;
                 END LOOP;

                 RETURN FALSE;
             END;
             \$\$ LANGUAGE plpgsql"
        );

        // 7. Return TABLE of all effective permissions for a user (direct + role-based)
        $db->query(
            "CREATE OR REPLACE FUNCTION authserver.get_user_effective_permissions(
                 p_userid  INTEGER,
                 p_org_id  INTEGER DEFAULT NULL
             ) RETURNS TABLE (
                 object_type       VARCHAR(50),
                 object_id         VARCHAR(100),
                 action            VARCHAR(20),
                 permission_source TEXT,
                 effective_grant   TEXT
             ) AS \$\$
             BEGIN
                 RETURN QUERY
                 WITH combined AS (
                     -- Direct user permissions
                     SELECT
                         ep.object_type, ep.object_id, ep.action,
                         'direct'::TEXT                    AS permission_source,
                         ep.effective_grant
                     FROM authserver.effective_permissions ep
                     WHERE ep.subject_type = 'user'
                       AND ep.subject_id   = p_userid

                     UNION ALL

                     -- Role-based permissions
                     SELECT
                         ep.object_type, ep.object_id, ep.action,
                         ('role:' || r.role_name)::TEXT    AS permission_source,
                         ep.effective_grant
                     FROM authserver.user_roles ur
                     JOIN authserver.roles r
                          ON r.roleid = ur.roleid
                     JOIN authserver.effective_permissions ep
                          ON ep.subject_type = 'role'
                         AND ep.subject_id   = r.roleid
                     WHERE ur.userid    = p_userid
                       AND ur.is_active = TRUE
                       AND (ur.expires_at IS NULL OR ur.expires_at > CURRENT_TIMESTAMP)
                       AND r.is_active  = TRUE
                       AND (p_org_id IS NULL OR r.{$orgColumn} IS NULL OR r.{$orgColumn} = p_org_id)
                 )
                 SELECT
                     c.object_type,
                     c.object_id,
                     c.action,
                     c.permission_source,
                     CASE
                         WHEN bool_or(c.effective_grant = 'deny')  THEN 'deny'
                         WHEN bool_or(c.effective_grant = 'allow') THEN 'allow'
                         ELSE 'deny'
                     END AS effective_grant
                 FROM combined c
                 GROUP BY c.object_type, c.object_id, c.action, c.permission_source;
             END;
             \$\$ LANGUAGE plpgsql"
        );
    }

    public function down(): void
    {
        $caps = $this->application->database->schema()->getCapabilities();

        if (!$caps->isPostgreSQL()) {
            return;
        }

        $db = $this->application->database;

        $db->query('DROP TRIGGER IF EXISTS trigger_check_user_deya_membership ON authserver.user_roles');
        $db->query('DROP TRIGGER IF EXISTS trigger_set_permission_priority ON authserver.permissions');

        $db->query('DROP FUNCTION IF EXISTS authserver.get_user_effective_permissions(INTEGER, INTEGER) CASCADE');
        $db->query('DROP FUNCTION IF EXISTS authserver.check_permission_with_inheritance(VARCHAR, INTEGER, VARCHAR, VARCHAR, VARCHAR) CASCADE');
        $db->query('DROP FUNCTION IF EXISTS authserver.log_audit_event(VARCHAR, BIGINT, BIGINT, INTEGER, TEXT, TEXT, VARCHAR, TEXT) CASCADE');
        $db->query('DROP FUNCTION IF EXISTS authserver.apply_role_template(INTEGER, VARCHAR, INTEGER, INTEGER) CASCADE');
        $db->query('DROP FUNCTION IF EXISTS authserver.apply_permission_template(INTEGER, VARCHAR, INTEGER, INTEGER, INTEGER) CASCADE');
        $db->query('DROP FUNCTION IF EXISTS authserver.check_user_deya_membership() CASCADE');
        $db->query('DROP FUNCTION IF EXISTS authserver.set_permission_priority() CASCADE');
    }
}
