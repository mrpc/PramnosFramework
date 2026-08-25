<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\ServiceProvider;

/**
 * Bootstraps the OAuth2 Authorization Server feature.
 *
 * Registered automatically by the FeatureRegistry when 'authserver' appears
 * in the application's app.php features list.
 *
 * Lifecycle:
 *   register() — runs before boot(); safe for early bindings only.
 *   boot()     — runs after all providers have registered; safe for anything
 *                that depends on other features (e.g. 'auth') being registered.
 *
 * RSA key generation is NOT automatic at bootstrap to avoid file-system side
 * effects during request handling. Call OAuth2ServerFactory::generateKeyPair()
 * from `pramnos init` or a one-time setup command instead.
 *
 */
class AuthServerServiceProvider extends ServiceProvider
{
    /**
     * Register early bindings.
     *
     * Binding the factory as a lazy closure lets controllers and controllers
     * resolve it via the application container without instantiating it on
     * every request.
     */
    public function register(): void
    {
        // Nothing to pre-register at framework level.
        // Applications may override boot() to bind the factory to a DI container.
    }

    /**
     * Bootstrap OAuth2 services after all providers have registered.
     *
     * RSA keys are still not generated here — that is a setup-time action, not a
     * per-request one, and controllers that need the keys guard for themselves.
     *
     * The signing-key **health check** is registered, which is a different thing:
     * registering it touches nothing, and the check only runs when something asks
     * for a health report. The built-in checks cover the database, disk and
     * memory and say nothing about the key pair, so `/health/check` reported `ok`
     * on a server that could not issue a single token.
     */
    public function boot(): void
    {
        \Pramnos\Health\HealthRegistry::register(new Health\SigningKeysCheck());
    }
}
