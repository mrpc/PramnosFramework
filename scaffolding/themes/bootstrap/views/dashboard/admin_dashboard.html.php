<?php
/**
 * Admin Operations Dashboard (Bootstrap theme).
 *
 * Variables:
 *   $this->activeUsers  — array {now, last_1h, last_24h, last_7d, last_30d}
 *   $this->dbStats      — array from DatabaseStatsService::getStats()
 *   $this->apiStats     — array from ApiPerformanceService::getSummary()
 *   $this->healthResults — array from HealthRegistry::runAll()
 */
?>
<div class="container-fluid py-4">
    <h2 class="mb-4">Admin Dashboard</h2>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card text-bg-primary">
                <div class="card-body">
                    <div class="text-white-50 small">Active Users (now)</div>
                    <div class="fs-3 fw-bold"><?php echo (int)($this->activeUsers['now'] ?? 0); ?></div>
                    <div class="text-white-50 small">Last 24h: <?php echo (int)($this->activeUsers['last_24h'] ?? 0); ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card text-bg-success">
                <div class="card-body">
                    <div class="text-white-50 small">API Requests (24h)</div>
                    <div class="fs-3 fw-bold"><?php echo (int)($this->apiStats['total_requests'] ?? 0); ?></div>
                    <div class="text-white-50 small">Errors: <?php echo number_format(($this->apiStats['error_rate'] ?? 0) * 100, 1); ?>%</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card text-bg-info">
                <div class="card-body">
                    <div class="text-white-50 small">Avg Latency (24h)</div>
                    <div class="fs-3 fw-bold"><?php echo number_format($this->apiStats['avg_execution_time'] ?? 0, 0); ?> ms</div>
                    <div class="text-white-50 small">p95: <?php echo number_format($this->apiStats['p95_execution_time'] ?? 0, 0); ?> ms</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card text-bg-secondary">
                <div class="card-body">
                    <div class="text-white-50 small">DB Size</div>
                    <div class="fs-3 fw-bold">
                        <?php
                        $bytes = $this->dbStats['db_size_bytes'] ?? 0;
                        echo $bytes > 1048576
                            ? number_format($bytes / 1048576, 1) . ' MB'
                            : number_format($bytes / 1024, 1) . ' KB';
                        ?>
                    </div>
                    <div class="text-white-50 small">Connections: <?php echo (int)($this->dbStats['connections_active'] ?? 0); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            Database
            <a href="<?php echo sURL; ?>dashboard/database" class="btn btn-sm btn-outline-primary">View Details</a>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr>
                        <td class="text-muted w-40" style="width:40%">Server</td>
                        <td><strong><?php $__t=$this->dbStats['type']??''; echo htmlspecialchars($this->dbStats['version'] ?? (['postgresql'=>'PostgreSQL','mysql'=>'MySQL'][$__t] ?? ($__t ?: '—'))); ?></strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Database size</td>
                        <td><?php
                            $bytes = $this->dbStats['db_size_bytes'] ?? 0;
                            echo $bytes > 1048576 ? number_format($bytes / 1048576, 2) . ' MB' : number_format($bytes / 1024, 1) . ' KB';
                        ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Connections</td>
                        <td><?php echo (int)($this->dbStats['connections_active'] ?? 0); ?> active / <?php echo (int)($this->dbStats['connections_total'] ?? 0); ?> total</td>
                    </tr>
                    <?php if (isset($this->dbStats['cache_hit_ratio'])): ?>
                    <tr>
                        <td class="text-muted">Cache hit ratio</td>
                        <td><?php echo number_format((float)$this->dbStats['cache_hit_ratio'], 1); ?>%</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            Cache
            <a href="<?php echo sURL; ?>dashboard/cache" class="btn btn-sm btn-outline-primary">View Details</a>
        </div>
        <div class="card-body text-muted small">
            Cache management: view namespaces, browse items, and clear the cache.
        </div>
    </div>

    <?php if (!empty($this->healthResults)): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Health Checks</div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush">
                <?php foreach ($this->healthResults as $name => $result): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
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
