<?php

declare(strict_types=1);

namespace Pramnos\Queue\Controllers;

use Pramnos\Application\Controller;

/**
 * Admin controller for background job queue management.
 *
 * Operates on the `queueitems` table created by the `create_queueitems_table`
 * migration (queue feature). Queue items are never permanently deleted — the
 * `clear()` action soft-deletes by marking status='deleted'.
 *
 * Status values: 'pending', 'processing', 'completed', 'warning', 'failed', 'stopped', 'deleted'.
 *
 * Actions:
 *   - display()    — a DataTable of jobs, fed by data(): status filter, search, page size
 *   - data()       — JSON rows for that table
 *   - retry($id)   — re-schedule a single failed job (reset to pending, increment attempts)
 *   - stop($id)    — stop a pending or processing job (status='stopped', lock cleared)
 *   - duplicate($id) — queue a new pending copy of a job
 *   - retryall()   — bulk retry all failed jobs
 *   - delete($id)  — mark a job as deleted (status='deleted')
 *   - clear()      — bulk-delete jobs by status (POST: status filter required)
 *   - stats()      — JSON: counts by status, throughput, avg processing time
 *
 * All actions require authentication the administration floor (the `super` role, 98, unless set).
 *
 * Scaffold wrappers at `src/Controllers/Queue.php` (queue feature only). A wrapper shows columns
 * of the application's own in the list by overriding {@see extraColumns()}.
 */
class QueueController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.queue';

    /** One record of this screen, for the side panel: see Controller::$assistSubject. */
    protected string $assistSubject = 'queue task';

    /** The usertype floor; 0 is the administration area's — {@see \Pramnos\Auth\AdminAccess::defaultUsertype()}, the `super` role unless set. */
    protected int $requiredUserType = 0;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'data', 'retry', 'retryall', 'delete', 'clear', 'stats', 'stop', 'duplicate']);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['retry', 'retryall', 'delete', 'clear', 'stop', 'duplicate']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /** The statuses a task can be in, for the filter and the badges. */
    protected const STATUSES = ['pending', 'processing', 'completed', 'warning', 'failed', 'stopped', 'deleted'];

    /**
     * Columns of the application's own to show in the list, as `[label => column]`.
     *
     * Empty here. A wrapper overrides it — the queue table of an application often carries
     * something of its own beside the framework's columns, and the people reading this screen
     * need it:
     *
     * ```php
     * protected function extraColumns(): array
     * {
     *     return ['Sent by' => 'meta_data'];
     * }
     * ```
     *
     * Shown and searched as text, escaped; not sortable, since the column has no index the
     * framework knows of.
     *
     * @return array<string, string>
     */
    protected function extraColumns(): array
    {
        return [];
    }

    /**
     * The jobs, as a DataTable fed by {@see data()}: a status filter, search over the type, the
     * error and the payload, a page size, and sorting by id, type and status — the indexed
     * columns, on a table that runs to tens of thousands of rows.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Queue';

        $dt = new \Pramnos\Html\Datatable('dt-queue');
        $dt->source           = adminUrl('queue/data');
        $dt->bootstrap        = false;
        $dt->footerTextSearch = true;

        // Enumerated, so a dropdown rather than a text box under the column.
        $statusFilter     = new \Pramnos\Html\Select('status_filter');
        $statusFilter->id = 'dt-queue-status';
        $statusFilter->addOptions(['' => 'Any status'] + array_combine(self::STATUSES, array_map('ucfirst', self::STATUSES)));

        $dt->addColumn('ID',       true, true,  false, 'num-html', '', true, 'left', false)
           ->addColumn('Type',     true, true,  true,  'html',     '', true, 'left', true)
           ->addColumn('Status',   true, true,  true,  'html', $statusFilter->render(), true, 'left', 'dt-queue-status')
           ->addColumn('Attempts', true, false, false, 'html',     '', true, 'left', false)
           ->addColumn('Created',  true, false, false, 'html',     '', true, 'left', false)
           ->addColumn('Error',    true, false, true,  'html',     '', true, 'left', true)
           // Searched, never shown: what a task was given is how a person finds one.
           ->addColumn('Payload',  false, false, true, 'html',     '', false, 'left', false);
        foreach (array_keys($this->extraColumns()) as $label) {
            $dt->addColumn((string) $label, true, false, true, 'html', '', true, 'left', true);
        }
        $dt->addColumn('Actions', true, false, false, 'html', '', false, 'right', false);

        $view            = $this->getView('queue');
        $view->datatable = $dt;
        $view->statuses  = self::STATUSES;

        return $view->display();
    }

    /**
     * The rows behind the list, as JSON, every value escaped.
     *
     * The fields are in the table's column order, because the DataTable asks to sort and search
     * by a column's position; `maxattempts` rides in the actions column's place and is replaced
     * by the actions.
     */
    public function data(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        \Pramnos\Framework\Factory::getDocument('json');

        $extra  = array_values(array_filter(
            $this->extraColumns(),
            static fn ($column): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $column) === 1
        ));
        $fields = array_merge(['taskid', 'type', 'status', 'attempts', 'createdat', 'error', 'payload'], $extra, ['maxattempts']);
        $result = \Pramnos\Html\Datatable\Datasource::getList('queueitems', $fields, false);
        $e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        foreach ($result[$dataKey] as &$row) {
            $id     = (int) $row[0];
            $status = (string) $row[2];
            $error  = (string) $row[5];

            $row[0] = (string) $id;
            $row[1] = $e($row[1]);
            $row[2] = $this->statusBadge($status);
            $row[3] = (int) $row[3] . ' / ' . (int) $row[count($fields) - 1];
            $row[4] = $e($row[4]);
            $row[5] = $error === '' ? '' : '<span title="' . $e($error) . '">' . $e(mb_strimwidth($error, 0, 160, '…')) . '</span>';
            $row[6] = '';
            foreach (array_keys($extra) as $i) {
                $row[7 + $i] = $e($row[7 + $i]);
            }
            $row[count($fields) - 1] = $this->actions($id, $status);

            unset($row['DT_RowId']);
        }
        unset($row);

        return \Pramnos\Http\Response::json($result);
    }

    /** A status as a badge. */
    protected function statusBadge(string $status): string
    {
        $class = match ($status) {
            'completed', 'processing' => 'pf-state pf-state-on',
            'failed', 'stopped', 'deleted' => 'pf-state pf-state-off',
            default => 'pf-state',
        };

        return '<span class="' . $class . '">' . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /** What can be done with a task in this status, as POST buttons carrying the token. */
    protected function actions(int $id, string $status): string
    {
        $url  = static fn (string $action): string => adminUrl('queue/' . $action . '/') . $id;
        $html = '';
        if ($status === 'failed') {
            $html .= \Pramnos\Html\Icon::postButton($url('retry'), 'retry', 'Run this task again');
        }
        if ($status === 'pending' || $status === 'processing') {
            $html .= \Pramnos\Html\Icon::postButton($url('stop'), 'deactivate', 'Stop this task', ['data-confirm' => 'Stop this task? A worker running it finishes the run in hand.']);
        }
        $html .= \Pramnos\Html\Icon::postButton($url('duplicate'), 'duplicate', 'Queue a copy of this task', ['data-confirm' => 'Queue a copy of this task?']);
        if ($status !== 'deleted') {
            $html .= \Pramnos\Html\Icon::postButton($url('delete'), 'delete', 'Delete this task', ['data-confirm' => 'Delete this task?']);
        }

        return $html;
    }

    /**
     * Stop a pending or processing task: status `stopped`, its lock cleared, so no worker claims
     * it again. A worker in the middle of running it finishes that run; what it then writes does
     * not bring the task back, since only `pending` is ever claimed.
     */
    public function stop(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $taskId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($taskId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(sURL . 'queue');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('#PREFIX#queueitems')
            ->where('taskid', $taskId)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status'      => 'stopped',
                'lockedby'    => null,
                'lockexpires' => null,
                // So the retention purge, which reads it, removes a stopped task in its time.
                'completedat' => date('Y-m-d H:i:s'),
            ]);

        $this->addMessage('Stopped.');
        $this->redirect(sURL . 'queue');
    }

    /**
     * Queue a new pending copy of a task: the same type, payload, priority and attempts allowed,
     * and the application's own columns; no attempts, no lock, no error, created now.
     */
    public function duplicate(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $taskId = (int) \Pramnos\Http\Request::staticGetOption();
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $copy   = array_merge(['type', 'payload', 'priority', 'maxattempts', 'task_hash'], array_values($this->extraColumns()));
        $task   = $taskId > 0
            ? $db->queryBuilder()->table('#PREFIX#queueitems')->select($copy)->where('taskid', $taskId)->first()
            : null;
        if (!$task || empty($task->fields)) {
            $this->addError('There is no such task.');
            $this->redirect(sURL . 'queue');
            return;
        }

        $db->queryBuilder()->table('#PREFIX#queueitems')->insert(
            ['status' => 'pending', 'attempts' => 0] + array_intersect_key((array) $task->fields, array_flip($copy))
        );

        $this->addMessage('A copy has been queued.');
        $this->redirect(sURL . 'queue');
    }

    /**
     * Re-schedule a single failed job.
     * Resets status to 'pending', clears the error field, and preserves the
     * attempts count so maxattempts enforcement still applies.
     */
    public function retry(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $taskId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($taskId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(sURL . 'queue');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('#PREFIX#queueitems')
            ->where('taskid', $taskId)
            ->where('status', 'failed')
            ->update([
                'status'    => 'pending',
                'error'     => null,
                'lockedby'  => null,
                'lockexpires' => null,
            ]);

        $this->addMessage('Queued again.');
        $this->redirect(sURL . 'queue');
    }

    /**
     * Bulk-retry all failed jobs.
     * Resets all failed jobs to 'pending' status.
     */
    public function retryall(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('#PREFIX#queueitems')
            ->where('status', 'failed')
            ->update([
                'status'    => 'pending',
                'error'     => null,
                'lockedby'  => null,
                'lockexpires' => null,
            ]);

        $this->addMessage('Every failed job has been queued again.');
        $this->redirect(sURL . 'queue');
    }

    /**
     * Soft-delete a single queue item (status='deleted').
     * Preserves the row for audit purposes; workers skip 'deleted' items.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $taskId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($taskId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(sURL . 'queue');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('#PREFIX#queueitems')
            ->where('taskid', $taskId)
            ->update(['status' => 'deleted']);

        $this->addMessage('Deleted.');
        $this->redirect(sURL . 'queue');
    }

    /**
     * Bulk soft-delete jobs by status.
     * POST field `status` is required. Allowed targets: 'failed', 'completed', 'stopped', 'deleted'.
     * 'pending' and 'processing' jobs cannot be bulk-cleared to avoid data loss.
     */
    public function clear(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $targetStatus = trim((string) ($_POST['status'] ?? ''));
        $allowed      = ['failed', 'completed', 'stopped', 'deleted'];

        if (!in_array($targetStatus, $allowed, true)) {
            $this->addError('That is not a status this queue uses.');
            $this->redirect(sURL . 'queue');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('#PREFIX#queueitems')
            ->where('status', $targetStatus)
            ->update(['status' => 'deleted']);

        $this->addMessage('Cleared.');
        $this->redirect(sURL . 'queue');
    }

    /**
     * JSON endpoint: queue statistics — counts by status and processing time.
     *
     * Response shape:
     *   {
     *     "counts": {"pending": int, "processing": int, "completed": int, "failed": int},
     *     "avg_processing_ms": float|null
     *   }
     */
    public function stats(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $counts = [];

        foreach (['pending', 'processing', 'completed', 'failed', 'stopped'] as $status) {
            $counts[$status] = $db->queryBuilder()
                ->table('#PREFIX#queueitems')
                ->where('status', $status)
                ->count();
        }

        // Average processing time for completed jobs where both timestamps are present
        $avgResult = null;
        try {
            // The difference of two timestamps is dialect SQL; the rest is the builder's.
            $qb      = $db->queryBuilder();
            $elapsed = $db->type === 'postgresql'
                ? 'AVG(EXTRACT(EPOCH FROM (completedat - startedat)) * 1000)'
                : 'AVG(TIMESTAMPDIFF(SECOND, startedat, completedat) * 1000)';
            $r = $qb->table('#PREFIX#queueitems')
                ->select([$qb->raw($elapsed . ' AS avg_ms')])
                ->where('status', 'completed')
                ->whereNotNull('startedat')
                ->whereNotNull('completedat')
                ->first();
            if ($r && $r->numRows > 0 && $r->fields['avg_ms'] !== null) {
                $avgResult = round((float) $r->fields['avg_ms'], 2);
            }
        } catch (\Exception $e) {
            // Table not yet created or insufficient schema
        }

        header('Content-Type: application/json');
        echo json_encode([
            'counts'             => $counts,
            'avg_processing_ms'  => $avgResult,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

}
