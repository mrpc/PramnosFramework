<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\DeferredWriteQueue;
use Pramnos\Database\HypertableRegistry;
use Pramnos\Database\MigrationLoader;

/**
 * The deferred-write queue against a real TimescaleDB, with real compression.
 *
 * WHAT: a row whose chunk is already compressed is queued, and a later drain
 *       decompresses that chunk once, writes it, and compresses it back.
 * WHY:  unit tests can prove the sequencing of decisions; only a real database
 *       can prove that the compression cutoff is read from the live policy,
 *       that `decompress_chunk`/`compress_chunk` accept the identifiers the
 *       queue hands them, that the JSON payload survives a round trip through a
 *       jsonb column, and — the one that would go unnoticed — that the chunk is
 *       genuinely compressed again afterwards. A chunk left decompressed never
 *       recompresses on its own, because the policy only ever looks at chunks it
 *       has not already handled.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port 5432).
 */
class DeferredWriteQueueTimescaleDBTest extends TestCase
{
    /** @var Database Connection to the TimescaleDB service */
    private Database $db;

    /** @var \Pramnos\Database\SchemaBuilder */
    private $schema;

    /** @var DeferredWriteQueue The object under test */
    private DeferredWriteQueue $queue;

    /** The probe hypertable, created and dropped per test */
    private const TABLE = 'drain_probe';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = $_ENV['PG_HOST'] ?? (getenv('PG_HOST') ?: 'timescaledb');
        $this->db->port     = 5432;
        $this->db->user     = $_ENV['PG_USER'] ?? (getenv('PG_USER') ?: 'postgres');
        $this->db->password = $_ENV['PG_PASS'] ?? (getenv('PG_PASS') ?: 'secret');
        $this->db->database = $_ENV['PG_NAME'] ?? (getenv('PG_NAME') ?: 'pramnos_test');
        $this->db->schema   = 'public';

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('TimescaleDB not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('TimescaleDB not reachable: ' . $e->getMessage());
        }

        $this->schema = $this->db->schema();

        if (!$this->db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('The TimescaleDB extension is not installed here');
        }

        $this->cleanUp();

        // The queue table comes from the framework migration, so that this also
        // proves the migration produces something the queue can actually use.
        $this->migration()->up();

        $this->createProbe();

        HypertableRegistry::register(self::TABLE, [
            'time_column'     => 'measured_at',
            'chunk_interval'  => '1 day',
            'compress_after'  => '7 days',
            'deferred_writes' => true,
            'conflict'        => ['device_id', 'measured_at'],
            'conflict_update' => ['value'],
        ]);

        $this->queue = new DeferredWriteQueue($this->db);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanUp();
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
        HypertableRegistry::reset();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * The migration creates a queue table the queue can write to and read back.
     *
     * The payload column is jsonb on PostgreSQL, and a row stored through the
     * query builder has to come back out as the same array — if it did not, the
     * drain would write mangled rows into the real table.
     */
    public function testTheMigrationCreatesAUsableQueueTable(): void
    {
        // Arrange
        $row = ['device_id' => 1, 'measured_at' => '2020-01-01 00:00:00', 'value' => 1.25];

        // Act
        $this->queue->defer(self::TABLE, $row, strtotime('2020-01-01 00:00:00'));
        $stored = $this->db->queryBuilder()->table(DeferredWriteQueue::TABLE)
            ->where('tablename', self::TABLE)->getAll();

        // Assert
        $this->assertTrue($this->schema->hasTable(DeferredWriteQueue::TABLE));
        $this->assertCount(1, $stored);
        $this->assertSame(
            $row,
            json_decode((string) $stored[0]['data'], true),
            'The payload survived the jsonb round trip unchanged'
        );
        $this->assertSame(1, $this->queue->pending(self::TABLE));
    }

    /**
     * The cutoff is read from the live compression policy.
     *
     * A constant in application code drifts the moment somebody changes the
     * policy, and drifts silently — the writes simply start failing again. The
     * policy here compresses after 7 days, so the cutoff must land within a
     * minute of "seven days ago".
     */
    public function testTheCutoffComesFromTheLiveCompressionPolicy(): void
    {
        // Arrange: a real compression policy on the probe.
        $this->schema->addCompressionPolicy(self::TABLE, '7 days');

        // Act
        $cutoff = $this->queue->writeCutoff(self::TABLE);

        // Assert
        $this->assertNotNull($cutoff);
        $this->assertEqualsWithDelta(
            strtotime('-7 days'),
            $cutoff,
            120,
            'The cutoff is seven days back, as the policy says'
        );
    }

    /**
     * A table with no policy at all defers nothing.
     *
     * That is the state of every backend without TimescaleDB and of every
     * hypertable that is not compressed. Deferring there would leave rows in a
     * queue that nothing needs to drain.
     */
    public function testATableWithNoPolicyAndNoDeclarationDefersNothing(): void
    {
        // Arrange: declared without compress_after, and no policy on the table.
        HypertableRegistry::register(self::TABLE, [
            'time_column'     => 'measured_at',
            'deferred_writes' => true,
        ]);
        $queue = new DeferredWriteQueue($this->db);

        // Act
        $written = $queue->write(self::TABLE, [
            'device_id'   => 3,
            'measured_at' => date('Y-m-d H:i:s', strtotime('-10 years')),
            'value'       => 0.5,
        ]);

        // Assert
        $this->assertNull($queue->writeCutoff(self::TABLE));
        $this->assertTrue($written, 'A ten-year-old row went straight in');
        $this->assertSame(0, $queue->pending(self::TABLE));
        $this->assertSame(1, $this->probeRowCount());
    }

    /**
     * The whole cycle: a late write is queued, then drained into a compressed
     * chunk, which is compressed again afterwards.
     *
     * This is the defect the class exists for, start to finish. The three
     * assertions that matter are that the row is in the hypertable, that the
     * queue is empty, and that the chunk is compressed — the last one because a
     * drain that quietly leaves chunks decompressed would undo the compression
     * the table was given in the first place.
     */
    public function testALateWriteIsQueuedThenDrainedIntoACompressedChunk(): void
    {
        // Arrange: an old row, its chunk compressed, and a live policy.
        $old = strtotime('-30 days');
        $this->insertDirectly(1, $old, 10.0);
        $this->compressEverything();
        $this->schema->addCompressionPolicy(self::TABLE, '7 days');
        $this->assertSame(1, $this->compressedChunkCount(), 'setup: the chunk is compressed');

        // Act: a second reading for the same old day arrives late.
        $written = $this->queue->write(self::TABLE, [
            'device_id'   => 2,
            'measured_at' => date('Y-m-d H:i:s', $old + 60),
            'value'       => 20.0,
        ]);
        $queuedBefore = $this->queue->pending(self::TABLE);
        $stats        = $this->queue->process(self::TABLE);

        // Assert
        $this->assertFalse($written, 'The write was deferred rather than lost');
        $this->assertSame(1, $queuedBefore);
        $this->assertSame(1, $stats[self::TABLE]['inserted']);
        $this->assertSame(1, $stats[self::TABLE]['chunks'], 'One chunk was opened');
        $this->assertSame(0, $stats[self::TABLE]['failed']);
        $this->assertSame(2, $this->probeRowCount(), 'The late row is in the hypertable');
        $this->assertSame(0, $this->queue->pending(self::TABLE), 'The queue drained');
        $this->assertSame(
            1,
            $this->compressedChunkCount(),
            'The chunk was compressed again — otherwise the drain silently costs storage'
        );
    }

    /**
     * Many queued rows in one chunk cost one decompress/compress pair.
     *
     * This is the reason the pattern is worth having at all: the expense is per
     * chunk, not per row. A drain that reported more chunks than there are
     * chunks would mean the pair is being paid per batch.
     */
    public function testABacklogInOneChunkOpensThatChunkOnce(): void
    {
        // Arrange: one compressed chunk, twenty rows waiting inside it.
        $day = strtotime('-30 days');
        $this->insertDirectly(1, $day, 1.0);
        $this->compressEverything();
        $this->schema->addCompressionPolicy(self::TABLE, '7 days');

        for ($i = 2; $i <= 21; $i++) {
            $this->queue->write(self::TABLE, [
                'device_id'   => $i,
                'measured_at' => date('Y-m-d H:i:s', $day + $i),
                'value'       => (float) $i,
            ]);
        }
        $this->assertSame(20, $this->queue->pending(self::TABLE), 'setup: all deferred');

        // Act
        $stats = $this->queue->process(self::TABLE);

        // Assert
        $this->assertSame(20, $stats[self::TABLE]['inserted']);
        $this->assertSame(1, $stats[self::TABLE]['chunks'], 'One chunk, one pair');
        $this->assertSame(21, $this->probeRowCount());
        $this->assertSame(1, $this->compressedChunkCount());
    }

    /**
     * A late row for a key that already exists overwrites it.
     *
     * A corrected reading should replace the stored one; without the declared
     * conflict the drain would either duplicate the row or fail on the primary
     * key and mark a perfectly good correction as failed.
     */
    public function testADeclaredConflictOverwritesTheStoredRow(): void
    {
        // Arrange: a stored reading, and a correction for the same instant.
        $day = strtotime('-30 days');
        $this->insertDirectly(1, $day, 10.0);
        $this->compressEverything();
        $this->schema->addCompressionPolicy(self::TABLE, '7 days');

        // Act
        $this->queue->write(self::TABLE, [
            'device_id'   => 1,
            'measured_at' => date('Y-m-d H:i:s', $day),
            'value'       => 99.0,
        ]);
        $this->queue->process(self::TABLE);

        // Assert
        $this->assertSame(1, $this->probeRowCount(), 'No duplicate row was created');
        $result = $this->db->query(
            'SELECT value FROM ' . self::TABLE . ' WHERE device_id = 1'
        );
        $this->assertEqualsWithDelta(99.0, (float) $result->fields['value'], 0.001);
    }

    /**
     * With no conflict_update declared, every non-key column is rewritten.
     *
     * Declaring the key alone is the common case, and it has to be enough: the
     * columns that are not part of the key are, by definition, the ones the
     * correction is carrying.
     */
    public function testAConflictWithoutAnUpdateListRewritesEveryNonKeyColumn(): void
    {
        // Arrange: the key is declared, the update list is not.
        HypertableRegistry::register(self::TABLE, [
            'time_column'     => 'measured_at',
            'deferred_writes' => true,
            'conflict'        => ['device_id', 'measured_at'],
        ]);
        $queue = new DeferredWriteQueue($this->db);

        $day = strtotime('-30 days');
        $this->insertDirectly(1, $day, 10.0);

        // Act
        $queue->defer(self::TABLE, [
            'device_id'   => 1,
            'measured_at' => date('Y-m-d H:i:s', $day),
            'value'       => 77.0,
        ], $day);
        $stats = $queue->process(self::TABLE);

        // Assert
        $this->assertSame(1, $stats[self::TABLE]['inserted']);
        $this->assertSame(1, $this->probeRowCount());
        $result = $this->db->query(
            'SELECT value FROM ' . self::TABLE . ' WHERE device_id = 1'
        );
        $this->assertEqualsWithDelta(77.0, (float) $result->fields['value'], 0.001);
    }

    /**
     * With no conflict declared at all, the drain does a plain insert.
     *
     * A table where a late row is genuinely a new row — an append-only event
     * log — must not have an ON CONFLICT clause invented for it.
     */
    public function testWithoutADeclaredConflictTheDrainInsertsPlainly(): void
    {
        // Arrange
        HypertableRegistry::register(self::TABLE, [
            'time_column'     => 'measured_at',
            'deferred_writes' => true,
        ]);
        $queue = new DeferredWriteQueue($this->db);
        $day   = strtotime('-30 days');

        // Act
        $queue->defer(self::TABLE, [
            'device_id'   => 4,
            'measured_at' => date('Y-m-d H:i:s', $day),
            'value'       => 4.0,
        ], $day);
        $stats = $queue->process(self::TABLE);

        // Assert
        $this->assertSame(1, $stats[self::TABLE]['inserted']);
        $this->assertSame(1, $this->probeRowCount());
    }

    /**
     * One unwritable row is marked failed; its neighbours are still written.
     *
     * Without the row-by-row replay a single bad row takes its whole batch down
     * on every run, for ever, and nothing says which row is to blame.
     */
    public function testAnUnwritableRowFailsAloneAndKeepsItsError(): void
    {
        // Arrange: two good rows and one whose device_id is null (NOT NULL).
        $day = strtotime('-30 days');
        $this->queue->defer(self::TABLE, [
            'device_id' => 1, 'measured_at' => date('Y-m-d H:i:s', $day), 'value' => 1.0,
        ], $day);
        $this->queue->defer(self::TABLE, [
            'device_id' => null, 'measured_at' => date('Y-m-d H:i:s', $day + 1), 'value' => 2.0,
        ], $day + 1);
        $this->queue->defer(self::TABLE, [
            'device_id' => 3, 'measured_at' => date('Y-m-d H:i:s', $day + 2), 'value' => 3.0,
        ], $day + 2);

        // Act
        $stats = $this->queue->process(self::TABLE);

        // Assert
        $this->assertSame(2, $stats[self::TABLE]['inserted']);
        $this->assertSame(1, $stats[self::TABLE]['failed']);
        $this->assertSame(2, $this->probeRowCount());
        $this->assertSame(1, $this->queue->failed(self::TABLE));

        $failed = $this->db->queryBuilder()->table(DeferredWriteQueue::TABLE)
            ->where('status', DeferredWriteQueue::STATUS_FAILED)->getAll();
        $this->assertNotSame('', (string) $failed[0]['errormessage'], 'The reason was kept');

        // Act: the operator fixes the cause and re-queues.
        $reset = $this->queue->retryFailed(self::TABLE);

        // Assert
        $this->assertSame(1, $reset);
        $this->assertSame(1, $this->queue->pending(self::TABLE));
        $this->assertSame(0, $this->queue->failed(self::TABLE));
    }

    /**
     * Draining without naming a table finds the tables that have work.
     */
    public function testDrainingEverythingFindsTheTablesWithWork(): void
    {
        // Arrange
        $day = strtotime('-30 days');
        $this->queue->defer(self::TABLE, [
            'device_id' => 1, 'measured_at' => date('Y-m-d H:i:s', $day), 'value' => 1.0,
        ], $day);

        // Act
        $tables = $this->queue->tablesWithPendingRows();
        $stats  = $this->queue->process();

        // Assert
        $this->assertSame([self::TABLE], $tables);
        $this->assertSame(1, $stats[self::TABLE]['inserted']);
    }

    /**
     * compressChunk()/decompressChunk() work on the identifiers the catalog reports.
     *
     * The internal chunk names are generated, live in `_timescaledb_internal`,
     * and must be quoted as identifiers rather than pasted into SQL. This is the
     * only test that exercises those two methods on their own.
     */
    public function testTheChunkCompressionRoundTrip(): void
    {
        // Arrange
        $this->insertDirectly(1, strtotime('-30 days'), 1.0);
        $chunk = $this->schema->getChunks(self::TABLE)[0];

        // Act
        $compressed = $this->schema->compressChunk($chunk->chunk_schema, $chunk->chunk_name);
        $afterCompress = $this->compressedChunkCount();
        $this->schema->decompressChunk($chunk->chunk_schema, $chunk->chunk_name);

        // Assert
        $this->assertTrue($compressed);
        $this->assertSame(1, $afterCompress);
        $this->assertSame(0, $this->compressedChunkCount());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The framework migration that creates the queue table.
     */
    private function migration(): \Pramnos\Database\Migration
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        $dir = dirname(__DIR__, 3) . '/database/migrations/framework/core';
        foreach (MigrationLoader::loadFromDirectory($dir, $app) as $migration) {
            if ((new \ReflectionClass($migration))->getShortName() === 'CreateDeferredwritesTable') {
                return $migration;
            }
        }

        $this->fail('CreateDeferredwritesTable was not found under migrations/framework/core');
    }

    /**
     * Build the probe as a compressible hypertable.
     */
    private function createProbe(): void
    {
        $this->db->execute(
            'CREATE TABLE ' . self::TABLE . ' ('
            . ' device_id INTEGER NOT NULL,'
            . ' measured_at TIMESTAMPTZ NOT NULL,'
            . ' value DOUBLE PRECISION,'
            . ' PRIMARY KEY (device_id, measured_at))'
        );
        $this->schema->createHypertable(self::TABLE, 'measured_at', [
            'chunk_time_interval' => '1 day',
            'if_not_exists'       => true,
        ]);
        $this->schema->enableCompression(self::TABLE, ['segmentby' => 'device_id']);
    }

    /**
     * Write straight into the hypertable, bypassing the queue.
     */
    private function insertDirectly(int $deviceId, int $timestamp, float $value): void
    {
        $this->db->execute(
            $this->db->prepareQuery(
                'INSERT INTO ' . self::TABLE . ' (device_id, measured_at, value)'
                . ' VALUES (%d, %s, %s)',
                $deviceId,
                date('Y-m-d H:i:s', $timestamp),
                (string) $value
            )
        );
    }

    /** Compress every chunk of the probe, whatever the policy would decide. */
    private function compressEverything(): void
    {
        foreach ($this->schema->getChunks(self::TABLE) as $chunk) {
            $this->schema->compressChunk($chunk->chunk_schema, $chunk->chunk_name);
        }
    }

    /** How many of the probe's chunks are compressed right now. */
    private function compressedChunkCount(): int
    {
        $count = 0;
        foreach ($this->schema->getChunks(self::TABLE) as $chunk) {
            if ($chunk->is_compressed === true || $chunk->is_compressed === 't') {
                $count++;
            }
        }

        return $count;
    }

    /** Rows currently in the probe hypertable. */
    private function probeRowCount(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS cnt FROM ' . self::TABLE);

        return (int) ($result->fields['cnt'] ?? 0);
    }

    /** Remove everything this test creates. */
    private function cleanUp(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE . ' CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS ' . DeferredWriteQueue::TABLE . ' CASCADE');
    }
}
