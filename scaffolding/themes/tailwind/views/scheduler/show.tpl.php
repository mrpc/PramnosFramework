@php /**
 * One scheduled task's runs (Tailwind theme), newest first, each with what it printed.
 *
 * Variables:
 *   $this->status — the task's row from ScheduleStatus::of()
 *   $this->runs   — this page of its runs from scheduleruns
 *   $this->page   — this page's number
 *   $this->pages  — how many pages
 *   $this->adjust — whether this account changes the task: its settings form is shown
 *   $this->full   — whether this account opens the whole screen
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$when  = static fn (?int $at): string => $at === null ? '—' : date('Y-m-d H:i:s', $at);
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
$row  = $this->status;
$page = (int) $this->page;
$here = adminUrl('Scheduler/show/') . $row['key'];
$bc   = \Pramnos\Html\Breadcrumb::admin([[$e(t('Scheduler')), adminUrl('Scheduler')], [$e($row['label']), '']]); @endphp
<div class="max-w-6xl mx-auto py-6 px-4">
    {!! $bc !!}
    <h2 class="mb-1">{{ $row['label'] }}</h2>
    <p class="text-sm text-base-content/60 mb-4">
        <span title="{{ $row['expression'] }}">{{ $row['words'] }}</span>
        · {{ t('Next run') }}: {{ $when($row['next']?->getTimestamp()) }}
        @if (!empty($row['paused'])) · <span class="badge badge-warning badge-sm">{{ sprintf(t('Paused since %s'), date('Y-m-d H:i', (int) ($row['override']['pausedat'] ?? 0))) }}</span>@elseif (!empty($row['outside'])) · <span class="badge badge-ghost badge-sm">{{ sprintf(t('Outside hours · next %s'), $row['next'] ? $row['next']->format('H:i') : '—') }}</span>@endif
        @if ($row['late']) · <span class="badge badge-error badge-sm">{{ t('Late') }}</span>@endif
    </p>
    @php if (!empty($this->adjust)):
        $ov    = $row['override'] ?? [];
        $offer = $row['adjustable'];
        $freq  = (string) ($ov['frequency'] ?? '');
        $parts = explode(':', $freq);
        $kind  = in_array($parts[0], ['daily', 'weekly'], true) ? $parts[0] : $freq;
        $at    = $parts[0] === 'daily' ? ($parts[1] ?? '') . ':' . ($parts[2] ?? '') : ($parts[0] === 'weekly' ? ($parts[2] ?? '') . ':' . ($parts[3] ?? '') : '');
        $day   = $parts[0] === 'weekly' ? (int) ($parts[1] ?? 1) : 1;
        $words = static function (string $k): string {
            [$type, $n] = array_pad(explode(':', $k), 2, '');
            return match ($type) {
                'every' => (int) $n === 1 ? t('Every minute') : sprintf(t('Every %d minutes'), (int) $n),
                'hours' => (int) $n === 1 ? t('Every hour') : sprintf(t('Every %d hours'), (int) $n),
                'daily' => t('Daily at'),
                default => t('Weekly on'),
            };
        }; @endphp
    <form method="post" action="{{ adminUrl('Scheduler/adjust/') . $row['key'] }}" class="card bg-base-100 border border-base-300 p-4 mb-4" data-field="settings">
        {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
        <div class="flex flex-wrap gap-3 items-end mb-3">
            <label><input type="checkbox" name="enabled" value="1" class="checkbox checkbox-sm"{!! empty($row['paused']) ? ' checked' : '' !!}> {{ t('Runs') }}</label>
            <label><span class="text-xs text-base-content/70 block">{{ t('Frequency') }}</span>
                <select name="frequency" class="select select-bordered select-sm">
                    <option value="">{{ sprintf(t('As in the code (%s)'), $row['task']->getCronExpression()->describe()) }}</option>
                    @foreach (\Pramnos\Scheduling\ScheduleOverrides::kinds($offer['presets']) as $option)
                        <option value="{{ $option }}"{!! $option === $kind ? ' selected' : '' !!}>{{ $words($option) }}</option>
                    @endforeach
                </select>
            </label>
            <label><span class="text-xs text-base-content/70 block">{{ t('Day') }}</span>
                <select name="weekday" class="select select-bordered select-sm">
                    @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $number => $name)
                        <option value="{!! $number !!}"{!! $number === $day ? ' selected' : '' !!}>{{ t($name) }}</option>
                    @endforeach
                </select>
            </label>
            <label><span class="text-xs text-base-content/70 block">{{ t('Time') }}</span><input type="time" name="time" value="{{ $at === ':' ? '' : $at }}" class="input input-bordered input-sm"></label>
        </div>
        <div class="flex flex-wrap gap-3 items-end mb-3">
            <label><span class="text-xs text-base-content/70 block">{{ t('Only between') }}</span><input type="time" name="hoursfrom" value="{{ $ov['hoursfrom'] ?? '' }}" class="input input-bordered input-sm"></label>
            <label><span class="text-xs text-base-content/70 block">{{ t('and') }}</span><input type="time" name="hoursto" value="{{ $ov['hoursto'] ?? '' }}" class="input input-bordered input-sm"></label>
            @if ($offer['limit'] !== null)
                <label><span class="text-xs text-base-content/70 block">{{ sprintf(t('Per run (%d–%d)'), $offer['limit'][0], $offer['limit'][1]) }}</span><input type="number" name="runlimit" min="{!! (int) $offer['limit'][0] !!}" max="{!! (int) $offer['limit'][1] !!}" value="{{ $ov['runlimit'] ?? '' }}" class="input input-bordered input-sm"></label>
            @endif
            <button type="submit" class="btn btn-primary btn-sm">{{ t('Save') }}</button>
        </div>
        @if ($ov !== [])
            <p class="text-xs text-base-content/60">{{ sprintf(t('Changed by %s on %s'), (string) ($ov['updatedby'] ?? ''), date('Y-m-d H:i', (int) ($ov['updatedat'] ?? 0))) }}</p>
        @endif
    </form>
    @if ($ov !== [])
        <form method="post" action="{{ adminUrl('Scheduler/reset/') . $row['key'] }}" class="mb-4">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-ghost btn-sm" data-confirm="{{ t('Back to the schedule in the code?') }}">{{ t('Back to the schedule in the code') }}</button></form>
    @endif
    @endif

    <div class="card bg-base-100 border border-base-300 overflow-x-auto">
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase"><tr>
                <th>{{ t('Started at') }}</th>
                <th>{{ t('Result') }}</th>
                <th>{{ t('Duration') }}</th>
                <th>{{ t('Exit code') }}</th>
                <th>{{ t('Host') }}</th>
                <th>{{ t('Output') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($this->runs as $run)
                <tr>
                    <td class="whitespace-nowrap">{{ $when((int) $run['started_at']) }}</td>
                    <td>{!! $badge((string) $run['result']) !!}@if (!empty($run['requested_by'])) <small class="text-base-content/60">{{ sprintf(t('asked by %s'), $run['requested_by']) }}</small>@endif</td>
                    <td>{{ $took($run['duration_ms']) }}</td>
                    <td>{!! $run['exit_code'] === null ? '' : (int) $run['exit_code'] !!}</td>
                    <td>{{ $run['host'] }}</td>
                    <td>@if ((string) ($run['output'] ?? '') !== '')<details><summary class="cursor-pointer text-xs">{{ t('Show') }}</summary><pre class="bg-base-200 rounded p-2 text-xs whitespace-pre-wrap break-words">{{ $run['output'] }}</pre></details>@endif</td>
                </tr>
            @endforeach
            @if (empty($this->runs))
                <tr><td colspan="6" class="text-center text-base-content/60 py-6">{{ t('No runs recorded yet.') }}</td></tr>
            @endif
            </tbody>
        </table>
    </div>
    @if ($this->pages > 1)
        <div class="join mt-3">
            @if ($page > 1)<a class="join-item btn btn-sm" href="{{ $here . '?page=' . ($page - 1) }}">&larr; {{ t('Newer') }}</a>@endif
            <span class="join-item btn btn-sm btn-disabled">{!! $page !!} / {!! (int) $this->pages !!}</span>
            @if ($page < $this->pages)<a class="join-item btn btn-sm" href="{{ $here . '?page=' . ($page + 1) }}">{{ t('Older') }} &rarr;</a>@endif
        </div>
    @endif
</div>
