<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the oauth_scopes table.
 *
 * @package PramnosFramework
 */
class CreateOAuthScopesTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 20;
    public string  $description  = 'Creates the oauth_scopes table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('oauth_scopes')) {
            return;
        }

        $schema->createTable('oauth_scopes', function ($table) {
            $table->increments('id');
            $table->string('scope', 100)->unique();
            $table->string('description', 255)->default('');
            $table->boolean('is_default')->default(false);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('oauth_scopes');
    }
}
