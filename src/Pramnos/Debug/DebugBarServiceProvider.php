<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Application\ServiceProvider;
use Pramnos\Debug\Collectors\LogCollector;
use Pramnos\Debug\Collectors\MemoryCollector;
use Pramnos\Debug\Collectors\QueryCollector;
use Pramnos\Debug\Collectors\RouteCollector;
use Pramnos\Debug\Collectors\SessionCollector;
use Pramnos\Debug\Collectors\TimeCollector;

/**
 * Bootstraps the DebugBar when APP_DEBUG is truthy.
 *
 * Opt-in: enabled automatically when the application setting 'debug' is true
 * OR when the `APP_DEBUG` environment variable is set. Does nothing in
 * production (debug off) to guarantee zero performance overhead.
 *
 * The toolbar is injected before `</body>` by DebugBarMiddleware which this
 * provider registers as a global middleware on the application pipeline.
 *
 * @package PramnosFramework
 */
class DebugBarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $bar = DebugBar::getInstance();
        $this->app->container->singleton('debug.bar', fn() => $bar);
    }

    public function boot(): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        if (!$this->app->container->has('debug.bar')) {
            return;
        }

        /** @var DebugBar $bar */
        $bar = $this->app->container->get('debug.bar');

        // Time collector — records request start time
        $timeCollector = new TimeCollector();
        $bar->addCollector($timeCollector);

        // Memory collector
        $bar->addCollector(new MemoryCollector());

        // Session collector
        $bar->addCollector(new SessionCollector());

        // Log collector
        $bar->addCollector(new LogCollector());

        // Query collector — only if DB is available
        $db = $this->app->database ?? null;
        if ($db !== null) {
            $db->enableQueryLog();
            $bar->addCollector(new QueryCollector($db));
        }

        // Route collector
        $bar->addCollector(new RouteCollector());

        // Inject toolbar via output buffering — works regardless of routing strategy
        $debugBar = $bar;
        ob_start(function (string $output) use ($debugBar): string {
            $bodyPos = strripos($output, '</body>');
            if ($bodyPos === false) {
                return $output;
            }
            $widget = $debugBar->render();
            if ($widget === '') {
                return $output;
            }
            return substr($output, 0, $bodyPos) . $widget . substr($output, $bodyPos);
        });
    }

    private function isDebugEnabled(): bool
    {
        $envDebug = getenv('APP_DEBUG');
        if ($envDebug !== false && $envDebug !== '' && $envDebug !== '0' && $envDebug !== 'false') {
            return true;
        }
        $setting = \Pramnos\Application\Settings::getSetting('debug');
        return $setting === true || $setting === '1' || $setting === 'true';
    }
}
