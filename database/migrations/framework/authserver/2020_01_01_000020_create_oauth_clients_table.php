<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the oauth_clients table.
 *
 * @package PramnosFramework
 */
class CreateOAuthClientsTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public string  $description  = 'Creates the oauth_clients table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('oauth_clients')) {
            return;
        }

        $schema->createTable('oauth_clients', function ($table) {
            $table->increments('id');
            $table->string('client_id', 100)->unique();
            $table->string('client_secret', 255)->nullable();
            $table->string('name', 255);
            $table->text('redirect_uri')->nullable();
            $table->text('allowed_scopes')->nullable();
            $table->boolean('confidential')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('oauth_clients');
    }
}
