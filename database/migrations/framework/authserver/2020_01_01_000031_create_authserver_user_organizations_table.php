<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Application\Settings;
use Pramnos\Database\Migration;

/**
 * Creates the authserver user_organizations table — user membership in organisations.
 *
 * A user must be a member of an organisation before they can be assigned any
 * organisation-scoped role (roles where organization_id IS NOT NULL). This mirrors
 * the GitHub organisation membership model: join first, then receive roles.
 *
 * The table name and organisation column name are configurable via Settings so
 * that applications can use domain-specific naming:
 *   - authserver_organization_table  (default: user_organizations)
 *   - authserver_organization_column (default: organization_id)
 *
 * UrbanWater example override in settings.php:
 *   'authserver_organization_table'  => 'user_deyas',
 *   'authserver_organization_column' => 'deyaid',
 *
 * The organisation ID column is a bare integer without a framework-level FK —
 * the framework does not assume a particular organisations table. Applications
 * that have an organisations table may add the FK in an app-level migration.
 *
 * @package PramnosFramework
 */
class CreateAuthserverUserOrganizationsTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 45;
    public array   $dependencies = ['create_authserver_user_roles_table'];
    public $description  = 'Creates the authserver user_organizations organisation membership table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        $orgTable  = Settings::getSetting('authserver_organization_table', 'user_organizations');
        $orgColumn = Settings::getSetting('authserver_organization_column', 'organization_id');

        $qualifiedTable = 'authserver.' . $orgTable;

        if ($schema->hasTable($qualifiedTable)) {
            return;
        }

        $schema->createTable($qualifiedTable, function ($table) use ($orgColumn) {
            $table->comment('User membership in organisations — required before assigning organisation-scoped roles');

            $table->bigInteger('userid')
                ->comment('FK to users.userid — the user who belongs to the organisation');
            $table->integer($orgColumn)
                ->comment('Organisation identifier — no framework FK; apps may add one in their own migrations');
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
    }

    public function down(): void
    {
        $orgTable = Settings::getSetting('authserver_organization_table', 'user_organizations');
        $this->application->database->schema()->dropTableIfExists('authserver.' . $orgTable);
    }
}
