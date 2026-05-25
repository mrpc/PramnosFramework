<?php
/**
 * Admin Operations Dashboard (plain-CSS theme).
 *
 * Variables:
 *   $this->activeUsers  — array {now, last_1h, last_24h, last_7d, last_30d}
 *   $this->dbStats      — array from DatabaseStatsService::getStats()
 *   $this->apiStats     — array from ApiPerformanceService::getSummary()
 *   $this->healthResults — array from HealthRegistry::runAll()
 */
?>
<div class="page-section">
    <h2 style="margin-bottom:16px">Admin Dashboard</h2>

    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div style="flex:1;min-width:200px">
            <div class="card text-bg-primary">
                <div class="card-body" style="padding:16px">
                    <div style="font-size:0.8rem;opacity:0.7">Active Users (now)</div>
                    <div style="font-size:1.75rem;font-weight:700"><?php echo (int)($this->activeUsers['now'] ?? 0); ?></div>
                    <div style="font-size:0.8rem;opacity:0.7">Last 24h: <?php echo (int)($this->activeUsers['last_24h'] ?? 0); ?></div>
                </div>
            </div>
        </div>
        <div style="flex:1;min-width:200px">
            <div class="card text-bg-success">
                <div class="card-body" style="padding:16px">
                    <div style="font-size:0.8rem;opacity:0.7">API Requests (24h)</div>
                    <div style="font-size:1.75rem;font-weight:700"><?php echo (int)($this->apiStats['total_requests'] ?? 0); ?></div>
                    <div style="font-size:0.8rem;opacity:0.7">Errors: <?php echo number_format(($this->apiStats['error_rate'] ?? 0) * 100, 1); ?>%</div>
                </div>
            </div>
        </div>
        <div style="flex:1;min-width:200px">
            <div class="card text-bg-info">
                <div class="card-body" style="padding:16px">
                    <div style="font-size:0.8rem;opacity:0.7">Avg Latency (24h)</div>
                    <div style="font-size:1.75rem;font-weight:700"><?php echo number_format($this->apiStats['avg_execution_time'] ?? 0, 0); ?> ms</div>
                    <div style="font-size:0.8rem;opacity:0.7">p95: <?php echo number_format($this->apiStats['p95_execution_time'] ?? 0, 0); ?> ms</div>
                </div>
            </div>
        </div>
        <div style="flex:1;min-width:200px">
            <div class="card text-bg-secondary">
                <div class="card-body" style="padding:16px">
                    <div style="font-size:0.8rem;opacity:0.7">DB Size</div>
                    <div style="font-size:1.75rem;font-weight:700">
                        <?php
                        $bytes = $this->dbStats['db_size_bytes'] ?? 0;
                        echo $bytes > 1048576
                            ? number_format($bytes / 1048576, 1) . ' MB'
                            : number_format($bytes / 1024, 1) . ' KB';
                        ?>
                    </div>
                    <div style="font-size:0.8rem;opacity:0.7">Connections: <?php echo (int)($this->dbStats['connections_active'] ?? 0); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center">
            <span>Database</span>
            <a href="<?php echo sURL; ?>dashboard/database" style="font-size:.85rem;font-weight:400">View Details &rarr;</a>
        </div>
        <div class="card-body" style="padding:16px">
            <table style="width:100%;border-collapse:collapse;font-size:.9rem">
                <tr>
                    <td style="padding:4px 8px;color:#666;width:40%">Server</td>
                    <td style="padding:4px 8px"><strong><?php echo htmlspecialchars($this->dbStats['version'] ?? ($this->dbStats['type'] ?? '—')); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px;color:#666">Database size</td>
                    <td style="padding:4px 8px"><?php
                        $bytes = $this->dbStats['db_size_bytes'] ?? 0;
                        echo $bytes > 1048576 ? number_format($bytes / 1048576, 2) . ' MB' : number_format($bytes / 1024, 1) . ' KB';
                    ?></td>
                </tr>
                <tr>
                    <td style="padding:4px 8px;color:#666">Connections</td>
                    <td style="padding:4px 8px"><?php echo (int)($this->dbStats['connections_active'] ?? 0); ?> active / <?php echo (int)($this->dbStats['connections_total'] ?? 0); ?> total</td>
                </tr>
                <?php if (isset($this->dbStats['cache_hit_ratio'])): ?>
                <tr>
                    <td style="padding:4px 8px;color:#666">Cache hit ratio</td>
                    <td style="padding:4px 8px"><?php echo number_format((float)$this->dbStats['cache_hit_ratio'], 1); ?>%</td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center">
            <span>Cache</span>
            <a href="<?php echo sURL; ?>dashboard/cache" style="font-size:.85rem;font-weight:400">View Details &rarr;</a>
        </div>
        <div class="card-body" style="padding:16px;color:#666;font-size:.9rem">
            Cache management: view namespaces, browse items, and clear the cache.
        </div>
    </div>

    <?php if (!empty($this->healthResults)): ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Health Checks</div>
        <div class="card-body" style="padding:16px" style="padding:0">
            <ul style="list-style:none;padding:0;margin:0">
                <?php foreach ($this->healthResults as $name => $result): ?>
                <li style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid #f0f0f0">
                    <span><?php echo htmlspecialchars($name); ?></span>
                    <?php if ($result['status'] === 'ok'): ?>
                        <span class="badge bg-success">OK</span>
                    <?php elseif ($result['status'] === 'warn'): ?>
                        <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($result['message'] ?? 'Warning'); ?></span>
                    <?php else: ?>
                        <span class="badge bg-danger"><?php echo htmlspecialchars($result['message'] ?? 'Error'); ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>
