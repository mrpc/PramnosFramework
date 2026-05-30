<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationRunner;

/**
 * Unit tests for MigrationRunner::hasPendingFromSlugs().
 *
 * The method is the "fast path" check in Application::runAutoMigrations() — it
 * determines whether a full migration load + run is needed using only filename-
 * derived slugs (no PHP loading) and a single DB query.
 *
 * These tests use a Database mock to simulate the history table queries, so no
 * Docker container is required.
 */
#[CoversClass(MigrationRunner::class)]
class MigrationRunnerHasPendingTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Builds a MigrationRunner with a mock Database whose query() return value
     * simulates the result of "SELECT key FROM schemaversion WHERE result = 1".
     *
     * hasPendingFromSlugs() calls getRanSlugs() directly (no ensureHistoryTable()
     * prefix), so the mock only needs to handle one query per call.
     *
     * @param string[] $ranSlugs  Slugs to return from the simulated SELECT.
     * @param bool     $throwOnQuery When true, query() throws to simulate missing table.
     */
    private function makeRunner(array $ranSlugs = [], bool $throwOnQuery = false): MigrationRunner
    {
        // Build a fake result object whose fetch() drains $ranSlugs one by one.
        // An anonymous class is used instead of getMockBuilder()->addMethods()
        // because addMethods() is deprecated since PHPUnit 11.5 and removed in 12.
        $resultFake = new class($ranSlugs) {
            public array $fields = [];
            private array $queue;

            public function __construct(array $queue)
            {
                $this->queue = $queue;
            }

            public function fetch(): bool
            {
                if (empty($this->queue)) {
                    $this->fields = [];
                    return false;
                }
                $this->fields = ['key' => array_shift($this->queue)];
                return true;
            }
        };

        $dbMock = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query', 'prepareQuery'])
            ->getMock();

        $dbMock->type = 'mysql';

        if ($throwOnQuery) {
            // Simulate the history table not existing — any SELECT throws.
            $dbMock->method('query')->willThrowException(
                new \RuntimeException('Table schemaversion does not exist')
            );
        } else {
            // hasPendingFromSlugs() calls getRanSlugs() which issues one SELECT.
            // Return our fake result for that single query.
            $dbMock->method('query')->willReturn($resultFake);
        }

        $dbMock->method('prepareQuery')->willReturnArgument(0);

        return new MigrationRunner($dbMock, 'schemaversion');
    }

    // -----------------------------------------------------------------------
    // No-DB / empty input edge cases
    // -----------------------------------------------------------------------

    /**
     * When the runner has no database connection, hasPendingFromSlugs() must
     * return false — there is nothing to check against and nothing to run.
     */
    public function testReturnsFalseWithNullDatabase(): void
    {
        // Arrange – runner with no DB
        $runner = new MigrationRunner(null, 'schemaversion');

        // Act
        $result = $runner->hasPendingFromSlugs(['create_users_table' => '2024_01_01_000000']);

        // Assert
        $this->assertFalse($result, 'No DB means no pending migrations to detect');
    }

    /**
     * An empty slug map means there are no migrations to check — must return false.
     */
    public function testReturnsFalseForEmptySlugMap(): void
    {
        // Arrange – runner with a DB mock (it should not be queried)
        $dbMock = $this->createMock(Database::class);
        $dbMock->expects($this->never())->method('query');
        $runner = new MigrationRunner($dbMock, 'schemaversion');

        // Act
        $result = $runner->hasPendingFromSlugs([]);

        // Assert
        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------------
    // All migrations already ran
    // -----------------------------------------------------------------------

    /**
     * When every slug in the map is already in the history table, the method
     * must return false — nothing pending.
     */
    public function testReturnsFalseWhenAllSlugsAlreadyRan(): void
    {
        // Arrange – both slugs are in the history table
        $runner = $this->makeRunner(['create_users_table', 'add_email_index']);

        $slugMap = [
            'create_users_table' => '2024_01_01_000000',
            'add_email_index'    => '2024_06_01_000000',
        ];

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap);

        // Assert
        $this->assertFalse($result, 'All slugs in history → nothing pending');
    }

    // -----------------------------------------------------------------------
    // Some migrations pending
    // -----------------------------------------------------------------------

    /**
     * When at least one slug is absent from the history table, the method must
     * return true.
     */
    public function testReturnsTrueWhenOneSlugsIsMissing(): void
    {
        // Arrange – only one of the two slugs has been run
        $runner = $this->makeRunner(['create_users_table']);

        $slugMap = [
            'create_users_table' => '2024_01_01_000000',
            'add_email_index'    => '2024_06_01_000000', // not in history
        ];

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap);

        // Assert
        $this->assertTrue($result, 'One slug missing from history → pending');
    }

    /**
     * When the history table is empty (fresh installation), every slug counts
     * as pending.
     */
    public function testReturnsTrueWhenHistoryTableIsEmpty(): void
    {
        // Arrange – empty history
        $runner = $this->makeRunner([]);

        $slugMap = ['create_users_table' => '2024_01_01_000000'];

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap);

        // Assert
        $this->assertTrue($result, 'Empty history → everything pending');
    }

    // -----------------------------------------------------------------------
    // Missing history table (fresh install)
    // -----------------------------------------------------------------------

    /**
     * When the history table does not exist yet, query() throws a runtime
     * exception.  hasPendingFromSlugs() must treat this as "everything pending"
     * so that the caller proceeds to run() which will create the table via
     * ensureHistoryTable().
     */
    public function testReturnsTrueWhenHistoryTableMissing(): void
    {
        // Arrange – query() throws (table doesn't exist)
        $runner = $this->makeRunner([], throwOnQuery: true);

        $slugMap = ['create_users_table' => '2024_01_01_000000'];

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap);

        // Assert
        $this->assertTrue($result, 'Missing history table must be treated as all-pending');
    }

    // -----------------------------------------------------------------------
    // Cutoff filtering
    // -----------------------------------------------------------------------

    /**
     * When a cutoff is set and ALL pending slugs have timestamps at-or-before
     * the cutoff, the method must return false — cutoff excludes everything.
     */
    public function testReturnsFalseWhenAllPendingSlugsArePreCutoff(): void
    {
        // Arrange – empty history, cutoff after all migration timestamps
        $runner = $this->makeRunner([]);

        $slugMap = [
            'old_schema' => '2020_01_01_000001', // before cutoff
            'init_data'  => '2020_06_01_000000', // before cutoff
        ];
        $cutoff = '2026_01_01_000000'; // everything is before this

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap, $cutoff);

        // Assert
        $this->assertFalse($result, 'All migrations pre-cutoff → nothing to run');
    }

    /**
     * When at least one pending slug has a timestamp strictly after the cutoff,
     * the method must return true.
     */
    public function testReturnsTrueWhenSomePendingSlugsArePostCutoff(): void
    {
        // Arrange – empty history, cutoff between the two migrations
        $runner = $this->makeRunner([]);

        $slugMap = [
            'old_schema'  => '2020_01_01_000001', // before cutoff → excluded
            'new_feature' => '2026_06_01_000000', // after cutoff → counts
        ];
        $cutoff = '2026_01_01_000000';

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap, $cutoff);

        // Assert
        $this->assertTrue($result, 'One post-cutoff pending slug → pending');
    }

    /**
     * The cutoff boundary is exclusive — a migration whose timestamp exactly
     * equals the cutoff is NOT included (≤ cutoff means skip).
     */
    public function testCutoffBoundaryIsExclusive(): void
    {
        // Arrange
        $runner = $this->makeRunner([]);

        $slugMap = ['exact_cutoff_migration' => '2026_01_01_000000'];
        $cutoff  = '2026_01_01_000000'; // exactly at boundary

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap, $cutoff);

        // Assert – exactly at cutoff → excluded → false
        $this->assertFalse($result, 'Timestamp == cutoff must be excluded (boundary is exclusive)');
    }

    /**
     * A migration whose timestamp is one second after the cutoff must count
     * as pending.
     */
    public function testOneSecondAfterCutoffCountsAsPending(): void
    {
        // Arrange
        $runner = $this->makeRunner([]);

        $slugMap = ['post_cutoff_migration' => '2026_01_01_000001'];
        $cutoff  = '2026_01_01_000000';

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap, $cutoff);

        // Assert
        $this->assertTrue($result, 'Timestamp one second after cutoff must be pending');
    }

    /**
     * A migration with no timestamp (empty string) is always treated as
     * potentially pending regardless of the cutoff — it matches the legacy
     * "always passes through" rule in MigrationRunner::filterCutoff().
     */
    public function testNoTimestampAlwaysCountsAsPendingRegardlessOfCutoff(): void
    {
        // Arrange – empty history, very late cutoff
        $runner = $this->makeRunner([]);

        $slugMap = ['legacy_migration' => '']; // no timestamp
        $cutoff  = '2099_12_31_235959';        // absurdly late cutoff

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap, $cutoff);

        // Assert
        $this->assertTrue($result, 'No-timestamp slug must count as pending regardless of cutoff');
    }

    /**
     * When cutoff is empty string, no filtering occurs — all unran slugs count
     * as pending.
     */
    public function testEmptyCutoffMeansNoFiltering(): void
    {
        // Arrange
        $runner = $this->makeRunner([]);

        $slugMap = ['old_migration' => '2000_01_01_000000'];
        $cutoff  = ''; // no cutoff

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap, $cutoff);

        // Assert
        $this->assertTrue($result, 'Empty cutoff → no filtering → old migration still pending');
    }

    // -----------------------------------------------------------------------
    // Already-ran + cutoff interaction
    // -----------------------------------------------------------------------

    /**
     * A slug that is already in the history table is not considered pending even
     * if it is post-cutoff.  "Already ran" takes precedence over everything.
     */
    public function testAlreadyRanSlugIsNotPendingEvenIfPostCutoff(): void
    {
        // Arrange – slug is in history AND post-cutoff
        $runner = $this->makeRunner(['new_feature']);

        $slugMap = ['new_feature' => '2026_06_01_000000'];
        $cutoff  = '2024_01_01_000000'; // new_feature is post-cutoff, but already ran

        // Act
        $result = $runner->hasPendingFromSlugs($slugMap, $cutoff);

        // Assert
        $this->assertFalse($result, 'Already-ran slug must not be reported as pending');
    }
}
