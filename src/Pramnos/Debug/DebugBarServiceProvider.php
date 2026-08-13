<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Application\ServiceProvider;
use Pramnos\Debug\Collectors\AuthCollector;
use Pramnos\Debug\Collectors\ExceptionsCollector;
use Pramnos\Debug\Collectors\LogCollector;
use Pramnos\Debug\Collectors\MemoryCollector;
use Pramnos\Debug\Collectors\MigrationsCollector;
use Pramnos\Debug\Collectors\ModelsCollector;
use Pramnos\Debug\Collectors\ServicesCollector;
use Pramnos\Debug\Collectors\QueryCollector;
use Pramnos\Debug\Collectors\RouteCollector;
use Pramnos\Debug\Collectors\SessionCollector;
use Pramnos\Debug\Collectors\TimeCollector;
use Pramnos\Debug\Collectors\ViewsCollector;

/**
 * Bootstraps the DebugBar when APP_DEBUG is truthy.
 *
 * Opt-in: enabled automatically when the application setting 'debug' is true
 * OR when the `APP_DEBUG` environment variable is set. Does nothing in
 * production (debug off) to guarantee zero performance overhead.
 *
 * **Injection is not this class's job.** The toolbar goes into the response through
 * {@see DebugBar::injectInto()}, reached from `Application::render()` and from
 * {@see DebugBarMiddleware} — so an application needs no pipeline to get one, and
 * cannot get two.
 *
 * This provider used to install a process-wide `ob_start()` instead, which caught
 * output from any code path including a raw `echo`. It was removed: booting the
 * toolbar added an output-buffer level, so code that cleared "its" buffer cleared
 * ours with the response inside it, and the client got `200` with an empty body
 * while every header said the request had succeeded. That happened twice in one
 * application, and the second report measured it off the socket — 523 header bytes,
 * zero body bytes. Laravel's debugbar and Symfony's profiler both inject through the
 * response and install no such buffer.
 *
 * What is left here is what a provider should do: register collectors, name the
 * request, and capture PHP diagnostics.
 *
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

        // Name this request before anything can log against it. From here every
        // log line carries the id, the response announces it, and the toolbar can
        // ask for the lines a dead request could not send back with it.
        //
        // HTTP only. A console command is not a request: there is no response to
        // announce an id on and no toolbar to ask for anything, so all an id
        // would do is change the shape of every line the command logs. It did
        // exactly that to the test suite, which runs under CLI — three
        // characterization tests asserting the plain log format started failing
        // because of a toolbar that was not even rendering.
        if (PHP_SAPI !== 'cli') {
            RequestId::activate();
        }

        $bar->addCollector(new TimeCollector());
        // Named for what it is. It measures this provider registering its own
        // collectors — useful when the toolbar itself is suspected of costing
        // something, and misleading under any name that suggests application
        // startup. That is `bootstrap`, measured in Application::init().
        DebugBar::startTimer('debugbar');
        $bar->addCollector(new MemoryCollector());
        $bar->addCollector(new SessionCollector());
        $bar->addCollector(new AuthCollector());
        $bar->addCollector(new LogCollector());

        // Query collector — only if DB is available
        $db = $this->app->database ?? null;
        if ($db !== null) {
            $db->enableQueryLog();
            $bar->addCollector(new QueryCollector($db));
        }

        $bar->addCollector(new RouteCollector());
        $bar->addCollector(new ViewsCollector());
        $bar->addCollector(new ModelsCollector());
        $bar->addCollector(new ServicesCollector());
        $bar->addCollector(new MigrationsCollector());
        $bar->addCollector(new ExceptionsCollector());

        DebugBar::stopTimer('debugbar');

        // The error handler is HTTP-only: under CLI it would capture PHPUnit's own
        // diagnostics into a collector nothing renders.
        if (PHP_SAPI === 'cli') {
            return;
        }

        // From here, once per process. An application whose bootstrap constructs
        // this provider by hand — the documented way to get collectors without
        // Application::init() — and which then also calls init() would install the
        // error handler twice and report every PHP notice twice with it.
        //
        // Only this part is guarded. Registering the collectors again is harmless —
        // addCollector() is keyed by name and simply replaces — and guarding that
        // as well would mean a second provider silently ending up with none.
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // @codeCoverageIgnoreStart
        // The error-handler setup below is HTTP-only; unreachable under CLI
        // (PHPUnit always runs with PHP_SAPI === 'cli').

        // Capture PHP errors (warnings, notices, deprecations) into ExceptionsCollector.
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($bar): bool {
            // Respect the `@` operator. A custom error handler is called for
            // suppressed diagnostics too — PHP lowers `error_reporting()` for
            // the duration of the expression rather than skipping the handler —
            // so without this check the toolbar reports errors that the code
            // deliberately silenced, and reports them on every request.
            //
            // `@get_browser()` on a server with no browscap is the standing
            // example: the code handles the failure and moves on, and the panel
            // was showing the warning anyway.
            if (!(error_reporting() & $errno)) {
                return false;
            }

            $ec = $bar->getCollector('exceptions');
            if ($ec instanceof ExceptionsCollector) {
                $ec->recordPhpError($errno, $errstr, $errfile, $errline);
            }
            return false; // continue default PHP error handling
        });


        // @codeCoverageIgnoreEnd
    }

    /**
     * Whether the HTTP-only setup has already run in this process.
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * Forget that boot() ran (for tests, and for a worker serving more than one
     * request in a single PHP lifetime).
     *
     * @return void
     */
    public static function resetBootState(): void
    {
        self::$booted = false;
    }

    private function isDebugEnabled(): bool
    {
        // A signed token opens the toolbar for one browser on a server where it
        // is otherwise off. Checked first because it is the only reason the
        // toolbar would appear on a live installation, and because redeeming a
        // token has to happen even when every other check would have said yes.
        if (DebugAccess::isGranted()) {
            return true;
        }

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
