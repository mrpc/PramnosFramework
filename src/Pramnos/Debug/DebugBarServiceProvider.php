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
        // DebugBar is a native singleton — no container binding needed.
    }

    public function boot(): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $bar = DebugBar::getInstance();

        $bar->addCollector(new TimeCollector());
        $bar->addCollector(new MemoryCollector());
        $bar->addCollector(new SessionCollector());
        $bar->addCollector(new LogCollector());

        // Query collector — only if DB is available
        $db = $this->app->database ?? null;
        if ($db !== null) {
            $db->enableQueryLog();
            $bar->addCollector(new QueryCollector($db));
        }

        $bar->addCollector(new RouteCollector());

        // Inject toolbar via output buffering — captures the full response
        // regardless of routing strategy and injects before </body>.
        ob_start(function (string $output) use ($bar): string {
            $bodyPos = strripos($output, '</body>');
            if ($bodyPos === false) {
                return $output;
            }
            $widget = $bar->render();
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
        if (defined('DEVELOPMENT') && DEVELOPMENT === true) {
            return true;
        }
        $debug = \Pramnos\Application\Settings::getSetting('debug');
        if ($debug === true || $debug === '1' || $debug === 'true' || $debug === 'yes') {
            return true;
        }
        $dev = \Pramnos\Application\Settings::getSetting('development');
        return $dev === true || $dev === '1' || $dev === 'true' || $dev === 'yes';
    }
}
