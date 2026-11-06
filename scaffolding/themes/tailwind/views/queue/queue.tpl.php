@php /**
 * Queue jobs list (Tailwind theme).
 *
 * Variables:
 *   $this->datatable  — \Pramnos\Html\Datatable, fed by Queue/data (server-side)
 *   $this->statuses   — [status => name], in the reader's language
 *   $this->counts     — [status => tasks], and `total`, counted on this request
 *   $this->byType     — per task type: running, pending, per_minute, net_per_minute, clears_in, health
 *   $this->rateWindow — the window those rates are measured over, in seconds
 *   $this->streams    — the event stream's consumer groups (StreamConsumer::status()), or none
 *
 * The state first — how many tasks are in each status, and per type whether it is keeping up —
 * then the tasks. Clear takes the status to clear here, since the list's filter lives in the
 * table, not in the URL.
 */
$e         = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$counts    = $this->counts ?? [];
$total     = (int) ($counts['total'] ?? 0);
$done      = (int) ($counts['completed'] ?? 0) + (int) ($counts['warning'] ?? 0);
$clearable = ['failed', 'completed', 'stopped', 'deleted']; @endphp
<div class="px-4 py-6">
    <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
        <h2>{{ t('Queue') }}</h2>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{!! adminUrl('Queue/history') !!}" data-pf-modal class="btn btn-outline btn-primary btn-xs">{{ t('Statistics — last 48 hours') }}</a>
            <form method="post" action="{!! adminUrl('Queue/retryall') !!}" style="display:inline;margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-warning btn-xs" data-confirm="{{ t('Retry all failed jobs?') }}">{{ t('Retry all failed') }}</button></form>
            <form method="post" action="{!! adminUrl('Queue/clear') !!}" class="flex items-center gap-1" style="margin:0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <select name="status" class="select select-xs" aria-label="{{ t('Status to clear') }}">
                    @foreach ($clearable as $status)<option value="{!! $status !!}">{{ $this->statuses[$status] ?? $status }}</option>@endforeach
                </select>
                <button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="{{ t('Clear every job in that status?') }}">{{ t('Clear') }}</button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-8 gap-2 mb-4" data-field="counts">
        @foreach (['pending', 'processing', 'completed', 'warning', 'failed', 'stopped', 'deleted'] as $status)
            <div class="card bg-base-100 border border-base-300 p-3">
                <div class="text-xs text-base-content/60">{{ $this->statuses[$status] ?? $status }}</div>
                <div class="text-xl font-semibold">{!! number_format((int) ($counts[$status] ?? 0)) !!}
                    @if ($status === 'completed' && $total > 0)<small class="text-xs text-base-content/60">{!! round($done / $total * 100, 1) !!}%</small>@endif
                </div>
            </div>
        @endforeach
        <div class="card bg-base-100 border border-base-300 p-3">
            <div class="text-xs text-base-content/60">{{ t('Total') }}</div>
            <div class="text-xl font-semibold">{!! number_format($total) !!}</div>
        </div>
    </div>

    @if (!empty($this->byType))
        <div class="card bg-base-100 border border-base-300 mb-4 overflow-x-auto">
            <table class="table table-sm" data-field="rates">
                <thead><tr>
                    <th>{{ t('Task type') }}</th>
                    <th class="text-right">{{ t('Running') }}</th>
                    <th class="text-right">{{ t('Pending') }}</th>
                    <th class="text-right">{{ t('Done/min') }}</th>
                    <th class="text-right">{{ t('Net/min') }}</th>
                    <th>{{ t('Clears in') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($this->byType as $row)
                        <tr class="{!! $row['health'] === 'behind' ? 'text-error' : '' !!}">
                            <td><code>{{ $row['type'] }}</code></td>
                            <td class="text-right">{!! (int) $row['running'] !!}</td>
                            <td class="text-right">{!! number_format((int) $row['pending']) !!}</td>
                            <td class="text-right">{{ $row['per_minute'] }}</td>
                            <td class="text-right">{!! ($row['net_per_minute'] > 0 ? '+' : '') . $e($row['net_per_minute']) !!}</td>
                            <td>{!! match ($row['health']) { 'draining' => $e($row['clears_in']), 'keeping_up' => '<span class="text-base-content/60">' . $e(t('keeping up')) . '</span>', default => '<strong>' . $e(t('growing — needs workers')) . '</strong>', } !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-xs text-base-content/60 px-4 pb-3">{{ sprintf(t('Over the last %d minutes. Keeping up means arrivals match completions and the backlog is under a minute of the type\'s own output. Growing is the one worth reading: work is waiting that the type is not clearing.'), intdiv((int) ($this->rateWindow ?? 600), 60)) }}</p>
        </div>
    @endif

    @if (!empty($this->streams))
        @php // The event stream: each consumer group's position, what waits for it, and what died.; @endphp
        <section class="pf-ui pf-embedded" data-field="streams">
            <div class="pf-card pf-card-flush">
                <div class="pf-card-head"><h3 class="pf-section-title">{{ t('Event streams') }}</h3>
                    @php // A lease taken in the last minute means a consumer is reading; none, and a stuck stream would look healthy.; @endphp
                    @if (max(array_column($this->streams, 'leasedUntil')) < time() - 60)
                        <span class="pf-badge pf-tone-warn" data-field="no-daemon">{{ t('No events:consume daemon is reading the stream') }}</span>
                    @endif
                </div>
                <table class="pf-table">
                    <thead><tr>
                        <th>{{ t('Consumer group') }}</th>
                        <th class="pf-num">{{ t('Waiting to be read') }}</th>
                        <th class="pf-num">{{ t('To be tried again') }}</th>
                        <th class="pf-num">{{ t('Dead') }}</th>
                        <th>{{ t('Oldest waiting') }}</th>
                        <th>{{ t('Read by') }}</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($this->streams as $stream)
                        <tr data-group="{{ $stream['group'] }}">
                            @php $short = substr((string) strrchr('\\' . $stream['group'], '\\'), 1); @endphp
                            <td><strong title="{{ $stream['group'] }}">{{ $short }}</strong>
                                <span class="pf-row-desc">{{ substr($stream['group'], 0, -strlen($short)) . ' · ' . t('position %s', number_format($stream['position'])) }}</span></td>
                            <td class="pf-num">{!! $stream['lag'] > 0 ? '<span class="pf-badge pf-tone-warn">' . $e(number_format($stream['lag']) . ($stream['lagCapped'] ? '+' : '')) . '</span>' : '<span class="pf-zero">0</span>' !!}</td>
                            <td class="pf-num">{!! $stream['pending'] > 0 ? '<span class="pf-badge pf-tone-warn">' . $e(number_format($stream['pending'])) . '</span>' : '<span class="pf-zero">0</span>' !!}</td>
                            <td class="pf-num">{!! $stream['dead'] > 0 ? '<span class="pf-badge pf-tone-bad">' . $e(number_format($stream['dead'])) . '</span>' : '<span class="pf-zero">0</span>' !!}</td>
                            <td class="pf-muted">{!! $stream['oldestPending'] === null ? '—' : $e(date('Y-m-d H:i', $stream['oldestPending'])) !!}</td>
                            <td class="pf-muted">{!! $stream['owner'] !== null && $stream['leasedUntil'] >= time() ? $e($stream['owner']) : $e(t('nobody now')) !!}</td>
                            <td class="pf-num">
                                @if ($stream['dead'] > 0)
                                    <form method="post" action="{{ adminUrl('Queue/streamretry') }}" class="pf-inline-form">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                        <input type="hidden" name="group" value="{{ $stream['group'] }}"><button type="submit" class="pf-btn pf-btn-sm">{{ t('Try the dead again') }}</button></form>
                                    <form method="post" action="{{ adminUrl('Queue/streamdrop') }}" class="pf-inline-form" data-confirm="{{ t('Delete this group\'s dead entries?') }}">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                                        <input type="hidden" name="group" value="{{ $stream['group'] }}"><button type="submit" class="pf-btn pf-btn-sm pf-btn-ghost pf-btn-danger">{{ t('Delete the dead') }}</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {!! $this->datatable->render() !!}
</div>
