<?php

namespace Pramnos\Tests\Integration\Database;

/**
 * QueryBuilder integration tests — TimescaleDB dialect.
 *
 * Extends the PostgreSQL test class to run the full QB test suite against the
 * timescaledb container with the TimescaleDB grammar active ($db->timescale = true).
 *
 * Two paths are verified:
 *
 * 1. All inherited PostgreSQL tests continue to pass — TimescaleDB is a superset
 *    of PostgreSQL and every PG-dialect query must work identically.
 *
 * 2. testTimeBucketGroupByHourOnTimescaleDB() — verifies that timeBucket()
 *    generates native time_bucket() SQL (not DATE_TRUNC) and that it executes
 *    correctly against a real TimescaleDB 14 instance.
 *
 * The only difference in setUp() is setting $this->db->timescale = true after
 * the parent connection is established. This switches the QB grammar from
 * PostgreSQLGrammar to TimescaleDBGrammar for all subsequent queryBuilder() calls.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432)
 * with the timescaledb extension installed.
 */
class QueryBuilderTimescaleDBTest extends QueryBuilderPostgreSQLTest
{
    protected function setUp(): void
    {
        // Establish the PostgreSQL connection via the parent (timescaledb container).
        parent::setUp();

        // Enable TimescaleDB grammar: timeBucket() → native time_bucket() function.
        // The QB grammar is resolved lazily per queryBuilder() call, so setting the
        // flag here is sufficient — no cached grammar to invalidate.
        $this->db->timescale = true;
    }

    // -------------------------------------------------------------------------
    // timeBucket() — TimescaleDB native time_bucket()
    // -------------------------------------------------------------------------

    /**
     * timeBucket() on TimescaleDB must use the native time_bucket() function
     * (not DATE_TRUNC) and the query must execute successfully on a real
     * TimescaleDB 14 instance.
     *
     * We seed qb_events with 4 rows: 2 in the 09:xx hour and 2 in the 11:xx hour.
     * GROUP BY with a 1-hour bucket must produce exactly 2 groups each containing
     * 2 events, proving that time_bucket('1 hour', col) is valid SQL on this backend.
     *
     * This is the third database in the "× 3 databases" requirement for timeBucket().
     * The inherited testTimeBucketGroupByHourOnPostgres() would also pass here (with
     * DATE_TRUNC), but this test specifically enables the native time_bucket() path.
     */
    public function testTimeBucketGroupByHourOnTimescaleDB(): void
    {
        // Arrange — 2 events per hour in two distinct hourly windows
        $this->db->execute("INSERT INTO qb_events (name, event_time) VALUES
            ('a', '2026-03-15 09:05:00+00'),
            ('b', '2026-03-15 09:55:00+00'),
            ('c', '2026-03-15 11:10:00+00'),
            ('d', '2026-03-15 11:50:00+00')
        ");

        // Act — timeBucket() must resolve to time_bucket() via TimescaleDBGrammar
        $qb     = $this->db->queryBuilder()->from('qb_events');
        $bucket = $qb->timeBucket('1 hour', 'event_time');

        // Verify the SQL string uses native time_bucket() — not DATE_TRUNC
        $this->assertStringContainsString(
            "time_bucket('1 hour', event_time)",
            (string) $bucket,
            'TimescaleDB grammar must produce native time_bucket() SQL'
        );

        $result = $qb
            ->select([$bucket, $qb->raw('COUNT(*) AS cnt')])
            ->groupBy([$bucket])
            ->orderByRaw('1 ASC')
            ->get();

        // Assert — two distinct hour buckets, each containing exactly 2 events
        $this->assertSame(2, $result->numRows, 'must produce 2 hourly time buckets');

        $counts = [];
        while ($result->fetch()) {
            $counts[] = (int) $result->fields['cnt'];
        }
        $this->assertSame([2, 2], $counts, 'each hourly bucket must contain exactly 2 events');
    }
}
