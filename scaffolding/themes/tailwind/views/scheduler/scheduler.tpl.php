@php /**
 * The schedule (Tailwind theme): every task, when it runs next, how its last run went.
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
        'ok'          => ['badge-success', t('Succeeded')],
        'failed'      => ['badge-error', t('Error')],
        'running'     => ['badge-info', t('In progress')],
        'interrupted' => ['badge-warning', t('Interrupted')],
        'skipped'     => ['badge-ghost', t('Skipped')],
        default       => ['badge-ghost', $result],
    };

    return '<span class="badge badge-sm ' . $class . '">' . $e($label) . '</span>';
};
$counts = $this->counts ?? [];
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('Scheduler')), '']]); @endphp
<div class="px-4 py-6">
    {!! $bc !!}
    <h2 class="mb-4">{{ t('Scheduler') }}</h2>

    @if (empty($this->working))
        <div role="status" class="alert alert-warning mb-4 block">
            <strong>{{ t('No work process is running.') }}</strong>
            {{ t('The schedule is registered, not executing — unless cron runs schedule:run, which cannot be seen from here.') }}
        </div>
    @endif
    @if (empty($this->history))
        <div role="status" class="alert alert-info mb-4 block">{{ t('No run history yet. Run migrate to create the scheduleruns table.') }}</div>
    @endif

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4" data-field="counts">
        @foreach (['tasks' => t('Tasks'), 'running' => t('Running now'), 'late' => t('Late'), 'failed' => t('Last run failed')] as $key => $label)
            <div class="card bg-base-100 border border-base-300 p-3">
                <div class="text-xs text-base-content/60">{{ $label }}</div>
                <div class="text-xl font-semibold {!! in_array($key, ['late', 'failed'], true) && (int) ($counts[$key] ?? 0) > 0 ? 'text-error' : '' !!}">{!! (int) ($counts[$key] ?? 0) !!}</div>
            </div>
        @endforeach
    </div>

    <div class="card bg-base-100 border border-base-300 overflow-x-auto">
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase"><tr>
                <th>{{ t('Task') }}</th>
                <th>{{ t('When') }}</th>
                <th>{{ t('Next run') }}</th>
                <th>{{ t('Last run') }}</th>
                <th>{{ t('State') }}</th>
                <th></th>
            </tr></thead>
            <tbody>
            @foreach (\Pramnos\Scheduling\ScheduleStatus::grouped($this->tasks ?? []) as $group => $rows)
            @if ($group !== '')<tr data-group="{{ $group }}"><th colspan="6" class="bg-base-200 text-xs font-semibold text-left">{{ $group }}</th></tr>@endif
            @foreach ($rows as $row)
                @php $last = $row['last']; $flags = array_keys(array_filter([
                    t('No overlap') => $row['summary']['no_overlap'] ?? false, t('One server') => $row['summary']['one_server'] ?? false,
                    t('In process') => $row['summary']['in_process'] ?? false, t('In the background') => $row['summary']['background'] ?? false,
                ])); @endphp
                <tr data-task="{{ $row['key'] }}">
                    <td>
                        <a href="{{ adminUrl('Scheduler/show/') . $row['key'] }}" data-pf-modal class="link link-hover font-medium">{{ $row['label'] }}</a>
                        @foreach ($flags as $flag)<span class="badge badge-ghost badge-xs ml-1">{{ $flag }}</span>@endforeach
                    </td>
                    <td title="{{ $row['expression'] }}">{{ $row['words'] }}</td>
                    <td data-field="next">{{ $when($row['next']?->getTimestamp()) }}</td>
                    <td data-field="last">@if ($last !== null){{ $when((int) $last['started_at']) }} {!! $badge((string) $last['result']) !!} <span class="text-base-content/60">{{ $took($last['duration_ms']) }}</span>@else—@endif</td>
                    <td data-field="state">
                        @if ($row['running'])<span class="badge badge-info badge-sm">{{ t('In progress') }}</span>@endif
                        @if ($row['late'])<span class="badge badge-error badge-sm" title="{{ sprintf(t('Expected at %s'), $when($row['expected']?->getTimestamp())) }}">{{ t('Late') }}</span>@endif
                        @if ($row['paused'])<span class="badge badge-warning badge-sm">{{ sprintf(t('Paused since %s'), $when(((int) ($row['override']['pausedat'] ?? 0)) ?: null)) }}</span>@elseif ($row['outside'])<span class="badge badge-ghost badge-sm">{{ sprintf(t('Outside hours · next %s'), $row['next'] ? $row['next']->format('H:i') : '—') }}</span>@endif
                        @if ($row['requested'])<span class="badge badge-ghost badge-sm">{{ t('Requested') }}</span>@endif
                    </td>
                    <td class="text-right whitespace-nowrap">
                        @if ($this->full ?? true)<form method="post" action="{{ adminUrl('Scheduler/runnow/') . $row['key'] }}" class="inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-primary btn-xs" data-confirm="{{ sprintf(t('Run %s now?'), $row['label']) }}"{!! $row['requested'] ? ' disabled' : '' !!}>{{ t('Run now') }}</button></form>@endif
                    </td>
                </tr>
            @endforeach
            @endforeach
            @if (empty($this->tasks))
                <tr><td colspan="6" class="text-center text-base-content/60 py-6">{{ t('No tasks are scheduled.') }}</td></tr>
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
