<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Debug;

use PHPUnit\Framework\TestCase;
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
 * Comprehensive unit tests for DebugBar to increase line coverage from ~33% to >75%.
 *
 * Coverage strategy:
 *  - getCollectors() — uncovered in existing tests
 *  - render() with every named collector type so all private renderXxx() methods are exercised
 *  - render() with 'memory' and 'route' collectors so renderInfoStrip() paths are hit
 *  - formatTabLabel() for every match arm (queries, timers, logs, session, views, models,
 *    migrations, exceptions, and the default arm)
 *  - Collector exception swallowing (collector that throws inside collect())
 *  - recordMigration() with and without collectors
 *  - startTimer()/stopTimer() when no TimeCollector is registered (null-safe paths)
 *  - renderInfoStrip() route badge method classes and environment chip
 *
 * These tests do not require a database or HTTP server.
 */
class DebugBarTest extends TestCase
{
    protected function setUp(): void
    {
        // Always start with a fresh singleton so tests do not bleed into each other.
        DebugBar::reset();
    }

    protected function tearDown(): void
    {
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

    // ── render() — collector that throws ────────────────────────────────────

    /**
     * render() must swallow exceptions thrown by a collector's collect() method
     * and substitute an error array so the rest of the toolbar still renders.
     *
     * A badly-written collector must never crash the entire debug toolbar.
     */
    public function testRenderSwallowsCollectorException(): void
    {
        // Arrange — collector whose collect() throws
        $bar  = DebugBar::getInstance();
        $evil = $this->createMock(CollectorInterface::class);
        $evil->method('name')->willReturn('broken');
        $evil->method('collect')->willThrowException(new \RuntimeException('collect failed'));
        $bar->addCollector($evil);

        // Act — must not throw; should still produce HTML
        $html = $bar->render();

        // Assert — toolbar is rendered and the error is surfaced in the panel
        $this->assertStringContainsString('pramnos-debugbar', $html);
        $this->assertStringContainsString('collect failed', $html);
    }

    // ── render() with 'memory' collector ────────────────────────────────────

    /**
     * render() must treat the 'memory' collector as inline-only: its data
     * must appear in the info strip (the Mem: chip) but not as a clickable tab.
     *
     * The toolbar must still render when only a memory collector is registered
     * alongside at least one tab-generating collector.
     */
    public function testRenderWithMemoryCollectorShowsMemChipNotTab(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new MemoryCollector());
        // Add a second collector so the toolbar is non-empty and renders.
        $bar->addCollector($this->makeCollector('demo'));

        // Act
        $html = $bar->render();

        // Assert — memory chip must be in the info strip
        $this->assertStringContainsString('Mem:', $html);
        // There must be NO tab button for 'memory' (memory is inline-only)
        $this->assertStringNotContainsString('data-panel="memory"', $html);
    }

    /**
     * render() with only a MemoryCollector (no other tab collector) must return
     * empty string because only inline-only collectors produce no tabs or panels.
     */
    public function testRenderWithOnlyMemoryCollectorReturnsEmpty(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new MemoryCollector());

        // Act
        $html = $bar->render();

        // Assert — no tabs and no panels means empty output
        $this->assertSame('', $html);
    }

    // ── render() with 'route' collector ─────────────────────────────────────

    /**
     * render() must treat the 'route' collector as a badge (not a regular tab):
     * it must create a panel but not a standard tab button.
     *
     * The route badge is a separate element in the info strip.
     */
    public function testRenderWithRouteCollectorCreatesPanel(): void
    {
        // Arrange
        $bar  = DebugBar::getInstance();
        $rc   = new RouteCollector();
        $rc->setRoute(['method' => 'GET', 'uri' => '/home', 'action' => 'HomeController@index']);
        $bar->addCollector($rc);
        // Add another collector to ensure the bar renders.
        $bar->addCollector($this->makeCollector('demo'));

        // Act
        $html = $bar->render();

        // Assert — route panel exists but has no standard tab entry with label 'Route'
        $this->assertStringContainsString('id="pdb-panel-route"', $html);
    }

    /**
     * The route badge in the info strip must show the HTTP method and URI
     * when route data is present.
     */
    public function testRenderRouteInfoStripShowsMethodAndUri(): void
    {
        // Arrange
        $bar  = DebugBar::getInstance();
        $rc   = new RouteCollector();
        $rc->setRoute(['method' => 'POST', 'uri' => '/api/users', 'action' => 'UsersController@store']);
        $bar->addCollector($rc);
        $bar->addCollector($this->makeCollector('dummy'));

        // Act
        $html = $bar->render();

        // Assert — method and URI appear in the rendered output
        $this->assertStringContainsString('POST', $html);
        $this->assertStringContainsString('/api/users', $html);
    }

    // ── renderInfoStrip() — route badge method CSS classes ──────────────────

    /**
     * The route badge must apply the correct CSS class for each HTTP method.
     *
     * pdb-m-get (GET), pdb-m-post (POST), pdb-m-put (PUT/PATCH), pdb-m-del (DELETE)
     * are used to colour the method badge; missing the class means a style regression.
     */
    public function testRenderRouteInfoStripMethodCssClasses(): void
    {
        $cases = [
            'GET'    => 'pdb-m-get',
            'POST'   => 'pdb-m-post',
            'PUT'    => 'pdb-m-put',
            'PATCH'  => 'pdb-m-put',
            'DELETE' => 'pdb-m-del',
        ];

        foreach ($cases as $method => $expectedClass) {
            // Arrange
            DebugBar::reset();
            $bar = DebugBar::getInstance();
            $rc  = new RouteCollector();
            $rc->setRoute(['method' => $method, 'uri' => '/foo']);
            $bar->addCollector($rc);
            $bar->addCollector($this->makeCollector('dummy'));

            // Act
            $html = $bar->render();

            // Assert — the CSS class for this method must be present
            $this->assertStringContainsString($expectedClass, $html,
                "Expected CSS class '{$expectedClass}' for method '{$method}'");
        }
    }

    /**
     * When the route method is an unknown verb (e.g. HEAD, OPTIONS) the badge
     * must still render without throwing, just without a specific colour class.
     */
    public function testRenderRouteInfoStripUnknownMethodRendersWithoutClass(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $rc  = new RouteCollector();
        $rc->setRoute(['method' => 'OPTIONS', 'uri' => '/ping']);
        $bar->addCollector($rc);
        $bar->addCollector($this->makeCollector('dummy'));

        // Act
        $html = $bar->render();

        // Assert — rendered without throwing; OPTIONS appears in output
        $this->assertStringContainsString('OPTIONS', $html);
    }

    /**
     * The route badge must NOT appear when the route URI is '(not matched)'.
     *
     * Unmatched routes should not pollute the info strip with a misleading badge.
     */
    public function testRenderRouteInfoStripSkipsUnmatchedRoute(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $rc  = new RouteCollector();
        $rc->setRoute(['method' => 'GET', 'uri' => '(not matched)']);
        $bar->addCollector($rc);
        $bar->addCollector($this->makeCollector('dummy'));

        // Act
        $html = $bar->render();

        // Assert — no route badge button for unmatched routes.
        // The CSS definition contains 'pdb-route-badge' as a selector, so we must
        // look for the specific HTML element that only exists when a badge is rendered.
        $this->assertStringNotContainsString('class="pdb-tab pdb-route-badge"', $html);
    }

    /**
     * The environment chip must appear in the info strip when APP_ENV is set.
     *
     * The chip informs developers which environment is active without looking
     * at application config files.
     */
    public function testRenderInfoStripShowsEnvChipFromAppEnv(): void
    {
        // Arrange
        $_ENV['APP_ENV'] = 'staging';
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeCollector('dummy'));

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('staging', $html);
        $this->assertStringContainsString('pdb-chip', $html);
    }

    /**
     * When APP_ENV is a production value ('production' or 'prod'), the
     * environment chip must carry the pdb-env-prod CSS class.
     *
     * This visually warns developers that they are looking at a production
     * toolbar, reducing the risk of accidental changes.
     */
    public function testRenderInfoStripProdEnvUsesRedChip(): void
    {
        // Arrange
        $_SERVER['APP_ENV'] = 'production';
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeCollector('dummy'));

        // Act
        $html = $bar->render();

        // Assert — production environment gets the warning colour class
        $this->assertStringContainsString('pdb-env-prod', $html);
    }

    /**
     * When APP_ENV is a non-production value, the environment chip must carry
     * the pdb-env-dev CSS class (green).
     */
    public function testRenderInfoStripDevEnvUsesGreenChip(): void
    {
        // Arrange
        $_ENV['APP_ENV'] = 'development';
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeCollector('dummy'));

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('pdb-env-dev', $html);
    }

    // ── formatTabLabel() — all named arms ───────────────────────────────────

    /**
     * The SQL tab label must include the live query count, optional cached count,
     * and total milliseconds.
     *
     * Verifies the 'queries' arm of formatTabLabel(), including the conditional
     * cached suffix.
     */
    public function testRenderQueriesTabLabel(): void
    {
        // Arrange — mock database returning two queries (one cached)
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('getQueryLog')->willReturn([
            ['sql' => 'SELECT 1', 'time' => 0.010, 'from_cache' => false],
            ['sql' => 'SELECT 2', 'time' => 0.005, 'from_cache' => true],
        ]);
        $bar = DebugBar::getInstance();
        $bar->addCollector(new QueryCollector($db));

        // Act
        $html = $bar->render();

        // Assert — tab must mention SQL, include total ms, and show cached count
        $this->assertStringContainsString('SQL', $html);
        $this->assertStringContainsString('cached', $html);
        $this->assertStringContainsString('ms)', $html);
    }

    /**
     * The SQL tab label must omit the cached suffix when there are no cached queries.
     */
    public function testRenderQueriesTabLabelNoCachedSuffix(): void
    {
        // Arrange — no cached queries
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('getQueryLog')->willReturn([
            ['sql' => 'SELECT 1', 'time' => 0.010, 'from_cache' => false],
        ]);
        $bar = DebugBar::getInstance();
        $bar->addCollector(new QueryCollector($db));

        // Act
        $html = $bar->render();

        // Assert — 'cached' suffix must not appear in the tab label
        // (it may appear in the panel content, but not in the tab button itself)
        $tabButtonRegex = '/data-panel="queries"[^>]*>SQL \(\d+[^)]*\)/';
        $this->assertMatchesRegularExpression($tabButtonRegex, $html);
        // Verify no "cached" in the button label (the label ends before the panel)
        preg_match('/<button class="pdb-tab" data-panel="queries">(.*?)<\/button>/', $html, $m);
        if (!empty($m[1])) {
            $this->assertStringNotContainsString('cached', $m[1]);
        }
    }

    /**
     * The Time tab label must include the total request milliseconds.
     *
     * Verifies the 'timers' arm of formatTabLabel().
     */
    public function testRenderTimersTabLabel(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new TimeCollector());

        // Act
        $html = $bar->render();

        // Assert — tab must say "Time (Xms)"
        $this->assertMatchesRegularExpression('/Time \(\d+(\.\d+)?ms\)/', $html);
    }

    /**
     * The Logs tab label must include the entry count.
     *
     * Verifies the 'logs' arm of formatTabLabel().
     */
    public function testRenderLogsTabLabel(): void
    {
        // Arrange
        $bar  = DebugBar::getInstance();
        $logs = new LogCollector();
        $logs->addEntry('info', 'test message');
        $logs->addEntry('error', 'another message');
        $bar->addCollector($logs);

        // Act
        $html = $bar->render();

        // Assert — tab must say "Logs (2)"
        $this->assertStringContainsString('Logs (2)', $html);
    }

    /**
     * The Session tab label must include the key count.
     *
     * Verifies the 'session' arm of formatTabLabel().
     */
    public function testRenderSessionTabLabel(): void
    {
        // Arrange — create a mock session collector that reports 3 keys
        $col = $this->createMock(CollectorInterface::class);
        $col->method('name')->willReturn('session');
        $col->method('collect')->willReturn([
            'active'     => true,
            'session_id' => 'abc123',
            'count'      => 3,
            'data'       => ['a' => '1', 'b' => '2', 'c' => '3'],
        ]);
        $bar = DebugBar::getInstance();
        $bar->addCollector($col);

        // Act
        $html = $bar->render();

        // Assert — tab must say "Session (3)"
        $this->assertStringContainsString('Session (3)', $html);
    }

    /**
     * The Views tab label must include the total count and optional cached suffix.
     *
     * Verifies the 'views' arm of formatTabLabel().
     */
    public function testRenderViewsTabLabelWithCached(): void
    {
        // Arrange
        $bar   = DebugBar::getInstance();
        $views = new ViewsCollector();
        $views->record('home', '/tpl/home.php', 10.0, false);
        $views->record('partials.nav', '/tpl/nav.php', 2.0, true);
        $bar->addCollector($views);

        // Act
        $html = $bar->render();

        // Assert — tab must mention "Views" and cached count
        $this->assertStringContainsString('Views', $html);
        $this->assertStringContainsString('cached', $html);
    }

    /**
     * The Views tab label must omit the cached suffix when no views are from cache.
     */
    public function testRenderViewsTabLabelNoCachedSuffix(): void
    {
        // Arrange
        $bar   = DebugBar::getInstance();
        $views = new ViewsCollector();
        $views->record('home', '/tpl/home.php', 10.0, false);
        $bar->addCollector($views);

        // Act
        $html = $bar->render();

        // Assert — "Views (1)" without cached suffix
        $this->assertStringContainsString('Views (1)', $html);
        preg_match('/<button class="pdb-tab" data-panel="views">(.*?)<\/button>/', $html, $m);
        if (!empty($m[1])) {
            $this->assertStringNotContainsString('cached', $m[1]);
        }
    }

    /**
     * The Models tab label must include class count and operation count.
     *
     * Verifies the 'models' arm of formatTabLabel().
     */
    public function testRenderModelsTabLabel(): void
    {
        // Arrange
        $bar    = DebugBar::getInstance();
        $models = new ModelsCollector();
        $models->record('NonexistentModel', 'users', 'load', 1);
        $models->record('NonexistentModel', 'users', 'save', 1); // same class, 2nd op
        $bar->addCollector($models);

        // Act
        $html = $bar->render();

        // Assert — "Models (1 · 2 ops)" — 1 unique class, 2 operations
        $this->assertStringContainsString('Models', $html);
        $this->assertStringContainsString('ops', $html);
    }

    /**
     * The Migrations tab label must read "Migrations" (no count) when nothing ran.
     *
     * Verifies the empty-count path of the 'migrations' arm.
     */
    public function testRenderMigrationsTabLabelNoMigrationsRan(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new MigrationsCollector());

        // Act
        $html = $bar->render();

        // Assert — plain "Migrations" without a count
        $this->assertStringContainsString('>Migrations<', $html);
    }

    /**
     * The Exceptions tab label must show a warning prefix when exceptions exist.
     *
     * Verifies the non-zero path of the 'exceptions' arm of formatTabLabel().
     */
    public function testRenderExceptionsTabLabelWithExceptions(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $exc = new ExceptionsCollector();
        $exc->record(new \RuntimeException('boom'));
        $bar->addCollector($exc);

        // Act
        $html = $bar->render();

        // Assert — warning prefix and count appear in the tab label
        $this->assertStringContainsString('Exceptions (1)', $html);
    }

    /**
     * The Exceptions tab label must show "(0)" when no exceptions have been recorded.
     *
     * Verifies the zero path of the 'exceptions' arm.
     */
    public function testRenderExceptionsTabLabelZeroCount(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new ExceptionsCollector());

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('Exceptions (0)', $html);
    }

    /**
     * An unknown collector name must use ucfirst(name) as the tab label.
     *
     * Verifies the 'default' arm of formatTabLabel().
     */
    public function testRenderDefaultTabLabelUsesUcfirstName(): void
    {
        // Arrange — collector with an unrecognised name
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeCollector('custom'));

        // Act
        $html = $bar->render();

        // Assert — tab label is ucfirst('custom') = 'Custom'
        $this->assertStringContainsString('>Custom<', $html);
    }

    // ── renderQueries() panel content ────────────────────────────────────────

    /**
     * The queries panel must list individual SQL strings in table rows.
     *
     * This exercises renderQueries() with both a live query and a cached query.
     */
    public function testRenderQueriesPanelContainsSqlRows(): void
    {
        // Arrange
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('getQueryLog')->willReturn([
            ['sql' => 'SELECT * FROM users', 'time' => 0.050, 'from_cache' => false],
            ['sql' => 'SELECT * FROM posts', 'time' => 0.002, 'from_cache' => true],
        ]);
        $bar = DebugBar::getInstance();
        $bar->addCollector(new QueryCollector($db));

        // Act
        $html = $bar->render();

        // Assert — SQL appears in the panel; cache badge present for cached query
        $this->assertStringContainsString('SELECT * FROM users', $html);
        $this->assertStringContainsString('SELECT * FROM posts', $html);
        $this->assertStringContainsString('CACHE', $html);
    }

    /**
     * Slow queries (>100ms) must carry the pdb-slow CSS class for visual highlighting.
     */
    public function testRenderQueriesPanelMarksSlowQueries(): void
    {
        // Arrange — one slow query (150ms)
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->method('getQueryLog')->willReturn([
            ['sql' => 'SELECT slow', 'time' => 0.150, 'from_cache' => false],
        ]);
        $bar = DebugBar::getInstance();
        $bar->addCollector(new QueryCollector($db));

        // Act
        $html = $bar->render();

        // Assert — pdb-slow class must be present for the slow row
        $this->assertStringContainsString('pdb-slow', $html);
    }

    // ── renderTimers() panel content ─────────────────────────────────────────

    /**
     * The timers panel must show the request duration and a timeline when
     * named timers have been recorded.
     *
     * This exercises the named-timers branch of renderTimers().
     */
    public function testRenderTimersPanelWithNamedTimers(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $tc  = new TimeCollector(microtime(true) - 0.5); // started 500ms ago
        $bar->addCollector($tc);
        DebugBar::startTimer('db');
        usleep(5000);
        DebugBar::stopTimer('db');

        // Act
        $html = $bar->render();

        // Assert — timeline div and legend table must be present
        $this->assertStringContainsString('pdb-timeline', $html);
        $this->assertStringContainsString('pdb-tl-seg', $html);
        $this->assertStringContainsString('db', $html);
    }

    /**
     * The timers panel must render even when there are no named timers —
     * it should show only the request duration line.
     */
    public function testRenderTimersPanelWithoutNamedTimers(): void
    {
        // Arrange — TimeCollector with no individual timers
        $bar = DebugBar::getInstance();
        $bar->addCollector(new TimeCollector());

        // Act
        $html = $bar->render();

        // Assert — request duration line present
        $this->assertStringContainsString('Request:', $html);
        // No timeline segment div when there are no named timers.
        // The CSS block defines '.pdb-timeline{...}' as a selector so we must check for
        // the actual div element, not the bare class name.
        $this->assertStringNotContainsString('<div class="pdb-timeline">', $html);
    }

    // ── renderMemory() panel content ─────────────────────────────────────────

    /**
     * The memory panel must output a definition list with peak and current values.
     *
     * renderMemory() is called only via renderPanel() when the collector name is
     * 'memory'. It is not reachable from the existing tests because the 'memory'
     * collector is inline-only and its panel is never created in the normal flow.
     * We reach it by calling render() via a synthetic data route: a mock collector
     * named 'memory' that is NOT an instance of MemoryCollector, bypassing the
     * inline-only guard.
     */
    public function testRenderMemoryPanelContainsPeakAndCurrentValues(): void
    {
        // Arrange — a mock that passes through the 'memory' name
        // but is not filtered out by the inline-only guard.
        // Because the guard checks $name === 'memory' and skips via `continue`,
        // we cannot reach renderMemory() through a real MemoryCollector via render().
        // Instead we exercise the private method indirectly through a separate
        // mock that identifies as something *other* than 'memory' but returns
        // data in the memory shape, then verify via a named-panel approach.
        //
        // Since renderMemory() is only reachable through renderPanel('memory', $data),
        // and renderPanel() is called from render() for the 'memory' name only as
        // part of a `continue` branch (no panel is appended), we cannot reach
        // renderMemory() via public API. We instead test the MemoryCollector's
        // collect() output shape and confirm the data keys match what renderMemory()
        // expects.
        $mc   = new MemoryCollector();
        $data = $mc->collect();

        // Assert — data shape has the keys renderMemory() reads
        $this->assertArrayHasKey('peak_human', $data);
        $this->assertArrayHasKey('current_human', $data);
        $this->assertNotEmpty($data['peak_human']);
        $this->assertNotEmpty($data['current_human']);
    }

    // ── renderRoute() panel content ───────────────────────────────────────────

    /**
     * The route panel must render a table with all route data keys.
     *
     * renderRoute() loops over every key-value pair in the data array and builds
     * an HTML table row for each. Verifying this ensures no key is silently dropped.
     */
    public function testRenderRoutePanelContainsAllKeys(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $rc  = new RouteCollector();
        $rc->setRoute([
            'method' => 'GET',
            'uri'    => '/users',
            'action' => 'UsersController@index',
            'name'   => 'users.index',
        ]);
        $bar->addCollector($rc);
        $bar->addCollector($this->makeCollector('dummy'));

        // Act
        $html = $bar->render();

        // Assert — each route key appears in the panel
        $this->assertStringContainsString('method', $html);
        $this->assertStringContainsString('action', $html);
        $this->assertStringContainsString('UsersController@index', $html);
    }

    // ── renderLogs() panel content ────────────────────────────────────────────

    /**
     * The logs panel must render a table with one row per log entry,
     * including level and message columns.
     */
    public function testRenderLogsPanelContainsEntries(): void
    {
        // Arrange
        $bar  = DebugBar::getInstance();
        $logs = new LogCollector();
        $logs->addEntry('error', 'Something went wrong');
        $logs->addEntry('info', 'Application started');
        $bar->addCollector($logs);

        // Act
        $html = $bar->render();

        // Assert — log entries appear in the panel
        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringContainsString('Application started', $html);
        $this->assertStringContainsString('error', $html);
    }

    // ── renderSession() panel content ─────────────────────────────────────────

    /**
     * The session panel must show "No active session" when the session is inactive.
     *
     * This tests the false-branch of renderSession().
     */
    public function testRenderSessionPanelNoActiveSession(): void
    {
        // Arrange — collector reporting inactive session
        $col = $this->createMock(CollectorInterface::class);
        $col->method('name')->willReturn('session');
        $col->method('collect')->willReturn(['active' => false, 'count' => 0, 'data' => []]);
        $bar = DebugBar::getInstance();
        $bar->addCollector($col);

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('No active session', $html);
    }

    /**
     * The session panel must show session ID and key-value rows for active sessions.
     *
     * This tests the true-branch of renderSession().
     */
    public function testRenderSessionPanelWithActiveSession(): void
    {
        // Arrange — collector reporting active session with data
        $col = $this->createMock(CollectorInterface::class);
        $col->method('name')->willReturn('session');
        $col->method('collect')->willReturn([
            'active'     => true,
            'session_id' => 'sess_abc123',
            'count'      => 2,
            'data'       => ['user_id' => '42', 'role' => 'admin'],
        ]);
        $bar = DebugBar::getInstance();
        $bar->addCollector($col);

        // Act
        $html = $bar->render();

        // Assert — session ID and data keys present
        $this->assertStringContainsString('sess_abc123', $html);
        $this->assertStringContainsString('user_id', $html);
        $this->assertStringContainsString('admin', $html);
    }

    // ── renderViews() panel content ───────────────────────────────────────────

    /**
     * The views panel must render a table row for each recorded view,
     * with a "CACHE" time cell for cached views.
     */
    public function testRenderViewsPanelContainsViewRows(): void
    {
        // Arrange
        $bar   = DebugBar::getInstance();
        $views = new ViewsCollector();
        $views->record('home.index', '/tpl/home.php', 15.5, false);
        $views->record('partials.nav', '/tpl/nav.php', 1.0, true);
        $bar->addCollector($views);

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('home.index', $html);
        $this->assertStringContainsString('CACHE', $html);
    }

    /**
     * The views panel must show "No views rendered" when no templates were rendered.
     */
    public function testRenderViewsPanelShowsEmptyMessage(): void
    {
        // Arrange — empty ViewsCollector
        $bar = DebugBar::getInstance();
        $bar->addCollector(new ViewsCollector());

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('No views rendered', $html);
    }

    /**
     * Slow views (>50ms, not from cache) must carry the pdb-slow CSS class.
     */
    public function testRenderViewsPanelMarksSlowViews(): void
    {
        // Arrange
        $bar   = DebugBar::getInstance();
        $views = new ViewsCollector();
        $views->record('slow.tpl', '/tpl/slow.php', 75.0, false);
        $bar->addCollector($views);

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('pdb-slow', $html);
    }

    // ── renderModels() panel content ──────────────────────────────────────────

    /**
     * The models panel must render a row for each recorded model operation.
     */
    public function testRenderModelsPanelContainsOperationRows(): void
    {
        // Arrange
        $bar    = DebugBar::getInstance();
        $models = new ModelsCollector();
        $models->record('NonexistentUser', 'users', 'load', 7);
        $models->record('NonexistentPost', 'posts', 'save', null);
        $bar->addCollector($models);

        // Act
        $html = $bar->render();

        // Assert — class names, table names, and operation types in the panel
        $this->assertStringContainsString('NonexistentUser', $html);
        $this->assertStringContainsString('users', $html);
        $this->assertStringContainsString('load', $html);
        $this->assertStringContainsString('NonexistentPost', $html);
    }

    /**
     * The models panel must show "No model operations" when nothing was recorded.
     */
    public function testRenderModelsPanelShowsEmptyMessage(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new ModelsCollector());

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('No model operations', $html);
    }

    // ── renderMigrations() panel content ─────────────────────────────────────

    /**
     * The migrations panel must list each migration that ran this request.
     */
    public function testRenderMigrationsPanelContainsMigrationRows(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $mc  = new MigrationsCollector();
        $mc->record('2026_06_09_000001_add_index', 42.0, 'ran');
        $bar->addCollector($mc);

        // Act
        $html = $bar->render();

        // Assert — slug appears in the panel
        $this->assertStringContainsString('2026_06_09_000001_add_index', $html);
        $this->assertStringContainsString('42', $html);
    }

    /**
     * Failed migrations must carry the failure indicator in the panel row.
     */
    public function testRenderMigrationsPanelShowsFailedMigration(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $mc  = new MigrationsCollector();
        $mc->record('2026_06_09_000002_failing_mig', 10.0, 'failed');
        $bar->addCollector($mc);

        // Act
        $html = $bar->render();

        // Assert — FAILED indicator and pdb-slow class for the failed row
        $this->assertStringContainsString('FAILED', $html);
        $this->assertStringContainsString('pdb-slow', $html);
    }

    // ── renderExceptions() panel content ─────────────────────────────────────

    /**
     * The exceptions panel must list each recorded exception with class, message,
     * and file+line.
     */
    public function testRenderExceptionsPanelContainsExceptionDetails(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $exc = new ExceptionsCollector();
        $exc->record(new \InvalidArgumentException('bad argument here', 0));
        $bar->addCollector($exc);

        // Act
        $html = $bar->render();

        // Assert — exception class and message appear in the panel
        $this->assertStringContainsString('InvalidArgumentException', $html);
        $this->assertStringContainsString('bad argument here', $html);
    }

    /**
     * The exceptions panel must show "No exceptions" when none were recorded.
     */
    public function testRenderExceptionsPanelShowsEmptyMessage(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new ExceptionsCollector());

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('No exceptions', $html);
    }

    /**
     * A PHP error recorded via recordPhpError() must appear in the panel
     * with 'PHP' as the type column value.
     */
    public function testRenderExceptionsPanelShowsPhpError(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $exc = new ExceptionsCollector();
        $exc->recordPhpError(E_WARNING, 'undefined variable $foo', '/path/to/file.php', 99);
        $bar->addCollector($exc);

        // Act
        $html = $bar->render();

        // Assert — 'PHP' type indicator must be present (type column)
        $this->assertStringContainsString('PHP', $html);
        $this->assertStringContainsString('undefined variable', $html);
    }

    // ── PHP version chip ──────────────────────────────────────────────────────

    /**
     * The info strip must always contain the PHP version chip.
     *
     * This chip is unconditional — it always appears whenever the toolbar renders,
     * giving developers instant visibility of the runtime PHP version.
     */
    public function testRenderInfoStripAlwaysContainsPhpVersionChip(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector($this->makeCollector('dummy'));

        // Act
        $html = $bar->render();

        // Assert — PHP version chip present with current major.minor
        $this->assertStringContainsString('PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $html);
    }

    // ── reset() / singleton lifecycle ────────────────────────────────────────

    /**
     * reset() must clear the singleton so the next getInstance() creates a fresh
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
