<?php
/**
 * Database details page (Tailwind theme).
 *
 * Variables:
 *   $this->stats       — array from DatabaseStatsService::getStats()
 *   $this->processes   — array of active process rows (PostgreSQL: includes datname, client_addr, backend_start)
 *   $this->tableSizes  — array of table size rows
 *   $this->tsData      — array: hypertables, aggregates, jobs, jobHistory, chunkCount, ts_version
 *   $this->replication — array of pg_stat_replication rows (PostgreSQL only)
 *   $this->publicViews — array of public-schema view rows (PostgreSQL only)
 */
$stats       = $this->stats       ?? [];
$processes   = $this->processes   ?? [];
$tableSizes  = $this->tableSizes  ?? [];
$tsData      = $this->tsData      ?? ['hypertables' => [], 'aggregates' => [], 'jobs' => [], 'jobHistory' => [], 'chunkCount' => 0, 'ts_version' => null];
$replication = $this->replication ?? [];
$publicViews = $this->publicViews ?? [];
$dbType      = $stats['type'] ?? 'mysql';

$fmtBytes = function (int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2)       . ' KB';
    return $bytes . ' B';
};
?>
<style>
.tw-badge { @apply inline-block px-2 py-0.5 rounded-full text-xs font-semibold; }
</style>
<div class="px-4 py-6">
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <a href="<?php echo sURL; ?>dashboard" class="text-sm text-blue-600 hover:underline">&larr; Dashboard</a>
        <h2 class="mb-0">Database Details</h2>
        <?php if (!empty($stats['version'])): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-200 text-gray-700">
                <?php echo htmlspecialchars($stats['version'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        <?php endif; ?>
        <?php if (!empty($tsData['ts_version'])): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-cyan-100 text-cyan-800">
                TimescaleDB <?php echo htmlspecialchars($tsData['ts_version'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        <?php endif; ?>
    </div>

    <!-- Overview cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-blue-600 mb-1">
                <?php echo $stats['db_size_bytes'] !== null ? $fmtBytes((int) $stats['db_size_bytes']) : '—'; ?>
            </div>
            <div class="text-xs text-gray-500">Database Size</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-green-600 mb-1"><?php echo $stats['connections_total'] ?? '—'; ?></div>
            <div class="text-xs text-gray-500">Connections Total</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-yellow-500 mb-1"><?php echo $stats['connections_active'] ?? '—'; ?></div>
            <div class="text-xs text-gray-500">Active Connections</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <?php $chr = $stats['cache_hit_ratio'] ?? null;
            $chrCls = $chr !== null && (float)$chr >= 95 ? 'text-green-600' : ($chr !== null && (float)$chr >= 80 ? 'text-orange-500' : 'text-red-600'); ?>
            <div class="text-2xl font-bold <?php echo $chrCls; ?> mb-1">
                <?php echo $chr !== null ? $chr . '%' : '—'; ?>
            </div>
            <div class="text-xs text-gray-500">Cache Hit Ratio</div>
        </div>
    </div>

    <!-- Active Processes -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm flex justify-between items-center">
            <span>Active Processes</span>
            <span class="bg-gray-200 text-gray-700 text-xs px-2 py-0.5 rounded-full"><?php echo count($processes); ?></span>
        </div>
        <?php if (empty($processes)): ?>
            <p class="text-center text-gray-400 py-6 text-sm">No active processes.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <?php if ($dbType === 'postgresql'): ?>
                    <tr>
                        <th class="px-3 py-2 text-left">PID</th>
                        <th class="px-3 py-2 text-left">User</th>
                        <th class="px-3 py-2 text-left">Database</th>
                        <th class="px-3 py-2 text-left">Application</th>
                        <th class="px-3 py-2 text-left">IP</th>
                        <th class="px-3 py-2 text-left">Started</th>
                        <th class="px-3 py-2 text-left">Running</th>
                        <th class="px-3 py-2 text-left">State</th>
                        <th class="px-3 py-2 text-left">Query</th>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th class="px-3 py-2 text-left">ID</th>
                        <th class="px-3 py-2 text-left">User</th>
                        <th class="px-3 py-2 text-left">DB</th>
                        <th class="px-3 py-2 text-left">Cmd</th>
                        <th class="px-3 py-2 text-left">Time</th>
                        <th class="px-3 py-2 text-left">State</th>
                        <th class="px-3 py-2 text-left">Info</th>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($processes as $p): ?>
                    <?php if ($dbType === 'postgresql'): ?>
                    <?php $durSec = (int) ($p['duration_sec'] ?? 0); ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-mono"><?php echo (int) ($p['pid'] ?? 0); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($p['usename'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($p['datname'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($p['application_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 font-mono text-gray-400"><?php echo htmlspecialchars($p['client_addr'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 text-gray-400"><?php echo htmlspecialchars($p['backend_start'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2">
                            <?php if ($durSec > 0):
                                $cls = $durSec > 300 ? 'bg-red-100 text-red-800' : ($durSec > 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800');
                                $min = intdiv($durSec, 60);
                                $sec = $durSec % 60;
                                $txt = $min > 0 ? "{$min}m {$sec}s" : "{$durSec}s"; ?>
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $cls; ?>"><?php echo $txt; ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="px-3 py-2">
                            <?php $stCls = ($p['state'] ?? '') === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'; ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $stCls; ?>">
                                <?php echo htmlspecialchars($p['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 font-mono max-w-xs">
                            <?php if (!empty($p['query'])): ?>
                            <div class="overflow-hidden text-ellipsis whitespace-nowrap" title="<?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button class="text-xs border border-gray-300 rounded px-1 mr-1 hover:bg-gray-100"
                                        data-copy-query="<?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?>">Copy</button>
                                <?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php else: ?><span class="text-gray-400">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-mono"><?php echo (int) ($p['Id'] ?? 0); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($p['User'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($p['db'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($p['Command'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2"><?php echo (int) ($p['Time'] ?? 0); ?>s</td>
                        <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($p['State'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 font-mono text-gray-400 max-w-xs overflow-hidden text-ellipsis whitespace-nowrap">
                            <?php echo htmlspecialchars($p['Info'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Replication Status (PostgreSQL only) -->
    <?php if ($dbType === 'postgresql'): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Replication Status</div>
        <?php if (empty($replication)): ?>
            <p class="text-center text-gray-400 py-6 text-sm">No replication configured. Standalone instance or replica.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Client Address</th>
                        <th class="px-3 py-2 text-left">State</th>
                        <th class="px-3 py-2 text-left">Sync State</th>
                        <th class="px-3 py-2 text-left">Lag</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($replication as $repl): ?>
                    <?php $lagSec = (int) ($repl['lag_sec'] ?? 0); ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($repl['client_addr'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2">
                            <?php $sCls = ($repl['state'] ?? '') === 'streaming' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $sCls; ?>"><?php echo htmlspecialchars($repl['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td class="px-3 py-2">
                            <?php $sysCls = ($repl['sync_state'] ?? '') === 'sync' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600'; ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $sysCls; ?>"><?php echo htmlspecialchars($repl['sync_state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td class="px-3 py-2">
                            <?php if (($repl['sync_state'] ?? '') === 'sync'): ?>
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">In Sync</span>
                            <?php elseif ($lagSec > 0):
                                $lagCls = $lagSec > 300 ? 'bg-red-100 text-red-800' : ($lagSec > 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800');
                                $lagTxt = $lagSec > 60 ? intdiv($lagSec,60).'m '.($lagSec%60).'s' : $lagSec.'s'; ?>
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $lagCls; ?>"><?php echo $lagTxt; ?></span>
                            <?php else: ?>
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Table Sizes -->
    <?php if (!empty($tableSizes)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Table Sizes (top 30)</div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Table</th>
                        <th class="px-3 py-2 text-right">Rows</th>
                        <th class="px-3 py-2 text-right">Data</th>
                        <th class="px-3 py-2 text-right">Indexes</th>
                        <th class="px-3 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($tableSizes as $t): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-mono">
                            <?php if (!empty($t['schemaname']) && $t['schemaname'] !== 'public'): ?>
                                <span class="text-gray-400"><?php echo htmlspecialchars($t['schemaname'], ENT_QUOTES, 'UTF-8'); ?>.</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($t['table_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td class="px-3 py-2 text-right text-gray-400"><?php echo $t['row_estimate'] !== null ? number_format((int)$t['row_estimate']) : '—'; ?></td>
                        <td class="px-3 py-2 text-right"><?php echo $t['data_bytes'] !== null ? $fmtBytes((int)$t['data_bytes']) : '—'; ?></td>
                        <td class="px-3 py-2 text-right text-gray-400"><?php echo $t['index_bytes'] !== null ? $fmtBytes((int)$t['index_bytes']) : '—'; ?></td>
                        <td class="px-3 py-2 text-right font-semibold"><?php echo $t['total_bytes'] !== null ? $fmtBytes((int)$t['total_bytes']) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Public Views (PostgreSQL only) -->
    <?php if ($dbType === 'postgresql' && !empty($publicViews)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm flex justify-between items-center">
            <span>Public Schema Views</span>
            <span class="bg-gray-200 text-gray-700 text-xs px-2 py-0.5 rounded-full"><?php echo count($publicViews); ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <tr><th class="px-3 py-2 text-left">View Name</th><th class="px-3 py-2 text-left">Definition (truncated)</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($publicViews as $i => $v): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-mono font-semibold"><?php echo htmlspecialchars($v['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 font-mono text-gray-400 max-w-sm overflow-hidden text-ellipsis whitespace-nowrap">
                            <?php echo htmlspecialchars(substr($v['view_definition'] ?? '', 0, 120), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td class="px-3 py-2">
                            <?php if (!empty($v['view_definition'])): ?>
                            <button class="text-xs border border-blue-400 text-blue-600 rounded px-2 py-0.5 hover:bg-blue-50"
                                    data-view-def-index="<?php echo (int) $i; ?>">View</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="viewDefOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000">
        <div style="position:relative;margin:3% auto;background:#fff;border-radius:8px;width:820px;max-width:96%;padding:24px;max-height:90vh;overflow-y:auto">
            <h6 id="viewDefTitle" style="margin:0 0 12px;font-size:1rem;font-weight:600"></h6>
            <pre id="viewDefBody" style="white-space:pre-wrap;font-size:.8rem;max-height:400px;overflow-y:auto;background:#f8f8f8;padding:12px;border-radius:6px"></pre>
            <div style="text-align:right;margin-top:12px">
                <button id="closeViewDefBtn" style="font-size:.8rem;padding:4px 16px;border:1px solid #ccc;border-radius:4px;background:#fff;cursor:pointer">Close</button>
            </div>
        </div>
    </div>
    <script>
    var _publicViews = <?php echo json_encode(array_values($publicViews)); ?>;
    document.getElementById('closeViewDefBtn').addEventListener('click', function() {
        document.getElementById('viewDefOverlay').style.display = 'none';
    });
    </script>
    <?php endif; ?>

    <!-- TimescaleDB section -->
    <?php if (!empty($tsData['ts_version'])): ?>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-cyan-500 mb-1"><?php echo (int) ($tsData['chunkCount'] ?? 0); ?></div>
            <div class="text-xs text-gray-500">Total Chunks</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <?php $cc = 0; foreach ($tsData['hypertables'] as $ht) { if (!empty($ht['compression_enabled'])) $cc++; } ?>
            <div class="text-2xl font-bold text-green-600 mb-1"><?php echo $cc; ?></div>
            <div class="text-xs text-gray-500">Compressed Hypertables</div>
        </div>
    </div>

    <?php if (!empty($tsData['hypertables'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm flex items-center gap-2">
            Hypertables
            <span class="bg-cyan-100 text-cyan-800 text-xs px-2 py-0.5 rounded-full"><?php echo count($tsData['hypertables']); ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <tr><th class="px-3 py-2 text-left">Name</th><th class="px-3 py-2 text-right">Chunks</th><th class="px-3 py-2 text-right">Dims</th><th class="px-3 py-2 text-left">Compression</th><th class="px-3 py-2 text-left">Tablespaces</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($tsData['hypertables'] as $ht): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($ht['hypertable_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 text-right"><?php echo (int) ($ht['num_chunks'] ?? 0); ?></td>
                        <td class="px-3 py-2 text-right"><?php echo (int) ($ht['num_dimensions'] ?? 0); ?></td>
                        <td class="px-3 py-2">
                            <?php $comp = $ht['compression_enabled'] ?? false; ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $comp ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'; ?>"><?php echo $comp ? 'On' : 'Off'; ?></span>
                        </td>
                        <td class="px-3 py-2 text-gray-400"><?php echo htmlspecialchars($ht['tablespaces'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['aggregates'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm flex items-center gap-2">
            Continuous Aggregates
            <span class="bg-cyan-100 text-cyan-800 text-xs px-2 py-0.5 rounded-full"><?php echo count($tsData['aggregates']); ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <tr><th class="px-3 py-2 text-left">Schema</th><th class="px-3 py-2 text-left">View</th><th class="px-3 py-2 text-left">Mat. Schema</th><th class="px-3 py-2 text-left">Mat. Table</th><th class="px-3 py-2 text-left">Mat. Only</th><th class="px-3 py-2 text-left">Compression</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($tsData['aggregates'] as $ca): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($ca['view_schema'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($ca['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 text-gray-400"><?php echo htmlspecialchars($ca['materialization_schema'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 font-mono text-gray-400"><?php echo htmlspecialchars($ca['materialization_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2">
                            <?php $mo = $ca['materialized_only'] ?? 'No'; ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $mo === 'Yes' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600'; ?>"><?php echo htmlspecialchars($mo, ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td class="px-3 py-2">
                            <?php $comp = $ca['compression_enabled'] ?? false; ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $comp ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'; ?>"><?php echo $comp ? 'On' : 'Off'; ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['jobs'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Scheduled Jobs</div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <tr><th class="px-3 py-2 text-left">ID</th><th class="px-3 py-2 text-left">Procedure</th><th class="px-3 py-2 text-left">Interval</th><th class="px-3 py-2 text-left">Last Run</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Next Run</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($tsData['jobs'] as $job): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-mono"><?php echo (int) ($job['job_id'] ?? 0); ?></td>
                        <td class="px-3 py-2 font-mono">
                            <?php if (!empty($job['proc_schema']) && $job['proc_schema'] !== '_timescaledb_internal'): ?>
                                <span class="text-gray-400"><?php echo htmlspecialchars($job['proc_schema'], ENT_QUOTES, 'UTF-8'); ?>.</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($job['proc_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($job['schedule_interval'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-3 py-2 text-gray-400"><?php echo !empty($job['last_run_started_at']) ? htmlspecialchars($job['last_run_started_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td class="px-3 py-2">
                            <?php $ls = $job['last_run_status'] ?? '—';
                            $lsCls = $ls === 'Success' ? 'bg-green-100 text-green-800' : ($ls === '—' ? 'bg-gray-100 text-gray-600' : 'bg-red-100 text-red-800'); ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $lsCls; ?>"><?php echo htmlspecialchars($ls, ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td class="px-3 py-2 text-gray-400"><?php echo !empty($job['next_start']) ? htmlspecialchars($job['next_start'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td class="px-3 py-2">
                            <button class="text-xs border border-red-400 text-red-600 rounded px-2 py-0.5 hover:bg-red-50"
                                    data-job-history-id="<?php echo (int) ($job['job_id'] ?? 0); ?>">
                                Error History
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="jobHistOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000">
        <div style="position:relative;margin:3% auto;background:#fff;border-radius:8px;width:820px;max-width:96%;padding:24px;max-height:90vh;overflow-y:auto">
            <h6 id="jobHistTitle" style="margin:0 0 12px;font-size:1rem;font-weight:600"></h6>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                    <thead style="background:#f5f5f5"><tr><th style="padding:6px 10px;text-align:left">Start</th><th style="padding:6px 10px;text-align:left">Finish</th><th style="padding:6px 10px;text-align:left">Status</th><th style="padding:6px 10px;text-align:left">Procedure</th><th style="padding:6px 10px;text-align:left">Error</th></tr></thead>
                    <tbody id="jobHistBody"></tbody>
                </table>
            </div>
            <p id="jobHistEmpty" style="display:none;text-align:center;color:#888">No history found for this job.</p>
            <div style="text-align:right;margin-top:12px">
                <button id="closeJobHistBtn" style="font-size:.8rem;padding:4px 16px;border:1px solid #ccc;border-radius:4px;background:#fff;cursor:pointer">Close</button>
            </div>
        </div>
    </div>
    <script>
    var _jobHistory = <?php echo json_encode(array_values($tsData['jobHistory'] ?? [])); ?>;
    document.getElementById('closeJobHistBtn').addEventListener('click', function() {
        document.getElementById('jobHistOverlay').style.display = 'none';
    });
    </script>
    <?php endif; ?>

    <?php endif; // TimescaleDB ?>

    <script>
    document.addEventListener('click', function(e) {
        var cBtn = e.target.closest('[data-copy-query]');
        if (cBtn) {
            if (navigator.clipboard) navigator.clipboard.writeText(cBtn.getAttribute('data-copy-query')).then(function() {
                var o = cBtn.textContent; cBtn.textContent = 'Copied!';
                setTimeout(function(){ cBtn.textContent = o; }, 1500);
            });
        }
        var vBtn = e.target.closest('[data-view-def-index]');
        if (vBtn && typeof _publicViews !== 'undefined') {
            var idx = parseInt(vBtn.getAttribute('data-view-def-index'), 10);
            if (_publicViews[idx]) {
                document.getElementById('viewDefTitle').textContent = _publicViews[idx].view_name;
                document.getElementById('viewDefBody').textContent  = _publicViews[idx].view_definition;
                document.getElementById('viewDefOverlay').style.display = 'block';
            }
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
                    tr.innerHTML = '<td style="padding:5px 10px">' + (r.start_time||'—') + '</td>'
                        + '<td style="padding:5px 10px">' + (r.finish_time||'—') + '</td>'
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
