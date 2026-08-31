<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Auth\Role;
use Pramnos\Database\Migration;

/**
 * Creates the authserver user_organizations table — user membership in organisations.
 *
 * Membership is **recorded here and enforced nowhere**. Nothing in the framework
 * checks it: not a foreign key, not {@see \Pramnos\Auth\PermissionResolver}, which
 * has no organisation dimension and returns every active role a user holds whatever
 * organisation that role names. A role scoped to organisation A therefore grants its
 * permissions everywhere.
 *
 * This docblock used to describe the GitHub model — join the organisation first, then
 * receive its roles — as though the framework implemented it. It does not, and
 * building a multi-tenant application on the assumption that it does produces exactly
 * the isolation failure the assumption was meant to prevent.
 *
 * What the table is good for: a place to record membership that an application's own
 * checks can read — an ABAC condition evaluated against the request context, or a
 * global scope on the models
 * ({@see \Pramnos\Application\Orm\Concerns\HasScopes}).
 *
 * The table name and organisation column name are configurable via Settings so
 * that applications can use domain-specific naming:
 *   - authserver_organization_table  (default: user_organizations)
 *   - authserver_organization_column (default: organization_id)
 *
 * the reference application example override in settings.php:
 *   'authserver_organization_table'  => 'user_deyas',
 *   'authserver_organization_column' => 'deyaid',
 *
 * When using the framework defaults (user_organizations / organization_id), a FK
 * to the public.organizations table is added automatically. When using Settings
 * overrides, the FK target is the application's own organisations table, which
 * should be added in an app-level migration.
 *
 */
class CreateAuthserverUserOrganizationsTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 45;
    public array   $dependencies = [
        'create_authserver_user_roles_table',
        'create_organizations_table',
    ];
    public $description  = 'Creates the authserver user_organizations organisation membership table';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $db     = $this->application->database;
        $caps   = $db->schema()->getCapabilities();

        /*
         * The name comes from `Role`, not from the setting directly.
         *
         * Both read `authserver_organization_table`, and they read it with **different
         * defaults** — `''` there, `'user_organizations'` here — so an installation holding the
         * setting as an empty string got `authserver.user_organizations` from the model and
         * `authserver.` from this migration. One reader, and the two cannot drift.
         */
        $qualifiedTable = Role::membershipTable();
        $orgColumn      = Role::organizationColumn();

        if ($schema->hasTable($qualifiedTable)) {
            return;
        }

        $schema->createTable($qualifiedTable, function ($table) use ($orgColumn) {
            $table->comment('User membership in organisations — required before assigning organisation-scoped roles');

            $table->bigInteger('userid')
                ->comment('FK to users.userid — the user who belongs to the organisation');
            $table->integer($orgColumn)
                ->comment('Organisation identifier — FK to organizations.organization_id (or app-specific override)');
            $table->bigInteger('granted_by')->nullable()
                ->comment('FK to users.userid of the administrator who added this user to the organisation');
            $table->timestamp('granted_at')->useCurrent()
                ->comment('Timestamp when the membership was granted');
            $table->timestamp('expires_at')->nullable()
                ->comment('Optional membership expiry; NULL = permanent membership');
            $table->boolean('is_active')->default(true)
                ->comment('Soft-delete flag — inactive memberships are excluded from role-assignment checks');

            $table->primary(['userid', $orgColumn]);

            $table->index(['userid', 'is_active'], 'idx_authserver_ud_userid');
            $table->index([$orgColumn, 'is_active'], 'idx_authserver_ud_org');
        });

        // Add FK to organizations table when using framework defaults.
        // When using Settings overrides (e.g. the reference application: user_deyas/deyaid),
        // the FK target is the app's own organisations table — add it in an app migration.
        if ($qualifiedTable === 'authserver.user_organizations'
            && $orgColumn === 'organization_id'
        ) {
            if ($caps->isPostgreSQL()) {
                $db->query(
                    "ALTER TABLE authserver.user_organizations
                     ADD CONSTRAINT fk_user_org_organization_id
                     FOREIGN KEY (organization_id)
                     REFERENCES public.organizations(organization_id)
                     ON DELETE RESTRICT"
                );
            } else {
                $db->query(
                    "ALTER TABLE `authserver_user_organizations`
                     ADD CONSTRAINT `fk_user_org_organization_id`
                     FOREIGN KEY (`organization_id`)
                     REFERENCES `organizations`(`organization_id`)
                     ON DELETE RESTRICT"
                );
            }
        }
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists(Role::membershipTable());
    }
}
