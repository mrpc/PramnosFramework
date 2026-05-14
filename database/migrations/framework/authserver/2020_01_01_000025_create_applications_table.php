<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the applications table — OAuth2 client registry.
 *
 * Stores registered OAuth2 client applications (the "clients" in OAuth2
 * terminology). Each application holds its client_id (apikey), client_secret
 * (apisecret), allowed redirect URIs (callback), granted scopes, and
 * additional metadata required by the league/oauth2-server.
 *
 * The `applications` table is the prerequisite for:
 * - `usertokens.applicationid` foreign key
 * - `authserver.slow_api_calls` view
 * - OAuth2 authorization flows (all 4 grant types)
 *
 * @package PramnosFramework
 */
class CreateApplicationsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 15;
    public array  $dependencies = ['create_authserver_schema', 'create_users_table'];
    public $description  = 'Creates the applications (OAuth2 client) table';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('applications')) {
            return;
        }

        $schema->createTable('applications', function ($table) {
            $table->comment('OAuth2 client applications registered with the authorization server');

            $table->increments('appid')
                ->comment('Auto-increment application identifier (OAuth2 client primary key)');
            $table->string('name', 255)
                ->comment('Human-readable application name shown in consent screens');
            $table->string('apikey', 255)->nullable()
                ->comment('OAuth2 client_id — unique public identifier for the application');
            $table->string('apisecret', 255)->nullable()
                ->comment('OAuth2 client_secret — hashed or plain secret for confidential clients');
            $table->tinyInteger('status')->default(1)
                ->comment('Application status: 0 = disabled, 1 = active');
            $table->integer('added')->default(0)
                ->comment('Unix timestamp of registration date');
            $table->text('description')->nullable()
                ->comment('Optional long description of the application purpose');
            $table->string('organization', 255)->nullable()
                ->comment('Organization or company that owns this application');
            $table->string('organizationurl', 500)->nullable()
                ->comment('URL of the owning organization');
            $table->string('url', 500)->nullable()
                ->comment('Homepage URL of the application');
            $table->tinyInteger('apptype')->default(0)
                ->comment('Application type code (0 = web, 1 = mobile, 2 = service)');
            $table->tinyInteger('accesstype')->default(0)
                ->comment('Access type: 0 = REST API key, 1 = OAuth2 flow');
            $table->string('apiversion', 20)->default('v1')
                ->comment('Target API version (e.g. v1, v2)');
            $table->text('scope')->nullable()
                ->comment('Space-separated list of OAuth2 scopes this client is allowed to request');
            $table->tinyInteger('public')->default(0)
                ->comment('Whether the application is publicly listed (0 = private, 1 = public)');
            $table->text('callback')->nullable()
                ->comment('Comma-separated or JSON-array of allowed OAuth2 redirect URIs');
            $table->bigInteger('owner')->nullable()
                ->comment('FK to users.userid — the user account that registered this application');
            $table->text('public_key')->nullable()
                ->comment('RSA/EC public key PEM for JWT client authentication (RFC 7523)');
            $table->string('jwks_uri', 500)->nullable()
                ->comment('URL to the JWKS endpoint for dynamic public-key rotation');

            $table->unique(['apikey'], 'uq_applications_apikey');
            $table->index(['status'], 'idx_applications_status');
            $table->index(['owner'], 'idx_applications_owner');
        });
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('applications');
    }
}
