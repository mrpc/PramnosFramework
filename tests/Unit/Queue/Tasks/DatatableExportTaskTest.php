<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue\Tasks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Datatable\Exportable;
use Pramnos\Queue\QueueItem;
use Pramnos\Queue\Tasks\DatatableExportTask;

/**
 * Unit tests for Pramnos\Queue\Tasks\DatatableExportTask.
 *
 * The real `execute()` wraps everything in a database transaction (REPEATABLE
 * READ), which is proved end to end by the integration test instead. These
 * tests drive the batching/CSV-writing logic directly via the protected
 * `writeBatches()` seam, against a fake in-memory `Exportable`, with no
 * database involved.
 */
#[CoversClass(DatatableExportTask::class)]
class DatatableExportTaskTest extends TestCase
{
    /**
     * The batch loop must stop as soon as a batch comes back shorter than the
     * requested batch size (the last page) — a fixed-size last batch would
     * otherwise run one extra, empty request forever.
     */
    public function testWriteBatchesStopsOnAShortLastBatch(): void
    {
        // Arrange: 5 rows, batch size 2 -> batches of 2, 2, 1.
        $source = new FakeExportableSource([
            ['id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'name' => 'Beta'],
            ['id' => 3, 'name' => 'Gamma'],
            ['id' => 4, 'name' => 'Delta'],
            ['id' => 5, 'name' => 'Epsilon'],
        ]);
        $task    = new ConcreteDatatableExportTask(new \stdClass());
        $columns = [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']];
        $handle  = fopen('php://memory', 'w+');

        // Act
        $total = $task->publicWriteBatches($source, [], $columns, 2, $handle);

        // Assert: every row was written, and the returned batch lengths prove the
        // short 3rd batch ended the loop rather than a 4th, empty fetch.
        $this->assertSame(5, $total);
        $this->assertSame([2, 2, 1], $source->returnedCounts);
    }

    /**
     * CSV rows pull from each row by the declared column `key`, in column order
     * — not by whatever order the source happens to return fields in.
     */
    public function testWriteBatchesWritesColumnsInDeclaredOrderByKey(): void
    {
        // Arrange: row's own key order (name, id) differs from the declared columns (id, name).
        $source = new FakeExportableSource([
            ['name' => 'Alpha', 'id' => 1],
        ]);
        $task    = new ConcreteDatatableExportTask(new \stdClass());
        $columns = [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']];
        $handle  = fopen('php://memory', 'w+');

        // Act
        $task->publicWriteBatches($source, [], $columns, 10, $handle);
        rewind($handle);
        $row = fgetcsv($handle, 0, ',', '"', '');

        // Assert: "id" column first in the CSV, even though the row array had "name" first.
        $this->assertSame(['1', 'Alpha'], $row);
    }

    /**
     * A row missing a declared column's key writes an empty cell rather than
     * throwing — a sparse row (a nullable field left out) must not abort the
     * whole export.
     */
    public function testWriteBatchesWritesEmptyCellForAMissingKey(): void
    {
        // Arrange
        $source = new FakeExportableSource([
            ['id' => 1],
        ]);
        $task    = new ConcreteDatatableExportTask(new \stdClass());
        $columns = [['key' => 'id', 'label' => 'Id'], ['key' => 'missing', 'label' => 'Missing']];
        $handle  = fopen('php://memory', 'w+');

        // Act
        $task->publicWriteBatches($source, [], $columns, 10, $handle);
        rewind($handle);
        $row = fgetcsv($handle, 0, ',', '"', '');

        // Assert
        $this->assertSame(['1', ''], $row);
    }

    /**
     * `writePage()` ("current page only" scope) is a single `exportBatch()` call
     * with the page's own `start`/`length`, as already posted in `$filters` —
     * not re-batched. It does not need a short-batch test because it is not a
     * loop: proving it reads `start`/`length` and writes exactly that one batch
     * is the whole contract.
     */
    public function testWritePageUsesStartAndLengthFromFilters(): void
    {
        // Arrange: page 2 of a DataTable with length=2 -> start=2, so rows[2..3]
        $source = new FakeExportableSource([
            ['id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'name' => 'Beta'],
            ['id' => 3, 'name' => 'Gamma'],
            ['id' => 4, 'name' => 'Delta'],
        ]);
        $task    = new ConcreteDatatableExportTask(new \stdClass());
        $columns = [['key' => 'id', 'label' => 'Id'], ['key' => 'name', 'label' => 'Name']];
        $handle  = fopen('php://memory', 'w+');

        // Act
        $total = $task->publicWritePage($source, ['start' => 2, 'length' => 2], $columns, $handle);
        rewind($handle);

        // Assert — exactly rows 3 and 4 (0-indexed 2,3), one fetch, not a loop
        $this->assertSame(2, $total);
        $this->assertSame([2], $source->returnedCounts);
        $this->assertSame(['3', 'Gamma'], fgetcsv($handle, 0, ',', '"', ''));
        $this->assertSame(['4', 'Delta'], fgetcsv($handle, 0, ',', '"', ''));
    }

    /** Missing `start`/`length` falls back to the first batch-sized page, not a crash. */
    public function testWritePageDefaultsWhenFiltersAreMissingPaging(): void
    {
        // Arrange
        $source  = new FakeExportableSource([['id' => 1, 'name' => 'Alpha']]);
        $task    = new ConcreteDatatableExportTask(new \stdClass());
        $columns = [['key' => 'id', 'label' => 'Id']];
        $handle  = fopen('php://memory', 'w+');

        // Act
        $total = $task->publicWritePage($source, [], $columns, $handle);

        // Assert
        $this->assertSame(1, $total);
    }

    // ── notifyByEmail() ──────────────────────────────────────────────────────

    /**
     * `notify_to` left empty (no address captured — e.g. a sealed identity with
     * no email property at all) must not reach `sendReadyEmail()` — nothing to
     * address it to.
     */
    public function testNotifyByEmailDoesNothingWithNoAddress(): void
    {
        // Arrange
        $task = new ThrowingNotifyDatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));
        $item->taskid = 9;

        // Act
        $task->publicNotifyByEmail(['notify_to' => ''], $item);

        // Assert — sendReadyEmail(), which always throws in this fixture, was never reached
        $this->assertFalse($task->sendReadyEmailCalled);
    }

    /**
     * A failure while queuing the ready notice — here, `sendReadyEmail()` always
     * throws — must not propagate out of `notifyByEmail()`: it is logged and
     * swallowed, since the export itself already succeeded by the time this runs.
     */
    public function testNotifyByEmailSwallowsAFailureFromSendReadyEmail(): void
    {
        // Arrange
        $task = new ThrowingNotifyDatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));
        $item->taskid = 9;

        // Act — must not throw
        $task->publicNotifyByEmail(['notify_to' => 'someone@example.com'], $item);

        // Assert — sendReadyEmail() was reached and did throw, but nothing escaped
        $this->assertTrue($task->sendReadyEmailCalled);
    }

    // ── validate() ────────────────────────────────────────────────────────────

    /** A payload naming a class that is not an Exportable must not validate. */
    public function testValidateRejectsAClassThatIsNotExportable(): void
    {
        // Arrange
        $task = new DatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode([
            'class'   => \stdClass::class,
            'columns' => [['key' => 'id', 'label' => 'Id']],
        ]));

        // Act
        $result = $task->validate($item);

        // Assert
        $this->assertFalse($result);
    }

    /** A payload with an Exportable class and at least one column validates. */
    public function testValidateAcceptsAnExportableClassWithColumns(): void
    {
        // Arrange
        $task = new DatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode([
            'class'   => FakeExportableSource::class,
            'columns' => [['key' => 'id', 'label' => 'Id']],
        ]));

        // Act
        $result = $task->validate($item);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * `format` is `'csv'` or `'excel'` — anything else must not validate, the
     * same safety net `queueexport()` already applies before the task is even
     * queued. Named `'xlsx'` deliberately, not the accepted token, to prove a
     * near-miss is still rejected.
     */
    public function testValidateRejectsAnUnsupportedFormat(): void
    {
        // Arrange
        $task = new DatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode([
            'class'   => FakeExportableSource::class,
            'columns' => [['key' => 'id', 'label' => 'Id']],
            'format'  => 'xlsx',
        ]));

        // Act
        $result = $task->validate($item);

        // Assert
        $this->assertFalse($result);
    }

    /** `format: 'excel'` validates — the second of the two accepted values. */
    public function testValidateAcceptsExcelFormat(): void
    {
        // Arrange
        $task = new DatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode([
            'class'   => FakeExportableSource::class,
            'columns' => [['key' => 'id', 'label' => 'Id']],
            'format'  => 'excel',
        ]));

        // Act
        $result = $task->validate($item);

        // Assert
        $this->assertTrue($result);
    }

    // ── filePath() ────────────────────────────────────────────────────────────

    /**
     * `filePath()` picks the extension by `$format` — `.csv` by default, `.xlsx`
     * for `'excel'` — so `exportdownload()` can compute the right path without
     * ever reading it back out of `success_message`.
     */
    public function testFilePathPicksTheExtensionByFormat(): void
    {
        // Act + Assert
        $this->assertStringEndsWith('42.csv', DatatableExportTask::filePath(42));
        $this->assertStringEndsWith('42.csv', DatatableExportTask::filePath(42, 'csv'));
        $this->assertStringEndsWith('42.xlsx', DatatableExportTask::filePath(42, 'excel'));
    }

    /** `csvPath()` is kept for call sites that predate `excel` — always `.csv`. */
    public function testCsvPathAlwaysReturnsTheCsvExtension(): void
    {
        // Act + Assert
        $this->assertSame(DatatableExportTask::filePath(42, 'csv'), DatatableExportTask::csvPath(42));
    }

    /** No columns at all means nothing to export — must not validate. */
    public function testValidateRejectsEmptyColumns(): void
    {
        // Arrange
        $task = new DatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode([
            'class'   => FakeExportableSource::class,
            'columns' => [],
        ]));

        // Act
        $result = $task->validate($item);

        // Assert
        $this->assertFalse($result);
    }

    /** getDescription() names the exported class, for the admin dashboard. */
    public function testGetDescriptionNamesTheExportedClass(): void
    {
        // Arrange
        $task = new DatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));

        // Act
        $description = $task->getDescription($item);

        // Assert
        $this->assertSame('Export of ' . FakeExportableSource::class, $description);
    }

    private function makeQueueItem(string $payload): QueueItem
    {
        $item = new class extends QueueItem {
            public function __construct() {}
            /** @param mixed $a */
            public function save($a = false, $d = false): static { return $this; }
        };
        $item->payload = $payload;
        $item->taskid  = 1;
        $item->type    = DatatableExportTask::TASK;

        return $item;
    }
}

/**
 * A test double for `Exportable`: serves fixed rows out of memory, in pages,
 * and records each call's requested batch size — so a test can assert the
 * loop stopped at the right page without a real database.
 */
class FakeExportableSource implements Exportable
{
    /** @var list<int> */
    public array $returnedCounts = [];

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows = [])
    {
    }

    public function exportBatch(array $filters, int $limit, int $offset): array
    {
        $batch = array_slice($this->rows, $offset, $limit);
        $this->returnedCounts[] = count($batch);

        return $batch;
    }
}

/** Exposes the protected writeBatches() seam to the test, unchanged otherwise. */
class ConcreteDatatableExportTask extends DatatableExportTask
{
    /**
     * @param array<int, array{key: string, label: string}> $columns
     * @param resource $handle
     */
    public function publicWriteBatches(Exportable $source, array $filters, array $columns, int $batchSize, $handle): int
    {
        return $this->writeBatches($source, $filters, $columns, $batchSize, $handle);
    }

    /**
     * @param array<int, array{key: string, label: string}> $columns
     * @param resource $handle
     */
    public function publicWritePage(Exportable $source, array $filters, array $columns, $handle): int
    {
        $ref = new \ReflectionMethod(DatatableExportTask::class, 'writePage');

        return $ref->invoke($this, $source, $filters, $columns, $handle);
    }
}

/**
 * `sendReadyEmail()` always throws, so a test can prove `notifyByEmail()`'s own
 * `catch` swallows it, with no outbox table or SMTP transport involved.
 */
class ThrowingNotifyDatatableExportTask extends DatatableExportTask
{
    public bool $sendReadyEmailCalled = false;

    protected function sendReadyEmail(string $to, string $url): void
    {
        $this->sendReadyEmailCalled = true;
        throw new \RuntimeException('simulated send failure');
    }

    /** @param array<string, mixed> $payload */
    public function publicNotifyByEmail(array $payload, QueueItem $queueItem): void
    {
        $ref = new \ReflectionMethod(DatatableExportTask::class, 'notifyByEmail');
        $ref->invoke($this, $payload, $queueItem);
    }
}
