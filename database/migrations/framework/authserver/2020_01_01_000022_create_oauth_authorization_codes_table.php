<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the oauth_authorization_codes table.
 *
 * @package PramnosFramework
 */
class CreateOAuthAuthorizationCodesTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 30;
    public array   $dependencies = ['create_oauth_clients_table'];
    public string  $description  = 'Creates the oauth_authorization_codes table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('oauth_authorization_codes')) {
            return;
        }

        $schema->createTable('oauth_authorization_codes', function ($table) {
            $table->increments('id');
            $table->string('code', 255)->unique();
            $table->string('client_id', 100);
            $table->integer('user_id')->unsigned()->nullable();
            $table->text('redirect_uri')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id'], 'idx_oauth_codes_client');
            $table->index(['expires_at'], 'idx_oauth_codes_expires');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('oauth_authorization_codes');
    }
}
