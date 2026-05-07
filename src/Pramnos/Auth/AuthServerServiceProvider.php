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
 * @package PramnosFramework
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
     * The hook is intentionally minimal — RSA key existence is not checked
     * here because generating keys is a setup-time action, not a per-request
     * action. Controllers that use OAuth2 should guard with
     * file_exists(ROOT . '/app/keys/private.key') themselves.
     */
    public function boot(): void
    {
    }
}
