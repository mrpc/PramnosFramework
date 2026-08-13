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
        $bar->addCollector(new ServicesCollector());
        $bar->addCollector(new MigrationsCollector());
        $bar->addCollector(new ExceptionsCollector());

        DebugBar::stopTimer('debugbar');

        // Never open an output buffer in CLI (PHPUnit) — the unclosed level
        // would trigger "did not close its own output buffers" on every test.
        if (PHP_SAPI === 'cli') {
            return;
        }

        // From here, once per process. An application whose bootstrap constructs
        // this provider by hand — the documented way to get collectors without
        // Application::init() — and which then also calls init() would install two
        // output buffers and two error handlers: the toolbar appears in the page
        // twice, and there are two levels for a stray ob_end_clean() elsewhere to
        // hit. Measured on a real page: a 9KB document came back at 281KB.
        //
        // Only this part is guarded. Registering the collectors again is harmless —
        // addCollector() is keyed by name and simply replaces — and guarding that
        // as well would mean a second provider silently ending up with none.
        if (self::$booted) {
            return;
        }
        self::$booted = true;

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
        //
        // The whole callback is guarded, because of how PHP treats a failure in
        // one: an exception thrown inside an output-buffer callback discards the
        // buffer. The client then gets 200 with an empty body while the response
        // headers — Server-Timing, X-Pramnos-Debug — say the request succeeded,
        // and nothing is logged, because this runs at shutdown. That failure has
        // happened, in a real development environment, and it cost a day: an
        // empty 200 passes every uptime check and reads like a broken front-end
        // build.
        //
        // Delivering the page is the job. Decorating it is not.
        //
        // `$phase` is read as well, because the other way to lose a page is for
        // somebody to throw this buffer away — see rescueDiscardedOutput().
        ob_start(fn (string $output, int $phase): string => self::handleBuffer($output, $phase, $bar, $app));

        // The rescue runs after every buffer is gone, which is the only moment at
        // which an `echo` reaches the client directly.
        register_shutdown_function([self::class, 'rescueDiscardedOutput']);
        // @codeCoverageIgnoreEnd
    }

    /**
     * One pass of the output buffer, whichever phase PHP is in.
     *
     * A method rather than the body of the closure so it can be driven by tests:
     * the closure runs at shutdown, in a context no test can construct, and what it
     * decides is whether a page is delivered.
     *
     * @param  string      $output The buffer's contents
     * @param  int         $phase  PHP's output-handler phase bitmask
     * @param  DebugBar    $bar
     * @param  object|null $app
     * @return string
     */
    public static function handleBuffer(string $output, int $phase, DebugBar $bar, ?object $app = null): string
    {
        // A clean is somebody throwing this buffer away. PHP has already decided
        // the content is going, so there is nothing to return that would keep it —
        // it is recorded here and re-sent at shutdown instead.
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
            self::noteDiscardedOutput($output);

            return $output;
        }

        if ($output !== '') {
            self::$delivered = true;
        }

        return self::decorate($output, $bar, $app);
    }

    /**
     * Whether boot() has already run in this process.
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * The response body somebody discarded, if that happened.
     *
     * @var string
     */
    private static string $discarded = '';

    /**
     * Whether any of this request's output reached the client through the buffer.
     *
     * @var bool
     */
    private static bool $delivered = false;

    /**
     * Remember output that is being thrown away, and say so.
     *
     * **The second way a toolbar can cost you the page, and the one the first fix
     * could not reach.** Booting the provider adds an output-buffer level. An
     * application that clears "its" buffer — a bare `ob_get_clean()`, or the classic
     * `while (ob_get_level()) { ob_end_clean(); }` loop that kernels use to drop
     * stray output before responding — discards **ours** as well, and the page is
     * inside it. Nothing errors. The client gets `200` with a body of zero bytes,
     * the response headers all say the request succeeded, and with the toolbar off
     * the same code works perfectly, because then there is no extra level to
     * destroy.
     *
     * Measured, not deduced: with `output_buffering` on in php.ini and the provider
     * booted there are two levels, and either of those two idioms empties a 280KB
     * page to nothing.
     *
     * This cannot be prevented from here — by the time the handler is called, PHP
     * has already decided the content is going. So it is recorded, reported, and
     * re-emitted at shutdown by {@see rescueDiscardedOutput()}.
     *
     * @param  string $output What was about to be dropped
     * @return void
     */
    private static function noteDiscardedOutput(string $output): void
    {
        if (trim($output) === '') {
            return;
        }

        self::$discarded = $output;

        try {
            \Pramnos\Logs\Logger::logError(
                'The debug toolbar\'s output buffer was discarded with '
                . strlen($output) . ' bytes of the response in it. Something in this '
                . 'request cleared an output buffer it did not open — a bare '
                . 'ob_get_clean(), or a "while (ob_get_level()) ob_end_clean()" loop. '
                . 'With the toolbar off there is no such buffer, which is why the same '
                . 'code works then. The response was re-sent at shutdown; fix the '
                . 'buffer handling, or turn the toolbar off for this route.',
                null
            );
        } catch (\Throwable) {
            // Reporting is best-effort; the rescue below is what matters.
        }
    }

    /**
     * Send a response that was discarded, once every buffer is out of the way.
     *
     * Deliberately conservative. It re-sends only when **nothing** was delivered
     * through the buffer and what was dropped looks like a whole HTML document —
     * because a fragment somebody was buffering on purpose, or a JSON body being
     * replaced, is a discard that meant what it said. A complete document reaching
     * this point means the page itself was thrown away, which is never what anybody
     * intended.
     *
     * Honest limit: if the application produced other output *after* discarding
     * ours, that output has already gone to the client and this appends to it. The
     * log line above is what makes such a case legible rather than mysterious.
     *
     * @return void
     */
    public static function rescueDiscardedOutput(): void
    {
        $body = self::$discarded;
        self::$discarded = '';

        if ($body === '' || self::$delivered) {
            return;
        }

        $head = strtolower(ltrim(substr($body, 0, 64)));
        if (!str_starts_with($head, '<!doctype') && !str_starts_with($head, '<html')) {
            return;
        }

        echo $body;
    }

    /**
     * Forget what the last request discarded (for tests, and for a worker that
     * serves more than one request per process).
     *
     * @return void
     */
    public static function resetOutputState(): void
    {
        self::$discarded = '';
        self::$delivered = false;
        self::$booted    = false;
    }

    /**
     * The response, with whatever debug data belongs in it.
     *
     * Extracted from the output-buffer callback so it can be driven by tests: a
     * closure registered with `ob_start()` during `boot()` runs at shutdown, in a
     * context no test can reproduce, and this is the code that decides whether a
     * page is delivered.
     *
     * Nothing that happens in here can cost the page. Anything thrown gives the
     * response back untouched, and a result shorter than what arrived is
     * discarded — a decoration that shortened the body has failed, whatever it
     * thinks, and the un-decorated page is always the better answer.
     *
     * @param  string      $output The response body as buffered
     * @param  DebugBar    $bar
     * @param  object|null $app    The application, for its per-request CSP nonce
     * @return string
     */
    public static function decorate(string $output, DebugBar $bar, ?object $app = null): string
    {
        try {
            $decorated = self::inject($output, $bar, $app);
        } catch (\Throwable) {
            // Rendering reads collectors, the session and the container, any of
            // which can fail for reasons that have nothing to do with the page
            // that is ready to be sent.
            return $output;
        }

        // The second half of the same rule, for a failure that returns a wrong
        // answer rather than raising one. Injection can only ever lengthen the
        // body today, so this is a guard against a future change rather than a
        // known path — and it is one line for the failure that cost a day.
        return strlen($decorated) >= strlen($output) ? $decorated : $output;
    }

    /**
     * Put the toolbar (or the JSON payload) into a response.
     *
     * @param  string      $output
     * @param  DebugBar    $bar
     * @param  object|null $app
     * @return string
     */
    private static function inject(string $output, DebugBar $bar, ?object $app): string
    {
        // Every response gets the headers, whatever its type. They are the only
        // channel that works for a 204, a redirect, or an HTML fragment — the
        // ordinary shapes of the requests a page makes after it has rendered.
        ApiDebugPayload::sendHeaders();

        // Only inject the toolbar into HTML documents. Non-HTML responses — the
        // 'raw' document the log viewer serves inside an <iframe>, JSON API
        // responses, PDFs, images, RSS, etc. — must never carry it.
        $doc = \Pramnos\Framework\Factory::getDocument();
        if (!is_object($doc) || (($doc->type ?? 'html') !== 'html')) {
            // A JSON body has no </body> to inject into, but it does have room
            // for a `_debug` key. Doing it here rather than in the API layer
            // catches everything: datatable endpoints, controllers that echo
            // their own JSON, anything that never goes near Application\Api.
            // That layer attaches its own payload first, and this leaves an
            // existing one alone.
            return ApiDebugPayload::attachTo($output);
        }

        $bodyPos = strripos($output, '</body>');
        if ($bodyPos === false) {
            return $output;
        }

        $widget = $bar->render($app->cspNonce ?? '');
        if ($widget === '') {
            return $output;
        }

        return substr($output, 0, $bodyPos) . $widget . substr($output, $bodyPos);
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
