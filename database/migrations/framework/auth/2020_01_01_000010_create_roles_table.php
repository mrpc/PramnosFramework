<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the roles table.
 *
 * @package PramnosFramework
 */
class CreateRolesTable extends Migration
{
    public string $feature     = 'auth';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public string $description = 'Creates the roles table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('roles')) {
            return;
        }

        $schema->createTable('roles', function ($table) {
            $table->increments('roleid');
            $table->string('name', 100)->unique();
            $table->string('label', 255)->default('');
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('roles');
    }
}
