<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Keeps what an OpenID Connect sign-in said beside its authorization code.
 *
 * The ID token issued when the code is redeemed has to carry the `nonce` the client sent to
 * `/oauth/authorize` and the time the user authenticated (`auth_time`). The code itself is
 * League's encrypted payload, which has no room for either, and the code's row in `usertokens`
 * is the one place both requests reach. A nullable JSON text column, written only for a code.
 */
class AddOidcContextToUsertokens extends Migration
{
    public string $feature      = 'auth';
    public string $scope        = 'framework';
    public int    $priority     = 96;
    public array  $dependencies = ['create_usertokens_table'];
    public $description = 'Adds usertokens.oidc_context (nonce, auth_time) for the ID token of an authorization code';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('#PREFIX#usertokens') || $schema->hasColumn('#PREFIX#usertokens', 'oidc_context')) {
            return;
        }

        $schema->alterTable('#PREFIX#usertokens', function ($table) {
            $table->text('oidc_context')->nullable()
                ->comment('For an auth_code: the OpenID Connect nonce and auth_time, as JSON');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('#PREFIX#usertokens') || !$schema->hasColumn('#PREFIX#usertokens', 'oidc_context')) {
            return;
        }

        $schema->alterTable('#PREFIX#usertokens', function ($table) {
            $table->dropColumn('oidc_context');
        });
    }
}
