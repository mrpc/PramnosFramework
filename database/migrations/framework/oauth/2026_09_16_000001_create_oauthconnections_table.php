<?php

namespace Pramnos\Framework\Migrations\Oauth;

use Pramnos\Database\Migration;

/**
 * Creates the oauthconnections table — this application's tokens *for somebody else's API*.
 *
 * The framework already had the server half of OAuth2: `league/oauth2-server` issues tokens
 * and `usertokens` records the ones it has issued. This is the opposite direction. A row
 * here is a token **we hold** because a user authorised us against Google, Meta, TikTok or
 * anything else — obtained by an authorization-code flow, refreshed on a schedule, and
 * useless the moment it cannot be refreshed any more.
 *
 * Three columns exist because of how this fails in production rather than in a diagram:
 *
 *   - `status` / `dead_reason` / `dead_at`. A refresh token is revoked when a user changes
 *     their password, removes the app, or the provider decides the grant is stale — and the
 *     only signal is an `invalid_grant` on a background job nobody is watching. A feed that
 *     goes quiet is indistinguishable from a feed with nothing new in it, which is how a
 *     month of missing data gets noticed a month late. A connection that cannot be refreshed
 *     says so, in a column something can query.
 *
 *   - `refresh_expires_at`, separately from `expires_at`. The access token expiring is
 *     routine. The *refresh* token expiring is terminal, and several providers do it —
 *     Instagram's long-lived token lasts 60 days and dies if it is never exercised. A
 *     connection whose refresh window is closing has to be refreshed even if nothing has
 *     called it, which is why the refresh runs on a schedule rather than when a token is
 *     next used.
 *
 *   - `account_id`, part of the unique key rather than a detail. One user may connect two
 *     pages, two channels or two advertising accounts on the same platform, and a unique
 *     key of (user, provider) would silently overwrite the first with the second.
 *
 * The tokens themselves are encrypted at rest through `Pramnos\Security\Encrypter` before
 * they reach this table. The columns are `text` rather than `string` for that reason: a
 * ciphertext is longer than its plaintext, and some providers' access tokens are already
 * over a kilobyte before encryption.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license MIT
 */
class CreateOauthconnectionsTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 60;
    public array  $dependencies = [];
    public $description  = 'Creates the oauthconnections table — OAuth2 tokens this application holds for third-party APIs';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('#PREFIX#oauthconnections')) {
            return;
        }

        $schema->createTable('#PREFIX#oauthconnections', function ($table) {
            $table->comment('OAuth2 connections this application holds for third-party APIs — the client half, not the server half');

            $table->increments('id')
                ->comment('Surrogate key');
            $table->integer('userid')
                ->comment('The user who authorised this connection');
            $table->string('provider', 64)
                ->comment('Provider name as configured, e.g. google, instagram, tiktok');
            $table->string('account_id', 190)->default('')
                ->comment("The provider's own id for the connected account; '' when the provider does not name one. Part of the unique key: one user may connect two pages or two channels on one platform");
            $table->string('account_name', 190)->nullable()
                ->comment('A human label for the connected account, for an interface that has to say which one died');

            $table->text('access_token')
                ->comment('Encrypted at rest through Security\Encrypter; text because a ciphertext is longer than its plaintext and some providers issue tokens over a kilobyte');
            $table->text('refresh_token')->nullable()
                ->comment('Encrypted the same way. NULL for a provider that issues none — such a connection simply dies at expires_at');
            $table->integer('expires_at')->nullable()
                ->comment('Unix timestamp the access token stops working; NULL for a token the provider says does not expire');
            $table->integer('refresh_expires_at')->nullable()
                ->comment('Unix timestamp the refresh token itself stops working. Terminal, unlike expires_at — this is what makes an idle connection need a scheduled refresh rather than a refresh on next use');
            $table->text('scopes')->nullable()
                ->comment('Space-separated scopes the provider actually granted, which is not always the set that was asked for');

            $table->string('status', 16)->default('active')
                ->comment("'active' or 'dead'. A dead connection is kept rather than deleted: the row is the evidence of what stopped working and when");
            $table->string('dead_reason', 190)->nullable()
                ->comment('What the provider said, so an operator is not left guessing between a revoked grant and a network failure');
            $table->integer('dead_at')->nullable()
                ->comment('Unix timestamp the connection was first found to be unrefreshable');

            $table->integer('created_at')->nullable()
                ->comment('Unix timestamp the connection was first authorised');
            $table->integer('updated_at')->nullable()
                ->comment('Unix timestamp of the last successful refresh or write');

            // One connection per (user, provider, account). Two rows for one account
            // would make "the token" ambiguous, and the loser would be refreshed for
            // ever against a provider that has already reissued it.
            $table->unique(['userid', 'provider', 'account_id']);
            $table->index('userid');
            // The scheduled refresh reads "active connections expiring soon", in that
            // order — so the index is on both, not on either.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('#PREFIX#oauthconnections');
    }
}
