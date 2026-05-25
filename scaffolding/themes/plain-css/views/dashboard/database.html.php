<?php
/**
 * Database details page (plain-CSS theme).
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
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
        <a href="<?php echo sURL; ?>dashboard" class="btn btn-outline-secondary">&larr; Dashboard</a>
        <h2 style="margin:0">Database Details</h2>
        <?php if (!empty($stats['version'])): ?>
            <span style="background:#e9ecef;padding:2px 8px;border-radius:4px;font-size:12px"><?php echo htmlspecialchars($stats['version'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <?php if (!empty($tsData['ts_version'])): ?>
            <span style="background:#cff4fc;color:#055160;padding:2px 8px;border-radius:4px;font-size:12px">TimescaleDB <?php echo htmlspecialchars($tsData['ts_version'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
    </div>

    <!-- Overview cards -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
        <div class="card" style="text-align:center;padding:16px">
            <div style="font-size:24px;font-weight:700;color:#0d6efd;margin-bottom:4px">
                <?php echo $stats['db_size_bytes'] !== null ? $fmtBytes((int)$stats['db_size_bytes']) : '—'; ?>
            </div>
            <div style="font-size:12px;color:#888">Database Size</div>
        </div>
        <div class="card" style="text-align:center;padding:16px">
            <div style="font-size:24px;font-weight:700;color:#198754;margin-bottom:4px"><?php echo $stats['connections_total'] ?? '—'; ?></div>
            <div style="font-size:12px;color:#888">Connections Total</div>
        </div>
        <div class="card" style="text-align:center;padding:16px">
            <div style="font-size:24px;font-weight:700;color:#856404;margin-bottom:4px"><?php echo $stats['connections_active'] ?? '—'; ?></div>
            <div style="font-size:12px;color:#888">Active Connections</div>
        </div>
        <div class="card" style="text-align:center;padding:16px">
            <?php $chr = $stats['cache_hit_ratio'] ?? null; ?>
            <div style="font-size:24px;font-weight:700;margin-bottom:4px;color:<?php echo $chr !== null && $chr >= 95 ? '#198754' : ($chr !== null && $chr >= 80 ? '#856404' : '#dc3545'); ?>">
                <?php echo $chr !== null ? $chr . '%' : '—'; ?>
            </div>
            <div style="font-size:12px;color:#888">Cache Hit Ratio</div>
        </div>
        <?php if ($dbType === 'postgresql' && isset($stats['xact_commit'])): ?>
        <div class="card" style="text-align:center;padding:16px">
            <div style="font-size:22px;font-weight:700;color:#0dcaf0;margin-bottom:4px"><?php echo number_format((int)$stats['xact_commit']); ?></div>
            <div style="font-size:12px;color:#888">Commits</div>
        </div>
        <div class="card" style="text-align:center;padding:16px">
            <div style="font-size:22px;font-weight:700;color:#dc3545;margin-bottom:4px"><?php echo number_format((int)$stats['xact_rollback']); ?></div>
            <div style="font-size:12px;color:#888">Rollbacks</div>
        </div>
        <?php endif; ?>
        <?php if ($dbType === 'mysql' && isset($stats['queries'])): ?>
        <div class="card" style="text-align:center;padding:16px">
            <div style="font-size:22px;font-weight:700;color:#0dcaf0;margin-bottom:4px"><?php echo number_format((int)$stats['queries']); ?></div>
            <div style="font-size:12px;color:#888">Total Queries</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Active Processes -->
    <div class="card" style="margin-bottom:20px">
        <div style="padding:10px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
            <span style="font-weight:600">Active Processes</span>
            <span style="background:#e9ecef;padding:2px 8px;border-radius:10px;font-size:12px"><?php echo count($processes); ?></span>
        </div>
        <?php if (empty($processes)): ?>
            <div style="text-align:center;color:#888;padding:24px;font-size:13px">No active processes.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead style="background:#f5f5f5">
                    <?php if ($dbType === 'postgresql'): ?>
                    <tr>
                        <th style="padding:7px 12px;text-align:left">PID</th>
                        <th style="padding:7px 12px;text-align:left">User</th>
                        <th style="padding:7px 12px;text-align:left">App</th>
                        <th style="padding:7px 12px;text-align:left">State</th>
                        <th style="padding:7px 12px;text-align:left">Wait</th>
                        <th style="padding:7px 12px;text-align:left">Dur.</th>
                        <th style="padding:7px 12px;text-align:left">Query</th>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th style="padding:7px 12px;text-align:left">ID</th>
                        <th style="padding:7px 12px;text-align:left">User</th>
                        <th style="padding:7px 12px;text-align:left">DB</th>
                        <th style="padding:7px 12px;text-align:left">Command</th>
                        <th style="padding:7px 12px;text-align:left">Time</th>
                        <th style="padding:7px 12px;text-align:left">State</th>
                        <th style="padding:7px 12px;text-align:left">Info</th>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                <?php foreach ($processes as $p): ?>
                    <?php if ($dbType === 'postgresql'): ?>
                    <tr style="border-top:1px solid #f0f0f0">
                        <td style="padding:5px 12px;font-family:monospace;color:#666"><?php echo (int)($p['pid'] ?? 0); ?></td>
                        <td style="padding:5px 12px"><?php echo htmlspecialchars($p['usename'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px;color:#888"><?php echo htmlspecialchars($p['application_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px">
                            <span style="background:<?php echo ($p['state'] ?? '') === 'active' ? '#d1e7dd' : '#e9ecef'; ?>;color:<?php echo ($p['state'] ?? '') === 'active' ? '#0a3622' : '#444'; ?>;padding:1px 6px;border-radius:3px;font-size:11px">
                                <?php echo htmlspecialchars($p['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td style="padding:5px 12px;color:#888"><?php echo htmlspecialchars($p['wait_event'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px"><?php $d = (int)($p['duration_sec'] ?? 0); echo $d > 0 ? $d . 's' : '—'; ?></td>
                        <td style="padding:5px 12px;font-family:monospace;color:#666;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?php echo htmlspecialchars($p['query'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr style="border-top:1px solid #f0f0f0">
                        <td style="padding:5px 12px;font-family:monospace;color:#666"><?php echo (int)($p['Id'] ?? 0); ?></td>
                        <td style="padding:5px 12px"><?php echo htmlspecialchars($p['User'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px;color:#888"><?php echo htmlspecialchars($p['db'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px"><?php echo htmlspecialchars($p['Command'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px"><?php echo (int)($p['Time'] ?? 0); ?>s</td>
                        <td style="padding:5px 12px;color:#888"><?php echo htmlspecialchars($p['State'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px;font-family:monospace;color:#666;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
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

    <!-- Table Sizes -->
    <?php if (!empty($tableSizes)): ?>
    <div class="card" style="margin-bottom:20px">
        <div style="padding:10px 16px;border-bottom:1px solid #eee;font-weight:600">Table Sizes (top 30)</div>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead style="background:#f5f5f5">
                    <tr>
                        <th style="padding:7px 12px;text-align:left">Table</th>
                        <th style="padding:7px 12px;text-align:right">Rows (est.)</th>
                        <th style="padding:7px 12px;text-align:right">Data</th>
                        <th style="padding:7px 12px;text-align:right">Indexes</th>
                        <th style="padding:7px 12px;text-align:right">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tableSizes as $t): ?>
                    <tr style="border-top:1px solid #f0f0f0">
                        <td style="padding:5px 12px;font-family:monospace">
                            <?php if (!empty($t['schemaname']) && $t['schemaname'] !== 'public'): ?>
                                <span style="color:#aaa"><?php echo htmlspecialchars($t['schemaname'], ENT_QUOTES, 'UTF-8'); ?>.</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($t['table_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td style="padding:5px 12px;text-align:right;color:#888"><?php echo $t['row_estimate'] !== null ? number_format((int)$t['row_estimate']) : '—'; ?></td>
                        <td style="padding:5px 12px;text-align:right"><?php echo $t['data_bytes'] !== null ? $fmtBytes((int)$t['data_bytes']) : '—'; ?></td>
                        <td style="padding:5px 12px;text-align:right;color:#888"><?php echo $t['index_bytes'] !== null ? $fmtBytes((int)$t['index_bytes']) : '—'; ?></td>
                        <td style="padding:5px 12px;text-align:right;font-weight:600"><?php echo $t['total_bytes'] !== null ? $fmtBytes((int)$t['total_bytes']) : '—'; ?></td>
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
    <div class="card" style="margin-bottom:20px">
        <div style="padding:10px 16px;border-bottom:1px solid #eee;display:flex;align-items:center;gap:8px">
            <span style="font-weight:600">Hypertables</span>
            <span style="background:#cff4fc;color:#055160;padding:1px 8px;border-radius:10px;font-size:12px"><?php echo count($tsData['hypertables']); ?></span>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead style="background:#f5f5f5">
                <tr>
                    <th style="padding:7px 12px;text-align:left">Name</th>
                    <th style="padding:7px 12px;text-align:right">Chunks</th>
                    <th style="padding:7px 12px;text-align:right">Dims</th>
                    <th style="padding:7px 12px;text-align:left">Compression</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tsData['hypertables'] as $ht): ?>
                <tr style="border-top:1px solid #f0f0f0">
                    <td style="padding:5px 12px;font-family:monospace"><?php echo htmlspecialchars($ht['hypertable_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:5px 12px;text-align:right"><?php echo (int)($ht['num_chunks'] ?? 0); ?></td>
                    <td style="padding:5px 12px;text-align:right"><?php echo (int)($ht['num_dimensions'] ?? 0); ?></td>
                    <td style="padding:5px 12px">
                        <?php $comp = $ht['compression_enabled'] ?? false; ?>
                        <span style="background:<?php echo $comp ? '#d1e7dd' : '#e9ecef'; ?>;color:<?php echo $comp ? '#0a3622' : '#444'; ?>;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600">
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
    <div class="card" style="margin-bottom:20px">
        <div style="padding:10px 16px;border-bottom:1px solid #eee;display:flex;align-items:center;gap:8px">
            <span style="font-weight:600">Continuous Aggregates</span>
            <span style="background:#cff4fc;color:#055160;padding:1px 8px;border-radius:10px;font-size:12px"><?php echo count($tsData['aggregates']); ?></span>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead style="background:#f5f5f5">
                <tr>
                    <th style="padding:7px 12px;text-align:left">View</th>
                    <th style="padding:7px 12px;text-align:left">Materialization</th>
                    <th style="padding:7px 12px;text-align:left">Compression</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tsData['aggregates'] as $ca): ?>
                <tr style="border-top:1px solid #f0f0f0">
                    <td style="padding:5px 12px;font-family:monospace"><?php echo htmlspecialchars($ca['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:5px 12px;color:#888;font-family:monospace;font-size:11px"><?php echo htmlspecialchars($ca['materialization_hypertable_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:5px 12px">
                        <?php $comp = $ca['compression_enabled'] ?? false; ?>
                        <span style="background:<?php echo $comp ? '#d1e7dd' : '#e9ecef'; ?>;color:<?php echo $comp ? '#0a3622' : '#444'; ?>;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600">
                            <?php echo $comp ? 'On' : 'Off'; ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['jobs'])): ?>
    <div class="card" style="margin-bottom:20px">
        <div style="padding:10px 16px;border-bottom:1px solid #eee;font-weight:600">Scheduled Jobs</div>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead style="background:#f5f5f5">
                    <tr>
                        <th style="padding:7px 12px;text-align:left">ID</th>
                        <th style="padding:7px 12px;text-align:left">Procedure</th>
                        <th style="padding:7px 12px;text-align:left">Interval</th>
                        <th style="padding:7px 12px;text-align:left">Last Run</th>
                        <th style="padding:7px 12px;text-align:left">Status</th>
                        <th style="padding:7px 12px;text-align:left">Next</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tsData['jobs'] as $job): ?>
                    <tr style="border-top:1px solid #f0f0f0">
                        <td style="padding:5px 12px;font-family:monospace;color:#666"><?php echo (int)($job['job_id'] ?? 0); ?></td>
                        <td style="padding:5px 12px;font-family:monospace"><?php echo htmlspecialchars($job['proc_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px;color:#888"><?php echo htmlspecialchars($job['schedule_interval'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px;color:#888"><?php echo !empty($job['last_run_started_at']) ? htmlspecialchars($job['last_run_started_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td style="padding:5px 12px">
                            <?php $ls = $job['last_run_status'] ?? '—'; ?>
                            <span style="background:<?php echo $ls === 'Success' ? '#d1e7dd' : ($ls === '—' ? '#e9ecef' : '#f8d7da'); ?>;color:<?php echo $ls === 'Success' ? '#0a3622' : ($ls === '—' ? '#444' : '#842029'); ?>;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600">
                                <?php echo htmlspecialchars($ls, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td style="padding:5px 12px;color:#888"><?php echo !empty($job['next_start']) ? htmlspecialchars($job['next_start'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
