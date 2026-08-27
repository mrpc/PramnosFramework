<?php
/**
 * Admin Operations Dashboard (Tailwind theme).
 *
 * Variables:
 *   $this->activeUsers  — array {now, last_1h, last_24h, last_7d, last_30d}
 *   $this->dbStats      — array from DatabaseStatsService::getStats()
 *   $this->apiStats     — array from ApiPerformanceService::getSummary()
 *   $this->healthResults — array from HealthRegistry::runAll()
 */
?>
<div class="px-4 py-6">
    <h2 class="mb-6">Admin Dashboard</h2>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="card bg-primary text-primary-content">
            <div class="p-5">
                <div class="text-xs opacity-75">Active Users (now)</div>
                <div class="text-3xl font-bold"><?php echo (int)($this->activeUsers['now'] ?? 0); ?></div>
                <div class="text-xs opacity-75">Last 24h: <?php echo (int)($this->activeUsers['last_24h'] ?? 0); ?></div>
            </div>
        </div>
        <div class="card bg-success text-success-content">
            <div class="p-5">
                <div class="text-xs opacity-75">API Requests (24h)</div>
                <div class="text-3xl font-bold"><?php echo (int)($this->apiStats['total_requests'] ?? 0); ?></div>
                <div class="text-xs opacity-75">Errors: <?php echo number_format(($this->apiStats['error_rate'] ?? 0) * 100, 1); ?>%</div>
            </div>
        </div>
        <div class="card bg-info text-info-content">
            <div class="p-5">
                <div class="text-xs opacity-75">Avg Latency (24h)</div>
                <div class="text-3xl font-bold"><?php echo number_format($this->apiStats['avg_execution_time'] ?? 0, 0); ?> ms</div>
                <div class="text-xs opacity-75">p95: <?php echo number_format($this->apiStats['p95_execution_time'] ?? 0, 0); ?> ms</div>
            </div>
        </div>
        <div class="card bg-secondary text-secondary-content">
            <div class="p-5">
                <div class="text-xs opacity-75">DB Size</div>
                <div class="text-3xl font-bold">
                    <?php
                    $bytes = $this->dbStats['db_size_bytes'] ?? 0;
                    echo $bytes > 1048576
                        ? number_format($bytes / 1048576, 1) . ' MB'
                        : number_format($bytes / 1024, 1) . ' KB';
                    ?>
                </div>
                <div class="text-xs opacity-75">Connections: <?php echo (int)($this->dbStats['connections_active'] ?? 0); ?></div>
            </div>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm flex justify-between items-center">
            <span>Database</span>
            <a href="<?php echo adminUrl('dashboard/database'); ?>" class="text-primary text-xs font-normal hover:underline">View Details &rarr;</a>
        </div>
        <div class="p-5">
            <table class="table table-sm text-sm">
                <tbody>
                    <tr>
                        <td class="py-1 pr-4 text-base-content/70 w-2/5">Server</td>
                        <td class="py-1 font-semibold"><?php $__t=$this->dbStats['type']??''; echo htmlspecialchars($this->dbStats['version'] ?? (['postgresql'=>'PostgreSQL','mysql'=>'MySQL'][$__t] ?? ($__t ?: '—'))); ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 pr-4 text-base-content/70">Database size</td>
                        <td class="py-1"><?php
                            $bytes = $this->dbStats['db_size_bytes'] ?? 0;
                            echo $bytes > 1048576 ? number_format($bytes / 1048576, 2) . ' MB' : number_format($bytes / 1024, 1) . ' KB';
                        ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 pr-4 text-base-content/70">Connections</td>
                        <td class="py-1"><?php echo (int)($this->dbStats['connections_active'] ?? 0); ?> active / <?php echo (int)($this->dbStats['connections_total'] ?? 0); ?> total</td>
                    </tr>
                    <?php if (isset($this->dbStats['cache_hit_ratio'])): ?>
                    <tr>
                        <td class="py-1 pr-4 text-base-content/70">Cache hit ratio</td>
                        <td class="py-1"><?php echo number_format((float)$this->dbStats['cache_hit_ratio'], 1); ?>%</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm flex justify-between items-center">
            <span>Cache</span>
            <a href="<?php echo adminUrl('dashboard/cache'); ?>" class="text-primary text-xs font-normal hover:underline">View Details &rarr;</a>
        </div>
        <div class="px-5 py-4 text-base-content/70 text-sm">
            Cache management: view namespaces, browse items, and clear the cache.
        </div>
    </div>

    <?php if (!empty($this->healthResults)): ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Health Checks</div>
        <div >
            <ul class="divide-y divide-base-300">
                <?php foreach ($this->healthResults as $name => $result): ?>
                <li class="flex justify-between items-center px-5 py-3">
                    <span><?php echo htmlspecialchars($name); ?></span>
                    <?php if ($result['status'] === 'ok'): ?>
                        <span class="badge badge-success">OK</span>
                    <?php elseif ($result['status'] === 'warn'): ?>
                        <span class="badge badge-warning"><?php echo htmlspecialchars($result['message'] ?? 'Warning'); ?></span>
                    <?php else: ?>
                        <span class="badge badge-error"><?php echo htmlspecialchars($result['message'] ?? 'Error'); ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>
