<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\WriteSpool;

/**
 * A spool that writes to a directory the test owns, and records what it drains.
 *
 * The database is the one thing not exercised here: what the spool does with a
 * row once it has read it back is one `queryBuilder()->insert()`, and the
 * decisions worth pinning down are all upstream of it — which backend took the
 * row, whether it survived, and what happens when writing it fails.
 */
class TestableWriteSpool extends WriteSpool
{
    /** @var list<array{table: string, row: array<string, mixed>}> Rows written */
    public static array $written = [];

    /** @var list<string> Tables whose writes must fail */
    public static array $failing = [];

    /** @var string|null Directory override */
    public static ?string $dir = null;

    /** @var bool Whether Redis should be reported as available */
    public static bool $redisUp = false;

    /** @var bool Drop written rows instead of recording them (memory tests) */
    public static bool $discard = false;

    /** @var list<int> Row ids whose write always fails */
    public static array $failIds = [];

    /** @var int|null How many rows were written when the batch opened */
    public static ?int $batchStart = null;

    /**
     * Start again with an empty spool directory.
     */
    public static function useDirectory(string $directory): void
    {
        static::$dir      = $directory;
        static::$written  = [];
        static::$failing  = [];
        static::$discard    = false;
        static::$failIds    = [];
        static::$batchStart = null;
        static::reset();
    }

    protected static function directory(): ?string
    {
        return static::$dir;
    }

    protected static function redisAvailable(): bool
    {
        return static::$redisUp;
    }

    protected static function writeNow(string $table, array $row): void
    {
        if (in_array($table, static::$failing, true)) {
            throw new \RuntimeException('cannot write to ' . $table);
        }

        // Keyed on the row itself, not on a call counter: a row that cannot be
        // written fails every time it is tried, which is what makes the batch
        // replay meaningful.
        if (in_array($row['id'] ?? null, static::$failIds, true)) {
            throw new \RuntimeException('row ' . $row['id'] . ' is unwritable');
        }

        if (static::$discard) {
            return;
        }

        static::$written[] = ['table' => $table, 'row' => $row];
    }

    /**
     * The transaction hooks, modelled rather than stubbed out.
     *
     * A rollback that does nothing would leave rows "written" that a real
     * database would have discarded — and the row-by-row replay would then
     * write them a second time, which looks exactly like the duplicate bug this
     * class must not have.
     */
    protected static function beginBatch(): void
    {
        static::$batchStart = count(static::$written);
    }

    protected static function commitBatch(): void
    {
        static::$batchStart = null;
    }

    protected static function rollbackBatch(): void
    {
        if (static::$batchStart !== null) {
            static::$written = array_slice(static::$written, 0, static::$batchStart);
            static::$batchStart = null;
        }
    }
}

/**
 * Buffering writes that should not be paid for while somebody is waiting.
 *
 * An audit row, an access log, a counter: worth keeping, worth nothing
 * individually, and written on every request. The request pays for them
 * synchronously today, and on a compressed hypertable one such insert can cost
 * more than the query the page actually ran.
 *
 * The property that matters most is the one at the bottom of the driver chain:
 * an installation with no Redis must not silently lose the feature. That is why
 * there is a file backend at all, and why the last resort is a direct write
 * rather than a discard.
 */
#[CoversClass(WriteSpool::class)]
class WriteSpoolTest extends TestCase
{
    /** @var string A scratch directory for one test */
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pramnos-spool-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);
        TestableWriteSpool::useDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        TestableWriteSpool::$dir = null;
        TestableWriteSpool::reset();
        parent::tearDown();
    }

    /** Every spool file currently on disk. */
    private function files(): array
    {
        return glob($this->dir . '/*') ?: [];
    }

    // -------------------------------------------------------------------------
    // Choosing a backend
    // -------------------------------------------------------------------------

    /**
     * With no Redis, rows go to a file rather than to the database.
     *
     * The whole reason the file backend exists. An installation without Redis
     * is the common case, and "no Redis, so write synchronously" would mean the
     * optimisation never applies to most installations.
     */
    public function testWithoutRedisRowsGoToAFile(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;

        // Act
        $driver = TestableWriteSpool::append('readings', ['id' => 1, 'value' => 2.5]);

        // Assert
        $this->assertSame(WriteSpool::DRIVER_FILE, $driver);
        $this->assertCount(1, $this->files(), 'one spool file was written');
        $this->assertSame([], TestableWriteSpool::$written, 'and nothing reached the database');
    }

    /**
     * With nowhere writable at all, the row is written immediately.
     *
     * The floor of the whole design: buffering is an optimisation, and a row
     * that cannot be buffered is still a row that has to be kept.
     */
    public function testWithNowhereToBufferTheRowIsWrittenNow(): void
    {
        // Arrange — no Redis, no directory
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::$dir     = null;
        TestableWriteSpool::reset();

        // Act
        $driver = TestableWriteSpool::append('readings', ['id' => 1]);

        // Assert
        $this->assertSame(WriteSpool::DRIVER_SYNC, $driver);
        $this->assertCount(1, TestableWriteSpool::$written);
        $this->assertSame(['id' => 1], TestableWriteSpool::$written[0]['row']);
    }

    /**
     * The chosen backend is decided once, not per row.
     *
     * The Redis probe is a connection attempt; doing it per row would cost more
     * than the write it is trying to save.
     */
    public function testTheBackendIsChosenOnce(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        $first = TestableWriteSpool::driver();

        // Act — change what the probe would say, without resetting
        TestableWriteSpool::$redisUp = true;

        // Assert
        $this->assertSame($first, TestableWriteSpool::driver(), 'the answer was remembered');
    }

    /**
     * An explicit driver is honoured.
     */
    public function testAnExplicitDriverIsHonoured(): void
    {
        // Arrange
        TestableWriteSpool::setDriver(WriteSpool::DRIVER_SYNC);

        // Act
        $driver = TestableWriteSpool::append('readings', ['id' => 1]);

        // Assert
        $this->assertSame(WriteSpool::DRIVER_SYNC, $driver);
        $this->assertSame([], $this->files(), 'nothing was buffered');
    }

    // -------------------------------------------------------------------------
    // The file backend
    // -------------------------------------------------------------------------

    /**
     * Buffered rows come back out, in order, and reach the database.
     */
    public function testBufferedRowsAreWrittenOnDrain(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);
        TestableWriteSpool::append('readings', ['id' => 2]);
        TestableWriteSpool::append('readings', ['id' => 3]);

        // Act
        $stats = TestableWriteSpool::drain();

        // Assert
        $this->assertSame(3, $stats['written']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame([1, 2, 3], array_column(
            array_column(TestableWriteSpool::$written, 'row'),
            'id'
        ), 'in the order they were appended');
        $this->assertSame([], $this->files(), 'and the spool file is gone');
    }

    /**
     * Rows for different tables are kept apart.
     */
    public function testRowsAreGroupedByTable(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);
        TestableWriteSpool::append('events', ['id' => 2]);

        // Act
        $stats = TestableWriteSpool::drain();

        // Assert
        $this->assertSame(2, $stats['written']);
        $this->assertSame(1, $stats['tables']['readings']);
        $this->assertSame(1, $stats['tables']['events']);
    }

    /**
     * A table name that would not survive a file path is still round-tripped.
     *
     * `#PREFIX#tokenactions` is what a caller passes and what the query builder
     * expects back — the spool must not quietly hand back a sanitised name.
     */
    public function testATableNameWithAPrefixTokenSurvives(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('#PREFIX#tokenactions', ['tokenid' => 7]);

        // Act
        TestableWriteSpool::drain();

        // Assert
        $this->assertSame('#PREFIX#tokenactions', TestableWriteSpool::$written[0]['table']);
    }

    /**
     * Nothing buffered means nothing done, and no error.
     *
     * This runs every minute on every installation; the empty case has to be
     * both cheap and silent.
     */
    public function testDrainingAnEmptySpoolDoesNothing(): void
    {
        // Act
        $stats = TestableWriteSpool::drain();

        // Assert
        $this->assertSame(0, $stats['written']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame([], $stats['tables']);
    }

    /**
     * A file whose rows cannot be written is kept, not deleted.
     *
     * Deleting it would turn a temporary database problem into permanent data
     * loss — the one outcome this class exists to prevent.
     */
    public function testAFileThatCannotBeWrittenIsKept(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);
        TestableWriteSpool::$failing = ['readings'];

        // Act
        $stats = TestableWriteSpool::drain();

        // Assert
        $this->assertSame(0, $stats['written']);
        $this->assertSame(1, $stats['failed']);
        $this->assertNotSame([], $this->files(), 'the rows are still on disk');
    }

    /**
     * A kept file is retried on the next drain, and cleared when it succeeds.
     *
     * The other half of the previous test: keeping the file is only right if
     * something later picks it up.
     */
    public function testAKeptFileIsRetriedAndThenCleared(): void
    {
        // Arrange — a failed drain
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);
        TestableWriteSpool::$failing = ['readings'];
        TestableWriteSpool::drain();

        // Act — the database recovers
        TestableWriteSpool::$failing = [];
        $stats = TestableWriteSpool::drain();

        // Assert
        $this->assertSame(1, $stats['written']);
        $this->assertSame([], $this->files());
    }

    /**
     * A line that is not a row is counted as failed rather than skipped.
     *
     * Counting it is what stops the file it came from being deleted — a
     * corrupt line is a thing somebody should see, not something to drop
     * quietly on every run for ever.
     */
    public function testAnUnreadableLineIsCountedAsFailed(): void
    {
        // Arrange — a good row, and a broken one written directly
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);
        file_put_contents($this->files()[0], "not json\n", FILE_APPEND);

        // Act
        $stats = TestableWriteSpool::drain();

        // Assert
        $this->assertSame(1, $stats['written'], 'the good row landed');
        $this->assertSame(1, $stats['failed']);
    }

    /**
     * Rows appended while a drain is running are not lost.
     *
     * The file is renamed before it is read, so a writer that arrives mid-drain
     * creates a fresh one instead of adding to a file that is about to be
     * deleted. Simulated by appending after the rename would have happened.
     */
    public function testRowsAppendedDuringADrainSurvive(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);

        // Act — drain, then append again, then drain again
        TestableWriteSpool::drain();
        TestableWriteSpool::append('readings', ['id' => 2]);
        $second = TestableWriteSpool::drain();

        // Assert
        $this->assertSame(1, $second['written']);
        $this->assertSame(2, TestableWriteSpool::$written[1]['row']['id']);
    }

    /**
     * A large spool is drained without reading it all into memory.
     *
     * Measured: a 100 MB spool read into one array peaked at 130 MB, which on a
     * default `memory_limit` is a fatal error — and a fatal error here spirals,
     * because the spool that could not be drained is the one that keeps growing.
     * Streaming holds one batch whatever the backlog.
     *
     * The assertion is on peak memory rather than on the implementation, so a
     * future rewrite that reintroduces the problem fails here.
     */
    public function testALargeSpoolIsDrainedWithoutHoldingItAll(): void
    {
        // Arrange — 20,000 rows of about 1 KB each, ~20 MB on disk
        TestableWriteSpool::$redisUp = false;
        $filler = str_repeat('x', 1000);
        for ($i = 0; $i < 20000; $i++) {
            TestableWriteSpool::append('readings', ['id' => $i, 'filler' => $filler]);
        }

        $size = filesize($this->files()[0]);
        $this->assertGreaterThan(15 * 1024 * 1024, $size, 'the spool really is large');

        // The recorded rows would themselves dominate the measurement, so the
        // fake write discards them.
        TestableWriteSpool::$written = [];
        TestableWriteSpool::$discard = true;

        // Act
        $before = memory_get_peak_usage(true);
        $stats  = TestableWriteSpool::drain();
        $used   = memory_get_peak_usage(true) - $before;

        // Assert
        $this->assertSame(20000, $stats['written']);
        $this->assertLessThan(
            $size / 2,
            $used,
            'draining held far less than the file it was reading'
        );
    }

    /**
     * Rows that failed are requeued; rows that landed are not replayed.
     *
     * Keeping the whole file instead would rewrite every row that already
     * landed, on every run, for as long as one bad row stayed in it — which for
     * an audit log means duplicates that grow without bound.
     */
    public function testOnlyTheFailedRowsAreKept(): void
    {
        // Arrange — three rows, the middle one unwritable
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);
        TestableWriteSpool::append('readings', ['id' => 2]);
        TestableWriteSpool::append('readings', ['id' => 3]);
        TestableWriteSpool::$failIds = [2];

        // Act
        $first = TestableWriteSpool::drain();

        // Assert — the batch raised and was replayed row by row, so the two
        // blameless rows landed and only the bad one came back
        $this->assertSame(2, $first['written']);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, TestableWriteSpool::pending(), 'only the failure was requeued');

        // Act — the cause is fixed
        TestableWriteSpool::$failIds = [];
        $second = TestableWriteSpool::drain();

        // Assert — every row written exactly once across both runs
        $this->assertSame(1, $second['written']);
        $ids = array_column(array_column(TestableWriteSpool::$written, 'row'), 'id');
        sort($ids);
        $this->assertSame([1, 2, 3], $ids, 'no row was written twice');
    }

    // -------------------------------------------------------------------------
    // Transformers
    // -------------------------------------------------------------------------

    /**
     * A row can be finished off at drain time rather than in the request.
     *
     * The case this exists for: the audit log needs the id of a URL from a
     * lookup table, and resolving that in the request costs a SELECT — the very
     * cost buffering exists to remove. The row carries the URL, and the drain,
     * which is long-running and can remember what it has resolved, turns it
     * into an id.
     */
    public function testARowIsTransformedWhenItIsWritten(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        WriteSpool::transform('readings', static function (array $row): array {
            $row['device_id'] = strlen((string) $row['device']) ;
            unset($row['device']);
            return $row;
        });

        try {
            // Act
            TestableWriteSpool::append('readings', ['device' => 'abcd', 'value' => 1]);
            TestableWriteSpool::drain();

            // Assert
            $written = TestableWriteSpool::$written[0]['row'];
            $this->assertSame(4, $written['device_id'], 'the transformer ran');
            $this->assertArrayNotHasKey('device', $written, 'and replaced the raw field');
        } finally {
            WriteSpool::transform('readings', null);
        }
    }

    /**
     * The same transformation applies when the row is written synchronously.
     *
     * Otherwise an installation with nowhere to buffer would write rows of a
     * different shape from one that buffers — the same code producing two
     * schemas depending on the filesystem.
     */
    public function testTheTransformerAlsoAppliesToADirectWrite(): void
    {
        // Arrange — nowhere to buffer, so append() writes immediately
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::$dir     = null;
        TestableWriteSpool::reset();
        WriteSpool::transform('readings', static function (array $row): array {
            $row['marked'] = true;
            return $row;
        });

        try {
            // Act
            $driver = TestableWriteSpool::append('readings', ['id' => 1]);

            // Assert
            $this->assertSame(WriteSpool::DRIVER_SYNC, $driver);
            $this->assertTrue(TestableWriteSpool::$written[0]['row']['marked']);
        } finally {
            WriteSpool::transform('readings', null);
        }
    }

    /**
     * A transformer that raises does not lose the row.
     *
     * Whatever it needed — a lookup table, a connection — may be working again
     * by the next drain. Writing the untransformed row gives the insert a
     * chance to succeed, and requeues it if it does not.
     */
    public function testAFailingTransformerDoesNotDiscardTheRow(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        WriteSpool::transform('readings', static function (array $row): array {
            throw new \RuntimeException('lookup table is missing');
        });

        try {
            // Act
            TestableWriteSpool::append('readings', ['id' => 7]);
            $stats = TestableWriteSpool::drain();

            // Assert
            $this->assertSame(1, $stats['written']);
            $this->assertSame(7, TestableWriteSpool::$written[0]['row']['id']);
        } finally {
            WriteSpool::transform('readings', null);
        }
    }

    /**
     * The pending count reflects what is actually waiting.
     */
    public function testPendingCountsWhatIsWaiting(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);
        TestableWriteSpool::append('readings', ['id' => 2]);
        TestableWriteSpool::append('events', ['id' => 3]);

        // Act & Assert
        $this->assertSame(3, TestableWriteSpool::pending());

        TestableWriteSpool::drain();
        $this->assertSame(0, TestableWriteSpool::pending());
    }

    /**
     * A progress reporter is told what is happening.
     *
     * A drain over a large backlog runs for a while with nothing to show, and
     * the console command exists to be watched.
     */
    public function testProgressIsReported(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;
        TestableWriteSpool::append('readings', ['id' => 1]);
        $said = [];

        // Act
        TestableWriteSpool::drain(function (string $line) use (&$said): void {
            $said[] = $line;
        });

        // Assert
        $this->assertNotSame([], $said);
        $this->assertStringContainsString('readings', implode("\n", $said));
    }

    /**
     * Malformed UTF-8 does not stop a row being buffered.
     *
     * A byte sequence that is not valid UTF-8 — a latin-1 name that reached the
     * application unconverted — would make `json_encode()` fail and return
     * false. The encode asks for substitution instead, so the row is buffered
     * with the offending bytes replaced rather than being pushed back onto the
     * request to write synchronously, or worse, dropped.
     *
     * The row is imperfect either way; the choice is only about where the
     * imperfection costs something, and it should not be the request path.
     */
    public function testMalformedUtf8DoesNotPreventBuffering(): void
    {
        // Arrange
        TestableWriteSpool::$redisUp = false;

        // Act — an invalid UTF-8 byte sequence
        $driver = TestableWriteSpool::append('readings', ['name' => "\xB1\x31"]);

        // Assert
        $this->assertSame(WriteSpool::DRIVER_FILE, $driver);
        $this->assertSame([], TestableWriteSpool::$written, 'the request did not pay for it');

        // Act — and it still drains
        $stats = TestableWriteSpool::drain();

        // Assert
        $this->assertSame(1, $stats['written']);
        $this->assertArrayHasKey('name', TestableWriteSpool::$written[0]['row']);
    }
}
