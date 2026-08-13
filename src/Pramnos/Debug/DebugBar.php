<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Debug\Collectors\CollectorInterface;
use Pramnos\Debug\Collectors\MigrationsCollector;
use Pramnos\Debug\Collectors\TimeCollector;

/**
 * Debug toolbar for Pramnos applications.
 *
 * Aggregates data from registered collectors and hands it to the toolbar's one
 * renderer: {@see DebugBarAsset}, the same source a SPA project is scaffolded
 * with. This class no longer draws anything — it emits the request's collector
 * data as a hidden JSON island and the script that reads it. No npm build step
 * or external assets required.
 *
 * That is deliberate. There used to be two renderers, ~970 lines of PHP here and
 * a JavaScript panel for SPA projects, drawing the same tables from the same
 * data. They drifted, and the same bug then had to be fixed twice — the `✕` that
 * hid nothing was one mistake written in two languages. A server-rendered page
 * now gets every tab a SPA gets, and per-request history in the requests tab,
 * because it is the same code.
 *
 * Typical usage (via DebugBarServiceProvider):
 *
 *   $bar = DebugBar::getInstance();
 *   $bar->addCollector(new QueryCollector($db));
 *   // ... request runs ...
 *   echo $bar->render(); // injected before </body> by DebugBarMiddleware
 *
 * Named timers (forwarded to the TimeCollector if registered):
 *
 *   DebugBar::startTimer('auth-check');
 *   // ...
 *   DebugBar::stopTimer('auth-check');
 *
 */
class DebugBar
{
    private static ?self $instance = null;

    /** @var array<string, CollectorInterface> */
    private array $collectors = [];

    private ?TimeCollector $timeCollector = null;

    private function __construct() {}

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /** Reset singleton (used in tests). */
    public static function reset(): void
    {
        static::$instance = null;
        static::$injected = false;
    }

    // ── Collector Registration ────────────────────────────────────────────────

    public function addCollector(CollectorInterface $collector): static
    {
        $this->collectors[$collector->name()] = $collector;
        if ($collector instanceof TimeCollector) {
            $this->timeCollector = $collector;
        }
        return $this;
    }

    public function getCollector(string $name): ?CollectorInterface
    {
        return $this->collectors[$name] ?? null;
    }

    /** @return array<string, CollectorInterface> */
    public function getCollectors(): array
    {
        return $this->collectors;
    }

    // ── Timer Convenience ──────────────────────────────────────────────────────

    public static function startTimer(string $name): void
    {
        static::getInstance()->timeCollector?->startTimer($name);
    }

    public static function stopTimer(string $name): void
    {
        static::getInstance()->timeCollector?->stopTimer($name);
    }

    /**
     * Records a migration that ran during this request.
     *
     * Adds a timeline segment to the TimeCollector AND an entry to the
     * MigrationsCollector so both the timeline and the Migrations tab reflect it.
     *
     * @param string $slug   Migration slug.
     * @param float  $ms     Execution time in milliseconds.
     * @param string $status 'ran' (success) or 'failed'.
     */
    public static function recordMigration(string $slug, float $ms, string $status = 'ran'): void
    {
        $bar = static::getInstance();
        $bar->timeCollector?->addCompletedSegment('migration:' . $slug, $ms);
        $mc = $bar->getCollector('migrations');
        if ($mc instanceof MigrationsCollector) {
            $mc->record($slug, $ms, $status);
        }
    }


    /**
     * Record a phase of work that has already finished.
     *
     * For work the toolbar could not have timed as it happened — application
     * bootstrap runs before the collectors exist, because registering them is
     * part of it. The times are absolute (`microtime(true)`), so a caller can
     * measure several phases and report them all at the end without them piling
     * up at the same instant.
     *
     * A no-op when no TimeCollector is registered, which is every request in
     * production.
     *
     * @param string $name  Label shown on the timeline.
     * @param float  $start microtime(true) when the phase began.
     * @param float  $end   microtime(true) when it ended.
     */
    public static function recordSegment(string $name, float $start, float $end): void
    {
        static::getInstance()->timeCollector?->addSegment($name, $start, $end);
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    /**
     * Render the debug toolbar.
     *
     * Two things come back, and neither of them is markup: a hidden data island
     * holding this request's collector data as JSON, and the single toolbar
     * source that reads it. The bar, the tabs and every table are the renderer's
     * job — {@see DebugBarAsset::source()} — so a fix to any of them is made once
     * and a server-rendered page shows exactly what a SPA shows.
     *
     * A `<div hidden>` rather than a `<script type="application/json">` because a
     * data island inside a script element is a grey area under a strict
     * Content-Security-Policy, and this has to work on every install.
     *
     * @param string $nonce CSP nonce for the inline `<script>`. The renderer
     *                      copies it onto the `<style>` element it injects, so
     *                      one nonce covers both. Pass Application::$cspNonce;
     *                      leave empty where CSP is not configured.
     *
     * @return string Empty when no collectors are registered — nothing collected
     *                means nothing to show, and nothing injected into the page.
     */
    public function render(string $nonce = ''): string
    {
        if ($this->collectors === []) {
            return '';
        }

        $payload = ApiDebugPayload::build() + [
            'request_method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'request_path'   => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            'status_code'    => (int) (http_response_code() ?: 200),
        ];

        // Hex-escaping the four characters that could end the element early means
        // the island needs no HTML escaping of its own: what `textContent` yields
        // is the JSON byte for byte, and there is nothing in it a parser could
        // read as markup.
        $json = (string) json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $script = DebugBarAsset::withAppName(DebugBarAsset::source(), $this->appName());
        $na     = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';

        return <<<HTML
<div id="pramnos-debug-data" hidden>{$json}</div>
<script{$na}>{$script}</script>
HTML;
    }

    /**
     * Put the toolbar into a response, and send the debug headers with it.
     *
     * **The one place injection happens.** Every delivery goes through here — the
     * document `Application::render()` builds, a string a middleware returns, a
     * `Response` body — so there is one definition of "which responses carry the
     * toolbar" and one definition of "once".
     *
     * It replaced a process-wide `ob_start()`. That buffer caught output from any
     * code path, including an application that simply echoes, and the price was
     * structural: booting the toolbar added an output-buffer level, so code that
     * cleared "its" buffer cleared ours with the response inside it, and the client
     * got `200` with an empty body while every header said the request had
     * succeeded. It happened twice in one application. Laravel's debugbar and
     * Symfony's profiler both inject through the response object and never install
     * such a buffer; this now does the same.
     *
     * Consequences, stated plainly: a response the framework never sees — raw
     * `echo`, or a `require` of a page file — no longer receives a toolbar. That is
     * the trade, and the Upgrade Guide says how to route such a response through the
     * framework instead.
     *
     * Idempotent per request, which is why the "toolbar twice in one page" class of
     * bug cannot come back: a second attempt returns the body untouched.
     *
     * Nothing here can cost the response. Anything thrown gives the body back
     * exactly as it arrived, and a shorter result is discarded — delivering the page
     * is the job, decorating it is not.
     *
     * @param  string $body  The response body as the application produced it
     * @param  string $nonce Per-request CSP nonce, or '' where CSP is not configured
     * @return string
     */
    public function injectInto(string $body, string $nonce = ''): string
    {
        if ($this->collectors === [] || self::$injected || $body === '') {
            return $body;
        }

        try {
            ApiDebugPayload::sendHeaders();

            // Only an HTML document gets a toolbar. The log viewer serves a `raw`
            // document inside an `<iframe>`, and a `</body>` in *that* is part of
            // the text being displayed rather than a place to inject a script.
            $document = \Pramnos\Framework\Factory::getDocument();
            $isHtml   = !is_object($document) || (($document->type ?? 'html') === 'html');

            $position = $isHtml ? strripos($body, '</body>') : false;
            if ($position === false) {
                // Nowhere to put a toolbar. A JSON object still has room for a
                // `_debug` key, and that rule lives in one place.
                return ApiDebugPayload::attachTo($body);
            }

            $widget = $this->render($nonce);
            if ($widget === '') {
                return $body;
            }

            self::$injected = true;

            return substr($body, 0, $position) . $widget . substr($body, $position);
        } catch (\Throwable) {
            return $body;
        }
    }

    /**
     * Whether this request's response has already been given the toolbar.
     *
     * @var bool
     */
    private static bool $injected = false;

    /**
     * Forget that a response was injected into.
     *
     * For tests, and for a worker that serves more than one request in a single PHP
     * lifetime.
     *
     * @return void
     */
    public static function resetInjection(): void
    {
        self::$injected = false;
    }

    /**
     * The application's name, for the brand in the bar.
     *
     * A toolbar that says "Pramnos" on every install tells the reader which
     * framework they are using, which they knew. Naming the application is what
     * distinguishes two tabs open on two of them.
     *
     * Settings are read behind a try/catch: reading a setting can reach the
     * database, and the toolbar annotating a response must never be the reason
     * that response fails.
     */
    private function appName(): string
    {
        try {
            $name = (string) (\Pramnos\Application\Settings::getSetting('title') ?: '');
        } catch (\Throwable $e) {
            // @codeCoverageIgnoreStart — Settings swallows its own database
            // failures and returns the default, so this catch is the second line
            // of a two-line defence. It stays because the first line is not this
            // class's to guarantee: a future Settings that lets an exception out
            // must not take the toolbar's own response with it.
            $name = '';
            // @codeCoverageIgnoreEnd
        }

        if ($name === '' && defined('TITLE')) {
            $name = (string) TITLE;
        }

        return $name;
    }
}
