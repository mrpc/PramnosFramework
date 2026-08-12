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
 * The toolbar is injected before `</body>` by DebugBarMiddleware which this
 * provider registers as a global middleware on the application pipeline.
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
        $bar->addCollector(new MigrationsCollector());
        $bar->addCollector(new ExceptionsCollector());

        DebugBar::stopTimer('debugbar');

        // Never open an output buffer in CLI (PHPUnit) — the unclosed level
        // would trigger "did not close its own output buffers" on every test.
        if (PHP_SAPI === 'cli') {
            return;
        }

        // @codeCoverageIgnoreStart
        // The following output-buffer and error-handler setup is HTTP-only;
        // unreachable under CLI (PHPUnit always runs with PHP_SAPI === 'cli').

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


        // Capture the app reference so the ob_start callback can read the
        // per-request CSP nonce that Application::exec() generates.
        $app = $this->app;

        // Inject toolbar via output buffering — captures the full response
        // regardless of routing strategy and injects before </body>.
        ob_start(function (string $output) use ($bar, $app): string {
            // Every response gets the headers, whatever its type. They are the
            // only channel that works for a 204, a redirect, or an HTML
            // fragment — the ordinary shapes of the requests a page makes after
            // it has already rendered.
            ApiDebugPayload::sendHeaders();

            // Only inject the toolbar into HTML documents. Non-HTML responses —
            // the 'raw' document the log viewer serves inside an <iframe>, JSON
            // API responses, PDFs, images, RSS, etc. — must never carry it.
            $doc = \Pramnos\Framework\Factory::getDocument();
            if (!is_object($doc) || (($doc->type ?? 'html') !== 'html')) {
                // A JSON body has no </body> to inject into, but it does have
                // room for a `_debug` key. Doing it here rather than in the API
                // layer catches everything: datatable endpoints, controllers
                // that echo their own JSON, anything that never goes near
                // Application\Api. That layer attaches its own payload first,
                // and this leaves an existing one alone.
                return ApiDebugPayload::attachTo($output);
            }
            $bodyPos = strripos($output, '</body>');
            if ($bodyPos === false) {
                return $output;
            }
            $nonce  = $app->cspNonce ?? '';
            $widget = $bar->render($nonce);
            if ($widget === '') {
                return $output;
            }
            return substr($output, 0, $bodyPos) . $widget . substr($output, $bodyPos);
        });
        // @codeCoverageIgnoreEnd
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
