<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Debug\DebugBar;
use Pramnos\Debug\Collectors\MigrationsCollector;
use Pramnos\Debug\Collectors\TimeCollector;

/**
 * Unit tests for MigrationsCollector and the DebugBar migrations integration.
 *
 * These tests verify that:
 *  - MigrationsCollector records in-request migrations correctly.
 *  - collect() returns the expected data shape even without a DB connection.
 *  - TimeCollector::addCompletedSegment() inserts a retroactive timeline entry.
 *  - DebugBar::recordMigration() populates both the timeline and the collector.
 *  - The DebugBar renders a 'Migrations' tab when the collector is registered.
 */
class MigrationsCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        DebugBar::reset();
    }

    protected function tearDown(): void
    {
        DebugBar::reset();
    }

    // ── MigrationsCollector ───────────────────────────────────────────────────

    /**
     * name() must return 'migrations' so the DebugBar indexes it correctly.
     */
    public function testCollectorNameIsMigrations(): void
    {
        // Arrange / Act
        $mc = new MigrationsCollector();

        // Assert
        $this->assertSame('migrations', $mc->name());
    }

    /**
     * A fresh collector with no records must return safe empty arrays
     * rather than throwing or returning null values.
     */
    public function testCollectReturnsEmptyStructureWhenNothingRecorded(): void
    {
        // Arrange
        $mc = new MigrationsCollector();

        // Act
        $data = $mc->collect();

        // Assert
        $this->assertSame(0, $data['count_request']);
        $this->assertSame([], $data['this_request']);
    }

    /**
     * record() must accumulate entries in 'this_request' with the correct shape.
     */
    public function testRecordAppendsToThisRequest(): void
    {
        // Arrange
        $mc = new MigrationsCollector();

        // Act
        $mc->record('2026_01_01_000001_create_foo', 42.5, 'ran');
        $mc->record('2026_01_01_000002_create_bar', 7.0, 'failed');
        $data = $mc->collect();

        // Assert
        $this->assertSame(2, $data['count_request']);
        $this->assertSame('2026_01_01_000001_create_foo', $data['this_request'][0]['slug']);
        $this->assertSame(42.5, $data['this_request'][0]['ms']);
        $this->assertSame('ran', $data['this_request'][0]['status']);
        $this->assertSame('failed', $data['this_request'][1]['status']);
    }

    /**
     * Multiple record() calls accumulate — each call appends, not replaces.
     */
    public function testMultipleRecordsAccumulate(): void
    {
        // Arrange
        $mc = new MigrationsCollector();

        // Act
        $mc->record('slug_a', 10.0);
        $mc->record('slug_b', 20.0);
        $mc->record('slug_c', 30.0);

        // Assert
        $this->assertSame(3, $mc->collect()['count_request']);
    }

    // ── TimeCollector::addCompletedSegment ────────────────────────────────────

    /**
     * addCompletedSegment() must add an entry to the named_timers list so that
     * the timeline bar shows the migration segment.
     */
    public function testAddCompletedSegmentAppearsInNamedTimers(): void
    {
        // Arrange — create collector with a known start time slightly in the past
        $start = microtime(true) - 0.5; // 500 ms ago
        $tc    = new TimeCollector($start);

        // Act
        $tc->addCompletedSegment('migration:test_slug', 100.0);
        $data = $tc->collect();

        // Assert — the segment must appear in named_timers
        $names = array_column($data['named_timers'], 'name');
        $this->assertContains('migration:test_slug', $names);
    }

    /**
     * addCompletedSegment() must produce a segment whose duration matches
     * the provided durationMs within floating-point tolerance.
     */
    public function testAddCompletedSegmentDurationIsCorrect(): void
    {
        // Arrange
        $start = microtime(true) - 1.0; // 1 second ago
        $tc    = new TimeCollector($start);

        // Act
        $tc->addCompletedSegment('migration:alpha', 200.0);
        $data = $tc->collect();

        // Assert — find the migration segment and check its ms
        $seg = null;
        foreach ($data['named_timers'] as $t) {
            if ($t['name'] === 'migration:alpha') {
                $seg = $t;
                break;
            }
        }
        $this->assertNotNull($seg, 'Segment not found in named_timers');
        $this->assertEqualsWithDelta(200.0, $seg['ms'], 5.0); // ±5ms tolerance
    }

    /**
     * offset_ms must be non-negative: a retroactive segment should never appear
     * before the request started.
     */
    public function testAddCompletedSegmentOffsetIsNonNegative(): void
    {
        // Arrange — simulate a migration that ran right at the start
        $start = microtime(true); // now
        $tc    = new TimeCollector($start);

        // Act — a very long migration that would push offset negative without clamping
        $tc->addCompletedSegment('migration:long', 99999.0);
        $data = $tc->collect();

        // Assert
        foreach ($data['named_timers'] as $t) {
            $this->assertGreaterThanOrEqual(0.0, $t['offset_ms']);
        }
    }

    // ── DebugBar::recordMigration ─────────────────────────────────────────────

    /**
     * recordMigration() must populate the MigrationsCollector when registered.
     */
    public function testRecordMigrationPopulatesCollector(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $mc  = new MigrationsCollector();
        $bar->addCollector(new TimeCollector());
        $bar->addCollector($mc);

        // Act
        DebugBar::recordMigration('2026_06_01_000001_test_slug', 55.0, 'ran');

        // Assert
        $data = $mc->collect();
        $this->assertSame(1, $data['count_request']);
        $this->assertSame('2026_06_01_000001_test_slug', $data['this_request'][0]['slug']);
        $this->assertSame(55.0, $data['this_request'][0]['ms']);
    }

    /**
     * recordMigration() must add a segment to the timeline when TimeCollector
     * is registered.
     */
    public function testRecordMigrationAddsTimelineSegment(): void
    {
        // Arrange
        $start = microtime(true) - 0.3;
        $bar   = DebugBar::getInstance();
        $tc    = new TimeCollector($start);
        $bar->addCollector($tc);
        $bar->addCollector(new MigrationsCollector());

        // Act
        DebugBar::recordMigration('my_migration_slug', 80.0);

        // Assert — timeline must contain the migration segment
        $data  = $tc->collect();
        $names = array_column($data['named_timers'], 'name');
        $this->assertContains('migration:my_migration_slug', $names);
    }

    /**
     * recordMigration() must be a no-op when no MigrationsCollector is registered
     * — should not throw even when only a TimeCollector is present.
     */
    public function testRecordMigrationIsNoopWithoutCollector(): void
    {
        // Arrange — register only TimeCollector, no MigrationsCollector
        $bar = DebugBar::getInstance();
        $bar->addCollector(new TimeCollector());

        // Act
        DebugBar::recordMigration('some_slug', 10.0);

        // Assert — the call is absorbed, and nothing is invented on the way:
        // the collector that *is* registered must not have grown a migrations
        // section. `assertTrue(true)` said only that no exception escaped, which
        // a method that silently recorded into the wrong place also satisfies.
        $this->assertNull(
            $bar->getCollector('migrations'),
            'no migrations collector may appear from a recorded migration'
        );
    }

    /**
     * recordMigration() must be a no-op when DebugBar has no collectors at all.
     */
    public function testRecordMigrationIsNoopWithEmptyBar(): void
    {
        // Act
        DebugBar::recordMigration('slug', 5.0);

        // Assert — an empty bar stays empty
        $this->assertSame(
            [],
            DebugBar::getInstance()->getCollectors(),
            'recording into a bar with no collectors must not create one'
        );
    }

    // ── What the toolbar receives ─────────────────────────────────────────────

    /**
     * A migration that ran this request reaches the toolbar's data island.
     *
     * The tab, its label and the table are drawn by the one renderer
     * (`DebugBarAsset::source()`) and driven in the JavaScript tests; what PHP
     * still owns — and what this pins — is that the collector's data travels
     * under the key that renderer reads.
     */
    public function testMigrationsThatRanReachTheDataIsland(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $mc  = new MigrationsCollector();
        $mc->record('my_slug', 30.0);
        $bar->addCollector(new TimeCollector());
        $bar->addCollector($mc);

        // Act
        $payload = $this->islandOf($bar->render());

        // Assert
        $this->assertSame('my_slug', $payload['migrations']['this_request'][0]['slug']);
        // assertEquals, not assertSame: JSON has one number type, so a round
        // trip through the island turns 30.0 into 30.
        $this->assertEquals(30.0, $payload['migrations']['this_request'][0]['ms']);
    }

    /**
     * When nothing ran, the key is still there and its list is empty.
     *
     * An absent key and an empty list are different claims to the renderer: it
     * draws no tab at all for a collector that reported nothing, and "nothing
     * ran this request" for one that reported an empty list.
     */
    public function testAnIslandSaysSoWhenNoMigrationRan(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new TimeCollector());
        $bar->addCollector(new MigrationsCollector());

        // Act
        $payload = $this->islandOf($bar->render());

        // Assert
        $this->assertSame([], $payload['migrations']['this_request']);
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
}
