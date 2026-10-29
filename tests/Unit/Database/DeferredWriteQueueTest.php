<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\DeferredWriteQueue;
use Pramnos\Database\HypertableRegistry;

/**
 * A queue with the database replaced by arrays.
 *
 * Everything that touches a connection is overridden, which leaves exactly what
 * these tests are about: the decisions. Whether a row is deferred, how a batch
 * that raises is recovered, whether a chunk that was decompressed is compressed
 * again, and what an upsert is asked to overwrite.
 */
class ArrayBackedQueue extends DeferredWriteQueue
{
    /** @var int|null What the compression policy is pretended to say */
    public ?int $cutoff = null;

    /** @var list<array<string, mixed>> Rows handed to defer() */
    public array $deferred = [];

    /** @var list<array{table: string, row: array<string, mixed>}> Rows written */
    public array $written = [];

    /** @var list<array{row: array<string, mixed>, conflict: list<string>, update: list<string>}> */
    public array $upserts = [];

    /** @var list<list<array<string, mixed>>> Batches pendingRows() will hand out */
    public array $batches = [];

    /** @var list<object> Chunks chunksWithPendingRows() will report */
    public array $chunks = [];

    /** @var list<string> Rows whose payload must throw when written */
    public array $poison = [];

    /** @var list<string> Every decompress/compress/transaction step, in order */
    public array $log = [];

    /** @var list<array{id: int, message: string}> Rows marked failed */
    public array $failures = [];

    /** @var list<int> Queue ids deleted after a successful write */
    public array $deletedIds = [];

    /** @var bool Whether the server is pretended to take late rows directly (TimescaleDB 2.31+) */
    public bool $directly = false;

    /** @var array{rows: int, pending: int, share: float} What a chunk is pretended to hold and await */
    public array $chunkShape = ['rows' => 1000, 'pending' => 10, 'share' => 0.0];

    /** @var string|null Where the WAL is pretended to stand when a drain begins; null: no WAL */
    public ?string $wal = null;

    /** @var list<int> WAL written since the start, one reading per chunk asked */
    public array $walReadings = [];

    /** @var int|null How far behind a replica is pretended to be */
    public ?int $lag = null;

    /** No connection: every method that would need one is overridden below. */
    public function __construct()
    {
    }

    protected function lookupCutoff(string $table): ?int
    {
        return $this->cutoff;
    }

    public function defer(string $table, array $row, int $timestamp): void
    {
        $this->deferred[] = [
            'table'     => $table,
            'row'       => $row,
            'timestamp' => $timestamp,
        ];
    }

    protected function insert(string $table, array $row): void
    {
        if (in_array($row['tag'] ?? '', $this->poison, true)) {
            throw new \RuntimeException('poisoned row: ' . $row['tag']);
        }

        $spec = HypertableRegistry::spec($table) ?? [];
        if (is_array($spec['conflict'] ?? null) && $spec['conflict'] !== []) {
            $update = $spec['conflict_update'] ?? null;
            if (!is_array($update) || $update === []) {
                $update = array_values(array_diff(array_keys($row), $spec['conflict']));
            }
            $this->upserts[] = [
                'row'      => $row,
                'conflict' => $spec['conflict'],
                'update'   => $update,
            ];
        }

        $this->written[] = ['table' => $table, 'row' => $row];
    }

    protected function pendingRows(string $table, ?int $from, ?int $to): array
    {
        return array_shift($this->batches) ?? [];
    }

    protected function chunksWithPendingRows(string $table): array
    {
        return $this->chunks;
    }

    protected function deleteRows(array $ids): void
    {
        foreach ($ids as $id) {
            $this->deletedIds[] = $id;
        }
    }

    protected function markFailed(int $id, string $message): void
    {
        $this->failures[] = ['id' => $id, 'message' => $message];
    }

    protected function countByStatus(int $status, ?string $table): int
    {
        return 0;
    }

    protected function decompress(object $chunk): void
    {
        $this->log[] = 'decompress:' . $chunk->chunk_name;
    }

    protected function recompress(object $chunk): void
    {
        $this->log[] = 'compress:' . $chunk->chunk_name;
    }

    protected function beginBatch(): void
    {
        $this->log[] = 'begin';
    }

    protected function commitBatch(): void
    {
        $this->log[] = 'commit';
    }

    protected function rollbackBatch(): void
    {
        $this->log[] = 'rollback';
    }

    protected function writesDirectly(): bool
    {
        return $this->directly;
    }

    protected function chunkRows(object $chunk): int
    {
        return $this->chunkShape['rows'];
    }

    protected function pendingCount(string $table, int $from, int $to): int
    {
        return $this->chunkShape['pending'];
    }

    protected function correctionShare(string $table, object $chunk, array $conflict): float
    {
        return $this->chunkShape['share'];
    }

    protected function walPosition(): ?string
    {
        return $this->wal;
    }

    protected function walSince(string $position): int
    {
        return array_shift($this->walReadings) ?? 0;
    }

    protected function replicaLag(): ?int
    {
        return $this->lag;
    }

    /** Whether a compressed chunk would be rewritten — the decision, exposed to test it alone. */
    public function decides(string $table, object $chunk): bool
    {
        return $this->rewritesChunk($table, $chunk);
    }
}

/**
 * A queue whose only fiction is what the compression policy says.
 *
 * Unlike {@see ArrayBackedQueue} this one keeps the real cutoff logic, so that
 * the order of its three sources — the live policy, the declaration, nothing —
 * is what the tests actually exercise.
 */
class PolicyBackedQueue extends DeferredWriteQueue
{
    /** @var bool Whether the connection is pretended to have the extension */
    public bool $timescale = true;

    /** @var int|null What the live policy reports */
    public ?int $live = null;

    /** @var bool Whether reading the policy raises */
    public bool $catalogBroken = false;

    /** No connection: the two methods that would need one are overridden. */
    public function __construct()
    {
    }

    protected function hasTimescaleDB(): bool
    {
        return $this->timescale;
    }

    protected function livePolicyCutoff(string $table): ?int
    {
        if ($this->catalogBroken) {
            throw new \RuntimeException('catalog unreadable');
        }

        return $this->live;
    }
}

/**
 * The queue with nothing overridden and no connection behind it.
 *
 * For the paths that return before they touch the database.
 */
class DeferredWriteQueueWithoutConnection extends DeferredWriteQueue
{
    /** No connection; only the early-return paths are safe to call. */
    public function __construct()
    {
    }
}

/**
 * The decisions the deferred-write queue makes, without a database.
 *
 * The value of this class is not that it can store a row — any table can do
 * that. It is that the expensive decompress/compress pair is paid once per
 * chunk however large the backlog, that a single unwritable row does not jam
 * the queue behind it, and that a chunk is never left decompressed. Those are
 * the properties these tests pin down.
 */
class DeferredWriteQueueTest extends TestCase
{
    protected function setUp(): void
    {
        HypertableRegistry::reset();
        HypertableRegistry::register('readings', [
            'time_column'     => 'measured_at',
            'chunk_interval'  => '1 day',
            'compress_after'  => '7 days',
            'deferred_writes' => true,
        ]);
    }

    protected function tearDown(): void
    {
        HypertableRegistry::reset();
        parent::tearDown();
    }

    /**
     * A row older than the cutoff is queued; a recent one is written.
     *
     * This is the whole write-side contract. Getting it backwards would either
     * lose every late row (the defect this exists to fix) or queue everything
     * and stop writing to the table at all.
     */
    public function testDefersOnlyRowsOlderThanTheCutoff(): void
    {
        // Arrange: the policy compresses anything older than an hour ago.
        $queue = new ArrayBackedQueue();
        $queue->cutoff = time() - 3600;

        // Act
        $recent = $queue->write('readings', ['measured_at' => time(), 'value' => 1]);
        $late   = $queue->write('readings', ['measured_at' => time() - 7200, 'value' => 2]);

        // Assert
        $this->assertTrue($recent, 'A row inside the writable window goes straight in');
        $this->assertFalse($late, 'A row behind the cutoff is queued instead');
        $this->assertCount(1, $queue->written);
        $this->assertCount(1, $queue->deferred);
        $this->assertSame(2, $queue->deferred[0]['row']['value']);
    }

    /**
     * With no compression policy, nothing is ever deferred.
     *
     * This is the state of every MySQL install, every development box without
     * the extension, and every CI run. If the queue deferred there, the rows
     * would sit unwritten until somebody noticed — so "no cutoff" must mean
     * "write it".
     */
    public function testWritesDirectlyWhenThereIsNoCutoff(): void
    {
        // Arrange: no policy to read.
        $queue = new ArrayBackedQueue();
        $queue->cutoff = null;

        // Act: a row from ten years ago, which no cutoff makes late.
        $written = $queue->write('readings', [
            'measured_at' => time() - 10 * 365 * 86400,
            'value'       => 3,
        ]);

        // Assert
        $this->assertTrue($written);
        $this->assertSame([], $queue->deferred);
    }

    /**
     * The cutoff is read once and reused, and forgetCutoffs() re-reads it.
     *
     * A bulk import calls write() once per row; a lookup per row would turn one
     * policy query into a million.
     */
    public function testCachesTheCutoffUntilItIsForgotten(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->cutoff = 1000;

        // Act: read, change what the "policy" says, read again, then forget.
        $first = $queue->writeCutoff('readings');
        $queue->cutoff = 2000;
        $cached = $queue->writeCutoff('readings');
        $queue->forgetCutoffs();
        $fresh = $queue->writeCutoff('readings');

        // Assert
        $this->assertSame(1000, $first);
        $this->assertSame(1000, $cached, 'The second read did not hit the database');
        $this->assertSame(2000, $fresh, 'Forgetting makes the next read live again');
    }

    /**
     * A null cutoff is cached too.
     *
     * The absence of a policy is an answer, and re-asking for it on every row
     * is exactly as expensive as re-asking for a real one — the case a plain
     * `if ($cached)` guard would get wrong.
     */
    public function testCachesTheAbsenceOfACutoff(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->cutoff = null;

        // Act
        $queue->writeCutoff('readings');
        $queue->cutoff = 5000;
        $second = $queue->writeCutoff('readings');

        // Assert
        $this->assertNull($second, 'null was remembered rather than re-read');
    }

    /**
     * The row's time comes from the declared time column when none is passed.
     */
    public function testReadsTheTimeFromTheDeclaredColumn(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->cutoff = time();

        // Act: no explicit time — measured_at is the declared time_column.
        $queue->write('readings', ['measured_at' => 100, 'value' => 1]);

        // Assert
        $this->assertSame(100, $queue->deferred[0]['timestamp']);
    }

    /**
     * A date string in the time column is understood.
     *
     * Applications store times as strings as often as as integers; refusing one
     * of the two would make the write path depend on the column's PHP type.
     */
    public function testAcceptsADateStringAsTheTime(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->cutoff = time();

        // Act
        $queue->write('readings', ['measured_at' => '2020-01-01 12:00:00']);

        // Assert
        $this->assertSame(
            strtotime('2020-01-01 12:00:00'),
            $queue->deferred[0]['timestamp']
        );
    }

    /**
     * A row with no readable time is refused loudly.
     *
     * Guessing "now" would silently write historical data into today's chunk,
     * which is worse than failing: the row is there, in the wrong place, and
     * nothing says so.
     */
    public function testRefusesARowWithNoUsableTime(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act: no measured_at, no explicit time.
        $queue->write('readings', ['value' => 1]);
    }

    /**
     * An explicit time overrides whatever the row carries.
     */
    public function testAnExplicitTimeWins(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->cutoff = time();

        // Act
        $queue->write('readings', ['measured_at' => 999, 'value' => 1], 555);

        // Assert
        $this->assertSame(555, $queue->deferred[0]['timestamp']);
    }

    /**
     * A chunk is decompressed once, written to, and compressed again.
     *
     * This is the point of the whole design. The assertion is on the *order and
     * count* of the steps: two batches inside one chunk must still produce one
     * decompress and one compress, because the alternative — a pair per batch,
     * or per row — is what makes the naive implementation unusable.
     */
    public function testPaysTheCompressionPairOncePerChunk(): void
    {
        // Arrange: one compressed chunk, two batches of rows inside it.
        $queue = new ArrayBackedQueue();
        $queue->chunks = [(object) [
            'chunk_schema'   => '_timescaledb_internal',
            'chunk_name'     => '_hyper_1_1_chunk',
            'is_compressed'  => true,
            'range_start_ts' => 0,
            'range_end_ts'   => 100,
        ]];
        $queue->batches = [
            [['id' => 1, 'data' => '{"value":1}']],
            [['id' => 2, 'data' => '{"value":2}']],
            [],   // chunk drained
            [],   // nothing outside the chunks either
        ];

        // Act
        $stats = $queue->process('readings');

        // Assert
        $this->assertSame(2, $stats['readings']['inserted']);
        $this->assertSame(1, $stats['readings']['chunks']);
        $this->assertSame(
            [
                'decompress:_hyper_1_1_chunk',
                'begin', 'commit',
                'begin', 'commit',
                'compress:_hyper_1_1_chunk',
            ],
            $queue->log,
            'One pair, wrapped around both batches'
        );
    }

    /**
     * A caller that asks for progress is told which chunk is being opened.
     *
     * A drain over a large backlog can run for minutes with nothing to show;
     * the console command exists to be watched, so the queue has to say what it
     * is doing while it does it.
     */
    public function testProgressIsReportedPerChunk(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->chunks = [(object) [
            'chunk_schema'   => '_timescaledb_internal',
            'chunk_name'     => '_hyper_9_9_chunk',
            'is_compressed'  => true,
            'range_start_ts' => 0,
            'range_end_ts'   => 100,
        ]];
        $queue->batches = [[], []];
        $said = [];

        // Act
        $queue->process('readings', function (string $line) use (&$said): void {
            $said[] = $line;
        });

        // Assert
        $this->assertCount(1, $said);
        $this->assertStringContainsString('_hyper_9_9_chunk', $said[0]);
        $this->assertStringContainsString('compressed', $said[0]);
    }

    /**
     * An uncompressed chunk is left alone.
     *
     * Compressing a chunk the policy has not compressed yet would change the
     * table's storage behind the operator's back.
     */
    public function testDoesNotTouchCompressionOnAnUncompressedChunk(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->chunks = [(object) [
            'chunk_schema'   => '_timescaledb_internal',
            'chunk_name'     => '_hyper_1_2_chunk',
            'is_compressed'  => false,
            'range_start_ts' => 0,
            'range_end_ts'   => 100,
        ]];
        $queue->batches = [[['id' => 1, 'data' => '{"value":1}']], [], []];

        // Act
        $queue->process('readings');

        // Assert
        $this->assertSame(['begin', 'commit'], $queue->log);
    }

    /**
     * PostgreSQL's `'t'` counts as compressed.
     *
     * Depending on driver and fetch mode the flag arrives as `true`, `'t'` or
     * `'1'`. Reading `'t'` as false would skip the decompress and then fail
     * every insert into that chunk — a failure that looks like bad data rather
     * than a type bug.
     */
    public function testTreatsThePostgresBooleanSpellingsAsCompressed(): void
    {
        foreach ([true, 't', '1', 1] as $flag) {
            // Arrange
            $queue = new ArrayBackedQueue();
            $queue->chunks = [(object) [
                'chunk_schema'   => '_timescaledb_internal',
                'chunk_name'     => 'c',
                'is_compressed'  => $flag,
                'range_start_ts' => 0,
                'range_end_ts'   => 100,
            ]];
            $queue->batches = [[], []];

            // Act
            $queue->process('readings');

            // Assert
            $this->assertContains(
                'decompress:c',
                $queue->log,
                'is_compressed spelled as ' . var_export($flag, true)
            );
        }
    }

    /**
     * One bad row fails alone; the rest of its batch is still written.
     *
     * Without the row-by-row replay, a single unwritable row would take five
     * hundred good ones down with it on every run, for ever — the queue would
     * never drain and nothing would say which row was to blame.
     */
    public function testAFailedBatchIsReplayedRowByRow(): void
    {
        // Arrange: three rows, the middle one unwritable.
        $queue = new ArrayBackedQueue();
        $queue->poison  = ['bad'];
        $queue->batches = [
            [
                ['id' => 1, 'data' => '{"tag":"ok1"}'],
                ['id' => 2, 'data' => '{"tag":"bad"}'],
                ['id' => 3, 'data' => '{"tag":"ok2"}'],
            ],
            [],
        ];

        // Act
        $stats = $queue->process('readings');

        // Assert
        $this->assertSame(2, $stats['readings']['inserted'], 'The blameless rows landed');
        $this->assertSame(1, $stats['readings']['failed']);
        $this->assertSame([1, 3], $queue->deletedIds, 'Only written rows leave the queue');
        $this->assertSame(2, $queue->failures[0]['id']);
        $this->assertStringContainsString('poisoned row', $queue->failures[0]['message']);
        $this->assertContains('rollback', $queue->log, 'The failed batch was rolled back');
    }

    /**
     * A chunk is compressed again even when its batch raised.
     *
     * A chunk left decompressed never compresses again on its own — the policy
     * only looks at chunks it has not already handled — so a failure would
     * quietly undo the compression the table exists to get.
     */
    public function testRecompressesEvenWhenEveryRowFails(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->poison = ['bad'];
        $queue->chunks = [(object) [
            'chunk_schema'   => '_timescaledb_internal',
            'chunk_name'     => 'c1',
            'is_compressed'  => true,
            'range_start_ts' => 0,
            'range_end_ts'   => 100,
        ]];
        $queue->batches = [[['id' => 1, 'data' => '{"tag":"bad"}']], [], []];

        // Act
        $stats = $queue->process('readings');

        // Assert
        $this->assertSame(1, $stats['readings']['failed']);
        $this->assertSame('compress:c1', end($queue->log), 'The chunk was put back');
    }

    /**
     * Rows that fall outside every chunk are still written.
     *
     * This is the ordinary path on a database with no compression at all: no
     * chunks are reported, so everything queued goes through the final pass.
     */
    public function testWritesRowsThatBelongToNoChunk(): void
    {
        // Arrange: no chunks reported at all.
        $queue = new ArrayBackedQueue();
        $queue->batches = [[['id' => 7, 'data' => '{"value":9}']], []];

        // Act
        $stats = $queue->process('readings');

        // Assert
        $this->assertSame(1, $stats['readings']['inserted']);
        $this->assertSame(0, $stats['readings']['chunks']);
        $this->assertSame([7], $queue->deletedIds);
    }

    /**
     * A queue row whose payload is unusable fails rather than writing nothing.
     *
     * An empty or corrupt JSON payload would otherwise insert an empty row, or
     * be silently skipped and re-read for ever on every subsequent run.
     */
    public function testAnUnusablePayloadIsMarkedFailed(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->batches = [[['id' => 4, 'data' => 'not json']], []];

        // Act
        $stats = $queue->process('readings');

        // Assert
        $this->assertSame(1, $stats['readings']['failed']);
        $this->assertSame(4, $queue->failures[0]['id']);
        $this->assertSame([], $queue->written, 'Nothing was written for it');
    }

    /**
     * A declared conflict makes the write an upsert over the non-key columns.
     *
     * A re-sent late reading should correct the stored one, not add a second
     * row for the same instant — and the columns to rewrite default to
     * "everything that is not part of the key", so declaring the key is enough.
     */
    public function testADeclaredConflictUpsertsOverTheNonKeyColumns(): void
    {
        // Arrange
        HypertableRegistry::register('readings', [
            'time_column'     => 'measured_at',
            'deferred_writes' => true,
            'conflict'        => ['device_id', 'measured_at'],
        ]);
        $queue = new ArrayBackedQueue();
        $queue->batches = [
            [['id' => 1, 'data' => '{"device_id":5,"measured_at":10,"value":1.5}']],
            [],
        ];

        // Act
        $queue->process('readings');

        // Assert
        $this->assertCount(1, $queue->upserts);
        $this->assertSame(['device_id', 'measured_at'], $queue->upserts[0]['conflict']);
        $this->assertSame(['value'], $queue->upserts[0]['update']);
    }

    /**
     * A declared conflict_update narrows what a conflict rewrites.
     *
     * Some columns must survive an overwrite — an "imported_at" audit stamp,
     * a flag set by an operator — so the declaration wins over the default.
     */
    public function testADeclaredConflictUpdateIsHonoured(): void
    {
        // Arrange
        HypertableRegistry::register('readings', [
            'time_column'     => 'measured_at',
            'deferred_writes' => true,
            'conflict'        => ['device_id', 'measured_at'],
            'conflict_update' => ['value'],
        ]);
        $queue = new ArrayBackedQueue();
        $queue->batches = [
            [['id' => 1, 'data' => '{"device_id":5,"measured_at":10,"value":1.5,"source":"x"}']],
            [],
        ];

        // Act
        $queue->process('readings');

        // Assert
        $this->assertSame(
            ['value'],
            $queue->upserts[0]['update'],
            'source was left alone because the declaration did not name it'
        );
    }

    /**
     * A schema-qualified table is looked up by its bare name.
     *
     * TimescaleDB's catalog stores `hypertable_name` without a schema, so
     * `authserver.user_activity_log` has to become `user_activity_log` before
     * anything is asked about it — otherwise every lookup matches nothing and
     * the queue silently believes there are no chunks.
     */
    public function testStripsTheSchemaWhenAskingTimescaleAboutATable(): void
    {
        // Arrange
        $queue  = new ArrayBackedQueue();
        $method = new \ReflectionMethod(DeferredWriteQueue::class, 'unqualified');

        // Act
        $qualified = $method->invoke($queue, 'authserver.user_activity_log');
        $bare      = $method->invoke($queue, 'readings');

        // Assert
        $this->assertSame('user_activity_log', $qualified);
        $this->assertSame('readings', $bare);
    }

    /**
     * A row judged writable that turns out not to be is queued, not lost.
     *
     * The cutoff is read once while the compression policy runs on its own
     * schedule, so a row can be cleared for a direct write and then meet a
     * chunk that was compressed a second later. Letting that exception escape
     * would lose exactly the rows this class exists to keep.
     */
    public function testARowThatFailsItsDirectWriteIsQueuedInstead(): void
    {
        // Arrange: no cutoff, so the row is cleared — but the write raises.
        $queue = new ArrayBackedQueue();
        $queue->poison = ['racy'];

        // Act
        $written = $queue->write('readings', ['measured_at' => time(), 'tag' => 'racy']);

        // Assert
        $this->assertFalse($written);
        $this->assertCount(1, $queue->deferred, 'The row was kept rather than dropped');
        $this->assertSame([], $queue->written);
    }

    /**
     * Without TimescaleDB there is no cutoff, whatever the registry declares.
     *
     * The declaration describes what the table should look like on a database
     * that can compress. On MySQL, and on PostgreSQL without the extension,
     * honouring it would queue rows that nothing is stopping from being written.
     */
    public function testWithoutTimescaleThereIsNoCutoffAtAll(): void
    {
        // Arrange: 'readings' is declared with compress_after => 7 days.
        $queue = new PolicyBackedQueue();
        $queue->timescale = false;

        // Act & Assert
        $this->assertNull($queue->writeCutoff('readings'));
    }

    /**
     * The live policy is preferred over the declaration.
     */
    public function testTheLivePolicyWinsOverTheDeclaration(): void
    {
        // Arrange
        $queue = new PolicyBackedQueue();
        $queue->live = 123456;

        // Act & Assert
        $this->assertSame(123456, $queue->writeCutoff('readings'));
    }

    /**
     * With no policy on the database, the declared compress_after is used.
     *
     * A table that the framework says should be compressed but is not yet — a
     * database that gained TimescaleDB late, before `timescale:ensure` ran — is
     * about to be compressed. Writing into the range the next policy run will
     * take is a row that vanishes at an unpredictable moment.
     */
    public function testItFallsBackToTheDeclaredCompressAfter(): void
    {
        // Arrange: extension present, no policy row.
        $queue = new PolicyBackedQueue();
        $queue->live = null;

        // Act
        $cutoff = $queue->writeCutoff('readings');

        // Assert
        $this->assertEqualsWithDelta(strtotime('-7 days'), $cutoff, 5);
    }

    /**
     * An unreadable catalog falls back rather than failing the write.
     *
     * A version of TimescaleDB that renames a column in
     * `timescaledb_information.jobs` would otherwise take the application's
     * writes down with it.
     */
    public function testAnUnreadableCatalogFallsBackToTheDeclaration(): void
    {
        // Arrange
        $queue = new PolicyBackedQueue();
        $queue->catalogBroken = true;

        // Act
        $cutoff = $queue->writeCutoff('readings');

        // Assert
        $this->assertEqualsWithDelta(strtotime('-7 days'), $cutoff, 5);
    }

    /**
     * An undeclared table has no cutoff and is therefore never deferred.
     */
    public function testAnUndeclaredTableHasNoCutoff(): void
    {
        // Arrange
        $queue = new PolicyBackedQueue();

        // Act & Assert
        $this->assertNull($queue->writeCutoff('never_declared'));
    }

    /**
     * A numeric string is accepted as a unix timestamp.
     *
     * Integer columns come back from PDO as strings; refusing them would make
     * the write path depend on the driver's fetch mode.
     */
    public function testANumericStringIsAUnixTimestamp(): void
    {
        // Arrange
        $queue = new ArrayBackedQueue();
        $queue->cutoff = time();

        // Act
        $queue->write('readings', ['measured_at' => '1234567890']);

        // Assert
        $this->assertSame(1234567890, $queue->deferred[0]['timestamp']);
    }

    /**
     * Retrying nothing does nothing.
     *
     * The early return keeps an empty queue from issuing a table-wide UPDATE on
     * every cron run.
     */
    public function testRetryingAnEmptyQueueIsANoOp(): void
    {
        // Arrange: the double reports zero rows in every state.
        $queue = new ArrayBackedQueue();

        // Act & Assert
        $this->assertSame(0, $queue->retryFailed('readings'));
        $this->assertSame(0, $queue->pending('readings'));
    }

    /**
     * Deleting an empty set of ids issues no statement.
     *
     * Reached when a batch fails and every row in it fails individually too —
     * a `WHERE id IN ()` would be a syntax error on both backends.
     */
    public function testDeletingNoRowsIssuesNoStatement(): void
    {
        // Arrange
        $queue  = new DeferredWriteQueueWithoutConnection();
        $method = new \ReflectionMethod(DeferredWriteQueue::class, 'deleteRows');

        // Act
        $method->invoke($queue, []);

        // Assert: reaching here without a database call is the assertion.
        $this->assertTrue(true);
    }

    /**
     * A backend without TimescaleDB reports no chunks, rather than raising.
     *
     * `timescaledb_information.chunks` does not exist there; asking for it
     * would turn every drain on MySQL into an error.
     */
    public function testABackendWithoutTimescaleReportsNoChunks(): void
    {
        // Arrange
        $queue = new PolicyBackedQueue();
        $queue->timescale = false;
        $method = new \ReflectionMethod(DeferredWriteQueue::class, 'chunksWithPendingRows');

        // Act
        $chunks = $method->invoke($queue, 'readings');

        // Assert
        $this->assertSame([], $chunks);
    }

    /**
     * The registry reports which tables opted into deferred writes.
     *
     * Only tables that declared it should be drained; a table that merely has a
     * compression policy has not asked for its late writes to be caught.
     */
    public function testTheRegistryListsOnlyDeferrableTables(): void
    {
        // Arrange
        HypertableRegistry::register('plain', [
            'time_column'    => 'created_at',
            'compress_after' => '7 days',
        ]);

        // Act
        $deferrable = HypertableRegistry::deferrable();

        // Assert
        $this->assertArrayHasKey('readings', $deferrable);
        $this->assertArrayNotHasKey('plain', $deferrable);
    }

    /**
     * Every declaration comes back with every key filled in.
     *
     * Callers read `$spec['conflict']` directly; a spec missing the key would
     * make that a notice on PHP 8 and, worse, make the read depend on how the
     * table happened to be registered.
     */
    public function testEveryDeclarationCarriesTheFullSetOfKeys(): void
    {
        // Arrange & Act
        $spec = HypertableRegistry::spec('tokenactions');

        // Assert
        $this->assertArrayHasKey('deferred_writes', $spec);
        $this->assertArrayHasKey('conflict', $spec);
        $this->assertArrayHasKey('conflict_update', $spec);
        $this->assertFalse($spec['deferred_writes'], 'Opting in is explicit');
    }

    /** A compressed chunk of the shape the drain reports. */
    private static function compressedChunk(string $name = '_hyper_1_1_chunk'): object
    {
        return (object) [
            'chunk_schema'   => '_timescaledb_internal',
            'chunk_name'     => $name,
            'is_compressed'  => true,
            'range_start_ts' => 0,
            'range_end_ts'   => 100,
        ];
    }

    /**
     * On a server that takes late rows directly (TimescaleDB 2.31+), one late value is written
     * straight into its compressed chunk; an import that says so is queued from its first row;
     * and a loop of single writes past BULK_AFTER is taken for an import and queued from there.
     * On an older server every late row is queued, as it always was.
     *
     * One reading that arrives late costs tens of kilobytes written directly, against a whole
     * chunk rewritten once drained; an import of millions written one by one would cost more
     * than the per-chunk drain, which is what it is for.
     */
    public function testOneLateValueIsWrittenAndAnImportIsQueued(): void
    {
        // Arrange
        $late  = ['measured_at' => time() - 7200, 'value' => 1];
        $queue = new ArrayBackedQueue();
        $queue->cutoff   = time() - 3600;
        $queue->directly = true;

        // Act — a run of single values up to the limit, then one more
        $written = 0;
        for ($i = 0; $i < DeferredWriteQueue::BULK_AFTER; $i++) {
            $written += $queue->write('readings', $late) ? 1 : 0;
        }
        $pastTheLimit = $queue->write('readings', $late);
        $import = new ArrayBackedQueue();
        $import->cutoff   = time() - 3600;
        $import->directly = true;
        $importFirst = $import->bulk()->write('readings', $late);
        $back        = $import->bulk(false)->write('readings', $late);
        $older = new ArrayBackedQueue();
        $older->cutoff = time() - 3600;
        $olderLate = $older->write('readings', $late);

        // Assert
        $this->assertSame(DeferredWriteQueue::BULK_AFTER, $written, 'single late values go straight in');
        $this->assertFalse($pastTheLimit, 'past the limit: an import, queued');
        $this->assertCount(1, $queue->deferred);
        $this->assertFalse($importFirst, 'an import that says so is queued from its first row');
        $this->assertTrue($back, 'and writes directly again once it says it is done');
        $this->assertFalse($olderLate, 'an older TimescaleDB queues every late row');
    }

    /**
     * Where the server takes late rows directly, a compressed chunk that is not mostly rewritten
     * is written into without decompressing it, and compressed once at the end — only the
     * segments the rows went into; one whose pending rows are most of it is rewritten as before.
     *
     * Measured on 2.31: a million new rows into a 554 MB chunk cost 0.45 GB of WAL written
     * directly, against 1.8 GB decompressed and compressed back.
     */
    public function testAChunkIsWrittenDirectlyUnlessMostOfItIsRewritten(): void
    {
        // Arrange — two compressed chunks: one with a few pending rows, one with more than its own
        $queue = new ArrayBackedQueue();
        $queue->directly = true;
        $queue->chunks   = [self::compressedChunk('_hyper_1_1_chunk')];
        $queue->batches  = [[['id' => 1, 'data' => '{"value":1}']], [], []];

        // Act
        $queue->process('readings');
        $direct = $queue->log;
        $rewrite = new ArrayBackedQueue();
        $rewrite->directly   = true;
        $rewrite->chunkShape = ['rows' => 1000, 'pending' => 600, 'share' => 0.0];
        $rewrite->chunks     = [self::compressedChunk('_hyper_1_2_chunk')];
        $rewrite->batches    = [[['id' => 2, 'data' => '{"value":2}']], [], []];
        $rewrite->process('readings');

        // Assert
        $this->assertSame(['begin', 'commit', 'compress:_hyper_1_1_chunk'], $direct, 'no decompress; compressed once at the end');
        $this->assertSame(['decompress:_hyper_1_2_chunk', 'begin', 'commit', 'compress:_hyper_1_2_chunk'], $rewrite->log);
    }

    /**
     * The decision per chunk: rewritten when the pending rows reach REWRITE_ROWS of its rows, or
     * the corrections among them — by the declared conflict key — reach REWRITE_CORRECTIONS;
     * written directly otherwise; and a table without a conflict key, which only gains rows, is
     * judged by the pending rows alone. An empty chunk is never rewritten.
     *
     * Measured on 2.31: corrections spread over every device broke even near 15% of the chunk,
     * new rows did not up to 17%, and a chunk rewritten whole was cheaper decompressed.
     */
    public function testTheChunkDecision(): void
    {
        // Arrange
        HypertableRegistry::register('keyed', ['time_column' => 'measured_at', 'conflict' => ['device', 'measured_at'], 'deferred_writes' => true]);
        $queue = new ArrayBackedQueue();
        $chunk = self::compressedChunk();
        $judge = static function (array $shape, string $table = 'keyed') use ($queue, $chunk): bool {
            $queue->chunkShape = $shape;

            return $queue->decides($table, $chunk);
        };

        // Act / Assert
        $this->assertFalse($judge(['rows' => 1000, 'pending' => 100, 'share' => 1.0]), '10% corrections: written directly');
        $this->assertTrue($judge(['rows' => 1000, 'pending' => 150, 'share' => 1.0]), '15% corrections: rewritten');
        $this->assertFalse($judge(['rows' => 1000, 'pending' => 400, 'share' => 0.25]), '10% of the chunk corrected, the rest new');
        $this->assertTrue($judge(['rows' => 1000, 'pending' => 500, 'share' => 0.0]), 'half the chunk pending, new or not: rewritten');
        $this->assertFalse($judge(['rows' => 1000, 'pending' => 400, 'share' => 1.0], 'readings'), 'no conflict key: only new rows, judged by count');
        $this->assertFalse($judge(['rows' => 0, 'pending' => 400, 'share' => 1.0]), 'an empty chunk is not rewritten');
    }

    /**
     * A drain stops before its next chunk once it has written its WAL budget, or while a streaming
     * replica is too far behind, and says why; the rest waits for the next run. Without WAL to
     * read (MySQL) it never stops.
     *
     * A rewrite writes about two and a half times its chunk's uncompressed size: a backfill across
     * hundreds of chunks would outrun the replica and lose its slot.
     */
    public function testADrainStopsAtItsWalBudgetOrABehindReplica(): void
    {
        // Arrange — two compressed chunks; 30 MB written by the time the second is reached
        $reported = [];
        $budget = new ArrayBackedQueue();
        $budget->wal         = '0/0';
        $budget->walBudget   = 20 * 1048576;
        // One reading: the budget is not asked before the first chunk, only before the second.
        $budget->walReadings = [30 * 1048576];
        $budget->chunks      = [self::compressedChunk('_hyper_1_1_chunk'), self::compressedChunk('_hyper_1_2_chunk')];
        $budget->batches     = [[['id' => 1, 'data' => '{"value":1}']], [], [], []];
        $behind = new ArrayBackedQueue();
        $behind->wal     = '0/0';
        $behind->lag     = 11 * 1048576;
        $behind->replicaLagLimit = 10 * 1048576;
        $behind->chunks  = [self::compressedChunk()];
        $none = new ArrayBackedQueue();
        $none->walBudget = 0;
        $none->chunks    = [self::compressedChunk()];
        $none->batches   = [[['id' => 2, 'data' => '{"value":2}']], [], []];

        // Act
        $spent   = $budget->process('readings', static function (string $line) use (&$reported): void { $reported[] = $line; })['readings'];
        $lagging = $behind->process('readings')['readings'];
        $mysql   = $none->process('readings')['readings'];

        // Assert
        $this->assertSame(1, $spent['chunks'], 'the first chunk is drained; the second waits');
        $this->assertSame('the WAL budget is spent (30 MB written)', $spent['paused']);
        $this->assertStringContainsString('the rest waits for the next run', end($reported));
        $this->assertSame(0, $lagging['chunks']);
        $this->assertSame('a replica is 11 MB behind', $lagging['paused']);
        $this->assertArrayNotHasKey('paused', $mysql, 'no WAL to read: never stops');
        $this->assertSame(1, $mysql['inserted']);
    }

    /**
     * Where the catalog cannot be read — a TimescaleDB that lays it out otherwise — a chunk's rows
     * are the planner's estimate; and on a database with no WAL to read (MySQL) a drain has no
     * starting position, so it never stops for a budget.
     */
    public function testTheChunkRowsFallBackAndMySqlHasNoWal(): void
    {
        // Arrange — a connection whose catalog query fails, then answers the estimate
        $estimate = new \stdClass();
        $estimate->numRows = 1;
        $estimate->fields  = ['n' => 42];
        $db = $this->getMockBuilder(\Pramnos\Database\Database::class)->disableOriginalConstructor()->onlyMethods(['prepareQuery', 'query', 'capabilities'])->getMock();
        $db->method('prepareQuery')->willReturn("'x'::regclass");
        $db->method('query')->willReturnOnConsecutiveCalls($this->throwException(new \RuntimeException('no such column')), $estimate);
        $capabilities = $this->getMockBuilder(\Pramnos\Database\DatabaseCapabilities::class)->disableOriginalConstructor()->onlyMethods(['isPostgreSQL'])->getMock();
        $capabilities->method('isPostgreSQL')->willReturn(false);
        $db->method('capabilities')->willReturn($capabilities);
        $queue = new DeferredWriteQueue($db);

        // Act
        $rows = (new \ReflectionMethod($queue, 'chunkRows'))->invoke($queue, (object) ['chunk_schema' => 's', 'chunk_name' => 'c']);
        $wal  = (new \ReflectionMethod($queue, 'walPosition'))->invoke($queue);

        // Assert
        $this->assertSame(42, $rows, 'the estimate, when the catalog cannot be read');
        $this->assertNull($wal, 'no WAL to read: no budget to keep');
    }
}
