<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Application\ServiceProvider;
use Pramnos\Debug\Collectors\AuthCollector;
use Pramnos\Debug\Collectors\GateCollector;
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

        // Authorization decisions. The recorder is opt-in for the same reason
        // Database::enableQueryLog() is: an application that never opens the toolbar should pay
        // one boolean check per decision, not build a log nobody reads.
        \Pramnos\Auth\Gate::enableDecisionLog();
        $bar->addCollector(new GateCollector());

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

    /**
     * Whether the toolbar renders on this request.
     *
     * Three answers, and each one is a statement somebody made deliberately:
     *
     *  1. **A signed token** — `debug:token`, minted at a terminal, redeemed once, expiring
     *     by itself. The only reason the toolbar appears on a live installation, and it
     *     appears for that one browser.
     *  2. **`APP_DEBUG`** in the environment — this deployment is a development one.
     *  3. **The `DEVELOPMENT` constant** — the same statement, made in code.
     *
     * ## What was removed, and why
     *
     * The `debug` and `development` **settings** used to be two more answers. They are rows
     * in the settings table, editable from `/admin/Settings` by anybody who can reach it —
     * and flipping one turned the toolbar on **for every visitor of the site**, not for the
     * person who flipped it.
     *
     * What the toolbar carries makes that a serious escalation rather than an untidy one:
     * every query with its bindings, the session's keys, the request's authentication state,
     * the resolved route and middleware. A single row in a table nobody thinks of as
     * dangerous is not the right lock for that, and the two settings were redundant besides
     * — an environment that is a development environment already says so, and a developer on
     * a live server has the token.
     *
     * The settings themselves still mean what they always meant everywhere else: error
     * display, the DevPanel, the debug log. Only this decision stopped reading them.
     */
    private function isDebugEnabled(): bool
    {
        return static::toolbarAllowed();
    }

    /**
     * The toolbar's own gate, in one place because it is asked from two.
     *
     * `Application::registerServiceProviders()` asks whether to load this provider at all,
     * and the provider asks whether to boot its collectors. Those were two different
     * expressions of the same question, so an installation could satisfy one and not the
     * other — a provider registered and then doing nothing, which is a confusing state to
     * debug with a tool that is not running.
     *
     * `getenv()` rather than the environment alone was the other half of that: `.env` is
     * loaded through `symfony/dotenv`, which populates `$_ENV` and `$_SERVER` and does not
     * call `putenv()`. So `getenv('APP_DEBUG')` answered "not set" on a project whose `.env`
     * says otherwise, and the toolbar was arriving through the settings path instead — the
     * one being removed here. `envvar()` reads what dotenv actually wrote.
     */
    public static function toolbarAllowed(): bool
    {
        // Checked first because redeeming a token has to happen even where another signal
        // would have said yes: the redemption is what sets the cookie for later requests.
        if (DebugAccess::isGranted()) {
            return true;
        }

        $envDebug = function_exists('envvar')
            ? envvar('APP_DEBUG', null)
            : (getenv('APP_DEBUG') ?: null);

        if (is_string($envDebug) || is_bool($envDebug) || is_int($envDebug)) {
            $value = is_string($envDebug) ? strtolower(trim($envDebug)) : $envDebug;

            if ($value !== '' && $value !== '0' && $value !== 'false' && $value !== false && $value !== 0) {
                return true;
            }
        }

        return defined('DEVELOPMENT') && DEVELOPMENT === true;
    }
}
