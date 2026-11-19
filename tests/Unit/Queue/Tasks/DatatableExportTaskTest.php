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

    // ── reportProgress() ─────────────────────────────────────────────────────

    /**
     * A controller whose `application->database` exists (so the cheap `isset()`
     * guard does not short-circuit it) but whose `QueueManager` call itself
     * throws must not propagate — the same best-effort swallow
     * {@see notifyByEmail()} already applies, so one batch's progress report
     * failing cannot fail the export it is reporting on.
     */
    public function testReportProgressSwallowsAFailureFromUpdateProgress(): void
    {
        // Arrange — application->database resolves, but every call on it throws
        $controller = new \stdClass();
        $controller->application = new \stdClass();
        $controller->application->database = new class {
            public function schema(): object
            {
                throw new \RuntimeException('simulated schema failure');
            }
        };
        $task = new ConcreteDatatableExportTask($controller);
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));

        // Act + Assert — must not throw
        $task->publicReportProgress($item, 42);
        $this->addToAssertionCount(1);
    }

    // ── notifyByEmail() ──────────────────────────────────────────────────────

    /**
     * `notify_to` empty AND `requested_by` absent/0 — e.g. a sealed identity
     * with no email property at all, and no reloadable user id — must not reach
     * `sendReadyNotification()`: nothing to address a mail to, and no account to
     * check for a push subscription either.
     */
    public function testNotifyByEmailDoesNothingWithNoAddress(): void
    {
        // Arrange
        $task = new ThrowingNotifyDatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));
        $item->taskid = 9;

        // Act
        $task->publicNotifyByEmail(['notify_to' => ''], $item);

        // Assert — sendReadyNotification(), which always throws in this fixture, was never reached
        $this->assertFalse($task->sendReadyEmailCalled);
    }

    /**
     * A failure while dispatching the ready notice — here,
     * `sendReadyNotification()` always throws — must not propagate out of
     * `notifyByEmail()`: it is logged and swallowed, since the export itself
     * already succeeded by the time this runs.
     */
    public function testNotifyByEmailSwallowsAFailureFromSendReadyEmail(): void
    {
        // Arrange
        $task = new ThrowingNotifyDatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));
        $item->taskid = 9;

        // Act — must not throw
        $task->publicNotifyByEmail(['notify_to' => 'someone@example.com'], $item);

        // Assert — sendReadyNotification() was reached and did throw, but nothing escaped
        $this->assertTrue($task->sendReadyEmailCalled);
    }

    /**
     * `requested_by` alone (no `notify_to`) is still enough to reach
     * `sendReadyNotification()` — a push subscription does not need an email
     * address, only the account id already sitting in the payload.
     */
    public function testNotifyByEmailReachesNotificationWithOnlyAUserId(): void
    {
        // Arrange
        $task = new ThrowingNotifyDatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));
        $item->taskid = 9;

        // Act
        $task->publicNotifyByEmail(['notify_to' => '', 'requested_by' => 7], $item);

        // Assert
        $this->assertTrue($task->sendReadyEmailCalled);
    }

    /**
     * `isBeingWatched()` is best-effort, the same reasoning as
     * {@see reportProgress()}: a controller with no usable database (every
     * unit test's `new \stdClass()`) must answer "not watched" rather than
     * throwing. `recentlyPolled()` actually finding a recent poll is covered
     * end to end by `QueueManagerMySQLTest::testRecordPollMakesRecentlyPolledTrue()`
     * — that needs a real `queueitems` row, which this suite's stdClass
     * controller deliberately avoids.
     */
    public function testIsBeingWatchedIsFalseWithNoUsableController(): void
    {
        // Arrange
        $task = new ConcreteDatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));

        // Act + Assert
        $this->assertFalse($task->publicIsBeingWatched($item));
    }

    /**
     * `notifyByEmail()` forwards `isBeingWatched()`'s answer into
     * `sendReadyNotification()`'s `$skipPush` — the one-line wiring that
     * decides whether `DatatableExportReadyNotification::via()` drops push.
     */
    public function testNotifyByEmailPassesIsBeingWatchedAsSkipPush(): void
    {
        // Arrange
        $task = new CapturingNotifyDatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode(['class' => FakeExportableSource::class]));

        // Act
        $task->publicNotifyByEmail(['notify_to' => 'someone@example.com'], $item);

        // Assert — no usable controller -> not watched -> push is not skipped
        $this->assertSame([false], $task->skipPushValuesSeen);
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

    /** `format: 'json'` validates — the third accepted value. */
    public function testValidateAcceptsJsonFormat(): void
    {
        // Arrange
        $task = new DatatableExportTask(new \stdClass());
        $item = $this->makeQueueItem(json_encode([
            'class'   => FakeExportableSource::class,
            'columns' => [['key' => 'id', 'label' => 'Id']],
            'format'  => 'json',
        ]));

        // Act
        $result = $task->validate($item);

        // Assert
        $this->assertTrue($result);
    }

    // ── csvToJson() ───────────────────────────────────────────────────────────

    /**
     * The header row becomes each object's keys, and only data rows become
     * objects — proving `format: 'json'`'s conversion actually turns tabular
     * CSV text into an array of `{label: value}` records, not a flat dump of
     * every CSV line.
     */
    public function testCsvToJsonUsesTheHeaderRowAsKeys(): void
    {
        // Arrange
        $task = new ConcreteDatatableExportTask(new \stdClass());
        $csv  = "Id,Name\n1,Alpha\n2,Beta\n";

        // Act
        $decoded = json_decode($task->publicCsvToJson($csv), true);

        // Assert
        $this->assertSame([
            ['Id' => '1', 'Name' => 'Alpha'],
            ['Id' => '2', 'Name' => 'Beta'],
        ], $decoded);
    }

    /**
     * A trailing blank line (the usual shape of a file ending in a newline)
     * must not become a spurious record of its own — `fgetcsv()` reads an
     * empty line as `[null]`, and that one row is skipped rather than
     * `array_combine()`d into the output.
     */
    public function testCsvToJsonSkipsATrailingBlankLine(): void
    {
        // Arrange
        $task = new ConcreteDatatableExportTask(new \stdClass());

        // Act
        $decoded = json_decode($task->publicCsvToJson("Id,Name\n1,Alpha\n\n"), true);

        // Assert — exactly the one real row, nothing for the trailing blank line
        $this->assertSame([['Id' => '1', 'Name' => 'Alpha']], $decoded);
    }

    /** No data rows — just a header — converts to an empty array, not null or an error. */
    public function testCsvToJsonWithNoRowsIsAnEmptyArray(): void
    {
        // Arrange
        $task = new ConcreteDatatableExportTask(new \stdClass());

        // Act
        $decoded = json_decode($task->publicCsvToJson("Id,Name\n"), true);

        // Assert
        $this->assertSame([], $decoded);
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
        $this->assertStringEndsWith('42.json', DatatableExportTask::filePath(42, 'json'));
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
    public function publicWriteBatches(Exportable $source, array $filters, array $columns, int $batchSize, $handle, ?QueueItem $queueItem = null): int
    {
        $queueItem ??= new class extends QueueItem {
            public function __construct() { $this->taskid = 1; }
            public function save($a = false, $d = false): static { return $this; }
        };

        return $this->writeBatches($source, $filters, $columns, $batchSize, $handle, $queueItem);
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

    public function publicCsvToJson(string $csv): string
    {
        $ref = new \ReflectionMethod(DatatableExportTask::class, 'csvToJson');

        return $ref->invoke($this, $csv);
    }

    public function publicIsBeingWatched(QueueItem $queueItem): bool
    {
        $ref = new \ReflectionMethod(DatatableExportTask::class, 'isBeingWatched');

        return $ref->invoke($this, $queueItem);
    }

    /**
     * The real `progressController()` opens an actual second database connection — not
     * what a unit test wants to exercise. Reusing `$this->controller`, the SAME fake this
     * fixture's constructor already received, keeps `reportProgress()`'s own behaviour
     * (and whatever it throws) under this test's full control.
     */
    protected function progressController(): object
    {
        return $this->controller;
    }

    public function publicReportProgress(QueueItem $queueItem, int $current): void
    {
        $ref = new \ReflectionMethod(DatatableExportTask::class, 'reportProgress');
        $ref->invoke($this, $queueItem, $current);
    }
}

/**
 * `sendReadyNotification()` always throws, so a test can prove
 * `notifyByEmail()`'s own `catch` swallows it, with no outbox table, SMTP
 * transport or push subscription involved.
 */
class ThrowingNotifyDatatableExportTask extends DatatableExportTask
{
    public bool $sendReadyEmailCalled = false;

    protected function sendReadyNotification(int $userId, string $to, string $url, bool $skipPush = false): void
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

/** Records every `$skipPush` value `notifyByEmail()` passes through, instead of sending anything. */
class CapturingNotifyDatatableExportTask extends DatatableExportTask
{
    /** @var list<bool> */
    public array $skipPushValuesSeen = [];

    protected function sendReadyNotification(int $userId, string $to, string $url, bool $skipPush = false): void
    {
        $this->skipPushValuesSeen[] = $skipPush;
    }

    /** @param array<string, mixed> $payload */
    public function publicNotifyByEmail(array $payload, QueueItem $queueItem): void
    {
        $ref = new \ReflectionMethod(DatatableExportTask::class, 'notifyByEmail');
        $ref->invoke($this, $payload, $queueItem);
    }
}
