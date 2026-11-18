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
     * The finished CSV's path for a given task id — deterministic, so
     * `DatatableExportActions::exportdownload()` computes it itself instead of
     * reading it back out of `success_message` (which holds the human-readable
     * "N rows exported" the admin queue screen shows, not a path).
     */
    public static function csvPath(int|string $taskId): string
    {
        return ROOT . DS . 'var' . DS . 'exports' . DS . $taskId . '.csv';
    }

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
        $format  = (string) ($payload['format'] ?? 'csv');

        return $class !== ''
            && class_exists($class)
            && is_subclass_of($class, Exportable::class)
            && !empty($payload['columns'])
            // 'csv' is deliberately the only value today, so a future format is an
            // additive one, not a redesign of this task.
            && $format === 'csv';
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
        $scope     = (string) ($payload['scope'] ?? 'all');

        $finalPath = self::csvPath($queueItem->taskid);
        $dir       = dirname($finalPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmpPath = $finalPath . '.tmp';
        $handle  = fopen($tmpPath, 'w');

        fputcsv($handle, array_map(
            static fn (array $column): string => (string) $column['label'],
            $columns
        ), ',', '"', '');

        $rowCount = $scope === 'page'
            // Exactly the rows already on the client's current page: one query, the
            // same start/length DataTables' own ajax.params() already carries in
            // $filters. No concurrent-batch problem to guard against with a single
            // query, so no REPEATABLE READ transaction either.
            ? $this->writePage($source, $filters, $columns, $handle)
            : $this->runInRepeatableReadSnapshot(
                fn (): int => $this->writeBatches($source, $filters, $columns, $batchSize, $handle)
            );

        fclose($handle);
        rename($tmpPath, $finalPath);

        $this->lastMessage = $rowCount . ' rows exported';

        if ($payload['notify_email'] ?? false) {
            $this->notifyByEmail($payload, $queueItem);
        }

        return ['message' => $this->lastMessage];
    }

    /**
     * Writes exactly one page's rows — `$filters['start']`/`$filters['length']`, the
     * paging DataTables' own `ajax.params()` already posts — instead of the full
     * batched export.
     *
     * @param array<int, array{key: string, label: string}> $columns
     * @param resource $handle
     */
    private function writePage(Exportable $source, array $filters, array $columns, $handle): int
    {
        $start  = max(0, (int) ($filters['start'] ?? 0));
        $length = max(1, (int) ($filters['length'] ?? self::DEFAULT_BATCH_SIZE));

        $batch = $source->exportBatch($filters, $length, $start);
        foreach ($batch as $row) {
            fputcsv($handle, array_map(
                static fn (array $column) => $row[$column['key']] ?? '',
                $columns
            ), ',', '"', '');
        }

        return count($batch);
    }

    /**
     * Queues the requester a link to the finished download, through the outbox
     * (`Email::queue()`) rather than a synchronous send — this already runs inside
     * a queue worker, so handing the SMTP round trip to `mail:flush` keeps one
     * slow send from holding up whatever export the worker picks up next.
     * Best-effort: a failed queue must not undo an export that otherwise
     * succeeded, so it is logged and swallowed rather than thrown.
     *
     * `notify_to` is the address `queueexport()` captured from the signed-in user
     * at request time — this does not reload the user later just to find one.
     *
     * @param array<string, mixed> $payload
     */
    private function notifyByEmail(array $payload, QueueItem $queueItem): void
    {
        try {
            $to = (string) ($payload['notify_to'] ?? '');
            if ($to === '') {
                return;
            }

            $exportBase = (string) ($payload['exportBase'] ?? '');
            $url        = (defined('sURL') ? (string) sURL : '') . $exportBase
                . '/exportdownload/' . $queueItem->taskid;

            $this->sendReadyEmail($to, $url);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'DatatableExportTask: failed to send the ready notification: ' . $exception->getMessage(),
                'queue_error'
            );
        }
    }

    /**
     * Queues the actual message. Pulled out of {@see notifyByEmail()} so a unit
     * test can override it to throw, proving that method's own `catch` swallows
     * a failure here without a real outbox or SMTP transport involved.
     */
    protected function sendReadyEmail(string $to, string $url): void
    {
        (new \Pramnos\Email\Email())
            ->setSubject(\Pramnos\Framework\Factory::getLanguage()->_('Your export is ready'))
            ->setTo($to)
            ->setBody('<p>' . $this->lastMessage . '.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                . \Pramnos\Framework\Factory::getLanguage()->_('Download CSV') . '</a></p>')
            ->queue();
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
