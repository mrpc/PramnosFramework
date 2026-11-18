<?php

declare(strict_types=1);

namespace Pramnos\Queue\Tasks;

use Pramnos\Html\Datatable\Exportable;
use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;

/**
 * Exports a `Datatable` with `backgroundExport = true` to a CSV file, in batches
 * instead of one unbounded query — see the Html Components Guide, "Background CSV
 * export".
 *
 * The whole batch loop runs inside one `REPEATABLE READ` transaction: every batch
 * then sees the same snapshot, so a row inserted or deleted between two batches
 * cannot be skipped or duplicated the way plain LIMIT/OFFSET pagination over a
 * live, changing table would otherwise allow.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class DatatableExportTask extends AbstractTask
{
    /** The queue's task type. */
    public const TASK = 'datatable.export';

    public string $name = self::TASK;

    /** Default rows fetched per `exportBatch()` call. */
    private const DEFAULT_BATCH_SIZE = 1500;

    /**
     * {@inheritdoc}
     *
     * The payload needs a `class` implementing {@see Exportable} and a non-empty
     * `columns` list — without either there is nothing to export.
     */
    public function validate(QueueItem $queueItem): bool
    {
        $payload = (array) json_decode((string) $queueItem->payload, true);
        $class   = (string) ($payload['class'] ?? '');

        return $class !== ''
            && class_exists($class)
            && is_subclass_of($class, Exportable::class)
            && !empty($payload['columns']);
    }

    public function execute(QueueItem $queueItem): mixed
    {
        $payload   = (array) json_decode((string) $queueItem->payload, true);
        $class     = (string) $payload['class'];
        /** @var Exportable $source */
        $source    = new $class($this->controller);
        $filters   = (array) ($payload['filters'] ?? []);
        $columns   = (array) $payload['columns'];
        $batchSize = max(1, (int) ($payload['batchSize'] ?? self::DEFAULT_BATCH_SIZE));

        $dir = ROOT . DS . 'var' . DS . 'exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmpPath = $dir . DS . $queueItem->taskid . '.csv.tmp';
        $handle  = fopen($tmpPath, 'w');

        fputcsv($handle, array_map(
            static fn (array $column): string => (string) $column['label'],
            $columns
        ), ',', '"', '');

        $rowCount = $this->runInRepeatableReadSnapshot(
            fn (): int => $this->writeBatches($source, $filters, $columns, $batchSize, $handle)
        );

        fclose($handle);
        $finalPath = $dir . DS . $queueItem->taskid . '.csv';
        rename($tmpPath, $finalPath);

        $this->lastMessage = 'var' . DS . 'exports' . DS . $queueItem->taskid . '.csv';

        return ['message' => $rowCount . ' rows exported'];
    }

    /**
     * Fetches every batch from `$source` and writes each row to `$handle` as CSV,
     * stopping once a batch comes back short of `$batchSize` (the last page).
     * Pulled out of {@see execute()} so it can be driven directly, without a real
     * database transaction, by {@see DatatableExportTaskTest}'s fake `Exportable`.
     *
     * @param array<int, array{key: string, label: string}> $columns
     * @param resource $handle
     */
    protected function writeBatches(Exportable $source, array $filters, array $columns, int $batchSize, $handle): int
    {
        $offset = 0;
        $total  = 0;
        do {
            $batch = $source->exportBatch($filters, $batchSize, $offset);
            foreach ($batch as $row) {
                fputcsv($handle, array_map(
                    static fn (array $column) => $row[$column['key']] ?? '',
                    $columns
                ), ',', '"', '');
            }
            $total  += count($batch);
            $offset += $batchSize;
        } while (count($batch) === $batchSize);

        return $total;
    }

    /**
     * Runs `$work` inside one transaction set to `REPEATABLE READ`, so every batch
     * it fetches sees the same snapshot.
     *
     * Dialect-specific session/transaction control, not expressible through the
     * query builder (rule 12): MySQL's isolation level is set *before*
     * `START TRANSACTION` (it is a session setting that applies to the next
     * transaction); PostgreSQL's is set *after* `BEGIN`, as the transaction's own
     * first statement.
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    private function runInRepeatableReadSnapshot(callable $work): mixed
    {
        $db = \Pramnos\Framework\Factory::getDatabase();

        if ($db->type === 'postgresql') {
            $db->startTransaction();
            $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        } else {
            $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->startTransaction();
        }

        try {
            $result = $work();
            $db->commitTransaction();

            return $result;
        } catch (\Throwable $exception) {
            $db->rollbackTransaction();
            throw $exception;
        }
    }

    /** "Export of App\Model\Users (12500 rows queued)". */
    public function getDescription(QueueItem $queueItem): string
    {
        $payload = (array) json_decode((string) $queueItem->payload, true);

        return 'Export of ' . (string) ($payload['class'] ?? '?');
    }
}
