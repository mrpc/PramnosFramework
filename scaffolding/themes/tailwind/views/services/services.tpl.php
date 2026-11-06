@php /**
 * Services / Workers (Tailwind / daisyUI theme) — the state of the workers, nothing to configure.
 *
 * Variables:
 *   $this->services     — enriched service entries {id, daemon, profile, workerId, pid, status, lockFile, updatedAt}
 *   $this->orchestrator — the supervisor's own state {running, pid, heartbeat_age_seconds}
 *   $this->pools        — pools as the supervisor last saw them, plus saved rows it has not seen
 *   $this->batches      — batches of the last day, counted by status
 *   $this->stopped      — services an operator stopped, by id
 *
 * In reading order: the supervisor, the services it runs, the pools sizing them, the batches.
 * A pool's limits are set on Services/pools. Every change is a POST with the session's token;
 * the numbers refresh from Services/status every ten seconds without a reload.
 */
$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$pools  = $this->pools ?? [];
$stopped = $this->stopped ?? []; @endphp
<div class="px-4 py-6" id="services-screen" data-status-url="{{ adminUrl('Services/status') }}"
     data-stale="{{ $e(t('Something new is running.')) . ' <a href="">' . $e(t('Reload')) . '</a>' }}">
    <div class="flex justify-between items-center mb-6">
        <h2 class="mb-0">{{ t('Services') }}</h2>
        <div class="flex gap-2 items-center">
            <a href="{{ adminUrl('Services/pools') }}" class="btn btn-outline btn-sm">{{ t('Configure pools') }}</a>
            <form method="post" action="{{ adminUrl('Services/restartall') }}" class="m-0">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <button type="submit" class="btn btn-outline btn-error btn-sm" data-confirm="{{ t('Restart every running service? Each finishes its current task first.') }}" title="{{ t('For a deploy the supervisor cannot see: composer update, a copied build') }}">{{ t('Restart all') }}</button>
            </form>
        </div>
    </div>
    @php /**
     * The supervisor's own state, before the list of what it supervises. Every control on
     * this page is something the orchestrator acts on: with none running, nothing happens.
     */
    $orchestrator = $this->orchestrator ?? null;
    $supervising  = is_array($orchestrator) && !empty($orchestrator['running']);
    $heartbeat    = is_array($orchestrator) ? $orchestrator['heartbeat_age_seconds'] : null; @endphp
    @if (!$supervising)
        <div role="status" class="alert alert-warning mb-4 block">
            <strong>{{ t('The orchestrator is not running.') }}</strong>
            {{ t('The controls below are read by the supervisor on its next cycle, so until it runs nothing changes. Stop still takes effect, because a daemon checks its own stop file.') }}
            <p class="mt-2">
                <a href="https://mrpc.github.io/PramnosFramework/Pramnos_Workers_And_Daemons_Guide/#creating-the-orchestrator-service" target="_blank" rel="noopener">{{ t('How to create the orchestrator service') }} &rarr;</a>
                <span class="text-base-content/60">{{ t('— a systemd unit for Ubuntu / Debian, and the Docker equivalent.') }}</span>
            </p>
        </div>
    @elseif ($heartbeat !== null && $heartbeat > 120)
        <div role="status" class="alert alert-warning mb-4 block">
            <strong>{{ sprintf(t('The orchestrator has not cycled for %ds'), (int) $heartbeat) }}</strong>
            {{ sprintf(t('(pid %d). A live process with a stale heartbeat is stuck rather than healthy.'), (int) ($orchestrator['pid'] ?? 0)) }}
        </div>
    @else
        <p class="text-sm text-base-content/60 mb-4" data-field="supervisor" data-template="{{ t('Supervisor running, pid %d, last cycle %ds ago.') }}">
            {{ $heartbeat !== null ? sprintf(t('Supervisor running, pid %d, last cycle %ds ago.'), (int) ($orchestrator['pid'] ?? 0), (int) $heartbeat) : sprintf(t('Supervisor running, pid %d.'), (int) ($orchestrator['pid'] ?? 0)) }}
        </p>
    @endif

    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-4 py-3 border-b border-base-300"><strong>{{ t('Services') }}</strong></div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>{{ t('Service') }}</th><th>{{ t('Worker ID') }}</th><th>{{ t('PID') }}</th><th>{{ t('Status') }}</th><th>{{ t('Updated') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->services ?? []) as $svc)
                    @php $id = (string) ($svc['id'] ?? ''); @endphp
                    <tr>
                        <td><strong>{{ $svc['daemon'] ?? '' }}</strong>
                            @if (!empty($svc['profile']))
                                <small class="text-base-content/60">({{ $svc['profile'] }})</small>
                            @endif
                        </td>
                        <td>{{ $svc['workerId'] ?? '' }}</td>
                        <td>{!! !empty($svc['pid']) ? (int) $svc['pid'] : '—' !!}</td>
                        <td>
                            @if (isset($stopped[$id]))
                                <span class="badge badge-neutral" title="{{ sprintf(t('Stopped by %s'), $stopped[$id]['stopped_by'] ?? t('an operator')) }}">{{ t('Stopped by operator') }}</span>
                            @elseif ($svc['status'] === 'running')
                                <span class="badge badge-success">{{ t('Running') }}</span>
                            @elseif ($svc['status'] === 'error')
                                <span class="badge badge-warning">{{ t('Stop Pending') }}</span>
                            @else
                                <span class="badge badge-neutral">{{ t('Stopped') }}</span>
                            @endif
                        </td>
                        <td class="text-sm text-base-content/60 mb-4">{{ $svc['updatedAt'] ?? '' }}</td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ adminUrl('Services/logs/') . urlencode($id) }}" class="btn btn-outline btn-xs">{{ t('Logs') }}</a>
                            @if ($svc['status'] === 'running' && !isset($stopped[$id]))
                                <form method="post" action="{{ adminUrl('Services/stop/') . urlencode($id) }}" class="inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-warning btn-xs">{{ t('Stop') }}</button></form>
                            @else
                                <form method="post" action="{{ adminUrl('Services/start/') . urlencode($id) }}" class="inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-success btn-xs">{{ t('Start') }}</button></form>
                            @endif
                            <form method="post" action="{{ adminUrl('Services/restart/') . urlencode($id) }}" class="inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-error btn-xs">{{ t('Restart') }}</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->services))
                    <tr><td colspan="6" class="text-center text-base-content/60 py-8">{{ t('No services registered.') }}</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-4 py-3 border-b border-base-300 flex justify-between items-center">
            <strong>{{ t('Worker pools') }}</strong>
            <a href="{{ adminUrl('Services/pools') }}" class="text-sm link">{{ t('Configure pools') }}</a>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>{{ t('Pool') }}</th><th>{{ t('Task types') }}</th><th class="text-right">{{ t('Workers') }}</th><th class="text-right">{{ t('Backlog') }}</th><th class="text-right">{{ t('Load') }}</th><th>{{ t('Decision') }}</th><th>{{ t('State') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($pools as $pool)
                    @php $name  = (string) $pool['name'];
                    $types = (array) ($pool['types'] ?? []); @endphp
                    <tr data-pool="{{ $name }}">
                        <td>
                            <strong>{{ $name }}</strong>
                            <span class="badge {!! ($pool['source'] ?? '') === 'code' ? 'badge-ghost' : 'badge-info' !!}">{{ ($pool['source'] ?? '') === 'code' ? t('code') : t('screen') }}</span>
                        </td>
                        <td class="text-xs">{!! $types === [] ? '<span class="text-base-content/60">' . $e(t('every type')) . '</span>' : $e(implode(', ', $types)) !!}</td>
                        <td class="text-right" data-field="size">{!! $pool['size'] === null ? '—' : (int) $pool['size'] !!}</td>
                        <td class="text-right" data-field="backlog">{!! $pool['backlog'] === null ? '—' : (int) $pool['backlog'] !!}</td>
                        <td class="text-right" data-field="load">{!! $pool['load'] === null ? '—' : $e(sprintf('%.2f', (float) $pool['load'])) !!}</td>
                        <td class="text-xs text-base-content/60" data-field="decision" style="max-width:28rem">{{ $pool['decision'] ?? '' }}</td>
                        <td>
                            @if (empty($pool['enabled']))
                                <span class="badge badge-neutral">{{ t('Stopped') }}</span>
                            @else
                                <span class="badge badge-success">{{ t('Running') }}</span>
                            @endif
                            @if (!empty($pool['waiting']))
                                <span class="badge badge-warning" data-field="waiting" title="{{ t("Saved after the supervisor's last cycle") }}">{{ t('waiting for supervisor') }}</span>
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if (empty($pool['enabled']))
                                <form method="post" action="{{ adminUrl('Services/poolstart/') . urlencode($name) }}" class="inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-success btn-xs">{{ t('Start') }}</button></form>
                            @else
                                <form method="post" action="{{ adminUrl('Services/poolstop/') . urlencode($name) }}" class="inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-outline btn-warning btn-xs">{{ t('Stop') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @if ($pools === [])
                    <tr><td colspan="8" class="text-center text-base-content/60 py-4">{{ t('No pools.') }} <a href="{{ adminUrl('Services/pools') }}" class="link">{{ t('Add one') }}</a></td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    @if (!empty($this->batches))
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-4 py-3 border-b border-base-300"><strong>{{ t('Batches') }}</strong> <small class="text-base-content/60">{{ t('— the last 24 hours') }}</small></div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>{{ t('Batch') }}</th><th>{{ t('Queued') }}</th><th class="text-right">{{ t('Pending') }}</th><th class="text-right">{{ t('Processing') }}</th><th class="text-right">{{ t('Done') }}</th><th class="text-right">{{ t('Failed') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($this->batches as $batch)
                    <tr data-batch="{{ $batch['id'] }}">
                        <td>{!! $batch['name'] === '' ? '<span class="text-base-content/60">' . $e(substr($batch['id'], 0, 8)) . '</span>' : $e($batch['name']) !!}</td>
                        <td class="text-xs text-base-content/60">{{ $batch['queued_at'] }}</td>
                        <td class="text-right" data-field="pending">{!! (int) $batch['pending'] !!}</td>
                        <td class="text-right" data-field="processing">{!! (int) $batch['processing'] !!}</td>
                        <td class="text-right" data-field="done">{!! (int) $batch['completed'] + (int) $batch['warning'] !!}</td>
                        <td class="text-end {!! $batch['failed'] > 0 ? 'text-error' : '' !!}" data-field="failed">{!! (int) $batch['failed'] !!}</td>
                        <td data-field="finished">{!! $batch['finished'] ? '<span class="badge badge-success">' . $e(t('done')) . '</span>' : '<span class="badge badge-primary">' . $e(t('running')) . '</span>' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@include('refresh')
