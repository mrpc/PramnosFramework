@php /**
 * Database details page (plain-CSS theme).
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
};

$card = 'border:1px solid #ddd;border-radius:4px;margin-bottom:16px;overflow:hidden'; @endphp
<style>
.db-table { width:100%;border-collapse:collapse }
.db-table th,.db-table td { padding:6px 10px;border-bottom:1px solid #eee;font-size:.85rem;vertical-align:middle }
.db-table thead tr { background:#f5f5f5 }
.db-badge { display:inline-block;padding:2px 8px;border-radius:12px;font-size:.78rem;font-weight:600 }
.db-badge-success { background:#d4edda;color:#155724 }
.db-badge-danger  { background:#f8d7da;color:#721c24 }
.db-badge-warning { background:#fff3cd;color:#856404 }
.db-badge-info    { background:#d1ecf1;color:#0c5460 }
.db-badge-secondary { background:#e2e3e5;color:#383d41 }
.db-badge-primary { background:#cce5ff;color:#004085 }
.db-mono { font-family:monospace }
.db-btn  { font-size:.75rem;padding:2px 8px;cursor:pointer;border:1px solid #aaa;border-radius:3px;background:#fff }
</style>

<div class="page-section" style="padding:16px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px">
        <a href="{!! adminUrl('dashboard') !!}" style="font-size:.85rem">&larr; Dashboard</a>
        <h2 style="margin:0">Database Details</h2>
        {{-- Only for an account the route will actually serve — see the controller. --}}
        @if (!empty($this->adminerUrl))
            <a href="{{ $this->adminerUrl }}"
               style="margin-left:auto;padding:4px 10px;border:1px solid #ccc;border-radius:4px;text-decoration:none;color:inherit">Open in Adminer</a>
        @endif
        @if (!empty($stats['version']))
            <span class="db-badge db-badge-secondary">{{ $stats['version'] }}</span>
        @endif
        @if (!empty($tsData['ts_version']))
            <span class="db-badge db-badge-info">TimescaleDB {{ $tsData['ts_version'] }}</span>
        @endif
    </div>

    <!-- Overview cards -->
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div style="flex:1;min-width:150px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.4rem;font-weight:700;color:#0d6efd">
                {!! $stats['db_size_bytes'] !== null ? $fmtBytes((int) $stats['db_size_bytes']) : '—' !!}
            </div>
            <div style="font-size:.8rem;color:#888">Database Size</div>
        </div>
        <div style="flex:1;min-width:150px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.4rem;font-weight:700;color:#198754">{!! $stats['connections_total'] ?? '—' !!}</div>
            <div style="font-size:.8rem;color:#888">Connections Total</div>
        </div>
        <div style="flex:1;min-width:150px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.4rem;font-weight:700;color:#ffc107">{!! $stats['connections_active'] ?? '—' !!}</div>
            <div style="font-size:.8rem;color:#888">Active Connections</div>
        </div>
        <div style="flex:1;min-width:150px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            @php $chr = $stats['cache_hit_ratio'] ?? null;
            $chrColor = $chr !== null && (float)$chr >= 95 ? '#198754' : ($chr !== null && (float)$chr >= 80 ? '#fd7e14' : '#dc3545'); @endphp
            <div style="font-size:1.4rem;font-weight:700;color:{!! $chrColor !!}">
                {!! $chr !== null ? $chr . '%' : '—' !!}
            </div>
            <div style="font-size:.8rem;color:#888">Cache Hit Ratio</div>
        </div>
    </div>

    <!-- Active Processes -->
    <div style="{!! $card !!}">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd;display:flex;justify-content:space-between">
            <span>Active Processes</span>
            <span class="db-badge db-badge-secondary">{!! count($processes) !!}</span>
        </div>
        <div style="overflow-x:auto">
            @if (empty($processes))
                <p style="text-align:center;color:#888;padding:16px 0">No active processes.</p>
            @else
            <table class="db-table">
                <thead>
                    @if ($dbType === 'postgresql')
                    <tr><th>PID</th><th>User</th><th>Database</th><th>Application</th><th>IP</th><th>Backend Start</th><th>Running</th><th>State</th><th>Query</th></tr>
                    @else
                    <tr><th>ID</th><th>User</th><th>DB</th><th>Command</th><th>Time</th><th>State</th><th>Info</th></tr>
                    @endif
                </thead>
                <tbody>
                @foreach ($processes as $p)
                    @if ($dbType === 'postgresql')
                    @php $durSec = (int) ($p['duration_sec'] ?? 0); @endphp
                    <tr>
                        <td class="db-mono">{!! (int) ($p['pid'] ?? 0) !!}</td>
                        <td>{{ $p['usename'] ?? '—' }}</td>
                        <td style="color:#888">{{ $p['datname'] ?? '—' }}</td>
                        <td style="color:#888">{{ $p['application_name'] ?? '—' }}</td>
                        <td class="db-mono" style="color:#888;font-size:.8rem">{{ $p['client_addr'] ?? '—' }}</td>
                        <td style="color:#888;font-size:.8rem">{{ $p['backend_start'] ?? '—' }}</td>
                        <td>
                            @php if ($durSec > 0):
                                $cls = $durSec > 300 ? 'danger' : ($durSec > 60 ? 'warning' : 'info');
                                $min = intdiv($durSec, 60);
                                $sec = $durSec % 60;
                                $txt = $min > 0 ? "{$min}m {$sec}s" : "{$durSec}s"; @endphp
                                <span class="db-badge db-badge-{!! $cls !!}">{!! $txt !!}</span>
                            @else—@endif
                        </td>
                        <td>
                            <span class="db-badge db-badge-{!! ($p['state'] ?? '') === 'active' ? 'success' : 'secondary' !!}">
                                {{ $p['state'] ?? '—' }}
                            </span>
                        </td>
                        <td style="max-width:260px">
                            @if (!empty($p['query']))
                            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $p['query'] }}">
                                <button class="db-btn" data-copy-query="{{ $p['query'] }}">Copy</button>
                                <span class="db-mono" style="font-size:.8rem">{{ $p['query'] }}</span>
                            </div>
                            @else<em style="color:#aaa">—</em>@endif
                        </td>
                    </tr>
                    @else
                    <tr>
                        <td class="db-mono">{!! (int) ($p['Id'] ?? 0) !!}</td>
                        <td>{{ $p['User'] ?? '—' }}</td>
                        <td style="color:#888">{{ $p['db'] ?? '—' }}</td>
                        <td>{{ $p['Command'] ?? '—' }}</td>
                        <td>{!! (int) ($p['Time'] ?? 0) !!}s</td>
                        <td style="color:#888">{{ $p['State'] ?? '—' }}</td>
                        <td class="db-mono" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.8rem">
                            {{ $p['Info'] ?? '' }}
                        </td>
                    </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>

    <!-- Replication Status (PostgreSQL only) -->
    @if ($dbType === 'postgresql')
    <div style="{!! $card !!}">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Replication Status</div>
        <div style="overflow-x:auto">
            @if (empty($replication))
                <p style="text-align:center;color:#888;padding:16px 0">No replication configured. Standalone instance or replica.</p>
            @else
            <table class="db-table">
                <thead><tr><th>Client Address</th><th>State</th><th>Sync State</th><th>Replication Lag</th></tr></thead>
                <tbody>
                @foreach ($replication as $repl)
                    @php $lagSec = (int) ($repl['lag_sec'] ?? 0); @endphp
                    <tr>
                        <td class="db-mono">{{ $repl['client_addr'] ?? '—' }}</td>
                        <td><span class="db-badge db-badge-{!! ($repl['state'] ?? '') === 'streaming' ? 'success' : 'warning' !!}">{{ $repl['state'] ?? '—' }}</span></td>
                        <td><span class="db-badge db-badge-{!! ($repl['sync_state'] ?? '') === 'sync' ? 'primary' : 'secondary' !!}">{{ $repl['sync_state'] ?? '—' }}</span></td>
                        <td>
                            @if (($repl['sync_state'] ?? '') === 'sync')
                                <span class="db-badge db-badge-success">In Sync</span>
                            @php elseif ($lagSec > 0):
                                $lagCls = $lagSec > 300 ? 'danger' : ($lagSec > 60 ? 'warning' : 'success');
                                $lagTxt = $lagSec > 60 ? intdiv($lagSec, 60) . 'm ' . ($lagSec % 60) . 's' : $lagSec . 's'; @endphp
                                <span class="db-badge db-badge-{!! $lagCls !!}">{!! $lagTxt !!}</span>
                            @else
                                <span class="db-badge db-badge-secondary">N/A</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
    @endif

    <!-- Table Sizes -->
    @if (!empty($tableSizes))
    <div style="{!! $card !!}">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Table Sizes (top 30)</div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>Table</th><th style="text-align:right">Rows</th><th style="text-align:right">Data</th><th style="text-align:right">Indexes</th><th style="text-align:right">Total</th></tr></thead>
                <tbody>
                @foreach ($tableSizes as $t)
                    <tr>
                        <td class="db-mono">
                            @if (!empty($t['schemaname']) && $t['schemaname'] !== 'public')
                                <span style="color:#aaa">{{ $t['schemaname'] }}.</span>
                            @endif
                            {{ $t['table_name'] ?? '' }}
                        </td>
                        <td style="text-align:right;color:#888">{!! $t['row_estimate'] !== null ? number_format((int) $t['row_estimate']) : '—' !!}</td>
                        <td style="text-align:right">{!! $t['data_bytes'] !== null ? $fmtBytes((int) $t['data_bytes']) : '—' !!}</td>
                        <td style="text-align:right;color:#888">{!! $t['index_bytes'] !== null ? $fmtBytes((int) $t['index_bytes']) : '—' !!}</td>
                        <td style="text-align:right;font-weight:600">{!! $t['total_bytes'] !== null ? $fmtBytes((int) $t['total_bytes']) : '—' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif


    <!-- TimescaleDB section -->
    @if (!empty($tsData['ts_version']))

    <div style="{!! $card !!}">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Storage Information</div>
        <div style="padding:16px;display:flex;gap:16px;flex-wrap:wrap">
            <div style="flex:1;min-width:140px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
                <div style="font-size:1.4rem;font-weight:700;color:#0dcaf0">{!! (int) ($tsData['chunkCount'] ?? 0) !!}</div>
                <div style="font-size:.8rem;color:#888">Total Chunks</div>
            </div>
            <div style="flex:1;min-width:140px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
                @php $cc = 0; foreach ($tsData['hypertables'] as $ht) { if (!empty($ht['compression_enabled'])) $cc++; } @endphp
                <div style="font-size:1.4rem;font-weight:700;color:#198754">{!! $cc !!}</div>
                <div style="font-size:.8rem;color:#888">Compressed Hypertables</div>
            </div>
        </div>
    </div>

    @if (!empty($tsData['hypertables']))
    <div style="{!! $card !!}">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Hypertables</div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>Name</th><th>Chunks</th><th>Dimensions</th><th>Compression</th><th>Tablespaces</th></tr></thead>
                <tbody>
                @foreach ($tsData['hypertables'] as $ht)
                    <tr>
                        <td class="db-mono">{{ $ht['hypertable_name'] ?? '' }}</td>
                        <td>{!! (int) ($ht['num_chunks'] ?? 0) !!}</td>
                        <td>{!! (int) ($ht['num_dimensions'] ?? 0) !!}</td>
                        <td>
                            @php $comp = $ht['compression_enabled'] ?? false; @endphp
                            <span class="db-badge db-badge-{!! $comp ? 'success' : 'secondary' !!}">{!! $comp ? 'On' : 'Off' !!}</span>
                        </td>
                        <td style="color:#888;font-size:.8rem">{{ $ht['tablespaces'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if (!empty($tsData['aggregates']))
    <div style="{!! $card !!}">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Continuous Aggregates</div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>Schema</th><th>View</th><th>Mat. Schema</th><th>Mat. Table</th><th>Mat. Only</th><th>Compression</th></tr></thead>
                <tbody>
                @foreach ($tsData['aggregates'] as $ca)
                    <tr>
                        <td style="color:#888">{{ $ca['view_schema'] ?? '—' }}</td>
                        <td class="db-mono">{{ $ca['view_name'] ?? '' }}</td>
                        <td style="color:#888;font-size:.8rem">{{ $ca['materialization_schema'] ?? '—' }}</td>
                        <td class="db-mono" style="color:#888;font-size:.8rem">{{ $ca['materialization_name'] ?? '—' }}</td>
                        <td>
                            @php $mo = $ca['materialized_only'] ?? 'No'; @endphp
                            <span class="db-badge db-badge-{!! $mo === 'Yes' ? 'info' : 'secondary' !!}">{{ $mo }}</span>
                        </td>
                        <td>
                            @php $comp = $ca['compression_enabled'] ?? false; @endphp
                            <span class="db-badge db-badge-{!! $comp ? 'success' : 'secondary' !!}">{!! $comp ? 'On' : 'Off' !!}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if (!empty($tsData['jobs']))
    <div style="{!! $card !!}">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Scheduled Jobs</div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>ID</th><th>Procedure</th><th>Interval</th><th>Last Run</th><th>Status</th><th>Next Run</th><th></th></tr></thead>
                <tbody>
                @foreach ($tsData['jobs'] as $job)
                    <tr>
                        <td class="db-mono">{!! (int) ($job['job_id'] ?? 0) !!}</td>
                        <td class="db-mono">
                            @if (!empty($job['proc_schema']) && $job['proc_schema'] !== '_timescaledb_internal')
                                <span style="color:#aaa">{{ $job['proc_schema'] }}.</span>
                            @endif
                            {{ $job['proc_name'] ?? '—' }}
                        </td>
                        <td style="color:#888">{{ $job['schedule_interval'] ?? '—' }}</td>
                        <td style="color:#888;font-size:.8rem">@php $stamp = (string) ($job['last_run_started_at'] ?? '');
                            $at = $stamp !== '' ? strtotime($stamp) : false;
                            echo $at !== false
                                ? htmlspecialchars(localDateTime($at), ENT_QUOTES, 'UTF-8')
                                : htmlspecialchars($stamp !== '' ? $stamp : '—', ENT_QUOTES, 'UTF-8'); @endphp</td>
                        <td>
                            @php $ls = $job['last_run_status'] ?? '—'; @endphp
                            <span class="db-badge db-badge-{!! $ls === 'Success' ? 'success' : ($ls === '—' ? 'secondary' : 'danger') !!}">{{ $ls }}</span>
                        </td>
                        <td style="color:#888;font-size:.8rem">@php $stamp = (string) ($job['next_start'] ?? '');
                            $at = $stamp !== '' ? strtotime($stamp) : false;
                            echo $at !== false
                                ? htmlspecialchars(localDateTime($at), ENT_QUOTES, 'UTF-8')
                                : htmlspecialchars($stamp !== '' ? $stamp : '—', ENT_QUOTES, 'UTF-8'); @endphp</td>
                        <td>
                            <button class="db-btn" style="border-color:#dc3545;color:#dc3545"
                                    data-job-history-id="{!! (int) ($job['job_id'] ?? 0) !!}" style="white-space:nowrap">Errors</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div id="jobHistOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000">
        <div style="position:relative;margin:3% auto;background:#fff;border-radius:6px;width:820px;max-width:96%;padding:20px;max-height:90vh;overflow-y:auto">
            <h6 id="jobHistTitle" style="margin:0 0 12px"></h6>
            <div style="overflow-x:auto">
                <table class="db-table">
                    <thead><tr><th>Start</th><th>Finish</th><th>Status</th><th>Procedure</th><th>Error</th></tr></thead>
                    <tbody id="jobHistBody"></tbody>
                </table>
            </div>
            <p id="jobHistEmpty" style="display:none;text-align:center;color:#888">No history found for this job.</p>
            <div style="text-align:right;margin-top:10px">
                <button id="closeJobHistBtn" class="db-btn">Close</button>
            </div>
        </div>
    </div>
    <script>
    var _jobHistory = {!! json_encode(array_values($tsData['jobHistory'] ?? [])) !!};
    document.getElementById('closeJobHistBtn').addEventListener('click', function() {
        document.getElementById('jobHistOverlay').style.display = 'none';
    });
    </script>
    @endif

    @php endif; // TimescaleDB; @endphp

    <script>
    document.addEventListener('click', function(e) {
        var cBtn = e.target.closest('[data-copy-query]');
        if (cBtn) {
            if (navigator.clipboard) navigator.clipboard.writeText(cBtn.getAttribute('data-copy-query')).then(function() {
                var o = cBtn.textContent; cBtn.textContent = 'Copied!';
                setTimeout(function(){ cBtn.textContent = o; }, 1500);
            });
        }
        var jBtn = e.target.closest('[data-job-history-id]');
        if (jBtn && typeof _jobHistory !== 'undefined') {
            var jobId = parseInt(jBtn.getAttribute('data-job-history-id'), 10);
            document.getElementById('jobHistTitle').textContent = 'Job #' + jobId + ' History';
            var records = _jobHistory.filter(function(r){ return parseInt(r.job_id, 10) === jobId; });
            var tbody = document.getElementById('jobHistBody');
            var empty = document.getElementById('jobHistEmpty');
            tbody.innerHTML = '';
            if (records.length === 0) {
                empty.style.display = '';
            } else {
                empty.style.display = 'none';
                records.forEach(function(r) {
                    var ok = r.succeeded === 't' || r.succeeded === 'true' || r.succeeded === true;
                    var tr = document.createElement('tr');
                    // The microseconds and the `+00` are twenty-nine characters that wrap in
                    // a modal column and say nothing a person reads.
                    var shortTime = function (v) {
                        return v ? String(v).replace(/\.\d+/, '').replace(/\+00$/, '') : '—';
                    };
                    tr.innerHTML = '<td style="font-size:.8rem;white-space:nowrap">' + shortTime(r.start_time) + '</td>'
                        + '<td style="font-size:.8rem;white-space:nowrap">' + shortTime(r.finish_time) + '</td>'
                        + '<td><span class="db-badge db-badge-' + (ok ? 'success' : 'danger') + '">' + (ok ? 'Success' : 'Failed') + '</span></td>'
                        + '<td class="db-mono" style="font-size:.8rem">' + (r.proc_schema ? r.proc_schema + '.' : '') + (r.proc_name || '—') + '</td>'
                        + '<td style="color:#dc3545;font-size:.8rem">' + (r.err_message || '') + '</td>';
                    tbody.appendChild(tr);
                });
            }
            document.getElementById('jobHistOverlay').style.display = 'block';
        }
    });
    </script>
</div>
