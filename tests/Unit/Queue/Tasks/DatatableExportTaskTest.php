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
}
