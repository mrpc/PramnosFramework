<?php
/**
 * Database details page (plain-CSS theme).
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

$card = 'border:1px solid #ddd;border-radius:4px;margin-bottom:16px;overflow:hidden';
?>
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
        <a href="<?php echo sURL; ?>dashboard" style="font-size:.85rem">&larr; Dashboard</a>
        <h2 style="margin:0">Database Details</h2>
        <?php if (!empty($stats['version'])): ?>
            <span class="db-badge db-badge-secondary"><?php echo htmlspecialchars($stats['version'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <?php if (!empty($tsData['ts_version'])): ?>
            <span class="db-badge db-badge-info">TimescaleDB <?php echo htmlspecialchars($tsData['ts_version'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
    </div>

    <!-- Overview cards -->
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div style="flex:1;min-width:150px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.4rem;font-weight:700;color:#0d6efd">
                <?php echo $stats['db_size_bytes'] !== null ? $fmtBytes((int) $stats['db_size_bytes']) : '—'; ?>
            </div>
            <div style="font-size:.8rem;color:#888">Database Size</div>
        </div>
        <div style="flex:1;min-width:150px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.4rem;font-weight:700;color:#198754"><?php echo $stats['connections_total'] ?? '—'; ?></div>
            <div style="font-size:.8rem;color:#888">Connections Total</div>
        </div>
        <div style="flex:1;min-width:150px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.4rem;font-weight:700;color:#ffc107"><?php echo $stats['connections_active'] ?? '—'; ?></div>
            <div style="font-size:.8rem;color:#888">Active Connections</div>
        </div>
        <div style="flex:1;min-width:150px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <?php $chr = $stats['cache_hit_ratio'] ?? null;
            $chrColor = $chr !== null && (float)$chr >= 95 ? '#198754' : ($chr !== null && (float)$chr >= 80 ? '#fd7e14' : '#dc3545'); ?>
            <div style="font-size:1.4rem;font-weight:700;color:<?php echo $chrColor; ?>">
                <?php echo $chr !== null ? $chr . '%' : '—'; ?>
            </div>
            <div style="font-size:.8rem;color:#888">Cache Hit Ratio</div>
        </div>
    </div>

    <!-- Active Processes -->
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd;display:flex;justify-content:space-between">
            <span>Active Processes</span>
            <span class="db-badge db-badge-secondary"><?php echo count($processes); ?></span>
        </div>
        <div style="overflow-x:auto">
            <?php if (empty($processes)): ?>
                <p style="text-align:center;color:#888;padding:16px 0">No active processes.</p>
            <?php else: ?>
            <table class="db-table">
                <thead>
                    <?php if ($dbType === 'postgresql'): ?>
                    <tr><th>PID</th><th>User</th><th>Database</th><th>Application</th><th>IP</th><th>Backend Start</th><th>Running</th><th>State</th><th>Query</th></tr>
                    <?php else: ?>
                    <tr><th>ID</th><th>User</th><th>DB</th><th>Command</th><th>Time</th><th>State</th><th>Info</th></tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                <?php foreach ($processes as $p): ?>
                    <?php if ($dbType === 'postgresql'): ?>
                    <?php $durSec = (int) ($p['duration_sec'] ?? 0); ?>
                    <tr>
                        <td class="db-mono"><?php echo (int) ($p['pid'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($p['usename'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="color:#888"><?php echo htmlspecialchars($p['datname'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="color:#888"><?php echo htmlspecialchars($p['application_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="db-mono" style="color:#888;font-size:.8rem"><?php echo htmlspecialchars($p['client_addr'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="color:#888;font-size:.8rem"><?php echo htmlspecialchars($p['backend_start'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($durSec > 0):
                                $cls = $durSec > 300 ? 'danger' : ($durSec > 60 ? 'warning' : 'info');
                                $min = intdiv($durSec, 60);
                                $sec = $durSec % 60;
                                $txt = $min > 0 ? "{$min}m {$sec}s" : "{$durSec}s"; ?>
                                <span class="db-badge db-badge-<?php echo $cls; ?>"><?php echo $txt; ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <span class="db-badge db-badge-<?php echo ($p['state'] ?? '') === 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo htmlspecialchars($p['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td style="max-width:260px">
                            <?php if (!empty($p['query'])): ?>
                            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button class="db-btn" data-copy-query="<?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?>">Copy</button>
                                <span class="db-mono" style="font-size:.8rem"><?php echo htmlspecialchars($p['query'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <?php else: ?><em style="color:#aaa">—</em><?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td class="db-mono"><?php echo (int) ($p['Id'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($p['User'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="color:#888"><?php echo htmlspecialchars($p['db'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($p['Command'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int) ($p['Time'] ?? 0); ?>s</td>
                        <td style="color:#888"><?php echo htmlspecialchars($p['State'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="db-mono" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.8rem">
                            <?php echo htmlspecialchars($p['Info'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Replication Status (PostgreSQL only) -->
    <?php if ($dbType === 'postgresql'): ?>
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Replication Status</div>
        <div style="overflow-x:auto">
            <?php if (empty($replication)): ?>
                <p style="text-align:center;color:#888;padding:16px 0">No replication configured. Standalone instance or replica.</p>
            <?php else: ?>
            <table class="db-table">
                <thead><tr><th>Client Address</th><th>State</th><th>Sync State</th><th>Replication Lag</th></tr></thead>
                <tbody>
                <?php foreach ($replication as $repl): ?>
                    <?php $lagSec = (int) ($repl['lag_sec'] ?? 0); ?>
                    <tr>
                        <td class="db-mono"><?php echo htmlspecialchars($repl['client_addr'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="db-badge db-badge-<?php echo ($repl['state'] ?? '') === 'streaming' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars($repl['state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><span class="db-badge db-badge-<?php echo ($repl['sync_state'] ?? '') === 'sync' ? 'primary' : 'secondary'; ?>"><?php echo htmlspecialchars($repl['sync_state'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td>
                            <?php if (($repl['sync_state'] ?? '') === 'sync'): ?>
                                <span class="db-badge db-badge-success">In Sync</span>
                            <?php elseif ($lagSec > 0):
                                $lagCls = $lagSec > 300 ? 'danger' : ($lagSec > 60 ? 'warning' : 'success');
                                $lagTxt = $lagSec > 60 ? intdiv($lagSec, 60) . 'm ' . ($lagSec % 60) . 's' : $lagSec . 's'; ?>
                                <span class="db-badge db-badge-<?php echo $lagCls; ?>"><?php echo $lagTxt; ?></span>
                            <?php else: ?>
                                <span class="db-badge db-badge-secondary">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Table Sizes -->
    <?php if (!empty($tableSizes)): ?>
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Table Sizes (top 30)</div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>Table</th><th style="text-align:right">Rows</th><th style="text-align:right">Data</th><th style="text-align:right">Indexes</th><th style="text-align:right">Total</th></tr></thead>
                <tbody>
                <?php foreach ($tableSizes as $t): ?>
                    <tr>
                        <td class="db-mono">
                            <?php if (!empty($t['schemaname']) && $t['schemaname'] !== 'public'): ?>
                                <span style="color:#aaa"><?php echo htmlspecialchars($t['schemaname'], ENT_QUOTES, 'UTF-8'); ?>.</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($t['table_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td style="text-align:right;color:#888"><?php echo $t['row_estimate'] !== null ? number_format((int) $t['row_estimate']) : '—'; ?></td>
                        <td style="text-align:right"><?php echo $t['data_bytes'] !== null ? $fmtBytes((int) $t['data_bytes']) : '—'; ?></td>
                        <td style="text-align:right;color:#888"><?php echo $t['index_bytes'] !== null ? $fmtBytes((int) $t['index_bytes']) : '—'; ?></td>
                        <td style="text-align:right;font-weight:600"><?php echo $t['total_bytes'] !== null ? $fmtBytes((int) $t['total_bytes']) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Public Schema Views (PostgreSQL only) -->
    <?php if ($dbType === 'postgresql' && !empty($publicViews)): ?>
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd;display:flex;justify-content:space-between">
            <span>Public Schema Views</span>
            <span class="db-badge db-badge-secondary"><?php echo count($publicViews); ?></span>
        </div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>View Name</th><th>Definition (truncated)</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($publicViews as $i => $v): ?>
                    <tr>
                        <td class="db-mono" style="font-weight:600"><?php echo htmlspecialchars($v['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="db-mono" style="max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#888;font-size:.8rem">
                            <?php echo htmlspecialchars(substr($v['view_definition'] ?? '', 0, 120), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <?php if (!empty($v['view_definition'])): ?>
                            <button class="db-btn" data-view-def-index="<?php echo (int) $i; ?>">View</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="viewDefOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000">
        <div style="position:relative;margin:3% auto;background:#fff;border-radius:6px;width:820px;max-width:96%;padding:20px;max-height:90vh;overflow-y:auto">
            <h6 id="viewDefTitle" style="margin:0 0 10px"></h6>
            <pre id="viewDefBody" style="white-space:pre-wrap;font-size:.8rem;max-height:400px;overflow-y:auto;background:#f8f8f8;padding:12px;border-radius:4px"></pre>
            <div style="text-align:right;margin-top:10px">
                <button id="closeViewDefBtn" class="db-btn">Close</button>
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

    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Storage Information</div>
        <div style="padding:16px;display:flex;gap:16px;flex-wrap:wrap">
            <div style="flex:1;min-width:140px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
                <div style="font-size:1.4rem;font-weight:700;color:#0dcaf0"><?php echo (int) ($tsData['chunkCount'] ?? 0); ?></div>
                <div style="font-size:.8rem;color:#888">Total Chunks</div>
            </div>
            <div style="flex:1;min-width:140px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
                <?php $cc = 0; foreach ($tsData['hypertables'] as $ht) { if (!empty($ht['compression_enabled'])) $cc++; } ?>
                <div style="font-size:1.4rem;font-weight:700;color:#198754"><?php echo $cc; ?></div>
                <div style="font-size:.8rem;color:#888">Compressed Hypertables</div>
            </div>
        </div>
    </div>

    <?php if (!empty($tsData['hypertables'])): ?>
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Hypertables</div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>Name</th><th>Chunks</th><th>Dimensions</th><th>Compression</th><th>Tablespaces</th></tr></thead>
                <tbody>
                <?php foreach ($tsData['hypertables'] as $ht): ?>
                    <tr>
                        <td class="db-mono"><?php echo htmlspecialchars($ht['hypertable_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int) ($ht['num_chunks'] ?? 0); ?></td>
                        <td><?php echo (int) ($ht['num_dimensions'] ?? 0); ?></td>
                        <td>
                            <?php $comp = $ht['compression_enabled'] ?? false; ?>
                            <span class="db-badge db-badge-<?php echo $comp ? 'success' : 'secondary'; ?>"><?php echo $comp ? 'On' : 'Off'; ?></span>
                        </td>
                        <td style="color:#888;font-size:.8rem"><?php echo htmlspecialchars($ht['tablespaces'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['aggregates'])): ?>
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Continuous Aggregates</div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>Schema</th><th>View</th><th>Mat. Schema</th><th>Mat. Table</th><th>Mat. Only</th><th>Compression</th></tr></thead>
                <tbody>
                <?php foreach ($tsData['aggregates'] as $ca): ?>
                    <tr>
                        <td style="color:#888"><?php echo htmlspecialchars($ca['view_schema'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="db-mono"><?php echo htmlspecialchars($ca['view_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="color:#888;font-size:.8rem"><?php echo htmlspecialchars($ca['materialization_schema'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="db-mono" style="color:#888;font-size:.8rem"><?php echo htmlspecialchars($ca['materialization_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php $mo = $ca['materialized_only'] ?? 'No'; ?>
                            <span class="db-badge db-badge-<?php echo $mo === 'Yes' ? 'info' : 'secondary'; ?>"><?php echo htmlspecialchars($mo, ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td>
                            <?php $comp = $ca['compression_enabled'] ?? false; ?>
                            <span class="db-badge db-badge-<?php echo $comp ? 'success' : 'secondary'; ?>"><?php echo $comp ? 'On' : 'Off'; ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($tsData['jobs'])): ?>
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Scheduled Jobs</div>
        <div style="overflow-x:auto">
            <table class="db-table">
                <thead><tr><th>ID</th><th>Procedure</th><th>Interval</th><th>Last Run</th><th>Status</th><th>Next Run</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tsData['jobs'] as $job): ?>
                    <tr>
                        <td class="db-mono"><?php echo (int) ($job['job_id'] ?? 0); ?></td>
                        <td class="db-mono">
                            <?php if (!empty($job['proc_schema']) && $job['proc_schema'] !== '_timescaledb_internal'): ?>
                                <span style="color:#aaa"><?php echo htmlspecialchars($job['proc_schema'], ENT_QUOTES, 'UTF-8'); ?>.</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($job['proc_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td style="color:#888"><?php echo htmlspecialchars($job['schedule_interval'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="color:#888;font-size:.8rem"><?php echo !empty($job['last_run_started_at']) ? htmlspecialchars($job['last_run_started_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td>
                            <?php $ls = $job['last_run_status'] ?? '—'; ?>
                            <span class="db-badge db-badge-<?php echo $ls === 'Success' ? 'success' : ($ls === '—' ? 'secondary' : 'danger'); ?>"><?php echo htmlspecialchars($ls, ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td style="color:#888;font-size:.8rem"><?php echo !empty($job['next_start']) ? htmlspecialchars($job['next_start'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td>
                            <button class="db-btn" style="border-color:#dc3545;color:#dc3545"
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
                    tr.innerHTML = '<td style="font-size:.8rem">' + (r.start_time || '—') + '</td>'
                        + '<td style="font-size:.8rem">' + (r.finish_time || '—') + '</td>'
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
