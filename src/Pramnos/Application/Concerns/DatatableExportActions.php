<?php

declare(strict_types=1);

namespace Pramnos\Application\Concerns;

use Pramnos\Html\Datatable\Exportable;
use Pramnos\Queue\QueueManager;
use Pramnos\Queue\Tasks\DatatableExportTask;
use Pramnos\User\User;

/**
 * `queueexport()` / `exportstatus($id)` / `exportdownload($id)` for a controller whose
 * `Datatable` has `backgroundExport = true` — see the Html Components Guide,
 * "Background CSV export".
 *
 * A controller using this trait:
 *
 * ```php
 * use DatatableExportActions;
 *
 * protected function exportableModels(): array
 * {
 *     return [Models\Users::class];
 * }
 * ```
 *
 * and wires the three actions into its own auth/write lists, same as any other
 * action (`addAuthAction(['queueexport', 'exportstatus', 'exportdownload'])`,
 * `addWriteAction(['queueexport'])`).
 *
 * `exportableModels()` is an allow-list, not a convenience: the model class is read
 * from the client's POST body, so a controller that queued whatever class a request
 * named would let a signed-in user export an arbitrary model through a queue worker.
 */
trait DatatableExportActions
{
    /**
     * The `Exportable` model classes this controller's export button may queue.
     *
     * @return list<class-string<Exportable>>
     */
    abstract protected function exportableModels(): array;

    /**
     * Queue a `DatatableExportTask` for the current table's filters.
     *
     * POST: `class` (one of {@see exportableModels()}), `columns` as a list of
     * `{key, label}` pairs (built server-side from the table's `aoColumns`, not
     * from DataTables' own `ajax.params().columns[]` — those carry `{data,name,...}`
     * and never a label), `filters` — the table's AJAX request object
     * (`table.ajax.params()`), passed straight through to `exportBatch()` — and,
     * from the export modal: `format` (`'csv'`, `'excel'` or `'json'`), `scope` (`'all'`, the
     * default, or `'page'` for just the rows already on screen), `notify_email`
     * (`'1'` to email the requester when it is ready) and `exportBase` (needed to
     * build that email's download link).
     */
    public function queueexport(): void
    {
        $user = User::current();
        if ($user === null) {
            $this->jsonError('Not signed in.', 401);

            return;
        }

        $class = (string) ($_POST['class'] ?? '');
        if (!in_array($class, $this->exportableModels(), true)) {
            $this->jsonError('That model is not exportable from this screen.', 403);

            return;
        }

        $format = (string) ($_POST['format'] ?? 'csv');
        if (!in_array($format, ['csv', 'excel', 'json'], true)) {
            $this->jsonError('That export format is not supported.', 422);

            return;
        }

        $scope = (string) ($_POST['scope'] ?? 'all');
        if (!in_array($scope, ['all', 'page'], true)) {
            $this->jsonError('That export scope is not supported.', 422);

            return;
        }

        $columns = [];
        foreach ((array) ($_POST['columns'] ?? []) as $column) {
            $key = (string) ($column['key'] ?? $column['name'] ?? $column['data'] ?? '');
            if ($key !== '') {
                $columns[] = ['key' => $key, 'label' => (string) ($column['label'] ?? $key)];
            }
        }
        if ($columns === []) {
            $this->jsonError('No exportable columns were posted.', 422);

            return;
        }

        $filters = (array) ($_POST['filters'] ?? []);

        $payload = [
            'class'        => $class,
            'filters'      => $filters,
            'columns'      => $columns,
            'format'       => $format,
            'scope'        => $scope,
            'notify_email' => (bool) ($_POST['notify_email'] ?? false),
            // Captured now, from the signed-in user the controller already has — the
            // task does not reload the user later just to find an email address.
            // $user may be a lighter sealed identity than a full User, with no
            // email property at all (RequestIdentity::seal()'s own contract).
            'notify_to'    => (string) ($user->email ?? ''),
            'exportBase'   => (string) ($_POST['exportBase'] ?? ''),
            'requested_by' => (int) $user->userid,
        ];

        // unique: true — requested_by is part of the payload, so this naturally scopes
        // per user: two people exporting the same thing never collide, but the same
        // person clicking "Start export" twice on the same screen while the first run
        // is still pending/processing does not queue a second, identical job.
        $queue     = new QueueManager($this);
        $taskId    = $queue->addTask(DatatableExportTask::TASK, $payload, unique: true);
        $duplicate = false;
        if ($taskId === null) {
            // Not a failure — the modal can wire straight into the run already in
            // flight instead of the requester seeing a bare error for clicking the
            // same button twice.
            $taskId = $queue->findActiveDuplicate(DatatableExportTask::TASK, $payload);
            if ($taskId === null) {
                // The task that blocked addTask() a moment ago finished between that
                // check and this one — safe to queue now instead of answering null.
                $taskId = $queue->addTask(DatatableExportTask::TASK, $payload, unique: true);
            } else {
                $duplicate = true;
            }
        }

        \Pramnos\Framework\Factory::getDocument('json');
        echo json_encode(['taskid' => $taskId, 'duplicate' => $duplicate]);
    }

    /**
     * This user's own `datatable.export` tasks — `{taskid, status, download}`,
     * `download` present only where `status === 'completed'`. No `super`+ needed:
     * filtered strictly to `requested_by` matching the current user, same as
     * {@see ownedExportItem()}'s ownership check, so there is nothing here a user
     * could not already reach one id at a time through `exportstatus()`.
     */
    public function myExports(): void
    {
        $user = User::current();
        if ($user === null) {
            $this->jsonError('Not signed in.', 401);

            return;
        }

        // Pulled in full and filtered in PHP: one person's own export history is
        // never large enough to need a JSON predicate pushed into SQL for it.
        $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#queueitems')
            ->where('type', DatatableExportTask::TASK)
            ->orderBy('taskid', 'desc')
            ->limit(100)
            ->get()->fetchAll();

        $exports = [];
        foreach ($rows as $row) {
            $payload = (array) json_decode((string) $row['payload'], true);
            if ((int) ($payload['requested_by'] ?? 0) !== (int) $user->userid) {
                continue;
            }
            $exports[] = [
                'taskid'   => (int) $row['taskid'],
                'status'   => (string) $row['status'],
                'label'    => $this->exportLabel($row, $payload),
                'download' => (string) $row['status'] === 'completed'
                    ? $this->exportDownloadUrl((int) $row['taskid'])
                    : null,
            ];
        }

        \Pramnos\Framework\Factory::getDocument('json');
        echo json_encode(['exports' => $exports]);
    }

    /**
     * "Users (CSV) · 2026-11-18 14:32 · 2866 rows exported" — a bare taskid told
     * the person nothing about which of their own exports a row even was. The
     * model's short class name, the format, when it was queued, and — once
     * completed — the row count already sitting in `success_message` are all
     * already on hand; nothing here is a fact only the task itself would know.
     *
     * @param array<string, mixed> $row     A `queueitems` row, as fetched above
     * @param array<string, mixed> $payload That row's decoded payload
     */
    private function exportLabel(array $row, array $payload): string
    {
        $name  = $this->exportShortName((string) ($payload['class'] ?? ''));
        $label = $name . ' (' . strtoupper((string) ($payload['format'] ?? 'csv')) . ')';

        if (!empty($row['createdat'])) {
            $label .= ' · ' . (string) $row['createdat'];
        }
        if ((string) ($row['status'] ?? '') === 'completed' && !empty($row['success_message'])) {
            $label .= ' · ' . (string) $row['success_message'];
        }

        return $label;
    }

    /**
     * Relative URL for a completed export's download. `exportBase` is posted on
     * `queueexport()`'s own request; `myExports()` is a separate GET, so its caller
     * sends it as a query parameter instead (the client already knows it, the same
     * value the export button itself posts).
     */
    private function exportDownloadUrl(int $taskId): string
    {
        $base = (string) ($_POST['exportBase'] ?? $_GET['exportBase'] ?? '');

        return rtrim($base, '/') . '/exportdownload/' . $taskId;
    }

    /**
     * `{status, message, progress}` for a queued export, if the current user may
     * see it. `progress` is the rows-written-so-far `DatatableExportTask` reports
     * per batch (`QueueManager::updateProgress()`) — `null` where nothing has
     * been reported yet (a fast `scope: 'page'` export, or an installation that
     * has not run the migration adding the column).
     *
     * Also records this poll (`QueueManager::recordPoll()`) — `DatatableExportTask`
     * reads it back at completion to tell whether the requester is actively
     * watching right now, and skip a push notification that would only repeat
     * what they already see on screen.
     */
    public function exportstatus(mixed $id = null): void
    {
        $item = $this->ownedExportItem();
        if ($item === null) {
            return;
        }

        (new QueueManager($this))->recordPoll((int) $item->taskid);

        \Pramnos\Framework\Factory::getDocument('json');
        echo json_encode([
            'status'   => (string) $item->status,
            'message'  => (string) $item->success_message,
            'progress' => isset($item->progress_current) ? (int) $item->progress_current : null,
        ]);
    }

    /** Streams the finished CSV, if the current user may see it and it is done. */
    public function exportdownload(mixed $id = null): void
    {
        $item = $this->ownedExportItem();
        if ($item === null) {
            return;
        }
        if ((string) $item->status !== 'completed') {
            $this->jsonError('That export is not ready yet.', 409);

            return;
        }

        $payload = (array) json_decode((string) $item->payload, true);
        $format  = (string) ($payload['format'] ?? 'csv');

        // Deterministic from the task id and format — not read from success_message,
        // which holds the human-readable "N rows exported" the admin queue screen shows,
        // not a path.
        $path = DatatableExportTask::filePath((int) $item->taskid, $format);
        if (!is_file($path)) {
            $this->jsonError('That export file is gone.', 404);

            return;
        }

        [$contentType, $extension] = match ($format) {
            'excel' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '.xlsx'],
            'json'  => ['application/json', '.json'],
            default => ['text/csv', '.csv'],
        };

        // Without this, the default ('html') document type still wraps whatever
        // readfile() writes with the theme's page template at render() time — the
        // binary download arrives with the admin page's own header/footer/script
        // tags appended after it, byte-identical to the file up to that point but
        // unopenable. The same pattern queueexport()/exportstatus() already use
        // with 'json', here for binary output instead.
        \Pramnos\Framework\Factory::getDocument('raw');

        // Guarded like LogController::sendHeader() — a CLI test run has no headers to send.
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . $this->exportFilename($item, $payload, $extension) . '"');
        }
        readfile($path);
    }

    /**
     * "Users-2026-11-19-1234.csv" — a bare "export-1234.csv" told a person nothing
     * about which of several downloads sitting in their Downloads folder this even
     * was. The model's short class name and the day the export was queued
     * (`$item->createdat`, not today's date: the same completed export always gets
     * the same name on a re-download) are both already on hand; the taskid stays,
     * at the end, so two same-day exports of the same model never collide.
     *
     * @param array<string, mixed> $payload
     */
    private function exportFilename(object $item, array $payload, string $extension): string
    {
        $date = ((string) ($item->createdat ?? '')) !== ''
            ? substr((string) $item->createdat, 0, 10)
            : date('Y-m-d');

        return $this->exportShortName((string) ($payload['class'] ?? ''))
            . '-' . $date . '-' . (int) $item->taskid . $extension;
    }

    /** "Users" from "App\Models\Users" — the one part of the class name a person reads. */
    private function exportShortName(string $class): string
    {
        return $class !== '' ? substr($class, (int) (strrpos($class, '\\') ?: -1) + 1) : 'Export';
    }

    /**
     * Loads the queue item named by the route's id, and checks the current user
     * either requested it (`requested_by` in its payload) or is `super`+. Writes a
     * JSON error and returns null when either check fails.
     */
    private function ownedExportItem(): ?object
    {
        $user = User::current();
        if ($user === null) {
            $this->jsonError('Not signed in.', 401);

            return null;
        }

        $taskId = (int) \Pramnos\Http\Request::staticGetOption();
        $item   = $taskId > 0
            ? \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#queueitems')->where('taskid', $taskId)->first()
            : null;
        if (!$item || empty($item->fields) || (string) $item->type !== DatatableExportTask::TASK) {
            $this->jsonError('There is no such export.', 404);

            return null;
        }

        $payload    = (array) json_decode((string) $item->fields['payload'], true);
        $requestedBy = (int) ($payload['requested_by'] ?? 0);
        $isOwner     = $requestedBy > 0 && $requestedBy === (int) $user->userid;
        $isSuper     = (int) $user->usertype >= \Pramnos\User\Roles::level(\Pramnos\User\Roles::SUPER);
        if (!$isOwner && !$isSuper) {
            $this->jsonError('That export is not yours.', 403);

            return null;
        }

        return (object) $item->fields;
    }

    /** One JSON error, with the HTTP status a poller/download link should act on. */
    private function jsonError(string $message, int $httpStatus): void
    {
        http_response_code($httpStatus);
        \Pramnos\Framework\Factory::getDocument('json');
        echo json_encode(['error' => $message]);
    }
}
