@php /**
 * The schedule (Bootstrap theme): every task, when it runs next, how its last run went.
 *
 * Variables:
 *   $this->tasks   — ScheduleStatus::of(): per task its key, label, expression and words, next
 *                    run, last run row, and whether it is late, failed, running or requested
 *   $this->counts  — late, failed, running, tasks
 *   $this->working — whether a `work` process holds its lock
 *   $this->history — whether the run history exists (the migration has run)
 *   $this->full    — whether this account opens the whole screen; false shows the adjustable
 *                    tasks alone, with no "run now"
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$when  = static fn (?int $at): string => $at === null ? '—' : date('Y-m-d H:i', $at);
$took  = static fn ($ms): string => $ms === null ? '' : ((int) $ms < 1000 ? (int) $ms . ' ms' : ((int) $ms < 60000 ? round((int) $ms / 1000, 1) . ' s' : intdiv((int) $ms, 60000) . ' min ' . intdiv((int) $ms % 60000, 1000) . ' s'));
$badge = static function (string $result) use ($e): string {
    [$class, $label] = match ($result) {
        'ok'          => ['bg-success', t('Succeeded')],
        'failed'      => ['bg-danger', t('Error')],
        'running'     => ['bg-info text-dark', t('In progress')],
        'interrupted' => ['bg-warning text-dark', t('Interrupted')],
        'skipped'     => ['bg-secondary', t('Skipped')],
        default       => ['bg-secondary', $result],
    };

    return '<span class="badge ' . $class . '">' . $e($label) . '</span>';
};
$counts = $this->counts ?? [];
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('Scheduler')), '']]); @endphp
<div class="container-fluid py-4">
    {!! $bc !!}
    <h2 class="mb-3">{{ t('Scheduler') }}</h2>

    @if (empty($this->working))
        <div role="status" class="alert alert-warning">
            <strong>{{ t('No work process is running.') }}</strong>
            {{ t('The schedule is registered, not executing — unless cron runs schedule:run, which cannot be seen from here.') }}
        </div>
    @endif
    @if (empty($this->history))
        <div role="status" class="alert alert-info">{{ t('No run history yet. Run migrate to create the scheduleruns table.') }}</div>
    @endif

    <div class="row g-2 mb-3" data-field="counts">
        @foreach (['tasks' => t('Tasks'), 'running' => t('Running now'), 'late' => t('Late'), 'failed' => t('Last run failed')] as $key => $label)
            <div class="col-6 col-sm-3"><div class="card p-2">
                <div class="small text-muted">{{ $label }}</div>
                <div class="fs-5 fw-semibold {!! in_array($key, ['late', 'failed'], true) && (int) ($counts[$key] ?? 0) > 0 ? 'text-danger' : '' !!}">{!! (int) ($counts[$key] ?? 0) !!}</div>
            </div></div>
        @endforeach
    </div>

    <div class="card table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th>{{ t('Task') }}</th>
                <th>{{ t('When') }}</th>
                <th>{{ t('Next run') }}</th>
                <th>{{ t('Last run') }}</th>
                <th>{{ t('State') }}</th>
                <th></th>
            </tr></thead>
            <tbody>
            @foreach (\Pramnos\Scheduling\ScheduleStatus::grouped($this->tasks ?? []) as $group => $rows)
            @if ($group !== '')<tr data-group="{{ $group }}"><th colspan="6" class="table-light small fw-semibold">{{ $group }}</th></tr>@endif
            @foreach ($rows as $row)
                @php $last = $row['last']; $flags = array_keys(array_filter([
                    t('No overlap') => $row['summary']['no_overlap'] ?? false, t('One server') => $row['summary']['one_server'] ?? false,
                    t('In process') => $row['summary']['in_process'] ?? false, t('In the background') => $row['summary']['background'] ?? false,
                ])); @endphp
                <tr data-task="{{ $row['key'] }}">
                    <td>
                        <a href="{{ adminUrl('Scheduler/show/') . $row['key'] }}" data-pf-modal class="fw-medium">{{ $row['label'] }}</a>
                        @foreach ($flags as $flag)<span class="badge bg-light text-dark border ms-1">{{ $flag }}</span>@endforeach
                    </td>
                    <td title="{{ $row['expression'] }}">{{ $row['words'] }}</td>
                    <td data-field="next">{{ $when($row['next']?->getTimestamp()) }}</td>
                    <td data-field="last">@if ($last !== null){{ $when((int) $last['started_at']) }} {!! $badge((string) $last['result']) !!} <span class="text-muted">{{ $took($last['duration_ms']) }}</span>@else—@endif</td>
                    <td data-field="state">
                        @if ($row['running'])<span class="badge bg-info text-dark">{{ t('In progress') }}</span>@endif
                        @if ($row['late'])<span class="badge bg-danger" title="{{ sprintf(t('Expected at %s'), $when($row['expected']?->getTimestamp())) }}">{{ t('Late') }}</span>@endif
                        @if ($row['paused'])<span class="badge bg-warning text-dark">{{ sprintf(t('Paused since %s'), $when(((int) ($row['override']['pausedat'] ?? 0)) ?: null)) }}</span>@elseif ($row['outside'])<span class="badge bg-secondary">{{ sprintf(t('Outside hours · next %s'), $row['next'] ? $row['next']->format('H:i') : '—') }}</span>@endif
                        @if ($row['requested'])<span class="badge bg-secondary">{{ t('Requested') }}</span>@endif
                    </td>
                    <td class="text-end text-nowrap">
                        @if ($this->full ?? true)<form method="post" action="{{ adminUrl('Scheduler/runnow/') . $row['key'] }}" class="d-inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-primary" data-confirm="{{ sprintf(t('Run %s now?'), $row['label']) }}"{!! $row['requested'] ? ' disabled' : '' !!}>{{ t('Run now') }}</button></form>@endif
                    </td>
                </tr>
            @endforeach
            @endforeach
            @if (empty($this->tasks))
                <tr><td colspan="6" class="text-center text-muted py-4">{{ t('No tasks are scheduled.') }}</td></tr>
            @endif
            </tbody>
        </table>
    </div>
    @php /*
     * The page's one tool for an AI agent in the browser (WebMCP, pf-utils.js): run a task by
     * picking it, rather than a tool per row. Not drawn; the buttons above are for people.
     */
    if (!empty($this->tasks) && !empty($this->history) && ($this->full ?? true)): @endphp
    <form method="post" action="{{ adminUrl('Scheduler/runnow') }}" hidden data-pf-tool="run_scheduled_task" data-pf-tool-description="Run one of the scheduled tasks once, now. Nothing runs in this request: the scheduler's next pass takes it, within a minute. The task changes whatever it changes when it runs on its own.">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        <select name="task" required data-pf-tool-param="The task to run">
            @foreach ($this->tasks as $row)
                <option value="{{ $row['key'] }}">{{ $row['label'] }}</option>
            @endforeach
        </select>
    </form>
    @endif
</div>
