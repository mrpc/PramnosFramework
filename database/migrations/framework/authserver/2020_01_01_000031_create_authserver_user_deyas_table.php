<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver user_deyas table — user membership in organisations (DEYAs).
 *
 * A user must be a member of an organisation before they can be assigned any
 * organisation-scoped role (roles where deyaid IS NOT NULL). This mirrors
 * the GitHub organisation membership model: join first, then receive roles.
 *
 * The deyaid column is a bare integer without a framework-level FK — the
 * framework does not assume a particular organisations table. Applications
 * that have a deya or organisations table may add the FK in an app migration.
 *
 * @package PramnosFramework
 */
class CreateAuthserverUserDeyasTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 45;
    public array   $dependencies = ['create_authserver_user_roles_table'];
    public $description  = 'Creates the authserver.user_deyas organisation membership table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.user_deyas')) {
            return;
        }

        $schema->createTable('authserver.user_deyas', function ($table) {
            $table->comment('User membership in organisations (DEYAs) — required before assigning organisation-scoped roles');

            $table->bigInteger('userid')
                ->comment('FK to users.userid — the user who belongs to the organisation');
            $table->integer('deyaid')
                ->comment('Organisation identifier — no framework FK; apps may add one in their own migrations');
            $table->bigInteger('granted_by')->nullable()
                ->comment('FK to users.userid of the administrator who added this user to the organisation');
            $table->timestamp('granted_at')->useCurrent()
                ->comment('Timestamp when the membership was granted');
            $table->timestamp('expires_at')->nullable()
                ->comment('Optional membership expiry; NULL = permanent membership');
            $table->boolean('is_active')->default(true)
                ->comment('Soft-delete flag — inactive memberships are excluded from role-assignment checks');

            $table->primary(['userid', 'deyaid']);

            $table->index(['userid', 'is_active'], 'idx_authserver_ud_userid');
            $table->index(['deyaid', 'is_active'], 'idx_authserver_ud_deyaid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.user_deyas');
    }
}
