<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Inspector\TimescaleInspector;

/**
 * Unit tests for TimescaleInspector.
 *
 * TimescaleInspector receives a Database instance via constructor injection,
 * which makes it straightforward to mock — no static singletons involved.
 *
 * Paths covered:
 *  - Non-PostgreSQL database type → getData() returns empty struct immediately
 *  - PostgreSQL + detectVersion() returns null → getData() returns empty struct
 *  - PostgreSQL + pg_extension row found → version string propagated
 *  - PostgreSQL + pg_extension missing + timescale flag true → 'unknown' returned
 *  - PostgreSQL + all sub-queries return data → getData() assembles full struct
 *  - PostgreSQL + sub-queries throw → each catch returns [] / 0 gracefully
 *  - getContinuousAggregates() primary query throws → fallback query used
 *  - getContinuousAggregates() both queries throw → empty array returned
 *  - getChunkCount() with result → integer returned
 *  - getChunkCount() query throws → 0 returned
 *
 * The Result stub mimics the \Pramnos\Database\Result shape that
 * TimescaleInspector reads: ->numRows and ->fields.
 */
#[CoversClass(TimescaleInspector::class)]
class TimescaleInspectorTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a minimal mock Database whose `type` and `timescale` properties
     * can be set directly (they are public), and whose `query()` method can
     * be stubbed.
     *
     * @param string $type      Value for Database::$type ('postgresql' or 'mysql').
     * @param bool   $timescale Value for Database::$timescale flag.
     */
    private function makeDb(string $type = 'postgresql', bool $timescale = false): Database
    {
        $db = $this->createMock(Database::class);
        // Public properties: set directly after creation.
        $db->type      = $type;
        $db->timescale = $timescale;
        return $db;
    }

    /**
     * Build a Result-like stdClass that TimescaleInspector can read.
     *
     * @param int   $numRows  Value for ->numRows (0 = no results).
     * @param array $fields   Value for ->fields (used by scalar queries like COUNT).
     * @param array $fetchAll Value returned by ->fetchAll() (used by list queries).
     */
    private function makeResult(int $numRows, array $fields = [], array $fetchAll = []): object
    {
        $r = new \stdClass();
        $r->numRows = $numRows;
        $r->fields  = $fields;
        // Attach a fetchAll method so TimescaleInspector can call it.
        // PHP stdClass doesn't support dynamic methods, so we use a closure-based trick
        // via an anonymous class that implements the required interface.
        return new class($numRows, $fields, $fetchAll) {
            public int   $numRows;
            public array $fields;
            private array $all;

            public function __construct(int $numRows, array $fields, array $all)
            {
                $this->numRows = $numRows;
                $this->fields  = $fields;
                $this->all     = $all;
            }

            public function fetchAll(): array
            {
                return $this->all;
            }
        };
    }

    // ── getData() — early-exit for non-PostgreSQL ─────────────────────────────

    /**
     * When the database type is 'mysql' getData() must return the empty struct
     * immediately, without making any queries. TimescaleDB information views
     * do not exist on MySQL so any query attempt would fail.
     */
    public function testGetDataReturnsEmptyStructForMysqlType(): void
    {
        // Arrange — MySQL database, query() should never be called
        $db = $this->makeDb('mysql');
        $db->expects($this->never())->method('query');

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — all fields at their zero-value defaults
        $this->assertNull($result['ts_version'],        'ts_version must be null for non-PostgreSQL');
        $this->assertSame([], $result['hypertables'],   'hypertables must be empty for non-PostgreSQL');
        $this->assertSame([], $result['aggregates'],    'aggregates must be empty for non-PostgreSQL');
        $this->assertSame([], $result['jobs'],          'jobs must be empty for non-PostgreSQL');
        $this->assertSame([], $result['jobHistory'],    'jobHistory must be empty for non-PostgreSQL');
        $this->assertSame(0,  $result['chunkCount'],    'chunkCount must be 0 for non-PostgreSQL');
    }

    /**
     * 'timescaledb' is mapped to 'postgresql' by the ORM layer before the
     * Database object is created. We test it as 'timescaledb' to ensure no
     * accidental string match bypasses the check.
     */
    public function testGetDataReturnsEmptyStructForTimescaledbTypeString(): void
    {
        // Arrange — type set to the raw 'timescaledb' string (before normalisation)
        $db = $this->makeDb('timescaledb');
        // query() may or may not be called depending on implementation details;
        // the important assertion is the return value.

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — non-postgresql type must return empty struct
        $this->assertNull($result['ts_version'],
            'timescaledb type string is not "postgresql" — empty struct expected');
    }

    // ── detectVersion() paths ─────────────────────────────────────────────────

    /**
     * When the pg_extension query returns no row and the timescale flag is false
     * detectVersion() returns null, causing getData() to return the empty struct.
     * This is the "TimescaleDB not installed" scenario.
     */
    public function testGetDataReturnsEmptyStructWhenTimescaleNotInstalled(): void
    {
        // Arrange — PostgreSQL but no TimescaleDB extension
        $db = $this->makeDb('postgresql', false);
        // All queries return a zero-row result
        $emptyResult = $this->makeResult(0);
        $db->method('query')->willReturn($emptyResult);

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — detectVersion() returned null, so empty struct is returned
        $this->assertNull($result['ts_version'],
            'ts_version must be null when TimescaleDB extension is not installed');
        $this->assertSame([], $result['hypertables'],
            'hypertables must be empty when extension is absent');
    }

    /**
     * When pg_extension is absent but Database::$timescale is true (e.g. during
     * an upgrade where the extension row temporarily disappears), detectVersion()
     * must return the string 'unknown' so the dashboard still displays something.
     */
    public function testDetectVersionReturnsUnknownWhenFlagSetButExtensionAbsent(): void
    {
        // Arrange — PostgreSQL, timescale flag = true, but pg_extension returns 0 rows
        $db = $this->makeDb('postgresql', true);
        $emptyResult = $this->makeResult(0);
        $db->method('query')->willReturn($emptyResult);

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — 'unknown' sentinel is set; the rest of the struct is populated
        $this->assertSame('unknown', $result['ts_version'],
            "ts_version must be 'unknown' when extension row absent but timescale flag is set");
    }

    /**
     * When the pg_extension query succeeds and returns a row, the version string
     * from the extversion column must be propagated into ts_version.
     */
    public function testDetectVersionReturnsVersionStringFromPgExtension(): void
    {
        // Arrange — pg_extension row with version '2.14.2'
        $db = $this->makeDb('postgresql', false);

        $versionResult    = $this->makeResult(1, ['extversion' => '2.14.2']);
        $emptyResult      = $this->makeResult(0);

        // First query = pg_extension (detectVersion); all subsequent = sub-queries
        $db->method('query')->willReturnOnConsecutiveCalls(
            $versionResult,  // detectVersion
            $emptyResult,    // getHypertables
            $emptyResult,    // getContinuousAggregates (primary)
            $emptyResult,    // getScheduledJobs
            $emptyResult,    // getJobHistory
            $emptyResult     // getChunkCount
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — version string flows through to the output
        $this->assertSame('2.14.2', $result['ts_version'],
            'ts_version must equal the extversion column value from pg_extension');
    }

    // ── Sub-query helpers — happy paths ───────────────────────────────────────

    /**
     * When getHypertables() query returns rows, the row array must be returned
     * in the 'hypertables' key. getData() assembles the full struct.
     */
    public function testGetDataPopulatesHypertablesFromQueryResult(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', false);

        $htRow   = ['hypertable_name' => 'metrics', 'num_chunks' => 12,
                    'num_dimensions' => 1, 'compression_enabled' => false,
                    'tablespaces' => null];
        $htResult = $this->makeResult(1, [], [$htRow]);

        $db->method('query')->willReturnOnConsecutiveCalls(
            $this->makeResult(1, ['extversion' => '2.14.0']), // detectVersion
            $htResult,                                          // getHypertables
            $this->makeResult(0),                              // getContinuousAggregates
            $this->makeResult(0),                              // getScheduledJobs
            $this->makeResult(0),                              // getJobHistory
            $this->makeResult(0)                               // getChunkCount
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — hypertables list contains our row
        $this->assertCount(1, $result['hypertables'],
            'hypertables must contain the row returned by the query');
        $this->assertSame('metrics', $result['hypertables'][0]['hypertable_name']);
    }

    /**
     * When getContinuousAggregates() primary query returns rows they must be
     * included in 'aggregates'. The fallback query should not be called.
     */
    public function testGetDataPopulatesAggregatesFromPrimaryQuery(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', false);

        $aggRow    = ['view_schema' => 'public', 'view_name' => 'daily_metrics',
                      'materialization_schema' => '_timescaledb_internal',
                      'materialization_name'   => '_materialized_hypertable_1',
                      'materialized_only' => 'Yes', 'compression_enabled' => false];
        $aggResult = $this->makeResult(1, [], [$aggRow]);

        $db->method('query')->willReturnOnConsecutiveCalls(
            $this->makeResult(1, ['extversion' => '2.14.0']),
            $this->makeResult(0),   // getHypertables
            $aggResult,             // getContinuousAggregates — primary
            $this->makeResult(0),   // getScheduledJobs
            $this->makeResult(0),   // getJobHistory
            $this->makeResult(0)    // getChunkCount
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert
        $this->assertCount(1, $result['aggregates'],
            'aggregates must contain the row returned by the primary query');
        $this->assertSame('daily_metrics', $result['aggregates'][0]['view_name']);
    }

    /**
     * When getScheduledJobs() query returns rows they must appear in 'jobs'.
     */
    public function testGetDataPopulatesJobsFromQueryResult(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', false);

        $jobRow = ['job_id' => 1001, 'proc_schema' => '_timescaledb_internal',
                   'proc_name' => 'policy_compression', 'schedule_interval' => '1 day',
                   'last_run_started_at' => null, 'last_successful_finish' => null,
                   'last_run_status' => null, 'next_start' => null];
        $jobResult = $this->makeResult(1, [], [$jobRow]);

        $db->method('query')->willReturnOnConsecutiveCalls(
            $this->makeResult(1, ['extversion' => '2.14.0']),
            $this->makeResult(0),  // hypertables
            $this->makeResult(0),  // aggregates
            $jobResult,            // jobs
            $this->makeResult(0),  // jobHistory
            $this->makeResult(0)   // chunkCount
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert
        $this->assertCount(1, $result['jobs'],
            'jobs must contain the row returned by the query');
        $this->assertSame(1001, $result['jobs'][0]['job_id']);
    }

    /**
     * When getJobHistory() query returns rows they must appear in 'jobHistory'.
     */
    public function testGetDataPopulatesJobHistoryFromQueryResult(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', false);

        $histRow   = ['job_id' => 1001, 'start_time' => '2026-01-01 00:00:00',
                      'finish_time' => '2026-01-01 00:00:01', 'succeeded' => 't',
                      'proc_schema' => '_timescaledb_internal', 'proc_name' => 'policy_compression',
                      'err_message' => null];
        $histResult = $this->makeResult(1, [], [$histRow]);

        $db->method('query')->willReturnOnConsecutiveCalls(
            $this->makeResult(1, ['extversion' => '2.14.0']),
            $this->makeResult(0),   // hypertables
            $this->makeResult(0),   // aggregates
            $this->makeResult(0),   // jobs
            $histResult,            // jobHistory
            $this->makeResult(0)    // chunkCount
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert
        $this->assertCount(1, $result['jobHistory'],
            'jobHistory must contain the row returned by the query');
        $this->assertSame(1001, $result['jobHistory'][0]['job_id']);
    }

    /**
     * getChunkCount() must return the integer value from the COUNT(*) query.
     * The total is read from ->fields['total'].
     */
    public function testGetDataReturnsChunkCountFromQuery(): void
    {
        // Arrange
        $db = $this->makeDb('postgresql', false);

        $countResult = $this->makeResult(1, ['total' => '247']);

        $db->method('query')->willReturnOnConsecutiveCalls(
            $this->makeResult(1, ['extversion' => '2.14.0']),
            $this->makeResult(0),  // hypertables
            $this->makeResult(0),  // aggregates
            $this->makeResult(0),  // jobs
            $this->makeResult(0),  // jobHistory
            $countResult           // chunkCount
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — cast to int must equal 247
        $this->assertSame(247, $result['chunkCount'],
            'chunkCount must be the integer value from COUNT(*)');
    }

    // ── Exception resilience ─────────────────────────────────────────────────

    /**
     * When detectVersion() throws an exception and the timescale flag is false
     * getData() must return the empty struct — it must not propagate the exception.
     *
     * This covers DB connection drops, missing pg_extension table, etc.
     */
    public function testGetDataReturnsEmptyStructWhenDetectVersionThrows(): void
    {
        // Arrange — pg_extension query throws
        $db = $this->makeDb('postgresql', false);
        $db->method('query')->willThrowException(new \RuntimeException('Connection lost'));

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — exception swallowed, empty struct returned
        $this->assertNull($result['ts_version'],
            'ts_version must be null when detectVersion() throws');
        $this->assertSame([], $result['hypertables']);
    }

    /**
     * When getHypertables() throws, the 'hypertables' key must be an empty array
     * rather than allowing the exception to propagate. Other keys must still be
     * populated if their own queries succeed.
     *
     * TimescaleInspector wraps each sub-query independently so a bad schema
     * version does not suppress unrelated data.
     */
    public function testGetDataReturnsEmptyHypertablesWhenQueryThrows(): void
    {
        // Arrange — version found, but hypertables query throws
        $db = $this->makeDb('postgresql', false);

        $db->method('query')->willReturnCallback(
            (function () {
                $call = 0;
                return function () use (&$call): mixed {
                    $call++;
                    if ($call === 1) {
                        // detectVersion — success
                        return $this->makeResult(1, ['extversion' => '2.14.0']);
                    }
                    if ($call === 2) {
                        // getHypertables — throw
                        throw new \RuntimeException('hypertables view missing');
                    }
                    // All other sub-queries — empty result
                    return $this->makeResult(0);
                };
            })()
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — hypertables empty due to exception; version still set
        $this->assertSame('2.14.0', $result['ts_version'],
            'ts_version must survive even if getHypertables() throws');
        $this->assertSame([], $result['hypertables'],
            'hypertables must be [] when its query throws');
    }

    /**
     * getContinuousAggregates() has a two-query fallback:
     *   1. Extended query (with materialization_hypertable_schema)
     *   2. Minimal query (without it) — for older TimescaleDB versions
     *
     * When the primary query throws the fallback must be attempted and its
     * result must be returned in 'aggregates'.
     */
    public function testGetDataUsesContinuousAggregatesFallbackWhenPrimaryThrows(): void
    {
        // Arrange — primary query throws; fallback returns one row
        $db = $this->makeDb('postgresql', false);

        $aggFallbackRow = ['view_schema' => 'public', 'view_name' => 'daily_agg',
                           'materialization_name' => '_mat_1', 'compression_enabled' => false];
        $aggFallback    = $this->makeResult(1, [], [$aggFallbackRow]);

        $db->method('query')->willReturnCallback(
            (function () use ($aggFallback) {
                $call = 0;
                return function () use (&$call, $aggFallback): mixed {
                    $call++;
                    return match ($call) {
                        1 => $this->makeResult(1, ['extversion' => '2.10.0']), // detectVersion
                        2 => $this->makeResult(0),                             // hypertables
                        3 => throw new \RuntimeException('column missing'),    // aggregates primary
                        4 => $aggFallback,                                     // aggregates fallback
                        default => $this->makeResult(0),
                    };
                };
            })()
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — fallback result returned
        $this->assertCount(1, $result['aggregates'],
            'aggregates must use the fallback query result when primary query throws');
        $this->assertSame('daily_agg', $result['aggregates'][0]['view_name']);
    }

    /**
     * When both getContinuousAggregates() queries throw, 'aggregates' must be
     * an empty array. The class must not propagate either exception.
     */
    public function testGetDataReturnsEmptyAggregatesWhenBothQueriesThrow(): void
    {
        // Arrange — both aggregate queries throw
        $db = $this->makeDb('postgresql', false);

        $db->method('query')->willReturnCallback(
            (function () {
                $call = 0;
                return function () use (&$call): mixed {
                    $call++;
                    return match ($call) {
                        1 => $this->makeResult(1, ['extversion' => '2.14.0']),
                        2 => $this->makeResult(0),                             // hypertables
                        3 => throw new \RuntimeException('primary throws'),    // agg primary
                        4 => throw new \RuntimeException('fallback throws'),   // agg fallback
                        default => $this->makeResult(0),
                    };
                };
            })()
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert
        $this->assertSame([], $result['aggregates'],
            'aggregates must be [] when both aggregate queries throw');
    }

    /**
     * When getChunkCount() query throws, chunkCount must be 0 rather than
     * propagating the exception. Missing chunks info should not crash the dashboard.
     */
    public function testGetDataReturnsZeroChunkCountWhenQueryThrows(): void
    {
        // Arrange — all queries succeed except getChunkCount
        $db = $this->makeDb('postgresql', false);

        $db->method('query')->willReturnCallback(
            (function () {
                $call = 0;
                return function () use (&$call): mixed {
                    $call++;
                    return match ($call) {
                        1 => $this->makeResult(1, ['extversion' => '2.14.0']),
                        6 => throw new \RuntimeException('chunks view missing'),
                        default => $this->makeResult(0),
                    };
                };
            })()
        );

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert
        $this->assertSame(0, $result['chunkCount'],
            'chunkCount must be 0 when the chunks query throws');
    }

    // ── Return-value completeness ─────────────────────────────────────────────

    /**
     * getData() must always return an array with all six expected keys, even when
     * every query throws. The shape is a contract used by the dashboard template.
     */
    public function testGetDataAlwaysReturnsAllSixKeys(): void
    {
        // Arrange — PostgreSQL + all queries throw
        $db = $this->makeDb('postgresql', true);
        $db->method('query')->willThrowException(new \RuntimeException('DB down'));

        $inspector = new TimescaleInspector($db);

        // Act
        $result = $inspector->getData();

        // Assert — all six keys present regardless of errors
        foreach (['ts_version', 'hypertables', 'aggregates', 'jobs', 'jobHistory', 'chunkCount'] as $key) {
            $this->assertArrayHasKey($key, $result,
                "getData() must always return the '$key' key");
        }
    }
}
