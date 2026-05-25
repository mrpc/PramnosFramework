<?php
/**
 * Database details page (Bootstrap theme).
 *
 * Variables:
 *   $this->stats      — array from DatabaseStatsService::getStats()
 *   $this->processes  — array of active process rows
 *   $this->tableSizes — array of table size rows
 *   $this->tsData     — array: hypertables, aggregates, jobs, ts_version (PostgreSQL/TimescaleDB only)
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
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="<?php echo sURL; ?>dashboard" class="btn btn-sm btn-outline-secondary">&larr; Dashboard</a>
        <h2 class="mb-0">Database Details</h2>
        <?php if (!empty($stats['version'])): ?>
            <span class="badge bg-secondary"><?php echo htmlspecialchars($stats['version'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <?php if (!empty($tsData['ts_version'])): ?>
            <span class="badge bg-info text-dark">TimescaleDB <?php echo htmlspecialchars($tsData['ts_version'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
    </div>

    <!-- Overview cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-primary mb-1">
                        <?php echo $stats['db_size_bytes'] !== null ? $fmtBytes((int) $stats['db_size_bytes']) : '—'; ?>
                    </div>
                    <div class="text-muted small">Database Size</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-success mb-1"><?php echo $stats['connections_total'] ?? '—'; ?></div>
                    <div class="text-muted small">Connections Total</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-warning mb-1"><?php echo $stats['connections_active'] ?? '—'; ?></div>
                    <div class="text-muted small">Active Connections</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <?php $chr = $stats['cache_hit_ratio'] ?? null; ?>
                    <div class="h4 fw-bold <?php echo $chr !== null && $chr >= 95 ? 'text-success' : ($chr !== null && $chr >= 80 ? 'text-warning' : 'text-danger'); ?> mb-1">
                        <?php echo $chr !== null ? $chr . '%' : '—'; ?>
                    </div>
                    <div class="text-muted small">Cache Hit Ratio</div>
                </div>
            </div>
        </div>
        <?php if ($dbType === 'postgresql' && isset($stats['xact_commit'])): ?>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-info mb-1"><?php echo number_format((int) $stats['xact_commit']); ?></div>
                    <div class="text-muted small">Transactions Committed</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-danger mb-1"><?php echo number_format((int) $stats['xact_rollback']); ?></div>
                    <div class="text-muted small">Transactions Rolled Back</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($dbType === 'mysql' && isset($stats['queries'])): ?>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h4 fw-bold text-info mb-1"><?php echo number_format((int) $stats['queries']); ?></div>
                    <div class="text-muted small">Total Queries</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Active Processes -->
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            Active Processes
            <span class="badge bg-secondary"><?php echo count($processes); ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($processes)): ?>
                <p class="text-muted text-center py-4 mb-0">No active processes.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <?php if ($dbType === 'postgresql'): ?>
                        <tr><th>PID</th><th>User</th><th>App</th><th>State</th><th>Wait</th><th>Duration</th><th>Query (truncated)</th></tr>
                        <?php else: ?>
                        <tr><th>ID</th><th>User</th><th>DB</th><th>Command</th><th>Time</th><th>State</th><th>Info</th></tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                    <?php foreach ($processes as $p): ?>
                        <?php if ($dbType === 'postgresql'): ?>
                        <tr>
                            <td><code><?php echo (int) ($p['pid'] ?? 0); ?></code></td>
                            <td><?php echo htmlspecialchars($p['usename'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($p['application_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge bg-<?php echo ($p['state'] ?? '') === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($p['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="text-muted"><?php echo htmlspecialchars($p['wait_event'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php $d = (int) ($p['duration_sec'] ?? 0); echo $d > 0 ? $d . 's' : '—'; ?></td>
                            <td class="text-muted font-monospace" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?php echo htmlspecialchars($p['query'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <td><code><?php echo (int) ($p['Id'] ?? 0); ?></code></td>
                            <td><?php echo htmlspecialchars($p['User'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($p['db'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($p['Command'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int) ($p['Time'] ?? 0); ?>s</td>
                            <td class="text-muted"><?php echo htmlspecialchars($p['State'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted font-monospace small" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
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
    </div>

    <!-- Table Sizes -->
    <?php if (!empty($tableSizes)): ?>
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
                    <?php foreach ($tableSizes as $t): ?>
                        <tr>
                            <td class="font-monospace">
                                <?php if (!empty($t['schemaname']) && $t['schemaname'] !== 'public'): ?>
                                    <span class="text-muted"><?php echo htmlspecialchars($t['schemaname'], ENT_QUOTES, 'UTF-8'); ?>.</span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($t['table_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="text-end text-muted"><?php echo $t['row_estimate'] !== null ? number_format((int) $t['row_estimate']) : '—'; ?></td>
                            <td class="text-end"><?php echo $t['data_bytes'] !== null ? $fmtBytes((int) $t['data_bytes']) : '—'; ?></td>
                            <td class="text-end text-muted"><?php echo $t['index_bytes'] !== null ? $fmtBytes((int) $t['index_bytes']) : '—'; ?></td>
                            <td class="text-end fw-semibold"><?php echo $t['total_bytes'] !== null ? $fmtBytes((int) $t['total_bytes']) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- TimescaleDB section (PostgreSQL only) -->
    <?php if (!empty($tsData['ts_version'])): ?>

    <?php if (!empty($tsData['hypertables'])): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            Hypertables
            <span class="badge bg-info text-dark"><?php echo count($tsData['hypertables']); ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light">
                    <tr><th>Name</th><th class="text-end">Chunks</th><th class="text-end">Dimensions</th><th>Compression</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tsData['hypertables'] as $ht): ?>
                    <tr>
                        <td class="font-monospace"><?php echo htmlspecialchars($ht['hypertable_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-end"><?php echo (int) ($ht['num_chunks'] ?? 0); ?></td>
                        <td class="text-end"><?php echo (int) ($ht['num_dimensions'] ?? 0); ?></td>
                        <td>
                            <?php $comp = $ht['compression_enabled'] ?? false; ?>
                            <span class="badge bg-<?php echo $comp ? 'success' : 'secondary'; ?>"><?php echo $comp ? 'On' : 'Off'; ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['aggregates'])): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            Continuous Aggregates
            <span class="badge bg-info text-dark"><?php echo count($tsData['aggregates']); ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light">
                    <tr><th>View</th><th>Materialization Table</th><th>Compression</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tsData['aggregates'] as $ca): ?>
                    <tr>
                        <td class="font-monospace"><?php echo htmlspecialchars($ca['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-muted font-monospace small"><?php echo htmlspecialchars($ca['materialization_hypertable_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php $comp = $ca['compression_enabled'] ?? false; ?>
                            <span class="badge bg-<?php echo $comp ? 'success' : 'secondary'; ?>"><?php echo $comp ? 'On' : 'Off'; ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['jobs'])): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Scheduled Jobs</div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light">
                    <tr><th>ID</th><th>Procedure</th><th>Interval</th><th>Last Run</th><th>Status</th><th>Next Run</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tsData['jobs'] as $job): ?>
                    <tr>
                        <td><code><?php echo (int) ($job['job_id'] ?? 0); ?></code></td>
                        <td class="font-monospace"><?php echo htmlspecialchars($job['proc_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-muted"><?php echo htmlspecialchars($job['schedule_interval'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-muted small"><?php echo !empty($job['last_run_started_at']) ? htmlspecialchars($job['last_run_started_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td>
                            <?php $ls = $job['last_run_status'] ?? '—'; ?>
                            <span class="badge bg-<?php echo $ls === 'Success' ? 'success' : ($ls === '—' ? 'secondary' : 'danger'); ?>"><?php echo htmlspecialchars($ls, ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td class="text-muted small"><?php echo !empty($job['next_start']) ? htmlspecialchars($job['next_start'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
