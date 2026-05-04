<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the usertokens table — multi-purpose token store for auth flows.
 *
 * Stores all token types used by the framework authentication system:
 * session tokens, API access tokens, OAuth access/refresh/auth-code tokens,
 * password-reset tokens, and email verification tokens.
 *
 * The `token` column is TEXT (not VARCHAR) to accommodate JWTs of any size,
 * which can exceed 255 characters when using RS256/RS512 with large key sets.
 *
 * PKCE support (RFC 7636): code_challenge and code_challenge_method columns
 * allow the OAuth authorization code flow to verify the original code verifier.
 *
 * @package PramnosFramework
 */
class CreateUsertokensTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_users_table'];
    public $description  = 'Creates the usertokens table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('usertokens')) {
            return;
        }

        $schema->createTable('usertokens', function ($table) {
            $table->comment('Multi-purpose token store: session tokens, API tokens, OAuth flows, password resets');

            $table->increments('tokenid')
                ->comment('Auto-increment token identifier');
            $table->bigInteger('userid')
                ->comment('FK to users.userid — owner of the token');
            $table->string('tokentype', 20)
                ->comment('Token category: session | api | access_token | refresh_token | auth_code | password_reset | email_verify');
            $table->text('token')
                ->comment('Token value — TEXT to accommodate JWTs of arbitrary length (RS256/RS512 can exceed VARCHAR(255))');
            $table->integer('created')
                ->comment('Unix timestamp when the token was issued');
            $table->string('notes', 255)->default('')
                ->comment('Human-readable description of the token purpose (e.g. app name, device name)');
            $table->integer('lastused')->default(0)
                ->comment('Unix timestamp of the most recent use of this token');
            $table->tinyInteger('status')
                ->comment('Token lifecycle state: 0 = inactive, 1 = active, 2 = removed/revoked');
            $table->integer('parentToken')->nullable()
                ->comment('Self-referencing FK to usertokens.tokenid — links refresh tokens to their parent access token');
            $table->integer('applicationid')->nullable()
                ->comment('FK to applications.appid — the OAuth client that issued this token; NULL for non-OAuth tokens');
            $table->integer('actions')->default(0)
                ->comment('Counter for number of API actions performed with this token');
            $table->integer('removedate')->default(0)
                ->comment('Unix timestamp when the token was deactivated (0 = still active)');
            $table->text('deviceinfo')
                ->comment('JSON-encoded device/client information (browser, OS, IP at token creation)');
            $table->text('scope')
                ->comment('Space-separated list of OAuth scopes granted to this token');
            $table->integer('expires')->nullable()
                ->comment('Unix timestamp when the token expires; NULL = never expires');
            $table->string('ipaddress', 45)->nullable()
                ->comment('IPv4 or IPv6 address from which the token was created (stored as string for portability)');

            // PKCE (RFC 7636) — populated only for auth_code tokens using PKCE flow
            $table->string('code_challenge', 128)->nullable()
                ->comment('PKCE code challenge (BASE64URL(SHA256(code_verifier)) or the plain verifier); 43-128 chars per RFC 7636');
            $table->string('code_challenge_method', 10)->nullable()
                ->comment('PKCE transformation method: plain | S256; NULL for non-PKCE tokens');

            $table->index(['userid', 'status'], 'idx_usertokens_userid_status');
            $table->index(['tokentype', 'status'], 'idx_usertokens_type_status');
            $table->index(['applicationid'], 'idx_usertokens_applicationid');

            $table->foreign('userid')
                ->references('userid')
                ->on('users')
                ->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('usertokens');
    }
}
