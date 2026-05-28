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

        // Act / Assert — must not throw
        DebugBar::recordMigration('some_slug', 10.0);
        $this->assertTrue(true);
    }

    /**
     * recordMigration() must be a no-op when DebugBar has no collectors at all.
     */
    public function testRecordMigrationIsNoopWithEmptyBar(): void
    {
        // Act / Assert — no exception even with an empty DebugBar
        DebugBar::recordMigration('slug', 5.0);
        $this->assertTrue(true);
    }

    // ── DebugBar rendering ────────────────────────────────────────────────────

    /**
     * When a MigrationsCollector is registered, render() must produce a
     * 'Migrations' tab button and a corresponding panel.
     */
    public function testDebugBarRendersMigrationsTab(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new TimeCollector());
        $bar->addCollector(new MigrationsCollector());

        // Act
        $html = $bar->render();

        // Assert — tab button and panel div must be present
        $this->assertStringContainsString('data-panel="migrations"', $html);
        $this->assertStringContainsString('id="pdb-panel-migrations"', $html);
    }

    /**
     * The tab label must show "(N ran)" when migrations ran this request.
     */
    public function testMigrationsTabLabelShowsCountWhenRan(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $mc  = new MigrationsCollector();
        $mc->record('my_slug', 30.0);
        $bar->addCollector(new TimeCollector());
        $bar->addCollector($mc);

        // Act
        $html = $bar->render();

        // Assert — label must contain "1 ran"
        $this->assertStringContainsString('1 ran', $html);
    }

    /**
     * When nothing ran this request, the panel must show an appropriate empty message.
     */
    public function testMigrationsPanelShowsEmptyMessageWhenNothingRan(): void
    {
        // Arrange
        $bar = DebugBar::getInstance();
        $bar->addCollector(new TimeCollector());
        $bar->addCollector(new MigrationsCollector());

        // Act
        $html = $bar->render();

        // Assert
        $this->assertStringContainsString('No migrations ran this request', $html);
    }
}
