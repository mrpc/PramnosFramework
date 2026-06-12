<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Debug\DebugBar;
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
     * render() must return a non-empty string containing the debugbar div when
     * at least one collector is registered.
     */
    public function testRenderProducesHtmlWithDebugbarDiv(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo', ['count' => 1]));

        // Act
        $html = $bar->render();

        // Assert — signature elements present
        $this->assertStringContainsString('id="pramnos-debugbar"', $html);
        $this->assertStringContainsString('pdb-bar', $html);
    }

    /**
     * render($nonce) must add nonce="..." to every inline <style> and <script>
     * tag in the widget output.
     *
     * Without nonces a strict CSP would block the inline tags, breaking the
     * toolbar in CSP-protected applications.
     */
    public function testRenderAddsCspNonceToInlineTags(): void
    {
        // Arrange
        $bar   = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo'));
        $nonce = 'abc123testNonce';

        // Act
        $html = $bar->render($nonce);

        // Assert — every inline <style> and <script> must carry the nonce
        $this->assertStringContainsString("nonce=\"$nonce\"", $html);
        // At least two occurrences: one <style> + one <script> (JS init) + the main <script>
        $this->assertGreaterThanOrEqual(2, substr_count($html, "nonce=\"$nonce\""));
        // The nonce must NOT be injected when empty
        $htmlNoNonce = $bar->render('');
        $this->assertStringNotContainsString('nonce=', $htmlNoNonce);
    }

    /**
     * render() must include a DevPanel link in the toolbar bar.
     *
     * The link lets developers navigate to the DevPanel from any page without
     * memorising the URL.
     */
    public function testRenderIncludesDevPanelLink(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeMockCollector('demo'));

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('pdb-devpanel', $html);
        $this->assertStringContainsString('href="/devpanel"', $html);
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

    private function makeMockCollector(string $name, array $collectResult = []): CollectorInterface
    {
        $col = $this->createMock(CollectorInterface::class);
        $col->method('name')->willReturn($name);
        $col->method('collect')->willReturn($collectResult ?: [$name => 'data']);
        return $col;
    }
}
