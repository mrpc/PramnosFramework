<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the oauth2_user_consents table — persisted user authorization decisions.
 *
 * Records which scopes a user has previously authorized for each OAuth2 client
 * application. This allows the authorization server to skip the consent screen
 * on subsequent authorization requests when all requested scopes have already
 * been granted.
 *
 * Scope merging policy: scopes are only ever expanded, never shrunk. When a new
 * authorization request includes additional scopes, the stored scope string is
 * updated to the union of old and new scopes.
 *
 * @package PramnosFramework
 */
class CreateOauth2UserConsentsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 56;
    public array  $dependencies = ['create_applications_table', 'create_users_table'];
    public $description  = 'Creates the oauth2_user_consents table (persisted user authorization decisions)';

    public function up(): void: void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('oauth2_user_consents')) {
            return;
        }

        $schema->createTable('oauth2_user_consents', function ($table) {
            $table->comment('Persisted user consent decisions for OAuth2 client applications');

            $table->bigIncrements('id')
                ->comment('Auto-increment primary key');
            $table->bigInteger('userid')
                ->comment('FK to users.userid — the user who granted consent');
            $table->integer('applicationid')
                ->comment('FK to applications.appid — the OAuth2 client that received consent');
            $table->text('scope')->nullable()
                ->comment('Space-separated list of scopes the user has authorized; only expanded, never shrunk');
            $table->timestamp('created_at')->nullable()
                ->comment('When the user first authorized this application');
            $table->timestamp('updated_at')->nullable()
                ->comment('When the consent record was last updated (scope expansion)');

            $table->unique(['userid', 'applicationid'], 'uq_oauth2_consents_user_app');
            $table->index(['userid'], 'idx_oauth2_consents_userid');
            $table->index(['applicationid'], 'idx_oauth2_consents_appid');
        });
    }

    public function down(): void: void
    {
        $this->application->database->schema()->dropTableIfExists('oauth2_user_consents');
    }
}
