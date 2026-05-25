<?php
/**
 * Database details page (Bootstrap theme).
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
                    <div class="h4 fw-bold <?php echo $chr !== null && (float)$chr >= 95 ? 'text-success' : ($chr !== null && (float)$chr >= 80 ? 'text-warning' : 'text-danger'); ?> mb-1">
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
                        <tr>
                            <th>PID</th><th>User</th><th>Database</th><th>Application</th>
                            <th>IP</th><th>Backend Start</th><th>Running</th><th>State</th><th>Query</th>
                        </tr>
                        <?php else: ?>
                        <tr><th>ID</th><th>User</th><th>DB</th><th>Command</th><th>Time</th><th>State</th><th>Info</th></tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                    <?php foreach ($processes as $p): ?>
                        <?php if ($dbType === 'postgresql'): ?>
                        <?php $durSec = (int) ($p['duration_sec'] ?? 0); ?>
                        <tr>
                            <td><code><?php echo (int) ($p['pid'] ?? 0); ?></code></td>
                            <td><?php echo htmlspecialchars($p['usename'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($p['datname'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($p['application_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted font-monospace small"><?php echo htmlspecialchars($p['client_addr'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted small"><?php echo htmlspecialchars($p['backend_start'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($durSec > 0):
                                    $cls = $durSec > 300 ? 'danger' : ($durSec > 60 ? 'warning' : 'info');
                                    $min = intdiv($durSec, 60);
                                    $sec = $durSec % 60;
                                    $txt = $min > 0 ? "{$min}m {$sec}s" : "{$durSec}s";
                                ?>
                                    <span class="badge bg-<?php echo $cls; ?>"><?php echo $txt; ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo ($p['state'] ?? '') === 'active' ? 'success' : 'secondary'; ?>">
                                    <?php echo htmlspecialchars($p['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="font-monospace" style="max-width:300px">
                                <?php if (!empty($p['query'])): ?>
                                <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 me-1"
                                            data-copy-query="<?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?>">Copy</button>
                                    <span class="small"><?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <?php else: ?><em class="text-muted small">—</em><?php endif; ?>
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

    <!-- Replication Status (PostgreSQL only) -->
    <?php if ($dbType === 'postgresql'): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Replication Status</div>
        <div class="card-body p-0">
            <?php if (empty($replication)): ?>
                <p class="text-muted text-center py-4 mb-0">No replication configured. Standalone instance or replica.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Client Address</th><th>State</th><th>Sync State</th><th>Replication Lag</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($replication as $repl): ?>
                        <?php $lagSec = (int) ($repl['lag_sec'] ?? 0); ?>
                        <tr>
                            <td class="font-monospace"><?php echo htmlspecialchars($repl['client_addr'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo ($repl['state'] ?? '') === 'streaming' ? 'success' : 'warning'; ?>">
                                    <?php echo htmlspecialchars($repl['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo ($repl['sync_state'] ?? '') === 'sync' ? 'primary' : 'secondary'; ?>">
                                    <?php echo htmlspecialchars($repl['sync_state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (($repl['sync_state'] ?? '') === 'sync'): ?>
                                    <span class="badge bg-success">In Sync</span>
                                <?php elseif ($lagSec > 0):
                                    $lagCls = $lagSec > 300 ? 'danger' : ($lagSec > 60 ? 'warning' : 'success');
                                    $lagTxt = $lagSec > 60 ? intdiv($lagSec, 60) . 'm ' . ($lagSec % 60) . 's' : $lagSec . 's';
                                ?>
                                    <span class="badge bg-<?php echo $lagCls; ?>"><?php echo $lagTxt; ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

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

    <!-- Public Schema Views (PostgreSQL only) -->
    <?php if ($dbType === 'postgresql' && !empty($publicViews)): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            Public Schema Views
            <span class="badge bg-secondary"><?php echo count($publicViews); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>View Name</th><th>Definition (truncated)</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($publicViews as $i => $v): ?>
                        <tr>
                            <td class="font-monospace fw-semibold"><?php echo htmlspecialchars($v['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted small font-monospace" style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?php echo htmlspecialchars(substr($v['view_definition'] ?? '', 0, 120), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <?php if (!empty($v['view_definition'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-info py-0"
                                        data-view-def-index="<?php echo (int) $i; ?>">View</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- View definition modal -->
    <div class="modal fade" id="viewDefModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewDefModalTitle">View Definition</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre id="viewDefModalBody" style="white-space:pre-wrap;max-height:420px;overflow-y:auto;font-size:.8rem"></pre>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    var _publicViews = <?php echo json_encode(array_values($publicViews)); ?>;
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-view-def-index]');
        if (!btn) return;
        var idx = parseInt(btn.getAttribute('data-view-def-index'), 10);
        if (_publicViews[idx]) {
            document.getElementById('viewDefModalTitle').textContent = _publicViews[idx].view_name;
            document.getElementById('viewDefModalBody').textContent  = _publicViews[idx].view_definition;
            new bootstrap.Modal(document.getElementById('viewDefModal')).show();
        }
    });
    </script>
    <?php endif; ?>

    <!-- TimescaleDB section (PostgreSQL only) -->
    <?php if (!empty($tsData['ts_version'])): ?>

    <!-- Storage Information -->
    <div class="card mb-4">
        <div class="card-header fw-semibold">Storage Information</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="border rounded p-3 text-center">
                        <div class="h4 fw-bold text-info mb-1"><?php echo (int) ($tsData['chunkCount'] ?? 0); ?></div>
                        <div class="text-muted small">Total Chunks</div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="border rounded p-3 text-center">
                        <?php
                        $compressedCount = 0;
                        foreach ($tsData['hypertables'] as $ht) {
                            if (!empty($ht['compression_enabled'])) {
                                $compressedCount++;
                            }
                        }
                        ?>
                        <div class="h4 fw-bold text-success mb-1"><?php echo $compressedCount; ?></div>
                        <div class="text-muted small">Compressed Hypertables</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($tsData['hypertables'])): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            Hypertables
            <span class="badge bg-info text-dark"><?php echo count($tsData['hypertables']); ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light">
                    <tr><th>Name</th><th class="text-end">Chunks</th><th class="text-end">Dimensions</th><th>Compression</th><th>Tablespaces</th></tr>
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
                        <td class="text-muted small"><?php echo htmlspecialchars($ht['tablespaces'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
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
                    <tr>
                        <th>Schema</th><th>View</th><th>Mat. Schema</th><th>Mat. Table</th>
                        <th>Materialized Only</th><th>Compression</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tsData['aggregates'] as $ca): ?>
                    <tr>
                        <td class="text-muted"><?php echo htmlspecialchars($ca['view_schema'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="font-monospace"><?php echo htmlspecialchars($ca['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($ca['materialization_schema'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-muted font-monospace small"><?php echo htmlspecialchars($ca['materialization_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php $mo = $ca['materialized_only'] ?? 'No'; ?>
                            <span class="badge bg-<?php echo $mo === 'Yes' ? 'info' : 'secondary'; ?> text-<?php echo $mo === 'Yes' ? 'dark' : 'white'; ?>">
                                <?php echo htmlspecialchars($mo, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
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
                    <tr><th>ID</th><th>Procedure</th><th>Interval</th><th>Last Run</th><th>Status</th><th>Next Run</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($tsData['jobs'] as $job): ?>
                    <tr>
                        <td><code><?php echo (int) ($job['job_id'] ?? 0); ?></code></td>
                        <td class="font-monospace">
                            <?php if (!empty($job['proc_schema']) && $job['proc_schema'] !== '_timescaledb_internal'): ?>
                                <span class="text-muted"><?php echo htmlspecialchars($job['proc_schema'], ENT_QUOTES, 'UTF-8'); ?>.</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($job['proc_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td class="text-muted"><?php echo htmlspecialchars($job['schedule_interval'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-muted small"><?php echo !empty($job['last_run_started_at']) ? htmlspecialchars($job['last_run_started_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td>
                            <?php $ls = $job['last_run_status'] ?? '—'; ?>
                            <span class="badge bg-<?php echo $ls === 'Success' ? 'success' : ($ls === '—' ? 'secondary' : 'danger'); ?>">
                                <?php echo htmlspecialchars($ls, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?php echo !empty($job['next_start']) ? htmlspecialchars($job['next_start'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger py-0"
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
    var _jobHistory = <?php echo json_encode(array_values($tsData['jobHistory'] ?? [])); ?>;
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
                tr.innerHTML = '<td class="small">' + (r.start_time || '—') + '</td>'
                    + '<td class="small">' + (r.finish_time || '—') + '</td>'
                    + '<td><span class="badge bg-' + (ok ? 'success' : 'danger') + '">' + (ok ? 'Success' : 'Failed') + '</span></td>'
                    + '<td class="font-monospace small">' + (r.proc_schema ? r.proc_schema + '.' : '') + (r.proc_name || '—') + '</td>'
                    + '<td class="text-danger small">' + (r.err_message || '') + '</td>';
                tbody.appendChild(tr);
            });
        }
        new bootstrap.Modal(document.getElementById('jobHistoryModal')).show();
    });
    </script>
    <?php endif; ?>

    <?php endif; // end TimescaleDB ?>

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
