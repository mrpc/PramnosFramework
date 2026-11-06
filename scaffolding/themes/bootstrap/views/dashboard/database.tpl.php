@php /**
 * Database details page (Bootstrap theme).
 *
 * Variables:
 *   $this->stats       — array from DatabaseStatsService::getStats()
 *   $this->processes   — array of active process rows (PostgreSQL: includes datname, client_addr, backend_start)
 *   $this->tableSizes  — array of table size rows
 *   $this->tsData      — array: hypertables, aggregates, jobs, jobHistory, chunkCount, ts_version
 *   $this->replication — array of pg_stat_replication rows (PostgreSQL only)
 */
$stats       = $this->stats       ?? [];
$processes   = $this->processes   ?? [];
$tableSizes  = $this->tableSizes  ?? [];
$tsData      = $this->tsData      ?? ['hypertables' => [], 'aggregates' => [], 'jobs' => [], 'jobHistory' => [], 'chunkCount' => 0, 'ts_version' => null];
$replication = $this->replication ?? [];
$dbType      = $stats['type'] ?? 'mysql';

$fmtBytes = function (int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2)       . ' KB';
    return $bytes . ' B';
}; @endphp
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{!! adminUrl('dashboard') !!}" class="btn btn-sm btn-outline-secondary">&larr; Dashboard</a>
        <h2 class="mb-0">Database Details</h2>
        {{-- Only for an account the route will actually serve — see the controller. --}}
        @if (!empty($this->adminerUrl))
            <a href="{{ $this->adminerUrl }}"
               class="btn btn-sm btn-outline-secondary ms-auto">Open in Adminer</a>
        @endif
        @if (!empty($stats['version']))
            <span class="badge bg-secondary">{{ $stats['version'] }}</span>
        @endif
        @if (!empty($tsData['ts_version']))
            <span class="badge bg-info text-dark">TimescaleDB {{ $tsData['ts_version'] }}</span>
        @endif
    </div>

    <!-- Overview cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-primary mb-1">
                        {!! $stats['db_size_bytes'] !== null ? $fmtBytes((int) $stats['db_size_bytes']) : '—' !!}
                    </div>
                    <div class="text-muted small">Database Size</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-success mb-1">{!! $stats['connections_total'] ?? '—' !!}</div>
                    <div class="text-muted small">Connections Total</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-warning mb-1">{!! $stats['connections_active'] ?? '—' !!}</div>
                    <div class="text-muted small">Active Connections</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    @php $chr = $stats['cache_hit_ratio'] ?? null; @endphp
                    <div class="h4 fw-bold {!! $chr !== null && (float)$chr >= 95 ? 'text-success' : ($chr !== null && (float)$chr >= 80 ? 'text-warning' : 'text-danger') !!} mb-1">
                        {!! $chr !== null ? $chr . '%' : '—' !!}
                    </div>
                    <div class="text-muted small">Cache Hit Ratio</div>
                </div>
            </div>
        </div>
        @if ($dbType === 'postgresql' && isset($stats['xact_commit']))
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-info mb-1">{!! number_format((int) $stats['xact_commit']) !!}</div>
                    <div class="text-muted small">Transactions Committed</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-danger mb-1">{!! number_format((int) $stats['xact_rollback']) !!}</div>
                    <div class="text-muted small">Transactions Rolled Back</div>
                </div>
            </div>
        </div>
        @endif
        @if ($dbType === 'mysql' && isset($stats['queries']))
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-info mb-1">{!! number_format((int) $stats['queries']) !!}</div>
                    <div class="text-muted small">Total Queries</div>
                </div>
            </div>
        </div>
        @endif
    </div>

    <!-- Active Processes -->
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            Active Processes
            <span class="badge bg-secondary">{!! count($processes) !!}</span>
        </div>
        <div class="card-body p-0">
            @if (empty($processes))
                <p class="text-muted text-center py-4 mb-0">No active processes.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        @if ($dbType === 'postgresql')
                        <tr>
                            <th>PID</th><th>User</th><th>Database</th><th>Application</th>
                            <th>IP</th><th>Backend Start</th><th>Running</th><th>State</th><th>Query</th>
                        </tr>
                        @else
                        <tr><th>ID</th><th>User</th><th>DB</th><th>Command</th><th>Time</th><th>State</th><th>Info</th></tr>
                        @endif
                    </thead>
                    <tbody>
                    @foreach ($processes as $p)
                        @if ($dbType === 'postgresql')
                        @php $durSec = (int) ($p['duration_sec'] ?? 0); @endphp
                        <tr>
                            <td><code>{!! (int) ($p['pid'] ?? 0) !!}</code></td>
                            <td>{{ $p['usename'] ?? '—' }}</td>
                            <td class="text-muted">{{ $p['datname'] ?? '—' }}</td>
                            <td class="text-muted">{{ $p['application_name'] ?? '—' }}</td>
                            <td class="text-muted font-monospace small">{{ $p['client_addr'] ?? '—' }}</td>
                            <td class="text-muted small">{{ $p['backend_start'] ?? '—' }}</td>
                            <td>
                                @php if ($durSec > 0):
                                    $cls = $durSec > 300 ? 'danger' : ($durSec > 60 ? 'warning' : 'info');
                                    $min = intdiv($durSec, 60);
                                    $sec = $durSec % 60;
                                    $txt = $min > 0 ? "{$min}m {$sec}s" : "{$durSec}s"; @endphp
                                    <span class="badge bg-{!! $cls !!}">{!! $txt !!}</span>
                                @else—@endif
                            </td>
                            <td>
                                <span class="badge bg-{!! ($p['state'] ?? '') === 'active' ? 'success' : 'secondary' !!}">
                                    {{ $p['state'] ?? '—' }}
                                </span>
                            </td>
                            <td class="font-monospace" style="max-width:300px">
                                @if (!empty($p['query']))
                                <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $p['query'] }}">
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 me-1"
                                            data-copy-query="{{ $p['query'] }}">Copy</button>
                                    <span class="small">{{ $p['query'] }}</span>
                                </div>
                                @else<em class="text-muted small">—</em>@endif
                            </td>
                        </tr>
                        @else
                        <tr>
                            <td><code>{!! (int) ($p['Id'] ?? 0) !!}</code></td>
                            <td>{{ $p['User'] ?? '—' }}</td>
                            <td class="text-muted">{{ $p['db'] ?? '—' }}</td>
                            <td>{{ $p['Command'] ?? '—' }}</td>
                            <td>{!! (int) ($p['Time'] ?? 0) !!}s</td>
                            <td class="text-muted">{{ $p['State'] ?? '—' }}</td>
                            <td class="text-muted font-monospace small" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                {{ $p['Info'] ?? '' }}
                            </td>
                        </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

    <!-- Replication Status (PostgreSQL only) -->
    @if ($dbType === 'postgresql')
    <div class="card mb-4">
        <div class="card-header fw-semibold">Replication Status</div>
        <div class="card-body p-0">
            @if (empty($replication))
                <p class="text-muted text-center py-4 mb-0">No replication configured. Standalone instance or replica.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Client Address</th><th>State</th><th>Sync State</th><th>Replication Lag</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($replication as $repl)
                        @php $lagSec = (int) ($repl['lag_sec'] ?? 0); @endphp
                        <tr>
                            <td class="font-monospace">{{ $repl['client_addr'] ?? '—' }}</td>
                            <td>
                                <span class="badge bg-{!! ($repl['state'] ?? '') === 'streaming' ? 'success' : 'warning' !!}">
                                    {{ $repl['state'] ?? '—' }}
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-{!! ($repl['sync_state'] ?? '') === 'sync' ? 'primary' : 'secondary' !!}">
                                    {{ $repl['sync_state'] ?? '—' }}
                                </span>
                            </td>
                            <td>
                                @if (($repl['sync_state'] ?? '') === 'sync')
                                    <span class="badge bg-success">In Sync</span>
                                @php elseif ($lagSec > 0):
                                    $lagCls = $lagSec > 300 ? 'danger' : ($lagSec > 60 ? 'warning' : 'success');
                                    $lagTxt = $lagSec > 60 ? intdiv($lagSec, 60) . 'm ' . ($lagSec % 60) . 's' : $lagSec . 's'; @endphp
                                    <span class="badge bg-{!! $lagCls !!}">{!! $lagTxt !!}</span>
                                @else
                                    <span class="badge bg-secondary">N/A</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
    @endif

    <!-- Table Sizes -->
    @if (!empty($tableSizes))
    <div class="card mb-4">
        <div class="card-header fw-semibold">Table Sizes (top 30)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Table</th>
                            <th class="text-end">Rows (est.)</th>
                            <th class="text-end">Data</th>
                            <th class="text-end">Indexes</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($tableSizes as $t)
                        <tr>
                            <td class="font-monospace">
                                @if (!empty($t['schemaname']) && $t['schemaname'] !== 'public')
                                    <span class="text-muted">{{ $t['schemaname'] }}.</span>
                                @endif
                                {{ $t['table_name'] ?? '' }}
                            </td>
                            <td class="text-end text-muted">{!! $t['row_estimate'] !== null ? number_format((int) $t['row_estimate']) : '—' !!}</td>
                            <td class="text-end">{!! $t['data_bytes'] !== null ? $fmtBytes((int) $t['data_bytes']) : '—' !!}</td>
                            <td class="text-end text-muted">{!! $t['index_bytes'] !== null ? $fmtBytes((int) $t['index_bytes']) : '—' !!}</td>
                            <td class="text-end fw-semibold">{!! $t['total_bytes'] !== null ? $fmtBytes((int) $t['total_bytes']) : '—' !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif


    <!-- TimescaleDB section (PostgreSQL only) -->
    @if (!empty($tsData['ts_version']))

    <!-- Storage Information -->
    <div class="card mb-4">
        <div class="card-header fw-semibold">Storage Information</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="border rounded p-3 text-center">
                        <div class="h4 fw-bold text-info mb-1">{!! (int) ($tsData['chunkCount'] ?? 0) !!}</div>
                        <div class="text-muted small">Total Chunks</div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="border rounded p-3 text-center">
                        @php $compressedCount = 0;
                        foreach ($tsData['hypertables'] as $ht) {
                            if (!empty($ht['compression_enabled'])) {
                                $compressedCount++;
                            }
                        } @endphp
                        <div class="h4 fw-bold text-success mb-1">{!! $compressedCount !!}</div>
                        <div class="text-muted small">Compressed Hypertables</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if (!empty($tsData['hypertables']))
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            Hypertables
            <span class="badge bg-info text-dark">{!! count($tsData['hypertables']) !!}</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light">
                    <tr><th>Name</th><th class="text-end">Chunks</th><th class="text-end">Dimensions</th><th>Compression</th><th>Tablespaces</th></tr>
                </thead>
                <tbody>
                @foreach ($tsData['hypertables'] as $ht)
                    <tr>
                        <td class="font-monospace">{{ $ht['hypertable_name'] ?? '' }}</td>
                        <td class="text-end">{!! (int) ($ht['num_chunks'] ?? 0) !!}</td>
                        <td class="text-end">{!! (int) ($ht['num_dimensions'] ?? 0) !!}</td>
                        <td>
                            @php $comp = $ht['compression_enabled'] ?? false; @endphp
                            <span class="badge bg-{!! $comp ? 'success' : 'secondary' !!}">{!! $comp ? 'On' : 'Off' !!}</span>
                        </td>
                        <td class="text-muted small">{{ $ht['tablespaces'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if (!empty($tsData['aggregates']))
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            Continuous Aggregates
            <span class="badge bg-info text-dark">{!! count($tsData['aggregates']) !!}</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Schema</th><th>View</th><th>Mat. Schema</th><th>Mat. Table</th>
                        <th>Materialized Only</th><th>Compression</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($tsData['aggregates'] as $ca)
                    <tr>
                        <td class="text-muted">{{ $ca['view_schema'] ?? '—' }}</td>
                        <td class="font-monospace">{{ $ca['view_name'] ?? '' }}</td>
                        <td class="text-muted small">{{ $ca['materialization_schema'] ?? '—' }}</td>
                        <td class="text-muted font-monospace small">{{ $ca['materialization_name'] ?? '—' }}</td>
                        <td>
                            @php $mo = $ca['materialized_only'] ?? 'No'; @endphp
                            <span class="badge bg-{!! $mo === 'Yes' ? 'info' : 'secondary' !!} text-{!! $mo === 'Yes' ? 'dark' : 'white' !!}">
                                {{ $mo }}
                            </span>
                        </td>
                        <td>
                            @php $comp = $ca['compression_enabled'] ?? false; @endphp
                            <span class="badge bg-{!! $comp ? 'success' : 'secondary' !!}">{!! $comp ? 'On' : 'Off' !!}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if (!empty($tsData['jobs']))
    <div class="card mb-4">
        <div class="card-header fw-semibold">Scheduled Jobs</div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light">
                    <tr><th>ID</th><th>Procedure</th><th>Interval</th><th>Last Run</th><th>Status</th><th>Next Run</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($tsData['jobs'] as $job)
                    <tr>
                        <td><code>{!! (int) ($job['job_id'] ?? 0) !!}</code></td>
                        <td class="font-monospace">
                            @if (!empty($job['proc_schema']) && $job['proc_schema'] !== '_timescaledb_internal')
                                <span class="text-muted">{{ $job['proc_schema'] }}.</span>
                            @endif
                            {{ $job['proc_name'] ?? '—' }}
                        </td>
                        <td class="text-muted">{{ $job['schedule_interval'] ?? '—' }}</td>
                        <td class="text-muted small">@php $stamp = (string) ($job['last_run_started_at'] ?? '');
                            $at = $stamp !== '' ? strtotime($stamp) : false;
                            echo $at !== false
                                ? htmlspecialchars(localDateTime($at), ENT_QUOTES, 'UTF-8')
                                : htmlspecialchars($stamp !== '' ? $stamp : '—', ENT_QUOTES, 'UTF-8'); @endphp</td>
                        <td>
                            @php $ls = $job['last_run_status'] ?? '—'; @endphp
                            <span class="badge bg-{!! $ls === 'Success' ? 'success' : ($ls === '—' ? 'secondary' : 'danger') !!}">
                                {{ $ls }}
                            </span>
                        </td>
                        <td class="text-muted small">@php $stamp = (string) ($job['next_start'] ?? '');
                            $at = $stamp !== '' ? strtotime($stamp) : false;
                            echo $at !== false
                                ? htmlspecialchars(localDateTime($at), ENT_QUOTES, 'UTF-8')
                                : htmlspecialchars($stamp !== '' ? $stamp : '—', ENT_QUOTES, 'UTF-8'); @endphp</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger py-0"
                                    data-job-history-id="{!! (int) ($job['job_id'] ?? 0) !!}" style="white-space:nowrap">Errors</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Job History Modal -->
    <div class="modal fade" id="jobHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Job History — <span id="jobHistoryModalJobId"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 small">
                            <thead class="table-light">
                                <tr><th>Start</th><th>Finish</th><th>Status</th><th>Procedure</th><th>Error</th></tr>
                            </thead>
                            <tbody id="jobHistoryModalBody"></tbody>
                        </table>
                    </div>
                    <div id="jobHistoryModalEmpty" class="text-muted text-center py-4" style="display:none">
                        No history found for this job.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    var _jobHistory = {!! json_encode(array_values($tsData['jobHistory'] ?? [])) !!};
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-job-history-id]');
        if (!btn) return;
        var jobId = parseInt(btn.getAttribute('data-job-history-id'), 10);
        document.getElementById('jobHistoryModalJobId').textContent = '#' + jobId;
        var records = _jobHistory.filter(function(r) { return parseInt(r.job_id, 10) === jobId; });
        var tbody = document.getElementById('jobHistoryModalBody');
        var empty = document.getElementById('jobHistoryModalEmpty');
        tbody.innerHTML = '';
        if (records.length === 0) {
            empty.style.display = '';
        } else {
            empty.style.display = 'none';
            records.forEach(function(r) {
                var ok  = r.succeeded === 't' || r.succeeded === 'true' || r.succeeded === true;
                var tr  = document.createElement('tr');
                // The microseconds and the `+00` are twenty-nine characters that wrap in a
                // modal column and say nothing a person reads.
                var shortTime = function (v) {
                    return v ? String(v).replace(/\.\d+/, '').replace(/\+00$/, '') : '—';
                };
                tr.innerHTML = '<td class="small text-nowrap">' + shortTime(r.start_time) + '</td>'
                    + '<td class="small text-nowrap">' + shortTime(r.finish_time) + '</td>'
                    + '<td><span class="badge bg-' + (ok ? 'success' : 'danger') + '">' + (ok ? 'Success' : 'Failed') + '</span></td>'
                    + '<td class="font-monospace small">' + (r.proc_schema ? r.proc_schema + '.' : '') + (r.proc_name || '—') + '</td>'
                    + '<td class="text-danger small">' + (r.err_message || '') + '</td>';
                tbody.appendChild(tr);
            });
        }
        new bootstrap.Modal(document.getElementById('jobHistoryModal')).show();
    });
    </script>
    @endif

    @php endif; // end TimescaleDB; @endphp

    <!-- Copy Query JS -->
    <script>
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-copy-query]');
        if (!btn) return;
        var query = btn.getAttribute('data-copy-query');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(query).then(function() {
                var orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function() { btn.textContent = orig; }, 1500);
            });
        }
    });
    </script>
</div>
