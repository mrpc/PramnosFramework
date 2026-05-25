<?php
/**
 * Database details page (Tailwind theme).
 *
 * Variables:
 *   $this->stats      — array from DatabaseStatsService::getStats()
 *   $this->processes  — array of active process rows
 *   $this->tableSizes — array of table size rows
 *   $this->tsData     — array: hypertables, aggregates, jobs, ts_version
 */
$stats      = $this->stats      ?? [];
$processes  = $this->processes  ?? [];
$tableSizes = $this->tableSizes ?? [];
$tsData     = $this->tsData     ?? ['hypertables' => [], 'aggregates' => [], 'jobs' => [], 'ts_version' => null];
$dbType     = $stats['type'] ?? 'mysql';

$fmtBytes = function (int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2)       . ' KB';
    return $bytes . ' B';
};
?>
<div class="px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="<?php echo sURL; ?>dashboard" class="px-3 py-1.5 text-sm border border-gray-300 text-gray-600 rounded hover:bg-gray-50">&larr; Dashboard</a>
        <h2 class="text-2xl font-semibold">Database Details</h2>
        <?php if (!empty($stats['version'])): ?>
            <span class="inline-block px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-600"><?php echo htmlspecialchars($stats['version'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <?php if (!empty($tsData['ts_version'])): ?>
            <span class="inline-block px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-700">TimescaleDB <?php echo htmlspecialchars($tsData['ts_version'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
    </div>

    <!-- Overview cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-indigo-600 mb-1">
                <?php echo $stats['db_size_bytes'] !== null ? $fmtBytes((int) $stats['db_size_bytes']) : '—'; ?>
            </div>
            <div class="text-xs text-gray-400">Database Size</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-green-600 mb-1"><?php echo $stats['connections_total'] ?? '—'; ?></div>
            <div class="text-xs text-gray-400">Connections Total</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600 mb-1"><?php echo $stats['connections_active'] ?? '—'; ?></div>
            <div class="text-xs text-gray-400">Active Connections</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <?php $chr = $stats['cache_hit_ratio'] ?? null; ?>
            <div class="text-2xl font-bold <?php echo $chr !== null && $chr >= 95 ? 'text-green-600' : ($chr !== null && $chr >= 80 ? 'text-yellow-600' : 'text-red-500'); ?> mb-1">
                <?php echo $chr !== null ? $chr . '%' : '—'; ?>
            </div>
            <div class="text-xs text-gray-400">Cache Hit Ratio</div>
        </div>
        <?php if ($dbType === 'postgresql' && isset($stats['xact_commit'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-blue-600 mb-1"><?php echo number_format((int) $stats['xact_commit']); ?></div>
            <div class="text-xs text-gray-400">Commits</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-red-500 mb-1"><?php echo number_format((int) $stats['xact_rollback']); ?></div>
            <div class="text-xs text-gray-400">Rollbacks</div>
        </div>
        <?php endif; ?>
        <?php if ($dbType === 'mysql' && isset($stats['queries'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-blue-600 mb-1"><?php echo number_format((int) $stats['queries']); ?></div>
            <div class="text-xs text-gray-400">Total Queries</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Active Processes -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-3 border-b border-gray-100 flex justify-between items-center">
            <span class="font-semibold text-gray-700">Active Processes</span>
            <span class="inline-block px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-600"><?php echo count($processes); ?></span>
        </div>
        <?php if (empty($processes)): ?>
            <p class="text-gray-400 text-center py-8 text-sm">No active processes.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <?php if ($dbType === 'postgresql'): ?>
                    <tr>
                        <th class="px-4 py-2 text-left">PID</th>
                        <th class="px-4 py-2 text-left">User</th>
                        <th class="px-4 py-2 text-left">App</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Wait</th>
                        <th class="px-4 py-2 text-left">Dur.</th>
                        <th class="px-4 py-2 text-left">Query</th>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th class="px-4 py-2 text-left">ID</th>
                        <th class="px-4 py-2 text-left">User</th>
                        <th class="px-4 py-2 text-left">DB</th>
                        <th class="px-4 py-2 text-left">Command</th>
                        <th class="px-4 py-2 text-left">Time</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Info</th>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($processes as $p): ?>
                    <?php if ($dbType === 'postgresql'): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs text-gray-500"><?php echo (int) ($p['pid'] ?? 0); ?></td>
                        <td class="px-4 py-2 text-xs"><?php echo htmlspecialchars($p['usename'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($p['application_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-1.5 py-0.5 rounded text-xs <?php echo ($p['state'] ?? '') === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo htmlspecialchars($p['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($p['wait_event'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs"><?php $d = (int) ($p['duration_sec'] ?? 0); echo $d > 0 ? $d . 's' : '—'; ?></td>
                        <td class="px-4 py-2 text-xs font-mono text-gray-500 max-w-xs overflow-hidden truncate"><?php echo htmlspecialchars($p['query'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <?php else: ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs text-gray-500"><?php echo (int) ($p['Id'] ?? 0); ?></td>
                        <td class="px-4 py-2 text-xs"><?php echo htmlspecialchars($p['User'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($p['db'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs"><?php echo htmlspecialchars($p['Command'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs"><?php echo (int) ($p['Time'] ?? 0); ?>s</td>
                        <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($p['State'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs font-mono text-gray-500 max-w-xs overflow-hidden truncate"><?php echo htmlspecialchars($p['Info'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Table Sizes -->
    <?php if (!empty($tableSizes)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-3 border-b border-gray-100 font-semibold text-gray-700">Table Sizes (top 30)</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Table</th>
                        <th class="px-4 py-2 text-right">Rows (est.)</th>
                        <th class="px-4 py-2 text-right">Data</th>
                        <th class="px-4 py-2 text-right">Indexes</th>
                        <th class="px-4 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($tableSizes as $t): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs">
                            <?php if (!empty($t['schemaname']) && $t['schemaname'] !== 'public'): ?>
                                <span class="text-gray-400"><?php echo htmlspecialchars($t['schemaname'], ENT_QUOTES, 'UTF-8'); ?>.</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($t['table_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td class="px-4 py-2 text-right text-xs text-gray-400"><?php echo $t['row_estimate'] !== null ? number_format((int) $t['row_estimate']) : '—'; ?></td>
                        <td class="px-4 py-2 text-right text-xs"><?php echo $t['data_bytes'] !== null ? $fmtBytes((int) $t['data_bytes']) : '—'; ?></td>
                        <td class="px-4 py-2 text-right text-xs text-gray-400"><?php echo $t['index_bytes'] !== null ? $fmtBytes((int) $t['index_bytes']) : '—'; ?></td>
                        <td class="px-4 py-2 text-right text-xs font-semibold"><?php echo $t['total_bytes'] !== null ? $fmtBytes((int) $t['total_bytes']) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TimescaleDB -->
    <?php if (!empty($tsData['ts_version'])): ?>

    <?php if (!empty($tsData['hypertables'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-3 border-b border-gray-100 flex items-center gap-2">
            <span class="font-semibold text-gray-700">Hypertables</span>
            <span class="inline-block px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-700"><?php echo count($tsData['hypertables']); ?></span>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                <tr>
                    <th class="px-4 py-2 text-left">Name</th>
                    <th class="px-4 py-2 text-right">Chunks</th>
                    <th class="px-4 py-2 text-right">Dims</th>
                    <th class="px-4 py-2 text-left">Compression</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($tsData['hypertables'] as $ht): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-mono text-xs"><?php echo htmlspecialchars($ht['hypertable_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="px-4 py-2 text-right text-xs"><?php echo (int) ($ht['num_chunks'] ?? 0); ?></td>
                    <td class="px-4 py-2 text-right text-xs"><?php echo (int) ($ht['num_dimensions'] ?? 0); ?></td>
                    <td class="px-4 py-2">
                        <?php $comp = $ht['compression_enabled'] ?? false; ?>
                        <span class="inline-block px-1.5 py-0.5 rounded text-xs <?php echo $comp ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $comp ? 'On' : 'Off'; ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['aggregates'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-3 border-b border-gray-100 flex items-center gap-2">
            <span class="font-semibold text-gray-700">Continuous Aggregates</span>
            <span class="inline-block px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-700"><?php echo count($tsData['aggregates']); ?></span>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                <tr>
                    <th class="px-4 py-2 text-left">View</th>
                    <th class="px-4 py-2 text-left">Materialization</th>
                    <th class="px-4 py-2 text-left">Compression</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($tsData['aggregates'] as $ca): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-mono text-xs"><?php echo htmlspecialchars($ca['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="px-4 py-2 text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($ca['materialization_hypertable_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="px-4 py-2">
                        <?php $comp = $ca['compression_enabled'] ?? false; ?>
                        <span class="inline-block px-1.5 py-0.5 rounded text-xs <?php echo $comp ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>"><?php echo $comp ? 'On' : 'Off'; ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['jobs'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-3 border-b border-gray-100 font-semibold text-gray-700">Scheduled Jobs</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">ID</th>
                        <th class="px-4 py-2 text-left">Procedure</th>
                        <th class="px-4 py-2 text-left">Interval</th>
                        <th class="px-4 py-2 text-left">Last Run</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Next</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($tsData['jobs'] as $job): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs text-gray-500"><?php echo (int) ($job['job_id'] ?? 0); ?></td>
                        <td class="px-4 py-2 font-mono text-xs"><?php echo htmlspecialchars($job['proc_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($job['schedule_interval'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs text-gray-400"><?php echo !empty($job['last_run_started_at']) ? htmlspecialchars($job['last_run_started_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td class="px-4 py-2">
                            <?php $ls = $job['last_run_status'] ?? '—'; ?>
                            <span class="inline-block px-1.5 py-0.5 rounded text-xs <?php echo $ls === 'Success' ? 'bg-green-100 text-green-700' : ($ls === '—' ? 'bg-gray-100 text-gray-500' : 'bg-red-100 text-red-700'); ?>"><?php echo htmlspecialchars($ls, ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-400"><?php echo !empty($job['next_start']) ? htmlspecialchars($job['next_start'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
