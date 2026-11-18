<?php

declare(strict_types=1);

namespace Pramnos\Queue\Tasks;

use Pramnos\Html\Datatable\Exportable;
use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;
use Pramnos\Queue\QueueManager;

/**
 * Exports a `Datatable` with `backgroundExport = true` to a CSV, Excel or JSON
 * file, in batches instead of one unbounded query — see the Html Components
 * Guide, "Background CSV export". Rows are always written as CSV first;
 * `Pramnos\Export\Xlsx::fromCsv()` converts that into the final `.xlsx` for
 * `format: 'excel'`, and {@see csvToJson()} converts it into a JSON array of
 * objects for `format: 'json'` — no second, parallel writer and no new
 * Composer dependency for either. `Xlsx` needs only `ext-zip`, which it
 * already checks for and fails clearly without; `Datatable::renderJs()` hides
 * the "Excel" option from the modal when it is missing. `json_encode()` is
 * core PHP, so `'json'` needs no such guard.
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

    /** `format` values this task accepts, and the file extension each writes. */
    private const EXTENSIONS = ['csv' => '.csv', 'excel' => '.xlsx', 'json' => '.json'];

    /**
     * The finished export's path for a given task id and format — deterministic,
     * so `DatatableExportActions::exportdownload()` computes it itself instead of
     * reading it back out of `success_message` (which holds the human-readable
     * "N rows exported" the admin queue screen shows, not a path).
     */
    public static function filePath(int|string $taskId, string $format = 'csv'): string
    {
        return ROOT . DS . 'var' . DS . 'exports' . DS . $taskId . (self::EXTENSIONS[$format] ?? '.csv');
    }

    /**
     * Every path a task id's export could have been written to, one per known
     * `format` — for cleanup that does not know (and should not have to look up)
     * which format a given finished task actually used.
     *
     * @return list<string>
     */
    public static function allFilePaths(int|string $taskId): array
    {
        return array_map(
            static fn (string $format): string => self::filePath($taskId, $format),
            array_keys(self::EXTENSIONS)
        );
    }

    /** @deprecated Use {@see filePath()} — kept for the CSV-only call sites that predate `excel`. */
    public static function csvPath(int|string $taskId): string
    {
        return self::filePath($taskId, 'csv');
    }

    /**
     * {@inheritdoc}
     *
     * The payload needs a `class` implementing {@see Exportable}, a non-empty
     * `columns` list, and a `format` this task knows how to write (`'csv'`, the
     * default, `'excel'`, or `'json'`) — without any of those there is nothing
     * to export.
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
            && isset(self::EXTENSIONS[$format]);
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
        $format    = (string) ($payload['format'] ?? 'csv');

        // Rows are always written as CSV first — Xlsx::fromCsv() converts that into the
        // final .xlsx when $format is 'excel', rather than a second, parallel writer.
        $finalPath = self::filePath($queueItem->taskid, $format);
        $csvPath   = self::filePath($queueItem->taskid, 'csv');
        $dir       = dirname($finalPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmpPath = $csvPath . '.tmp';
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
                fn (): int => $this->writeBatches($source, $filters, $columns, $batchSize, $handle, $queueItem)
            );

        fclose($handle);

        if ($format === 'excel') {
            file_put_contents(
                $finalPath,
                \Pramnos\Export\Xlsx::fromCsv((string) file_get_contents($tmpPath), 'Export')
            );
            unlink($tmpPath);
        } elseif ($format === 'json') {
            file_put_contents($finalPath, $this->csvToJson((string) file_get_contents($tmpPath)));
            unlink($tmpPath);
        } else {
            rename($tmpPath, $finalPath);
        }

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
            $userId = (int) ($payload['requested_by'] ?? 0);
            $to     = (string) ($payload['notify_to'] ?? '');
            // $requested_by is already in the payload for the ownership check — reused
            // here rather than reloading a User, same reasoning as capturing $notify_to
            // instead of an address looked up later. Either alone is enough: mail skips
            // itself with no address, push only fires where a subscription exists.
            if ($userId <= 0 && $to === '') {
                return;
            }

            $exportBase = (string) ($payload['exportBase'] ?? '');
            $url        = (defined('sURL') ? (string) sURL : '') . $exportBase
                . '/exportdownload/' . $queueItem->taskid;

            $this->sendReadyNotification($userId, $to, $url, $this->isBeingWatched($queueItem));
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'DatatableExportTask: failed to send the ready notification: ' . $exception->getMessage(),
                'queue_error'
            );
        }
    }

    /**
     * Dispatches {@see DatatableExportReadyNotification} through the framework's
     * `Notifier` — mail always, push too where the requester has a live
     * subscription — instead of a raw `Email::queue()` call. Pulled out of
     * {@see notifyByEmail()} so a unit test can override it to throw, proving
     * that method's own `catch` swallows a failure here without a real outbox,
     * SMTP transport or push subscription involved.
     */
    protected function sendReadyNotification(int $userId, string $to, string $url, bool $skipPush = false): void
    {
        $notifiable = (object) ['userid' => $userId, 'email' => $to];
        (new \Pramnos\Notification\Notifier())->send(
            [$notifiable],
            new DatatableExportReadyNotification($this->lastMessage, $url, $skipPush)
        );
    }

    /**
     * Whether the requester's own `exportstatus()` polling touched
     * `last_polled_at` recently enough that they are almost certainly still
     * looking at the modal — in which case a push would only repeat the
     * download link already on their screen. Best-effort, like
     * {@see reportProgress()}: a controller with no usable database (a unit
     * test's double) answers "not watched" rather than throwing.
     */
    private function isBeingWatched(QueueItem $queueItem): bool
    {
        if (!isset($this->controller->application->database)) {
            return false;
        }

        try {
            return (new QueueManager($this->controller))->recentlyPolled($queueItem->taskid);
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * `format: 'json'`'s own conversion — a header row and data rows, written as
     * CSV like every other format, turned into a JSON array of `{label: value}`
     * objects. Keeping one writer (CSV) and converting it afterwards, same as
     * `Xlsx::fromCsv()` for `'excel'`, instead of a second code path that writes
     * JSON directly batch by batch.
     */
    private function csvToJson(string $csv): string
    {
        $csv    = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '') ?: [];
        $rows   = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($row, count($header), ''));
        }
        fclose($stream);

        return (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Fetches every batch from `$source` and writes each row to `$handle` as CSV,
     * stopping once a batch comes back short of `$batchSize` (the last page).
     * Pulled out of {@see execute()} so it can be driven directly, without a real
     * database transaction, by {@see DatatableExportTaskTest}'s fake `Exportable`.
     *
     * Reports rows written so far after every batch (`QueueManager::updateProgress()`),
     * so the modal's polling has something better to show than a static "Exporting…"
     * for an export whose batches take minutes. Best effort: a batch that could not
     * report this must not fail the export over it.
     *
     * @param array<int, array{key: string, label: string}> $columns
     * @param resource $handle
     */
    protected function writeBatches(Exportable $source, array $filters, array $columns, int $batchSize, $handle, QueueItem $queueItem): int
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
            $this->reportProgress($queueItem, $total);
        } while (count($batch) === $batchSize);

        return $total;
    }

    /**
     * Best effort, same reasoning as {@see notifyByEmail()}'s own catch: a batch
     * that cannot report its progress — an installation that has not run the
     * migration adding `progress_current` yet, a stubbed `$controller` in a unit
     * test — must not fail the export over it.
     */
    private function reportProgress(QueueItem $queueItem, int $current): void
    {
        try {
            // isset() on the whole chain rather than catching the warning a stubbed
            // $controller in a unit test would otherwise emit for the missing property.
            if (!isset($this->controller->application->database)) {
                return;
            }
            (new QueueManager($this->controller))->updateProgress($queueItem, $current);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'DatatableExportTask: failed to report progress: ' . $exception->getMessage(),
                'queue_error'
            );
        }
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
