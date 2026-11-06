@php /**
 * Database details page (Tailwind theme).
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

$fmtBytes = function (int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2)       . ' KB';
    return $bytes . ' B';
}; @endphp
<style>
.tw-badge { @apply inline-block px-2 py-0.5 rounded-full text-xs font-semibold; }
</style>
<div class="px-4 py-6">
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <a href="{!! adminUrl('dashboard') !!}" class="text-sm text-primary hover:underline">&larr; Dashboard</a>
        <h2 class="mb-0">Database Details</h2>
        @if (!empty($stats['version']))
            <span class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-base-300 text-base-content">
                {{ $stats['version'] }}
            </span>
        @endif
        {{-- Adminer, for the account that may open it — the controller publishes the URL only for
a root account with the package installed, and null otherwise, so there is no link
here that answers 404. This screen says how big, how busy and how far behind; the
question after that is always about a row. --}}
        @if (!empty($this->adminerUrl))
            <a href="{{ $this->adminerUrl }}"
               class="btn btn-sm btn-outline ms-auto">Open in Adminer</a>
        @endif
        @if (!empty($tsData['ts_version']))
            <span class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-info/10 text-info">
                TimescaleDB {{ $tsData['ts_version'] }}
            </span>
        @endif
    </div>

    <!-- Overview cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            <div class="text-2xl font-bold text-primary mb-1">
                {!! $stats['db_size_bytes'] !== null ? $fmtBytes((int) $stats['db_size_bytes']) : '—' !!}
            </div>
            <div class="text-xs text-base-content/70">Database Size</div>
        </div>
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            <div class="text-2xl font-bold text-success mb-1">{!! $stats['connections_total'] ?? '—' !!}</div>
            <div class="text-xs text-base-content/70">Connections Total</div>
        </div>
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            <div class="text-2xl font-bold text-warning mb-1">{!! $stats['connections_active'] ?? '—' !!}</div>
            <div class="text-xs text-base-content/70">Active Connections</div>
        </div>
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            @php $chr = $stats['cache_hit_ratio'] ?? null;
            $chrCls = $chr !== null && (float)$chr >= 95 ? 'text-success' : ($chr !== null && (float)$chr >= 80 ? 'text-warning' : 'text-error'); @endphp
            <div class="text-2xl font-bold {!! $chrCls !!} mb-1">
                {!! $chr !== null ? $chr . '%' : '—' !!}
            </div>
            <div class="text-xs text-base-content/70">Cache Hit Ratio</div>
        </div>
    </div>

    <!-- Active Processes -->
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        @php /*
         * User and Database, once, in the header — not once per row.
         *
         * The query filters on `datname = current_database()`, so every row carried the same
         * database name, and in practice the same user: two of the widest columns on the screen
         * repeating a value that does not vary. The table needed a horizontal scrollbar, which
         * put the query — the one column somebody is reading — off the right edge.
         *
         * A row that *does* differ (a replica's user, a TimescaleDB worker) is marked instead,
         * so the information is not lost. Which is the point: these columns were not
         * uninformative, they were uninformative *four times*.
         */
        $commonUsers = array_unique(array_filter(array_map(
            static fn (array $p): string => (string) ($p['usename'] ?? ''),
            $processes
        )));
        $commonDbs = array_unique(array_filter(array_map(
            static fn (array $p): string => (string) ($p['datname'] ?? ''),
            $processes
        )));
        $oneUser = count($commonUsers) === 1 ? reset($commonUsers) : '';
        $oneDb   = count($commonDbs) === 1 ? reset($commonDbs) : ''; @endphp
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm flex flex-wrap gap-2 justify-between items-center">
            <span>Active Processes</span>
            @if ($oneUser !== '' || $oneDb !== '')
                <span class="font-normal text-xs text-base-content/60 font-mono">
                    {{ trim($oneUser . ($oneDb !== '' ? ' @ ' . $oneDb : '')) }}
                </span>
            @endif
            <span class="badge badge-neutral badge-sm ms-auto">{!! count($processes) !!}</span>
        </div>
        @if (empty($processes))
            <p class="text-center text-base-content/60 py-6 text-sm">No active processes.</p>
        @else
        <div class="overflow-x-auto">
            <table class="table table-sm text-xs">
                <thead class="bg-base-200 text-base-content/70 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">PID</th>
                        {{-- User and Database live in the header — see above. --}}
                        @if ($oneUser === '' || $oneDb === '')
                        <th class="px-3 py-2 text-left">Connection</th>
                        @endif
                        <th class="px-3 py-2 text-left">Application</th>
                        <th class="px-3 py-2 text-left">IP</th>
                        <th class="px-3 py-2 text-left">Started</th>
                        <th class="px-3 py-2 text-left">Running / idle</th>
                        <th class="px-3 py-2 text-left">State</th>
                        <th class="px-3 py-2 text-left">Query</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-300">
                @foreach ($processes as $p)
                    @php /*
                     * Two numbers, and `state` says which one is true.
                     *
                     * This column read `duration_sec` — `now() - query_start` — for every row,
                     * idle connections included, where it measures time since that connection
                     * last ran anything rather than work in progress. A pooled connection idle
                     * for three hours showed as "194m 6s" in red beside the word `idle`, so the
                     * screen looked like a stuck query every time it was opened. Two of those
                     * and nobody reads the column again.
                     */
                    $isActive = ($p['state'] ?? '') === 'active';
                    $activeSec = isset($p['active_sec']) ? (int) $p['active_sec'] : 0;
                    $idleSec   = isset($p['idle_sec']) ? (int) $p['idle_sec'] : 0;
                    // A MySQL process list has neither column; `duration_sec` is what it has.
                    $durSec = $isActive
                        ? ($activeSec ?: (int) ($p['duration_sec'] ?? 0))
                        : $idleSec; @endphp
                    <tr class="hover:bg-base-200">
                        <td class="px-3 py-2 font-mono">{!! (int) ($p['pid'] ?? 0) !!}</td>
                        @if ($oneUser === '' || $oneDb === '')
                        {{-- Only when they are not all the same, and then together. --}}
                        <td class="px-3 py-2 font-mono text-base-content/70">
                            {{ ($p['usename'] ?? '—') . ' @ ' . ($p['datname'] ?? '—') }}
                        </td>
                        @endif
                        <td class="px-3 py-2 text-base-content/70">{{ $p['application_name'] ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-base-content/60">{{ $p['client_addr'] ?? '—' }}</td>
                        @php // `backend_start` arrives as a formatted string from the query. Reformatted
                        // through the language's own convention when it can be read as a time —
                        // `2026-08-28 09:46:29` wrapped onto two lines in this column, and the
                        // seconds are noise for a connection that has been open for hours.
                        $started = (string) ($p['backend_start'] ?? '');
                        $startedAt = $started !== '' ? strtotime($started) : false; @endphp
                        <td class="px-3 py-2 text-base-content/60 whitespace-nowrap">{!! $startedAt !== false ? htmlspecialchars(localDateTime($startedAt), ENT_QUOTES, 'UTF-8') : htmlspecialchars($started !== '' ? $started : '—', ENT_QUOTES, 'UTF-8') !!}</td>
                        <td class="px-3 py-2">
                            @php if ($durSec > 0):
                                // Only a *running* query is worth colouring. An idle connection
                                // is the normal state of a pool, however long it has been idle.
                                $cls = !$isActive
                                    ? 'bg-base-200 text-base-content/60'
                                    : ($durSec > 300
                                        ? 'bg-error/10 text-error'
                                        : ($durSec > 60 ? 'bg-warning/10 text-warning' : 'bg-primary/10 text-primary'));
                                $min = intdiv($durSec, 60);
                                $sec = $durSec % 60;
                                $txt = $min > 0 ? "{$min}m {$sec}s" : "{$durSec}s";
                                $txt = $isActive ? $txt : 'idle ' . $txt; @endphp
                                <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $cls !!}"
                                      title="{!! $isActive ? 'How long this query has been running' : 'How long this connection has been idle — normal for a pooled connection' !!}">{!! $txt !!}</span>
                            @else—@endif
                        </td>
                        <td class="px-3 py-2">
                            @php $stCls = ($p['state'] ?? '') === 'active' ? 'bg-success/10 text-success' : 'bg-base-200 text-base-content/80'; @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $stCls !!}">
                                {{ $p['state'] ?? '—' }}
                            </span>
                        </td>
                        <td class="px-3 py-2 font-mono max-w-xs">
                            @if (!empty($p['query']))
                            <div class="overflow-hidden text-ellipsis whitespace-nowrap" title="{{ $p['query'] }}">
                                <button class="text-xs border border-base-300 rounded-sm px-1 mr-1 hover:bg-base-200"
                                        data-copy-query="{{ $p['query'] }}">Copy</button>
                                {{ $p['query'] }}
                            </div>
                            @else<span class="text-base-content/60">—</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <!-- Replication Status -->
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Replication Status</div>
        @if (empty($replication))
            <p class="text-center text-base-content/60 py-6 text-sm">No replication configured. Standalone instance or replica.</p>
        @else
        <div class="overflow-x-auto">
            <table class="table table-sm text-xs">
                <thead class="bg-base-200 text-base-content/70 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Client Address</th>
                        <th class="px-3 py-2 text-left">State</th>
                        <th class="px-3 py-2 text-left">Sync State</th>
                        <th class="px-3 py-2 text-left">Lag</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-300">
                @foreach ($replication as $repl)
                    @php $lagSec = (int) ($repl['lag_sec'] ?? 0); @endphp
                    <tr class="hover:bg-base-200">
                        <td class="px-3 py-2 font-mono">{{ $repl['client_addr'] ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @php $sCls = ($repl['state'] ?? '') === 'streaming' ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'; @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $sCls !!}">{{ $repl['state'] ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-2">
                            @php $sysCls = ($repl['sync_state'] ?? '') === 'sync' ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/80'; @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $sysCls !!}">{{ $repl['sync_state'] ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-2">
                            @if (($repl['sync_state'] ?? '') === 'sync')
                                <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium bg-success/10 text-success">In Sync</span>
                            @php elseif ($lagSec > 0):
                                $lagCls = $lagSec > 300 ? 'bg-error/10 text-error' : ($lagSec > 60 ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success');
                                $lagTxt = $lagSec > 60 ? intdiv($lagSec,60).'m '.($lagSec%60).'s' : $lagSec.'s'; @endphp
                                <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $lagCls !!}">{!! $lagTxt !!}</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium bg-base-200 text-base-content/80">N/A</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <!-- Table Sizes -->
    @if (!empty($tableSizes))
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Table Sizes (top 30)</div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-xs">
                <thead class="bg-base-200 text-base-content/70 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Table</th>
                        <th class="px-3 py-2 text-right">Rows</th>
                        <th class="px-3 py-2 text-right">Data</th>
                        <th class="px-3 py-2 text-right">Indexes</th>
                        <th class="px-3 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-300">
                @foreach ($tableSizes as $t)
                    <tr class="hover:bg-base-200">
                        <td class="px-3 py-2 font-mono">
                            @if (!empty($t['schemaname']) && $t['schemaname'] !== 'public')
                                <span class="text-base-content/60">{{ $t['schemaname'] }}.</span>
                            @endif
                            {{ $t['table_name'] ?? '' }}
                        </td>
                        <td class="px-3 py-2 text-right text-base-content/60">{!! $t['row_estimate'] !== null ? number_format((int)$t['row_estimate']) : '—' !!}</td>
                        <td class="px-3 py-2 text-right">{!! $t['data_bytes'] !== null ? $fmtBytes((int)$t['data_bytes']) : '—' !!}</td>
                        <td class="px-3 py-2 text-right text-base-content/60">{!! $t['index_bytes'] !== null ? $fmtBytes((int)$t['index_bytes']) : '—' !!}</td>
                        <td class="px-3 py-2 text-right font-semibold">{!! $t['total_bytes'] !== null ? $fmtBytes((int)$t['total_bytes']) : '—' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif


    <!-- TimescaleDB section -->
    @if (!empty($tsData['ts_version']))

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            <div class="text-2xl font-bold text-info mb-1">{!! (int) ($tsData['chunkCount'] ?? 0) !!}</div>
            <div class="text-xs text-base-content/70">Total Chunks</div>
        </div>
        <div class="card bg-base-100 border border-base-300 p-4 text-center">
            @php $cc = 0; foreach ($tsData['hypertables'] as $ht) { if (!empty($ht['compression_enabled'])) $cc++; } @endphp
            <div class="text-2xl font-bold text-success mb-1">{!! $cc !!}</div>
            <div class="text-xs text-base-content/70">Compressed Hypertables</div>
        </div>
    </div>

    @if (!empty($tsData['hypertables']))
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm flex items-center gap-2">
            Hypertables
            <span class="badge badge-info badge-sm">{!! count($tsData['hypertables']) !!}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-xs">
                <thead class="bg-base-200 text-base-content/70 uppercase">
                    <tr><th class="px-3 py-2 text-left">Name</th><th class="px-3 py-2 text-right">Chunks</th><th class="px-3 py-2 text-right">Dims</th><th class="px-3 py-2 text-left">Compression</th><th class="px-3 py-2 text-left">Tablespaces</th></tr>
                </thead>
                <tbody class="divide-y divide-base-300">
                @foreach ($tsData['hypertables'] as $ht)
                    <tr class="hover:bg-base-200">
                        <td class="px-3 py-2 font-mono">{{ $ht['hypertable_name'] ?? '' }}</td>
                        <td class="px-3 py-2 text-right">{!! (int) ($ht['num_chunks'] ?? 0) !!}</td>
                        <td class="px-3 py-2 text-right">{!! (int) ($ht['num_dimensions'] ?? 0) !!}</td>
                        <td class="px-3 py-2">
                            @php $comp = $ht['compression_enabled'] ?? false; @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $comp ? 'bg-success/10 text-success' : 'bg-base-200 text-base-content/80' !!}">{!! $comp ? 'On' : 'Off' !!}</span>
                        </td>
                        <td class="px-3 py-2 text-base-content/60">{{ $ht['tablespaces'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if (!empty($tsData['aggregates']))
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm flex items-center gap-2">
            Continuous Aggregates
            <span class="badge badge-info badge-sm">{!! count($tsData['aggregates']) !!}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-xs">
                <thead class="bg-base-200 text-base-content/70 uppercase">
                    <tr><th class="px-3 py-2 text-left">Schema</th><th class="px-3 py-2 text-left">View</th><th class="px-3 py-2 text-left">Mat. Schema</th><th class="px-3 py-2 text-left">Mat. Table</th><th class="px-3 py-2 text-left">Mat. Only</th><th class="px-3 py-2 text-left">Compression</th></tr>
                </thead>
                <tbody class="divide-y divide-base-300">
                @foreach ($tsData['aggregates'] as $ca)
                    <tr class="hover:bg-base-200">
                        <td class="px-3 py-2 text-base-content/70">{{ $ca['view_schema'] ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono">{{ $ca['view_name'] ?? '' }}</td>
                        <td class="px-3 py-2 text-base-content/60">{{ $ca['materialization_schema'] ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-base-content/60">{{ $ca['materialization_name'] ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @php $mo = $ca['materialized_only'] ?? 'No'; @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $mo === 'Yes' ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/80' !!}">{{ $mo }}</span>
                        </td>
                        <td class="px-3 py-2">
                            @php $comp = $ca['compression_enabled'] ?? false; @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $comp ? 'bg-success/10 text-success' : 'bg-base-200 text-base-content/80' !!}">{!! $comp ? 'On' : 'Off' !!}</span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if (!empty($tsData['jobs']))
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Scheduled Jobs</div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-xs">
                <thead class="bg-base-200 text-base-content/70 uppercase">
                    <tr><th class="px-3 py-2 text-left">ID</th><th class="px-3 py-2 text-left">Procedure</th><th class="px-3 py-2 text-left">Interval</th><th class="px-3 py-2 text-left">Last Run</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Next Run</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-base-300">
                @foreach ($tsData['jobs'] as $job)
                    <tr class="hover:bg-base-200">
                        <td class="px-3 py-2 font-mono">{!! (int) ($job['job_id'] ?? 0) !!}</td>
                        <td class="px-3 py-2 font-mono">
                            @if (!empty($job['proc_schema']) && $job['proc_schema'] !== '_timescaledb_internal')
                                <span class="text-base-content/60">{{ $job['proc_schema'] }}.</span>
                            @endif
                            {{ $job['proc_name'] ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-base-content/70">{{ $job['schedule_interval'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-base-content/60">@php $stamp = (string) ($job['last_run_started_at'] ?? '');
                            $at = $stamp !== '' ? strtotime($stamp) : false;
                            echo $at !== false
                                ? htmlspecialchars(localDateTime($at), ENT_QUOTES, 'UTF-8')
                                : htmlspecialchars($stamp !== '' ? $stamp : '—', ENT_QUOTES, 'UTF-8'); @endphp</td>
                        <td class="px-3 py-2">
                            @php $ls = $job['last_run_status'] ?? '—';
                            $lsCls = $ls === 'Success' ? 'bg-success/10 text-success' : ($ls === '—' ? 'bg-base-200 text-base-content/80' : 'bg-error/10 text-error'); @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-sm text-xs font-medium {!! $lsCls !!}">{{ $ls }}</span>
                        </td>
                        <td class="px-3 py-2 text-base-content/60">@php $stamp = (string) ($job['next_start'] ?? '');
                            $at = $stamp !== '' ? strtotime($stamp) : false;
                            echo $at !== false
                                ? htmlspecialchars(localDateTime($at), ENT_QUOTES, 'UTF-8')
                                : htmlspecialchars($stamp !== '' ? $stamp : '—', ENT_QUOTES, 'UTF-8'); @endphp</td>
                        <td class="px-3 py-2">
                            {{-- `whitespace-nowrap`: "Error History" wrapped to two lines in this column
                                     and made every row twice as tall. --}}
                            <button class="btn btn-ghost btn-xs whitespace-nowrap"
                                    data-job-history-id="{!! (int) ($job['job_id'] ?? 0) !!}">
                                Errors
                            </button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div id="jobHistOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000">
        <div style="position:relative;margin:3% auto;background:var(--color-base-100);border-radius:8px;width:820px;max-width:96%;padding:24px;max-height:90vh;overflow-y:auto">
            <h6 id="jobHistTitle" style="margin:0 0 12px;font-size:1rem;font-weight:600"></h6>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                    <thead class="bg-base-200"><tr><th style="padding:6px 10px;text-align:left">Start</th><th style="padding:6px 10px;text-align:left">Finish</th><th style="padding:6px 10px;text-align:left">Status</th><th style="padding:6px 10px;text-align:left">Procedure</th><th style="padding:6px 10px;text-align:left">Error</th></tr></thead>
                    <tbody id="jobHistBody"></tbody>
                </table>
            </div>
            <p id="jobHistEmpty" style="display:none;text-align:center;color:#888">No history found for this job.</p>
            <div style="text-align:right;margin-top:12px">
                <button id="closeJobHistBtn" style="font-size:.8rem;padding:4px 16px;border:1px solid var(--color-base-300);border-radius:4px;background:var(--color-base-100);color:var(--color-base-content);cursor:pointer">Close</button>
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
            var records = _jobHistory.filter(function(r){ return parseInt(r.job_id,10) === jobId; });
            var tbody = document.getElementById('jobHistBody');
            var empty = document.getElementById('jobHistEmpty');
            tbody.innerHTML = '';
            if (records.length === 0) {
                empty.style.display = '';
            } else {
                empty.style.display = 'none';
                records.forEach(function(r) {
                    var ok = r.succeeded === 't' || r.succeeded === 'true' || r.succeeded === true;
                    var badgeStyle = ok ? 'background:#d4edda;color:#155724' : 'background:#f8d7da;color:#721c24';
                    var tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid #eee';
                    /*
                     * Trimmed here too.
                     *
                     * The rows arrive as PostgreSQL wrote them —
                     * `2026-08-29 23:13:48.822452+00` — and the microseconds and the offset are
                     * twenty-nine characters that wrap in a modal column and say nothing.
                     */
                    var shortTime = function (v) {
                        return v ? String(v).replace(/\.\d+/, '').replace(/\+00$/, '') : '—';
                    };
                    tr.innerHTML = '<td style="padding:5px 10px;white-space:nowrap">' + shortTime(r.start_time) + '</td>'
                        + '<td style="padding:5px 10px;white-space:nowrap">' + shortTime(r.finish_time) + '</td>'
                        + '<td style="padding:5px 10px"><span style="' + badgeStyle + ';padding:2px 8px;border-radius:12px;font-size:.78rem">' + (ok?'Success':'Failed') + '</span></td>'
                        + '<td style="padding:5px 10px;font-family:monospace">' + (r.proc_schema?r.proc_schema+'.':'') + (r.proc_name||'—') + '</td>'
                        + '<td style="padding:5px 10px;color:#dc3545">' + (r.err_message||'') + '</td>';
                    tbody.appendChild(tr);
                });
            }
            document.getElementById('jobHistOverlay').style.display = 'block';
        }
    });
    </script>
</div>
