@php /**
 * Services / Workers (plain-CSS theme) — the state of the workers, nothing to configure.
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
<div class="page-section" id="services-screen" data-status-url="{{ adminUrl('Services/status') }}"
     data-stale="{{ $e(t('Something new is running.')) . ' <a href="">' . $e(t('Reload')) . '</a>' }}">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">{{ t('Services') }}</h2>
        <div class="d-flex gap-2 align-items-center">
            <a href="{{ adminUrl('Services/pools') }}" class="btn btn-outline-secondary btn-sm">{{ t('Configure pools') }}</a>
            <form method="post" action="{{ adminUrl('Services/restartall') }}" class="m-0">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="{{ t('Restart every running service? Each finishes its current task first.') }}" title="{{ t('For a deploy the supervisor cannot see: composer update, a copied build') }}">{{ t('Restart all') }}</button>
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
        <div role="status" class="alert alert-warning">
            <strong>{{ t('The orchestrator is not running.') }}</strong>
            {{ t('The controls below are read by the supervisor on its next cycle, so until it runs nothing changes. Stop still takes effect, because a daemon checks its own stop file.') }}
            <p class="mb-0 mt-2">
                <a href="https://mrpc.github.io/PramnosFramework/Pramnos_Workers_And_Daemons_Guide/#creating-the-orchestrator-service" target="_blank" rel="noopener">{{ t('How to create the orchestrator service') }} &rarr;</a>
                <span class="text-muted">{{ t('— a systemd unit for Ubuntu / Debian, and the Docker equivalent.') }}</span>
            </p>
        </div>
    @elseif ($heartbeat !== null && $heartbeat > 120)
        <div role="status" class="alert alert-warning">
            <strong>{{ sprintf(t('The orchestrator has not cycled for %ds'), (int) $heartbeat) }}</strong>
            {{ sprintf(t('(pid %d). A live process with a stale heartbeat is stuck rather than healthy.'), (int) ($orchestrator['pid'] ?? 0)) }}
        </div>
    @else
        <p class="text-muted small" data-field="supervisor" data-template="{{ t('Supervisor running, pid %d, last cycle %ds ago.') }}">
            {{ $heartbeat !== null ? sprintf(t('Supervisor running, pid %d, last cycle %ds ago.'), (int) ($orchestrator['pid'] ?? 0), (int) $heartbeat) : sprintf(t('Supervisor running, pid %d.'), (int) ($orchestrator['pid'] ?? 0)) }}
        </p>
    @endif

    <div class="card mb-4">
        <div class="card-header"><strong>{{ t('Services') }}</strong></div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>{{ t('Service') }}</th><th>{{ t('Worker ID') }}</th><th>{{ t('PID') }}</th><th>{{ t('Status') }}</th><th>{{ t('Updated') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach (($this->services ?? []) as $svc)
                    @php $id = (string) ($svc['id'] ?? ''); @endphp
                    <tr>
                        <td><strong>{{ $svc['daemon'] ?? '' }}</strong>
                            @if (!empty($svc['profile']))
                                <small class="text-muted">({{ $svc['profile'] }})</small>
                            @endif
                        </td>
                        <td>{{ $svc['workerId'] ?? '' }}</td>
                        <td>{!! !empty($svc['pid']) ? (int) $svc['pid'] : '—' !!}</td>
                        <td>
                            @if (isset($stopped[$id]))
                                <span class="badge bg-secondary" title="{{ sprintf(t('Stopped by %s'), $stopped[$id]['stopped_by'] ?? t('an operator')) }}">{{ t('Stopped by operator') }}</span>
                            @elseif ($svc['status'] === 'running')
                                <span class="badge bg-success">{{ t('Running') }}</span>
                            @elseif ($svc['status'] === 'error')
                                <span class="badge bg-warning text-dark">{{ t('Stop Pending') }}</span>
                            @else
                                <span class="badge bg-secondary">{{ t('Stopped') }}</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $svc['updatedAt'] ?? '' }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ adminUrl('Services/logs/') . urlencode($id) }}" class="btn btn-sm btn-outline-secondary">{{ t('Logs') }}</a>
                            @if ($svc['status'] === 'running' && !isset($stopped[$id]))
                                <form method="post" action="{{ adminUrl('Services/stop/') . urlencode($id) }}" class="d-inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-warning">{{ t('Stop') }}</button></form>
                            @else
                                <form method="post" action="{{ adminUrl('Services/start/') . urlencode($id) }}" class="d-inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-success">{{ t('Start') }}</button></form>
                            @endif
                            <form method="post" action="{{ adminUrl('Services/restart/') . urlencode($id) }}" class="d-inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-danger">{{ t('Restart') }}</button></form>
                        </td>
                    </tr>
                @endforeach
                @if (empty($this->services))
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ t('No services registered.') }}</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>{{ t('Worker pools') }}</strong>
            <a href="{{ adminUrl('Services/pools') }}" class="small">{{ t('Configure pools') }}</a>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>{{ t('Pool') }}</th><th>{{ t('Task types') }}</th><th class="text-end">{{ t('Workers') }}</th><th class="text-end">{{ t('Backlog') }}</th><th class="text-end">{{ t('Load') }}</th><th>{{ t('Decision') }}</th><th>{{ t('State') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($pools as $pool)
                    @php $name  = (string) $pool['name'];
                    $types = (array) ($pool['types'] ?? []); @endphp
                    <tr data-pool="{{ $name }}">
                        <td>
                            <strong>{{ $name }}</strong>
                            <span class="badge {!! ($pool['source'] ?? '') === 'code' ? 'bg-light text-dark border' : 'bg-info text-dark' !!}">{{ ($pool['source'] ?? '') === 'code' ? t('code') : t('screen') }}</span>
                        </td>
                        <td class="small">{!! $types === [] ? '<span class="text-muted">' . $e(t('every type')) . '</span>' : $e(implode(', ', $types)) !!}</td>
                        <td class="text-end" data-field="size">{!! $pool['size'] === null ? '—' : (int) $pool['size'] !!}</td>
                        <td class="text-end" data-field="backlog">{!! $pool['backlog'] === null ? '—' : (int) $pool['backlog'] !!}</td>
                        <td class="text-end" data-field="load">{!! $pool['load'] === null ? '—' : $e(sprintf('%.2f', (float) $pool['load'])) !!}</td>
                        <td class="small text-muted" data-field="decision" style="max-width:28rem">{{ $pool['decision'] ?? '' }}</td>
                        <td>
                            @if (empty($pool['enabled']))
                                <span class="badge bg-secondary">{{ t('Stopped') }}</span>
                            @else
                                <span class="badge bg-success">{{ t('Running') }}</span>
                            @endif
                            @if (!empty($pool['waiting']))
                                <span class="badge bg-warning text-dark" data-field="waiting" title="{{ t("Saved after the supervisor's last cycle") }}">{{ t('waiting for supervisor') }}</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            @if (empty($pool['enabled']))
                                <form method="post" action="{{ adminUrl('Services/poolstart/') . urlencode($name) }}" class="d-inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-success">{{ t('Start') }}</button></form>
                            @else
                                <form method="post" action="{{ adminUrl('Services/poolstop/') . urlencode($name) }}" class="d-inline m-0">{!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}<button type="submit" class="btn btn-sm btn-outline-warning">{{ t('Stop') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @if ($pools === [])
                    <tr><td colspan="8" class="text-center text-muted py-3">{{ t('No pools.') }} <a href="{{ adminUrl('Services/pools') }}">{{ t('Add one') }}</a></td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    @if (!empty($this->batches))
    <div class="card">
        <div class="card-header"><strong>{{ t('Batches') }}</strong> <small class="text-muted">{{ t('— the last 24 hours') }}</small></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>{{ t('Batch') }}</th><th>{{ t('Queued') }}</th><th class="text-end">{{ t('Pending') }}</th><th class="text-end">{{ t('Processing') }}</th><th class="text-end">{{ t('Done') }}</th><th class="text-end">{{ t('Failed') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($this->batches as $batch)
                    <tr data-batch="{{ $batch['id'] }}">
                        <td>{!! $batch['name'] === '' ? '<span class="text-muted">' . $e(substr($batch['id'], 0, 8)) . '</span>' : $e($batch['name']) !!}</td>
                        <td class="small text-muted">{{ $batch['queued_at'] }}</td>
                        <td class="text-end" data-field="pending">{!! (int) $batch['pending'] !!}</td>
                        <td class="text-end" data-field="processing">{!! (int) $batch['processing'] !!}</td>
                        <td class="text-end" data-field="done">{!! (int) $batch['completed'] + (int) $batch['warning'] !!}</td>
                        <td class="text-end {!! $batch['failed'] > 0 ? 'text-danger' : '' !!}" data-field="failed">{!! (int) $batch['failed'] !!}</td>
                        <td data-field="finished">{!! $batch['finished'] ? '<span class="badge bg-success">' . $e(t('done')) . '</span>' : '<span class="badge bg-primary">' . $e(t('running')) . '</span>' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
<style>
/* The few classes this screen uses that the plain-CSS theme does not define, kept to it. */
#services-screen .d-flex { display: flex; } #services-screen .justify-content-between { justify-content: space-between; }
#services-screen .align-items-center { align-items: center; } #services-screen .align-items-end { align-items: flex-end; }
#services-screen .row { display: flex; flex-wrap: wrap; gap: 8px; } #services-screen .col-md-1 { width: 6rem; }
#services-screen .col-md-2 { width: 10rem; } #services-screen .col-md-3 { width: 14rem; } #services-screen .col-md-12 { width: 100%; }
#services-screen .card { margin-bottom: 16px; } #services-screen .card-header, #services-screen .card-footer { padding: 10px 14px; background: #f7f7f9; }
#services-screen .card-footer { border-top: 1px solid #e5e5e5; } #services-screen .table-light { background: #f7f7f9; }
#services-screen .text-end { text-align: right; } #services-screen .text-nowrap { white-space: nowrap; } #services-screen .d-inline { display: inline; }
#services-screen .m-0 { margin: 0; } #services-screen .text-muted { color: #6c757d; } #services-screen .small { font-size: .85em; }
#services-screen .text-danger { color: #b02a37; } #services-screen .bg-info { background: #cff4fc; } #services-screen .bg-primary { background: #cfe2ff; }
#services-screen .bg-light { background: #f8f9fa; } #services-screen table { width: 100%; border-collapse: collapse; }
#services-screen td, #services-screen th { padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: middle; }
#services-screen .form-control { width: 100%; box-sizing: border-box; padding: 4px 6px; }
</style>
@include('refresh')
