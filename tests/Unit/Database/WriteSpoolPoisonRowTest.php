<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\WriteSpool;

/**
 * A spooled row that can never be written is eventually set aside.
 *
 * The spool is a promise to write a row later, and "later" can arrive after the row
 * stopped being writable. `tokenactions` carries a foreign key to `usertokens`, and a
 * token cleaned up while its rows waited takes the key with it:
 *
 * ```
 * insert or update on table "_hyper_12_225_chunk" violates foreign key constraint
 * DETAIL: Key (tokenid)=(3907) is not present in table "usertokens"
 * ```
 *
 * Nothing will make that row writable. The drain requeued it anyway, so once the schedule
 * started running, one installation retried the same 209 rows every minute — for ever,
 * printing a line per row per minute. A backlog that cannot drain is worse than one that
 * never drained: it is loud, permanent, and it buries the failures that *are* actionable.
 *
 * A row is now retried a bounded number of times and then parked in `<table>.spool.failed`
 * with the error that stopped it. Parked rather than dropped, because the row is somebody's
 * audit trail and "here is what we could not write, and why" is a thing an operator can act
 * on; and never read back, because reading it back is the loop.
 */
#[CoversClass(WriteSpool::class)]
class WriteSpoolPoisonRowTest extends TestCase
{
    private string $spoolDir = '';

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir() . '/pramnos_spool_' . bin2hex(random_bytes(4));
        mkdir($this->spoolDir, 0777, true);

        WriteSpool::reset();
        WriteSpool::setDriver(WriteSpool::DRIVER_FILE);
        SpyingWriteSpool::useDirectory($this->spoolDir);
        SpyingWriteSpool::$failures = [];
        SpyingWriteSpool::$written  = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);
        WriteSpool::reset();
        SpyingWriteSpool::useDirectory(null);
    }

    /**
     * Lines currently waiting in the spool file for a table.
     *
     * @param string $table
     * @return list<string>
     */
    private function spoolLines(string $table): array
    {
        $file = $this->spoolDir . '/' . $table . '.spool';

        if (!is_file($file)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($file))));
    }

    /**
     * Rows parked as unwritable.
     *
     * @param string $table
     * @return list<array<string, mixed>>
     */
    private function parkedRows(string $table): array
    {
        $file = $this->spoolDir . '/' . $table . '.spool.failed';

        if (!is_file($file)) {
            return [];
        }

        $rows = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
            $rows[] = json_decode($line, true);
        }

        return $rows;
    }

    /**
     * A row that always fails is retried, then parked.
     *
     * The regression test. Five drains, five attempts, and on the fifth the row leaves the
     * spool for good rather than coming back on the sixth.
     *
     * @return void
     */
    public function testARowThatCannotBeWrittenIsParkedAfterItsAttempts(): void
    {
        // Arrange — one row, and a write that always refuses it
        SpyingWriteSpool::$failures['always'] = 'foreign key violation';
        SpyingWriteSpool::append('audit', ['id' => 1, 'kind' => 'always']);

        // Act — drain repeatedly, as the schedule would every minute
        for ($minute = 1; $minute <= WriteSpool::DEFAULT_MAX_ATTEMPTS; $minute++) {
            $stats = SpyingWriteSpool::drain();

            if ($minute < WriteSpool::DEFAULT_MAX_ATTEMPTS) {
                $this->assertCount(1, $this->spoolLines('audit'), "still queued after attempt {$minute}");
                $this->assertSame(0, $stats['parked'], "not parked yet at attempt {$minute}");
            }
        }

        // Assert — gone from the spool, kept in the parked file with its reason
        $this->assertSame([], $this->spoolLines('audit'), 'the row must stop coming back');
        $this->assertSame(0, SpyingWriteSpool::pending(), 'and must not count as pending');

        $parked = $this->parkedRows('audit');
        $this->assertCount(1, $parked);
        $this->assertSame(['id' => 1, 'kind' => 'always'], $parked[0]['row']);
        $this->assertStringContainsString('foreign key', $parked[0]['error']);
        $this->assertSame(1, SpyingWriteSpool::parked());
    }

    /**
     * A parked file is never picked up by a later drain.
     *
     * The loop being broken, asserted directly: `spoolFiles()` globs `*.spool*`, which
     * would otherwise hand the parked rows straight back to the drain that parked them.
     *
     * @return void
     */
    public function testAParkedFileIsNotDrainedAgain(): void
    {
        // Arrange — a parked file beside an empty spool
        file_put_contents(
            $this->spoolDir . '/audit.spool.failed',
            json_encode(['parked_at' => date('c'), 'error' => 'gone', 'row' => ['id' => 9]]) . "\n"
        );

        // Act
        $stats = SpyingWriteSpool::drain();

        // Assert — nothing was read, nothing was written, nothing was re-parked
        $this->assertSame(0, $stats['written']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame([], SpyingWriteSpool::$written);
        $this->assertCount(1, $this->parkedRows('audit'));
    }

    /**
     * A transient failure still gets its retries.
     *
     * The limit must not turn a deadlock or a restarting database into lost rows: the row
     * that fails once and succeeds next minute is the case the spool exists for.
     *
     * @return void
     */
    public function testARowThatRecoversIsWrittenRatherThanParked(): void
    {
        // Arrange — fails once, then works
        SpyingWriteSpool::$failures['transient'] = 'deadlock detected';
        SpyingWriteSpool::append('audit', ['id' => 2, 'kind' => 'transient']);

        // Act
        SpyingWriteSpool::drain();
        SpyingWriteSpool::$failures = [];
        $stats = SpyingWriteSpool::drain();

        // Assert
        $this->assertSame(1, $stats['written']);
        $this->assertSame(0, $stats['parked']);
        $this->assertSame([], $this->parkedRows('audit'));
        $this->assertSame([['id' => 2, 'kind' => 'transient']], SpyingWriteSpool::$written);
    }

    /**
     * The attempt count survives requeueing, and the row does not.
     *
     * A counter that reset on every drain would be a limit that never triggers — which is
     * the behaviour being replaced, spelled differently.
     *
     * @return void
     */
    public function testTheAttemptCountSurvivesTheRequeue(): void
    {
        // Arrange
        SpyingWriteSpool::$failures['always'] = 'nope';
        SpyingWriteSpool::append('audit', ['id' => 3, 'kind' => 'always']);

        // Act — two drains
        SpyingWriteSpool::drain();
        SpyingWriteSpool::drain();

        // Assert — the queued line now carries a count of 2
        $lines = $this->spoolLines('audit');
        $this->assertCount(1, $lines);
        $decoded = json_decode($lines[0], true);
        $this->assertSame(2, $decoded['__spool_attempts']);
        $this->assertSame(['id' => 3, 'kind' => 'always'], $decoded['__spool_row']);
    }

    /**
     * A row appended before the upgrade is read as a row.
     *
     * A drain that could not read the lines already in the file would lose the backlog it
     * was written to protect.
     *
     * @return void
     */
    public function testALineInTheOldFormatIsStillWritten(): void
    {
        // Arrange — a bare row, as append() wrote before attempts existed
        file_put_contents(
            $this->spoolDir . '/audit.spool',
            json_encode(['id' => 4, 'kind' => 'legacy']) . "\n"
        );

        // Act
        $stats = SpyingWriteSpool::drain();

        // Assert
        $this->assertSame(1, $stats['written']);
        $this->assertSame([['id' => 4, 'kind' => 'legacy']], SpyingWriteSpool::$written);
    }

    /**
     * `--max-attempts=0` keeps the old behaviour for an installation that wants it.
     *
     * @return void
     */
    public function testAZeroLimitRetriesForEver(): void
    {
        // Arrange
        WriteSpool::setMaxAttempts(0);
        SpyingWriteSpool::$failures['always'] = 'nope';
        SpyingWriteSpool::append('audit', ['id' => 5, 'kind' => 'always']);

        // Act — well past the default limit
        for ($i = 0; $i < WriteSpool::DEFAULT_MAX_ATTEMPTS + 3; $i++) {
            SpyingWriteSpool::drain();
        }

        // Assert — still queued, never parked
        $this->assertCount(1, $this->spoolLines('audit'));
        $this->assertSame([], $this->parkedRows('audit'));
    }

    /**
     * Identical failures are reported once with a count.
     *
     * Two hundred rows failing for one reason is one thing to know and two hundred lines to
     * read, once a minute — which is how the reason stops being read at all.
     *
     * @return void
     */
    public function testIdenticalFailuresAreReportedOnceWithACount(): void
    {
        // Arrange — ten rows, one reason
        SpyingWriteSpool::$failures['always'] = 'foreign key violation';
        for ($i = 0; $i < 10; $i++) {
            SpyingWriteSpool::append('audit', ['id' => 100 + $i, 'kind' => 'always']);
        }

        // Act
        $lines = [];
        SpyingWriteSpool::drain(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        // Assert — one summary line for ten failures, not ten lines
        $perRow = array_filter($lines, static fn(string $l): bool => str_contains($l, 'row failed'));
        $this->assertSame([], $perRow, 'a line per failed row is a log nobody reads');

        $summary = array_filter($lines, static fn(string $l): bool => str_contains($l, '10× foreign key violation'));
        $this->assertCount(1, $summary, implode("\n", $lines));
    }
}

/**
 * A spool whose database write is a scripted success or failure.
 *
 * Only `writeNow()` is replaced — every decision under test (decode, attempt counting,
 * requeue, park) runs as it does in production.
 */
class SpyingWriteSpool extends WriteSpool
{
    /** @var array<string, string> kind => error message */
    public static array $failures = [];

    /** @var list<array<string, mixed>> Rows that reached the database */
    public static array $written = [];

    /** @var string|null Where the spool lives for this test */
    private static ?string $testDirectory = null;

    public static function useDirectory(?string $directory): void
    {
        self::$testDirectory = $directory;
        static::reset();
    }

    protected static function directory(): ?string
    {
        return self::$testDirectory ?? parent::directory();
    }

    protected static function writeNow(string $table, array $row): void
    {
        $kind = (string) ($row['kind'] ?? '');

        if (isset(self::$failures[$kind])) {
            throw new \RuntimeException(self::$failures[$kind]);
        }

        self::$written[] = $row;
    }

    protected static function beginBatch(): void
    {
    }

    protected static function commitBatch(): void
    {
    }

    protected static function rollbackBatch(): void
    {
    }
}
