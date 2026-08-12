<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Debug\RequestId;
use Pramnos\Debug\DebugBar;
use Pramnos\Debug\Collectors\CollectorInterface;
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
 * Unit tests for DebugBar's collector registry and the data island it emits.
 *
 * This file used to be forty tests asserting on generated HTML — tab labels,
 * panel markup, info-strip chips — because DebugBar drew the toolbar itself.
 * It no longer does: there is one renderer, `DebugBarAsset::source()`, and this
 * class collects data and hands it over. Assertions on drawn HTML moved to the
 * JavaScript tests, which run that renderer for real:
 *
 *   - `tests/js/spa-debug-panel.test.js`  — the module delivery, and every tab
 *   - `tests/js/debugbar-ajax.test.js`    — the data island, and the transports
 *
 * What is left here is what PHP still owns: which collectors are registered,
 * that a collector which throws is reported rather than fatal, and that
 * everything collected reaches the island under the key the renderer reads.
 *
 * These tests do not require a database or HTTP server.
 */
class DebugBarTest extends TestCase
{
    protected function setUp(): void
    {
        // Request ids are process-wide and change Logger's output shape while
        // active. A test that activated them must not decide how another test's
        // log lines are written — which is exactly what happened once.
        RequestId::reset();
        // Always start with a fresh singleton so tests do not bleed into each other.
        DebugBar::reset();
    }

    protected function tearDown(): void
    {
        RequestId::reset();
        DebugBar::reset();
        // Reset environment variables that may have been set by tests.
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV'], $_ENV['ENVIRONMENT'], $_SERVER['ENVIRONMENT']);
    }

    // ── getCollectors() ───────────────────────────────────────────────────────

    /**
     * getCollectors() must return all registered collectors indexed by their name.
     *
     * This is the primary way callers enumerate which collectors are active —
     * it must faithfully mirror the internal map rather than returning a subset.
     */
    public function testGetCollectorsReturnsAllRegisteredCollectors(): void
    {
        // Arrange
        $bar  = DebugBar::getInstance();
        $col1 = $this->makeCollector('alpha');
        $col2 = $this->makeCollector('beta');

        // Act
        $bar->addCollector($col1);
        $bar->addCollector($col2);
        $all = $bar->getCollectors();

        // Assert — both collectors present, keyed by name
        $this->assertArrayHasKey('alpha', $all);
        $this->assertArrayHasKey('beta', $all);
        $this->assertSame($col1, $all['alpha']);
        $this->assertSame($col2, $all['beta']);
        $this->assertCount(2, $all);
    }

    /**
     * getCollectors() on a fresh DebugBar must return an empty array.
     *
     * Callers should be able to safely iterate the result without an
     * "undefined key" error even when nothing has been registered.
     */
    public function testGetCollectorsReturnsEmptyArrayWhenNoneRegistered(): void
    {
        // Arrange / Act
        $all = DebugBar::getInstance()->getCollectors();

        // Assert
        $this->assertSame([], $all);
    }

    /**
     * addCollector() must return the DebugBar instance for fluent chaining.
     *
     * The fluent interface lets service providers register multiple collectors
     * in a single expression without intermediate variables.
     */
    public function testAddCollectorReturnsFluentInterface(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();

        // Act
        $returned = $bar->addCollector($this->makeCollector('x'));

        // Assert — fluent interface: same instance returned
        $this->assertSame($bar, $returned);
    }

    /**
     * getCollector() must return null for an unknown collector name.
     *
     * Callers that optionally use a collector (e.g. recordMigration) rely on
     * the null return to skip the operation rather than throwing.
     */
    public function testGetCollectorReturnsNullForUnknownName(): void
    {
        // Arrange / Act
        $result = DebugBar::getInstance()->getCollector('does-not-exist');

        // Assert
        $this->assertNull($result);
    }

    // ── Timer methods without a TimeCollector ────────────────────────────────

    /**
     * startTimer() must be a no-op when no TimeCollector is registered.
     *
     * The null-safe operator ($this->timeCollector?->startTimer()) must not
     * throw when timeCollector is null.
     */
    public function testStartTimerIsNoopWithoutTimeCollector(): void
    {
        // Arrange — no TimeCollector registered

        // Act / Assert — must not throw
        DebugBar::startTimer('orphan-timer');
        $this->assertTrue(true);
    }

    /**
     * stopTimer() must be a no-op when no TimeCollector is registered.
     */
    public function testStopTimerIsNoopWithoutTimeCollector(): void
    {
        // Arrange — no TimeCollector registered

        // Act / Assert — must not throw
        DebugBar::stopTimer('orphan-timer');
        $this->assertTrue(true);
    }
    // ── The data island ──────────────────────────────────────────────────────

    /**
     * A collector that throws is reported in the island, not allowed to break it.
     *
     * A badly-written collector must never be the reason a page fails to render,
     * and it must not fail silently either: the renderer shows "This collector
     * failed", because a blank tab reads as "nothing happened here".
     */
    public function testACollectorThatThrowsIsReportedInTheIsland(): void
    {
        // Arrange — a collector whose collect() throws
        $bar  = DebugBar::getInstance();
        $evil = $this->createMock(CollectorInterface::class);
        $evil->method('name')->willReturn('broken');
        $evil->method('collect')->willThrowException(new \RuntimeException('collect failed'));
        $bar->addCollector($evil);

        // Act — must not throw
        $payload = $this->islandOf($bar->render());

        // Assert
        $this->assertSame('collect failed', $payload['broken']['error']);
    }

    /**
     * Every registered collector reaches the island under its own name.
     *
     * This is the whole contract now that the toolbar has one renderer: PHP
     * collects, the island carries, and the script draws. The tabs, their
     * labels and each table are the renderer's business and are driven for real
     * in `tests/js/spa-debug-panel.test.js` and `tests/js/debugbar-ajax.test.js`
     * — asserting on generated HTML here is what let two renderers drift apart.
     */
    public function testEveryRegisteredCollectorReachesTheIsland(): void
    {
        // Arrange — one of each built-in collector, with something to say
        $bar = DebugBar::getInstance();

        $route = new RouteCollector();
        $route->setRoute(['method' => 'GET', 'uri' => '/home', 'action' => 'HomeController@index']);

        $logs = new LogCollector();
        $logs->addEntry('error', 'boom');

        $views = new ViewsCollector();
        $views->record('home', 'home.html.php', 12.0);

        $models = new ModelsCollector();
        $models->record(\stdClass::class, 'things', 'load', 7);

        $migrations = new MigrationsCollector();
        $migrations->record('2026_08_12_000001_add_thing', 4.5);

        $exceptions = new ExceptionsCollector();
        $exceptions->record(new \RuntimeException('kaboom'));

        $bar->addCollector(new TimeCollector())
            ->addCollector(new MemoryCollector())
            ->addCollector(new SessionCollector())
            ->addCollector($route)
            ->addCollector($logs)
            ->addCollector($views)
            ->addCollector($models)
            ->addCollector($migrations)
            ->addCollector($exceptions);

        // Act
        $payload = $this->islandOf($bar->render());

        // Assert — each collector's own data, under the key the renderer looks for
        $this->assertArrayHasKey('timers', $payload);
        $this->assertSame('/home', $payload['route']['uri']);
        $this->assertSame('boom', $payload['logs']['entries'][0]['message']);
        $this->assertSame('home', $payload['views']['views'][0]['view']);
        $this->assertSame('things', $payload['models']['operations'][0]['table']);
        $this->assertSame(
            '2026_08_12_000001_add_thing',
            $payload['migrations']['this_request'][0]['slug']
        );
        $this->assertSame('RuntimeException', $payload['exceptions']['items'][0]['class']);
        // Memory is the collector's own array of sizes. The number the bar shows
        // comes from `request.memory`, which no collector can overwrite — reading
        // the top-level key printed "[object Object]MB".
        $this->assertArrayHasKey('peak_human', $payload['memory']);
        // assertIsNumeric, not assertIsFloat: JSON has one number type, so a
        // peak that lands on a whole megabyte comes back as an int — which is
        // how much memory the rest of the suite happened to leave behind.
        $this->assertIsNumeric($payload['request']['memory']);
        $this->assertGreaterThan(0, $payload['request']['memory']);
    }

    /**
     * A collector the old renderer had no tab for still travels.
     *
     * `memory` used to be inline-only and `route` a badge, so a bar with only one
     * of them rendered nothing at all. Which collectors deserve a tab is now the
     * renderer's decision, made from the data — so the data is always sent.
     */
    public function testACollectorWithNoTabOfItsOwnStillTravels(): void
    {
        // Arrange — the case that used to render an empty string
        $bar = DebugBar::getInstance();
        $bar->addCollector(new MemoryCollector());

        // Act
        $html = $bar->render();

        // Assert
        $this->assertNotSame('', $html);
        $this->assertArrayHasKey('peak_human', $this->islandOf($html)['memory']);
    }

    /**
     * recordMigration() must reach both the timeline and the Migrations tab.
     *
     * One call, two places: a migration that ran during a request is a segment
     * of that request's time *and* a row in its own list. Recording it in one
     * and not the other is how a 2-second request comes to have no explanation.
     */
    public function testRecordMigrationFeedsBothTheTimelineAndTheList(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new TimeCollector())
            ->addCollector(new MigrationsCollector());

        // Act
        DebugBar::recordMigration('2026_08_12_000002_add_column', 31.0);
        $payload = $this->islandOf($bar->render());

        // Assert — the list
        $this->assertSame(
            '2026_08_12_000002_add_column',
            $payload['migrations']['this_request'][0]['slug']
        );
        // And the timeline segment, named so it is recognisable among the rest
        $names = array_column($payload['timers']['named_timers'], 'name');
        $this->assertContains('migration:2026_08_12_000002_add_column', $names);
    }

    /**
     * recordMigration() must be a no-op when neither collector is registered.
     *
     * Migrations can run before the toolbar is booted, and on installations
     * where it never boots at all.
     */
    public function testRecordMigrationIsANoopWithoutCollectors(): void
    {
        // Arrange — no collectors

        // Act / Assert — must not throw
        DebugBar::recordMigration('2026_08_12_000003_orphan', 1.0);
        $this->assertSame([], DebugBar::getInstance()->getCollectors());
    }

    // ── Reset ────────────────────────────────────────────────────────────────

    /**
     * reset() must discard the singleton so the next getInstance() returns a new
     * DebugBar with no collectors.
     *
     * This is the canonical way tests isolate themselves from each other.
     */
    public function testResetCreatesNewInstanceWithNoCollectors(): void
    {
        // Arrange — register a collector on the first instance
        $first = DebugBar::getInstance();
        $first->addCollector($this->makeCollector('should-disappear'));

        // Act
        DebugBar::reset();
        $second = DebugBar::getInstance();

        // Assert — new instance is different and has no collectors
        $this->assertNotSame($first, $second);
        $this->assertSame([], $second->getCollectors());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The data island's payload, decoded the way the browser reads it.
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

    /**
     * Create a minimal mock CollectorInterface with the given name and optional
     * collect() return value.
     */
    private function makeCollector(string $name, array $data = []): CollectorInterface
    {
        $col = $this->createMock(CollectorInterface::class);
        $col->method('name')->willReturn($name);
        $col->method('collect')->willReturn($data ?: [$name => 'data']);
        return $col;
    }
}
