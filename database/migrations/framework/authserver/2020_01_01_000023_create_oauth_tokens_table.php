<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the oauth_tokens table (access and refresh tokens).
 *
 * @package PramnosFramework
 */
class CreateOAuthTokensTable extends Migration
{
    public string  $feature      = 'authserver';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public array   $dependencies = ['create_oauth_clients_table'];
    public string  $description  = 'Creates the oauth_tokens table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('oauth_tokens')) {
            return;
        }

        $schema->createTable('oauth_tokens', function ($table) {
            $table->increments('id');
            $table->string('token', 512)->unique();
            $table->string('type', 20)->default('access');
            $table->string('client_id', 100);
            $table->integer('user_id')->unsigned()->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id'], 'idx_oauth_tokens_client');
            $table->index(['user_id'], 'idx_oauth_tokens_user');
            $table->index(['type', 'revoked'], 'idx_oauth_tokens_type');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('oauth_tokens');
    }
}
