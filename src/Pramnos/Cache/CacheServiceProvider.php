<?php

namespace Pramnos\Cache;

use Pramnos\Application\ServiceProvider;

/**
 * Bootstraps the Cache feature for applications that declare 'cache'
 * in their app.php features list.
 *
 * The Cache class already reads its configuration from Settings::getSetting('cache')
 * at construction time, so this provider's primary role is to participate in the
 * FeatureRegistry lifecycle (enabling the 'cache' feature key) and to provide a
 * hook point for applications that want to customise the cache adapter at boot.
 *
 * To use:
 *   // app.php
 *   return ['features' => ['cache', 'auth', …], …];
 *
 *   // settings.php / config
 *   $settings->cache->method   = 'redis';   // or 'array', 'file', 'memcached'
 *   $settings->cache->hostname = '127.0.0.1';
 *   $settings->cache->port     = 6379;
 *
 * The singleton instance is then available application-wide via:
 *   Factory::getCache()
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class CacheServiceProvider extends ServiceProvider
{
    /**
     * Register early bindings.
     *
     * Ensures the Cache singleton is initialised with the settings that are
     * live at register() time, before any boot() code tries to use it.
     */
    public function register(): void
    {
        // Warm the singleton so it picks up the current Settings values.
        // Subsequent calls to Factory::getCache() return the same instance.
        Cache::getInstance();
    }

    /**
     * Bootstrap cache services after all providers have registered.
     *
     * Override in application-level providers to, for example, register a
     * scheduled cache-flush task:
     *
     *   $scheduler->call(fn() => Factory::getCache()->clear())->everyHour();
     */
    public function boot(): void
    {
    }
}
