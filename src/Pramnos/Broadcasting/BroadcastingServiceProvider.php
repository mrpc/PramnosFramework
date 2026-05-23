<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

use Pramnos\Application\ServiceProvider;
use Pramnos\Application\Settings;
use Pramnos\Broadcasting\Drivers\LogDriver;
use Pramnos\Broadcasting\Drivers\NullDriver;

/**
 * Bootstraps the broadcasting feature.
 *
 * Activated by listing 'broadcasting' in app.php features.
 *
 * ## Configuration (app.php)
 *
 * ```php
 * 'features' => ['broadcasting'],
 * 'broadcasting' => [
 *     'default' => 'log',   // 'null' (default) | 'log'
 *     'log_path' => ROOT . '/logs/broadcasting.log',
 * ],
 * ```
 *
 * ## Container binding
 *
 * The provider registers a 'broadcasting' singleton in the container so that
 * any class (including the Broadcastable trait) can resolve it:
 *
 * ```php
 * $manager = $app->container->get('broadcasting');
 * $manager->broadcast('channel', 'event', ['key' => 'value']);
 * ```
 *
 * @package PramnosFramework
 * @subpackage Broadcasting
 */
class BroadcastingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;

        $app->container->singleton('broadcasting', function () use ($app): BroadcastingManager {
            $config  = $app->applicationInfo['broadcasting'] ?? [];
            $default = $config['default'] ?? 'null';

            $manager = new BroadcastingManager(); // registers NullDriver automatically

            // Register LogDriver if configured
            $logPath = $config['log_path'] ?? null;
            $manager->addDriver(new LogDriver($logPath));

            // Set the default driver
            try {
                $manager->setDefault($default);
            } catch (\InvalidArgumentException) {
                // Configured driver not available — fall back to null
                $manager->setDefault('null');
                \Pramnos\Logs\Logger::log(
                    "Broadcasting: unknown driver '{$default}', falling back to null.",
                    'broadcasting',
                );
            }

            return $manager;
        });
    }

    public function boot(): void
    {
        // Nothing to boot at framework level.
        // Application providers can call $app->container->get('broadcasting')
        // to add custom drivers after register() has run.
    }
}
