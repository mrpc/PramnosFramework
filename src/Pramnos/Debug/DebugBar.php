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
