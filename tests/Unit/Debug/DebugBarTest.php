<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Debug\DebugBar;
use Pramnos\Debug\RequestId;
use Pramnos\Debug\DebugBarMiddleware;
use Pramnos\Debug\Collectors\CollectorInterface;
use Pramnos\Debug\Collectors\MemoryCollector;
use Pramnos\Debug\Collectors\SessionCollector;
use Pramnos\Debug\Collectors\TimeCollector;
use Pramnos\Debug\Collectors\LogCollector;
use Pramnos\Debug\Collectors\RouteCollector;
use Pramnos\Debug\Collectors\QueryCollector;

/**
 * Unit tests for DebugBar and its built-in collectors.
 *
 * These tests do not require a running database or web server. They verify
 * that each collector produces the expected data shape, that the DebugBar
 * renders a recognisable HTML structure, that sensitive session keys are
 * masked, and that the middleware injects the widget at the correct position.
 */
class DebugBarTest extends TestCase
{
    protected function setUp(): void
    {
        DebugBar::reset();
    }

    protected function tearDown(): void
    {
        DebugBar::reset();
    }

    // ── DebugBar core ─────────────────────────────────────────────────────────

    /**
     * getInstance() must always return the same singleton instance.
     */
    public function testGetInstanceReturnsSingleton(): void
    {
        // Arrange / Act
        $a = DebugBar::getInstance();
        $b = DebugBar::getInstance();

        // Assert
        $this->assertSame($a, $b);
    }

    /**
     * addCollector() must register the collector under its name() key so
     * getCollector() can retrieve it.
     */
    public function testAddCollectorRegistersCollector(): void
    {
        // Arrange
        $bar       = DebugBar::getInstance();
        $collector = $this->makeMockCollector('test-col');

        // Act
        $bar->addCollector($collector);

        // Assert
        $this->assertSame($collector, $bar->getCollector('test-col'));
    }

    /**
     * render() emits the data island and the one toolbar source — nothing else.
     *
     * The bar used to be built here, in ~500 lines of PHP that drew the same
     * tables as the SPA panel's JavaScript. They drifted, and a bug then had to
     * be fixed twice. What ships now is this request's collector data as JSON
     * and the script that draws it; the drawing is covered by the JS tests,
     * which drive that script for real.
     */
    public function testRenderEmitsTheDataIslandAndTheToolbarSource(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo', ['count' => 1]));

        // Act
        $html = $bar->render();

        // Assert — the island, and the script that reads it
        $this->assertStringContainsString('<div id="pramnos-debug-data" hidden>', $html);
        $this->assertStringContainsString('window.__pramnosDebugBar', $html);
        // No markup of its own: a second renderer is exactly what was removed.
        $this->assertStringNotContainsString('<div id="pramnos-debugbar">', $html);
    }

    /**
     * The island carries every collector's data, plus what identifies the
     * request it describes.
     *
     * `request_method` / `request_path` / `status_code` are what let the page's
     * own request appear in the requests tab beside the API calls that follow
     * it — the toolbar has one list, and the page belongs in it.
     */
    public function testIslandCarriesCollectorDataAndRequestIdentity(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/admin/settings?tab=mail';
        // Explicit, because http_response_code() is process-wide: an earlier
        // test in a full run can leave it at 404, and the island would then
        // faithfully report what the process last set.
        http_response_code(200);
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo', ['count' => 7]));

        // Act
        $payload = $this->islandOf($bar->render());

        // Assert — the collector's own data, under its own name
        $this->assertSame(7, $payload['demo']['count']);
        // And the request it describes
        $this->assertSame('POST', $payload['request_method']);
        $this->assertSame('/admin/settings?tab=mail', $payload['request_path']);
        $this->assertSame(200, $payload['status_code']);
        // The timing copy no collector can overwrite — the top-level `memory`
        // key is replaced by MemoryCollector, which is how a panel once printed
        // "[object Object]MB".
        //
        // assertIsNumeric, not assertIsFloat: JSON has one number type, so a
        // duration that lands on a whole millisecond comes back as an int. The
        // stricter assertion passed or failed depending on how long the rest of
        // the suite had been running.
        $this->assertIsNumeric($payload['request']['time']);
        // Not `> 0`: `REQUEST_TIME_FLOAT` is read from $_SERVER, and a test
        // elsewhere in a full run clears it — the payload then measures from
        // "now" and honestly reports 0.
        $this->assertGreaterThanOrEqual(0, $payload['request']['time']);

        // Cleanup
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    /**
     * The island carries this request's id.
     *
     * The id is the one thing the client cannot work out: it is what every log
     * line written during the request also carries, and what the toolbar hands
     * back when it asks the server for those lines. Where to ask is a constant
     * the toolbar already knows, so it is not sent.
     */
    public function testIslandCarriesTheRequestId(): void
    {
        // Arrange
        RequestId::reset();
        RequestId::activate();
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo'));

        // Act
        $payload = $this->islandOf($bar->render());

        // Assert
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $payload['request']['id']);
        // The same id the log lines will carry — that is the entire point of it.
        $this->assertSame(RequestId::current(), $payload['request']['id']);
        // And nothing telling the client where the endpoint lives: that is a
        // constant, and a response should carry what only it knows.
        $this->assertArrayNotHasKey('logs_url', $payload);

        // Cleanup
        RequestId::reset();
    }

    /**
     * The island must not be able to end its own element.
     *
     * Collector data is application data — a query containing `</div>`, a log
     * message containing `<script>`. The JSON is hex-escaped rather than HTML-
     * escaped, so `textContent` hands the script back the exact bytes and there
     * is nothing in them a parser can read as markup.
     */
    public function testIslandCannotBreakOutOfItsElement(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('queries', [
            'count'   => 1,
            'queries' => [['sql' => "SELECT '</div><script>alert(1)</script>'", 'time' => 1]],
        ]));

        // Act
        $html = $bar->render();

        // Assert — the dangerous characters are gone from the wire...
        $islandEnd = strpos($html, '</div>');
        $this->assertGreaterThan(0, $islandEnd);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        // ...and the value still arrives intact once JSON-decoded.
        $payload = $this->islandOf($html);
        $this->assertStringContainsString('alert(1)', $payload['queries']['queries'][0]['sql']);
    }

    /**
     * render($nonce) must put the nonce on the inline <script>.
     *
     * Without it a strict CSP blocks the script outright. The toolbar copies the
     * same nonce onto the `<style>` element it injects — read from the script
     * tag itself, and driven in `tests/js/debugbar-ajax.test.js`.
     */
    public function testRenderAddsCspNonceToTheScriptTag(): void
    {
        // Arrange
        $bar   = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo'));
        $nonce = 'abc123testNonce';

        // Act
        $html = $bar->render($nonce);

        // Assert
        $this->assertStringContainsString("<script nonce=\"$nonce\">", $html);
        // The nonce must NOT be injected when empty — `nonce=""` is not the same
        // as no nonce, and a policy would reject it.
        $this->assertStringNotContainsString('nonce=', $bar->render(''));
    }

    /**
     * The toolbar is branded with the application's name when there is one.
     *
     * A bar that says "Pramnos" on every install names the framework, which the
     * reader knew. Naming the application is what tells two tabs apart.
     */
    public function testRenderBrandsTheBarWithTheApplicationName(): void
    {
        // Arrange — TITLE is the constant an application defines for itself
        if (!defined('TITLE')) {
            define('TITLE', 'Acme Admin');
        }
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo'));

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('&#9881; ' . TITLE, $html);
    }

    /**
     * render() must not contain any onclick= attributes.
     *
     * Inline event handlers are blocked by strict CSP even with a nonce,
     * because CSP nonces apply only to <script> elements, not to event handlers.
     */
    public function testRenderHasNoInlineEventHandlers(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo'));
        $bar->addCollector(new TimeCollector());
        $bar->addCollector(new MemoryCollector());

        // Act
        $html = $bar->render();

        // Assert — no inline event handlers
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('onsubmit=', $html);
    }

    /**
     * render() must return an empty string when no collectors are registered.
     *
     * No collectors → no widget → nothing injected into the response.
     */
    public function testRenderReturnsEmptyStringWithNoCollectors(): void
    {
        // Arrange / Act
        $html = DebugBar::getInstance()->render();

        // Assert
        $this->assertSame('', $html);
    }

    /**
     * startTimer()/stopTimer() must delegate to the registered TimeCollector.
     */
    public function testTimerConvenienceMethods(): void
    {
        // Arrange
        $bar  = DebugBar::getInstance();
        $time = new TimeCollector();
        $bar->addCollector($time);

        // Act
        DebugBar::startTimer('test');
        usleep(5000); // 5ms
        DebugBar::stopTimer('test');

        $data = $time->collect();

        // Assert — named timer recorded
        $this->assertNotEmpty($data['named_timers']);
        $this->assertSame('test', $data['named_timers'][0]['name']);
        $this->assertGreaterThan(0, $data['named_timers'][0]['ms']);
    }

    // ── Collectors ────────────────────────────────────────────────────────────

    /**
     * MemoryCollector::collect() must return peak_human and current_human with
     * a size unit suffix.
     */
    public function testMemoryCollectorReturnsHumanReadableSizes(): void
    {
        // Arrange / Act
        $data = (new MemoryCollector())->collect();

        // Assert
        $this->assertArrayHasKey('peak_human',    $data);
        $this->assertArrayHasKey('current_human', $data);
        // Verify the value ends with a recognised unit
        $this->assertMatchesRegularExpression('/(B|KB|MB)$/', $data['peak_human']);
    }

    /**
     * SessionCollector must mask keys that match sensitive patterns.
     *
     * 'auth', 'password', 'token' keys must be replaced with '***'.
     */
    public function testSessionCollectorMasksSensitiveKeys(): void
    {
        // Arrange
        $_SESSION = [
            'username'     => 'alice',
            'auth'         => 'secret-auth-token',
            'logged'       => true,
            'user_password' => 'hunter2',
        ];
        $collector = new SessionCollector();

        // Act
        $data = $collector->collect();

        // Assert — sensitive keys are masked, non-sensitive ones are visible
        $this->assertSame('***', $data['data']['auth']);
        $this->assertSame('***', $data['data']['user_password']);
        $this->assertSame('alice', $data['data']['username']);

        // Cleanup
        $_SESSION = [];
    }

    /**
     * SessionCollector must report inactive when no session is active.
     */
    public function testSessionCollectorReportsNoSessionWhenInactive(): void
    {
        // Arrange — session not started (default for unit tests)
        // Ensure no session is active
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $collector = new SessionCollector();

        // Act
        $data = $collector->collect();

        // Assert
        $this->assertFalse($data['active']);
    }

    /**
     * TimeCollector::collect() must return request_ms as a non-negative float.
     */
    public function testTimeCollectorReturnsRequestMs(): void
    {
        // Arrange / Act
        $data = (new TimeCollector())->collect();

        // Assert
        $this->assertArrayHasKey('request_ms', $data);
        $this->assertGreaterThanOrEqual(0, $data['request_ms']);
    }

    /**
     * LogCollector::addEntry() / collect() must record entries up to the cap.
     */
    public function testLogCollectorRecordsAndCapsEntries(): void
    {
        // Arrange
        $collector = new LogCollector(maxEntries: 3);

        // Act — add 5 entries (cap is 3)
        for ($i = 1; $i <= 5; $i++) {
            $collector->addEntry('info', "message {$i}");
        }
        $data = $collector->collect();

        // Assert — only last 3 are kept
        $this->assertSame(3, $data['count']);
        $this->assertSame('message 3', $data['entries'][0]['message']);
    }

    /**
     * RouteCollector::setRoute() / collect() must return the stored route data.
     */
    public function testRouteCollectorStoresAndReturnsRouteData(): void
    {
        // Arrange
        $collector = new RouteCollector();
        $routeData = ['uri' => '/test', 'method' => 'GET', 'action' => 'HomeController@index'];

        // Act
        $collector->setRoute($routeData);
        $data = $collector->collect();

        // Assert
        $this->assertSame('/test', $data['uri']);
        $this->assertSame('GET', $data['method']);
    }

    /**
     * QueryCollector::collect() must sum total_ms across all logged queries.
     */
    public function testQueryCollectorSumsTotalTime(): void
    {
        // Arrange — mock Database with a query log
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('getQueryLog')->willReturn([
            ['sql' => 'SELECT 1', 'time' => 0.010, 'at' => microtime(true)],
            ['sql' => 'SELECT 2', 'time' => 0.020, 'at' => microtime(true)],
        ]);
        $collector = new QueryCollector($db);

        // Act
        $data = $collector->collect();

        // Assert
        $this->assertSame(2, $data['count']);
        $this->assertSame(30.0, $data['total_ms']); // 10ms + 20ms
    }

    // ── DebugBarMiddleware ────────────────────────────────────────────────────

    /**
     * DebugBarMiddleware must inject the widget just before </body>.
     *
     * This verifies the correct injection position — the widget must appear
     * inside the <body> tag, not after </html> or elsewhere.
     */
    public function testMiddlewareInjectsWidgetBeforeClosingBody(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('test'));

        $middleware = new DebugBarMiddleware($bar);
        $request    = $this->createMock(\Pramnos\Http\Request::class);
        $html       = '<html><body><p>Hello</p></body></html>';

        // Act
        $result = $middleware->handle($request, fn() => $html);

        // Assert — widget injected before </body>
        $this->assertStringContainsString('pramnos-debugbar', $result);
        $bodyClosePos  = strpos($result, '</body>');
        $widgetPos     = strpos($result, 'pramnos-debugbar');
        $this->assertLessThan($bodyClosePos, $widgetPos, 'Widget must appear before </body>');
    }

    /**
     * DebugBarMiddleware must pass non-HTML responses through unchanged.
     *
     * JSON API responses must not have the toolbar injected.
     */
    public function testMiddlewareDoesNotInjectIntoNonHtmlResponse(): void
    {
        // Arrange
        $bar        = DebugBar::getInstance();
        $middleware = new DebugBarMiddleware($bar);
        $request    = $this->createMock(\Pramnos\Http\Request::class);
        $json       = '{"status":"ok"}';

        // Act
        $result = $middleware->handle($request, fn() => $json);

        // Assert — response unchanged
        $this->assertSame($json, $result);
    }

    /**
     * DebugBarMiddleware must pass non-string responses through unchanged
     * (covers the early-return at line 29: !is_string || empty).
     * Redirects and raw integers from controllers must flow through untouched.
     */
    public function testMiddlewarePassesThroughNonStringResponse(): void
    {
        // Arrange
        $bar        = DebugBar::getInstance();
        $middleware = new DebugBarMiddleware($bar);
        $request    = $this->createMock(\Pramnos\Http\Request::class);

        // Act — non-string return (e.g. redirect returns null or integer)
        $result = $middleware->handle($request, fn() => null);

        // Assert — returned as-is, not modified or cast
        $this->assertNull($result);
    }

    /**
     * DebugBarMiddleware must pass empty-string responses through unchanged.
     * Empty responses cannot have a widget appended meaningfully.
     */
    public function testMiddlewarePassesThroughEmptyStringResponse(): void
    {
        // Arrange
        $bar        = DebugBar::getInstance();
        $middleware = new DebugBarMiddleware($bar);
        $request    = $this->createMock(\Pramnos\Http\Request::class);

        // Act
        $result = $middleware->handle($request, fn() => '');

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * DebugBarMiddleware must pass HTML responses through unchanged when the
     * DebugBar widget is empty (no collectors registered).
     * Covers line 39: when render() returns '' the response is not modified.
     */
    public function testMiddlewarePassesThroughWhenWidgetIsEmpty(): void
    {
        // Arrange — fresh DebugBar with no collectors → render() returns ''
        DebugBar::reset();
        $bar        = DebugBar::getInstance(); // no collectors added
        $middleware = new DebugBarMiddleware($bar);
        $request    = $this->createMock(\Pramnos\Http\Request::class);
        $html       = '<html><body><p>Content</p></body></html>';

        // Act
        $result = $middleware->handle($request, fn() => $html);

        // Assert — response unchanged because widget render returned empty string
        $this->assertSame($html, $result);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * The data island's payload, decoded.
     *
     * Reading it back the way the browser does — `textContent`, then
     * `JSON.parse` — is the only way to assert on what the toolbar will actually
     * receive rather than on a string that happens to contain the right words.
     *
     * @param  string $html The output of {@see DebugBar::render()}
     * @return array<string, mixed>
     */
    private function islandOf(string $html): array
    {
        $matched = preg_match(
            '#<div id="pramnos-debug-data" hidden>(.*?)</div>#s',
            $html,
            $m
        );
        $this->assertSame(1, $matched, 'the render carries a data island');

        $decoded = json_decode(html_entity_decode($m[1]), true);
        $this->assertIsArray($decoded, 'the island holds a JSON object');

        return $decoded;
    }

    private function makeMockCollector(string $name, array $collectResult = []): CollectorInterface
    {
        $col = $this->createMock(CollectorInterface::class);
        $col->method('name')->willReturn($name);
        $col->method('collect')->willReturn($collectResult ?: [$name => 'data']);
        return $col;
    }
}
