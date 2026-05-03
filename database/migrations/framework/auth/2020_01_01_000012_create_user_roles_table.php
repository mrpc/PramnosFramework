<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the user_roles pivot table.
 *
 * @package PramnosFramework
 */
class CreateUserRolesTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 30;
    public array   $dependencies = ['create_users_table', 'create_roles_table'];
    public string  $description  = 'Creates the user_roles pivot table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('user_roles')) {
            return;
        }

        $schema->createTable('user_roles', function ($table) {
            $table->increments('id');
            $table->integer('userid')->unsigned();
            $table->integer('roleid')->unsigned();
            $table->timestamp('assigned_at')->useCurrent();
            $table->unique(['userid', 'roleid'], 'uq_user_role');
            $table->foreign('userid')->references('userid')->on('users')->onDelete('CASCADE');
            $table->foreign('roleid')->references('roleid')->on('roles')->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('user_roles');
    }
}
