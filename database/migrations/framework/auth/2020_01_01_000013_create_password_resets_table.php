<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the password_resets table.
 *
 * @package PramnosFramework
 */
class CreatePasswordResetsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public array   $dependencies = ['create_users_table'];
    public string  $description  = 'Creates the password_resets table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('password_resets')) {
            return;
        }

        $schema->createTable('password_resets', function ($table) {
            $table->increments('id');
            $table->string('email', 255);
            $table->string('token', 255);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->index(['email'], 'idx_password_resets_email');
            $table->index(['token'], 'idx_password_resets_token');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('password_resets');
    }
}
