<?php

namespace Pramnos\Queue;

use Pramnos\Application\ServiceProvider;

/**
 * Bootstraps the Queue feature for any application that declares 'queue'
 * in its app.php features list.
 *
 * Lifecycle:
 *   register() — runs before all boot() calls; safe for early bindings only.
 *   boot()     — runs after all providers have registered; safe for anything
 *                that depends on other features being registered.
 *
 * Console commands (queue:process, cleanup:queue, daemons:start) are
 * registered separately via Console\Application::registerCommands() because
 * the CLI bootstrap does not go through the ServiceProvider lifecycle.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class QueueServiceProvider extends ServiceProvider
{
    /**
     * Register early bindings.
     *
     * Nothing to bind at framework level — the queue system is stateless
     * (workers are instantiated on demand by CLI commands or application code).
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap queue services after all providers have registered.
     *
     * Hook point for applications that want to register scheduled cleanup,
     * event listeners, or custom task handlers at bootstrap time.
     */
    public function boot(): void
    {
    }
}
