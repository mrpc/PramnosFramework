<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the users table.
 *
 * @package PramnosFramework
 */
class CreateUsersTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 20;
    public array   $dependencies = ['create_roles_table'];
    public string  $description  = 'Creates the users table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('users')) {
            return;
        }

        $schema->createTable('users', function ($table) {
            $table->increments('userid');
            $table->string('username', 100)->unique();
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->string('firstname', 100)->default('');
            $table->string('lastname', 100)->default('');
            $table->boolean('active')->default(true);
            $table->timestamp('last_login')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('users');
    }
}
