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
 *   - display()    — the queue's state per status and per type, and a DataTable of jobs fed by
 *                    data(): status and type filters, search, page size
 *   - data()       — JSON rows for that table
 *   - show($id)    — one task: every field, its payload key by key, what can be done with it
 *   - history()    — the hourly roll-up per task type, over the last two days
 *   - retry($id)   — re-schedule a single failed job (reset to pending, increment attempts)
 *   - stop($id)    — stop a pending or processing job (status='stopped', lock cleared)
 *   - duplicate($id) — queue a new pending copy of a job
 *   - retryall()   — bulk retry all failed jobs
 *   - delete($id)  — mark a job as deleted (status='deleted')
 *   - clear()      — bulk-delete jobs by status (POST: status filter required)
 *   - stats()      — JSON: counts by status and average processing time, for a monitor; no
 *                    page links to it
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
        $this->addAuthAction(['display', 'data', 'show', 'history', 'retry', 'retryall', 'delete', 'clear', 'stats', 'stop', 'duplicate']);
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

    /** How far back the per-type rates on the list page look, in seconds. */
    protected const RATE_WINDOW = 600;

    /** How far back the statistics page reads the hourly roll-up, in hours. */
    protected const HISTORY_HOURS = 48;

    /**
     * The queue at a glance, then its tasks.
     *
     * Above the table: how many tasks are in each status, counted on this request — the page is
     * opened to watch a queue drain — and, per task type, whether it is keeping up. Per type and
     * not one total: a draining type and a growing one add up to a figure that describes
     * neither.
     *
     * The table is a DataTable fed by {@see data()}: status and type filters, search over the
     * type, the error and the payload, a page size, and sorting by id, type and status — the
     * indexed columns, on a table that runs to tens of thousands of rows.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Queue');

        $dt = new \Pramnos\Html\Datatable('dt-queue');
        $dt->source           = adminUrl('queue/data');
        $dt->bootstrap        = false;
        $dt->footerTextSearch = true;

        // Enumerated, so dropdowns rather than text boxes under the columns.
        $statusFilter     = new \Pramnos\Html\Select('status_filter');
        $statusFilter->id = 'dt-queue-status';
        $statusFilter->addOptions(['' => t('Any status')] + $this->statusLabels());
        $types            = $this->taskTypes();
        $typeFilter       = new \Pramnos\Html\Select('type_filter');
        $typeFilter->id   = 'dt-queue-type';
        $typeFilter->addOptions(['' => t('Any type')] + ($types === [] ? [] : array_combine($types, $types)));

        $dt->addColumn(t('ID'),             true,  true,  false, 'num-html', '', true, 'left', false)
           ->addColumn(t('Type'),           true,  true,  true,  'html', $typeFilter->render(), true, 'left', 'dt-queue-type')
           ->addColumn(t('Status'),         true,  true,  true,  'html', $statusFilter->render(), true, 'left', 'dt-queue-status')
           ->addColumn(t('Priority'),       true,  false, false, 'html', '', true, 'left', false)
           ->addColumn(t('Attempts'),       true,  false, false, 'html', '', true, 'left', false)
           ->addColumn(t('Created at'),     true,  false, false, 'html', '', true, 'left', false)
           ->addColumn(t('Started at'),     true,  false, false, 'html', '', true, 'left', false)
           ->addColumn(t('Completed at'),   true,  false, false, 'html', '', true, 'left', false)
           ->addColumn(t('Execution time'), true,  false, false, 'html', '', true, 'left', false)
           ->addColumn(t('Error'),          true,  false, true,  'html', '', true, 'left', true)
           // Searched, never shown: what a task was given is how a person finds one.
           ->addColumn(t('Payload'),        false, false, true,  'html', '', false, 'left', false);
        foreach (array_keys($this->extraColumns()) as $label) {
            $dt->addColumn((string) $label, true, false, true, 'html', '', true, 'left', true);
        }
        $dt->addColumn(t('Actions'), true, false, false, 'html', '', false, 'right', false);

        $view             = $this->getView('queue');
        $view->datatable  = $dt;
        $view->statuses   = $this->statusLabels();
        $view->counts     = $this->statusCounts();
        $view->byType     = $this->ratesByType();
        $view->rateWindow = static::RATE_WINDOW;

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
        $fields = array_merge(
            ['taskid', 'type', 'status', 'priority', 'attempts', 'createdat', 'startedat', 'completedat', 'execution_time', 'error', 'payload'],
            $extra,
            ['maxattempts']
        );
        $result = \Pramnos\Html\Datatable\Datasource::getList('queueitems', $fields, false);
        $e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $last   = count($fields) - 1;

        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        foreach ($result[$dataKey] as &$row) {
            $id     = (int) $row[0];
            $status = (string) $row[2];
            $error  = (string) $row[9];

            // The id opens the task: what it was given, its error in full, its lock.
            $row[0] = '<a href="' . $e(adminUrl('queue/show/') . $id) . '" data-pf-modal>' . $id . '</a>';
            $row[1] = $e($row[1]) . $this->eventOf((string) $row[1], (string) $row[10]);
            $row[2] = $this->statusBadge($status);
            $row[3] = (string) (int) $row[3];
            $row[4] = (int) $row[4] . ' / ' . (int) $row[$last];
            foreach ([5, 6, 7] as $i) {
                $row[$i] = $e($row[$i]);
            }
            $row[8]  = (string) $row[8] === '' ? '' : round((float) $row[8], 3) . ' s';
            $row[9]  = $error === '' ? '' : '<span title="' . $e($error) . '">' . $e(mb_strimwidth($error, 0, 160, '…')) . '</span>';
            $row[10] = '';
            foreach (array_keys($extra) as $i) {
                $row[11 + $i] = $e($row[11 + $i]);
            }
            $row[$last] = $this->actions($id, $status);

            unset($row['DT_RowId']);
        }
        unset($row);

        return \Pramnos\Http\Response::json($result);
    }

    /**
     * One task: every field it has, its payload key by key, and what can be done with it.
     */
    public function show(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $taskId = (int) \Pramnos\Http\Request::staticGetOption();
        $result = $taskId > 0
            ? \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#queueitems')->where('taskid', $taskId)->first()
            : null;
        if (!$result || empty($result->fields)) {
            $this->addError(t('There is no such task.'));
            $this->redirect(adminUrl('queue'));

            return null;
        }
        $task = (array) $result->fields;

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = sprintf(t('Task #%d'), $taskId);

        $view          = $this->getView('queue');
        $view->task    = $task;
        $view->fields  = $this->taskFields($task);
        $view->payload = $this->payloadEntries((string) ($task['payload'] ?? ''));
        $view->status  = $this->statusBadge((string) ($task['status'] ?? ''));
        $view->actions = $this->actions($taskId, (string) ($task['status'] ?? ''));

        return $view->display('show');
    }

    /**
     * What the queue has been doing: the hourly roll-up per task type over the last two days,
     * from {@see \Pramnos\Queue\QueueManager::history()} — kept when the tasks themselves are
     * purged, so it answers for longer than the table does.
     *
     * Its own page, not a block on the list: the list is opened to watch a queue drain and is
     * counted on every refresh, and this is a different question asked far less often.
     */
    public function history(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Queue statistics');

        $view          = $this->getView('queue');
        $view->hours   = static::HISTORY_HOURS;
        $view->history = $this->queueManager()->history(static::HISTORY_HOURS);

        return $view->display('history');
    }

    /** The framework's queue manager over this screen's connection. */
    protected function queueManager(): \Pramnos\Queue\QueueManager
    {
        return new \Pramnos\Queue\QueueManager($this);
    }

    /**
     * Each status's name in the reader's language, by status.
     *
     * @return array<string, string>
     */
    protected function statusLabels(): array
    {
        return [
            'pending'    => t('Pending'),
            'processing' => t('Processing'),
            'completed'  => t('Completed'),
            'warning'    => t('Warning'),
            'failed'     => t('Failed'),
            'stopped'    => t('Stopped'),
            'deleted'    => t('Deleted'),
        ];
    }

    /**
     * How many tasks are in each status, and in all — one grouped count, on this request.
     *
     * @return array<string, int>
     */
    protected function statusCounts(): array
    {
        $qb     = \Pramnos\Framework\Factory::getDatabase()->queryBuilder();
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($qb->table('#PREFIX#queueitems')->select(['status', $qb->raw('COUNT(*) AS n')])->groupBy('status')->getAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * The task types the filter offers: those the queue carries, and those with a handler.
     *
     * The table's first, from {@see \Pramnos\Queue\QueueManager::activeTaskTypes()}: a type with
     * tasks and no handler is the one somebody needs to find, and a directory of handlers
     * cannot list it.
     *
     * @return list<string>
     */
    protected function taskTypes(): array
    {
        $manager = $this->queueManager();
        $types   = array_unique(array_merge($manager->activeTaskTypes(0), $manager->getTaskTypes()));
        sort($types);

        return array_values($types);
    }

    /**
     * Per task type with anything to report — pending, running, or arrived in the window —
     * whether it is keeping up: running now, pending, done and net a minute, and when it clears.
     *
     * `health` is `draining` (a completion time can be given), `keeping_up` (arrivals match
     * completions and the backlog is under a minute of the type's own output), or `behind`
     * (work is waiting that the type is not clearing — the one worth reading).
     *
     * @return list<array<string, mixed>>
     */
    protected function ratesByType(): array
    {
        $manager = $this->queueManager();
        $waiting = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#queueitems')->whereIn('status', ['pending', 'processing'])->groupBy('type')->pluck('type');
        $types   = array_unique(array_merge($waiting, $manager->activeTaskTypes(static::RATE_WINDOW)));
        sort($types);

        $rows = [];
        foreach ($types as $type) {
            $rate      = $manager->throughput(static::RATE_WINDOW, (string) $type);
            $minutes   = $rate['window'] / 60;
            $perMinute = $rate['completions'] / $minutes;
            $health    = match (true) {
                $rate['pending'] > 0 && (int) $rate['clears_in'] > 0 => 'draining',
                $rate['net'] >= 0 && $rate['pending'] <= $perMinute  => 'keeping_up',
                default                                              => 'behind',
            };
            $rows[] = [
                'type'           => (string) $type,
                'running'        => $rate['processing'],
                'pending'        => $rate['pending'],
                'per_minute'     => round($perMinute, 1),
                'net_per_minute' => round($rate['net'] / $minutes, 1),
                'clears_in'      => $health === 'draining' ? $this->duration((int) $rate['clears_in']) : null,
                'health'         => $health,
            ];
        }

        return $rows;
    }

    /** Seconds as a short duration: `45s`, `12m`, `3h 10m`, `2d 4h`. */
    protected function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60    => $seconds . 's',
            $seconds < 3600  => intdiv($seconds, 60) . 'm',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm',
            default          => intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h',
        };
    }

    /**
     * A task's fields as the detail page lists them, `[label => text]`, each only where the
     * table has the column and the task a value — `batchid`, `availableat`, `cpu_time` came with
     * later migrations — and the application's own columns after them.
     *
     * @param array<string, mixed> $task
     * @return array<string, string>
     */
    protected function taskFields(array $task): array
    {
        $seconds = static fn ($value): ?string => $value === null || $value === '' ? null : round((float) $value, 3) . ' s';
        $fields  = [
            t('Type')           => $task['type'] ?? null,
            t('Priority')       => $task['priority'] ?? null,
            t('Attempts')       => (int) ($task['attempts'] ?? 0) . ' / ' . (int) ($task['maxattempts'] ?? 0),
            t('Created at')     => $task['createdat'] ?? null,
            t('Updated at')     => $task['updatedat'] ?? null,
            t('Available from') => $task['availableat'] ?? null,
            t('Started at')     => $task['startedat'] ?? null,
            t('Completed at')   => $task['completedat'] ?? null,
            t('Execution time') => $seconds($task['execution_time'] ?? null),
            t('CPU time')       => $seconds($task['cpu_time'] ?? null),
            t('Locked by')      => $task['lockedby'] ?? null,
            t('Lock expires')   => $task['lockexpires'] ?? null,
            t('Batch')          => trim(($task['batchname'] ?? '') . ' ' . ($task['batchid'] ?? '')),
            t('Task hash')      => $task['task_hash'] ?? null,
            t('Folded by key')  => $task['dedupekey'] ?? null,
            t('Events folded in') => (int) ($task['absorbed'] ?? 0) > 0 ? (string) (int) $task['absorbed'] : null,
            t('Error')          => $task['error'] ?? null,
            t('Result')         => $task['success_message'] ?? null,
        ];
        foreach ($this->extraColumns() as $label => $column) {
            $fields[(string) $label] = $task[$column] ?? null;
        }
        $fields += $this->eventFields($task);

        return array_map('strval', array_filter($fields, static fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * The payload as the detail page shows it: key by key, each value as readable JSON — a value
     * that is itself JSON text decoded first — or the text as it is when it is not JSON.
     *
     * @return array<string, string> `[key => text]`; one entry under `''` for a payload that is not an object
     */
    protected function payloadEntries(string $payload): array
    {
        $json    = static fn ($value): string => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return ['' => $payload];
        }
        $entries = [];
        foreach ($decoded as $key => $value) {
            $inner = is_string($value) ? json_decode($value, true) : null;
            $entries[(string) $key] = is_array($inner) ? $json($inner) : (is_string($value) ? $value : $json($value));
        }

        return $entries;
    }

    /**
     * For a queued listener's task, the event that brought it: what, when, who, from where, and
     * the event that caused that one — "why did this run".
     *
     * @param array<string, mixed> $task
     * @return array<string, string|null>
     */
    protected function eventFields(array $task): array
    {
        if (($task['type'] ?? '') !== \Pramnos\Event\EventListenerTask::TYPE) {
            return [];
        }
        $job  = (array) json_decode((string) ($task['payload'] ?? ''), true);
        $meta = (array) ($job['meta'] ?? []);
        $at   = (int) ($meta['firedAt'] ?? 0);

        return [
            t('Event')       => isset($job['event']) ? $job['event'] . (isset($meta['eventId']) ? ' (' . $meta['eventId'] . ')' : '') : null,
            t('Listener')    => $job['listener'] ?? null,
            t('Fired at')    => $at > 0 ? date('Y-m-d H:i:s', $at) : null,
            t('Fired by')    => array_key_exists('userid', $meta) ? ($meta['userid'] === null ? t('The system') : '#' . (int) $meta['userid']) : null,
            t('Source')      => isset($meta['source']) ? $meta['source'] . (!empty($meta['ip']) ? ' · ' . $meta['ip'] : '') : null,
            t('Caused by')   => $meta['causedBy'] ?? null,
            t('First event') => isset($meta['rootId']) && $meta['rootId'] !== ($meta['eventId'] ?? null) ? $meta['rootId'] . ' · ' . sprintf(t('%d deep'), (int) ($meta['depth'] ?? 0)) : null,
        ];
    }

    /** On the list, a queued listener's task says which event it is for. */
    protected function eventOf(string $type, string $payload): string
    {
        if ($type !== \Pramnos\Event\EventListenerTask::TYPE) {
            return '';
        }
        $event = (string) (((array) json_decode($payload, true))['event'] ?? '');

        return $event === '' ? '' : ' <span class="pf-muted">· ' . htmlspecialchars($event, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /** A status as a badge. */
    protected function statusBadge(string $status): string
    {
        $class = match ($status) {
            'completed', 'processing' => 'pf-state pf-state-on',
            'failed', 'stopped', 'deleted' => 'pf-state pf-state-off',
            default => 'pf-state',
        };

        return '<span class="' . $class . '">' . htmlspecialchars($this->statusLabels()[$status] ?? ucfirst($status), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /** What can be done with a task in this status, as POST buttons carrying the token. */
    protected function actions(int $id, string $status): string
    {
        $url  = static fn (string $action): string => adminUrl('queue/' . $action . '/') . $id;
        $html = '';
        if ($status === 'failed') {
            $html .= \Pramnos\Html\Icon::postButton($url('retry'), 'retry', t('Run this task again'));
        }
        if ($status === 'pending' || $status === 'processing') {
            $html .= \Pramnos\Html\Icon::postButton($url('stop'), 'deactivate', t('Stop this task'), ['data-confirm' => t('Stop this task? A worker running it finishes the run in hand.')]);
        }
        $html .= \Pramnos\Html\Icon::postButton($url('duplicate'), 'duplicate', t('Queue a copy of this task'), ['data-confirm' => t('Queue a copy of this task?')]);
        if ($status !== 'deleted') {
            $html .= \Pramnos\Html\Icon::postButton($url('delete'), 'delete', t('Delete this task'), ['data-confirm' => t('Delete this task?')]);
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
            $this->addError(t('The id in that link is not valid.'));
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

        $this->addMessage(t('Stopped.'));
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
            $this->addError(t('There is no such task.'));
            $this->redirect(sURL . 'queue');
            return;
        }

        $db->queryBuilder()->table('#PREFIX#queueitems')->insert(
            ['status' => 'pending', 'attempts' => 0] + array_intersect_key((array) $task->fields, array_flip($copy))
        );

        $this->addMessage(t('A copy has been queued.'));
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
            $this->addError(t('The id in that link is not valid.'));
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

        $this->addMessage(t('Queued again.'));
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

        $this->addMessage(t('Every failed job has been queued again.'));
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
            $this->addError(t('The id in that link is not valid.'));
            $this->redirect(sURL . 'queue');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('#PREFIX#queueitems')
            ->where('taskid', $taskId)
            ->update(['status' => 'deleted']);

        $this->addMessage(t('Deleted.'));
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
            $this->addError(t('That is not a status this queue uses.'));
            $this->redirect(sURL . 'queue');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('#PREFIX#queueitems')
            ->where('status', $targetStatus)
            ->update(['status' => 'deleted']);

        $this->addMessage(t('Cleared.'));
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
