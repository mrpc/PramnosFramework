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
     * and never a label), and `filters` — the table's AJAX request object
     * (`table.ajax.params()`), passed straight through to `exportBatch()`.
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

        $queue  = new QueueManager($this);
        $taskId = $queue->addTask(DatatableExportTask::TASK, [
            'class'       => $class,
            'filters'     => $filters,
            'columns'     => $columns,
            'requested_by' => (int) $user->userid,
        ]);

        \Pramnos\Framework\Factory::getDocument('json');
        echo json_encode(['taskid' => $taskId]);
    }

    /** `{status, message}` for a queued export, if the current user may see it. */
    public function exportstatus(mixed $id = null): void
    {
        $item = $this->ownedExportItem();
        if ($item === null) {
            return;
        }

        \Pramnos\Framework\Factory::getDocument('json');
        echo json_encode([
            'status'  => (string) $item->status,
            'message' => (string) $item->success_message,
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

        // Deterministic from the task id — not read from success_message, which holds
        // the human-readable "N rows exported" the admin queue screen shows, not a path.
        $path = DatatableExportTask::csvPath((int) $item->taskid);
        if (!is_file($path)) {
            $this->jsonError('That export file is gone.', 404);

            return;
        }

        // Guarded like LogController::sendHeader() — a CLI test run has no headers to send.
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="export-' . (int) $item->taskid . '.csv"');
        }
        readfile($path);
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
