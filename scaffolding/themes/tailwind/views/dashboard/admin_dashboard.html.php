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
        <div >
            <div class="card text-bg-primary">
                <div class="p-5">
                    <div class="text-white text-opacity-75 text-xs">Active Users (now)</div>
                    <div class="text-3xl font-bold"><?php echo (int)($this->activeUsers['now'] ?? 0); ?></div>
                    <div class="text-white text-opacity-75 text-xs">Last 24h: <?php echo (int)($this->activeUsers['last_24h'] ?? 0); ?></div>
                </div>
            </div>
        </div>
        <div >
            <div class="card text-bg-success">
                <div class="p-5">
                    <div class="text-white text-opacity-75 text-xs">API Requests (24h)</div>
                    <div class="text-3xl font-bold"><?php echo (int)($this->apiStats['total_requests'] ?? 0); ?></div>
                    <div class="text-white text-opacity-75 text-xs">Errors: <?php echo number_format(($this->apiStats['error_rate'] ?? 0) * 100, 1); ?>%</div>
                </div>
            </div>
        </div>
        <div >
            <div class="card text-bg-info">
                <div class="p-5">
                    <div class="text-white text-opacity-75 text-xs">Avg Latency (24h)</div>
                    <div class="text-3xl font-bold"><?php echo number_format($this->apiStats['avg_execution_time'] ?? 0, 0); ?> ms</div>
                    <div class="text-white text-opacity-75 text-xs">p95: <?php echo number_format($this->apiStats['p95_execution_time'] ?? 0, 0); ?> ms</div>
                </div>
            </div>
        </div>
        <div >
            <div class="card text-bg-secondary">
                <div class="p-5">
                    <div class="text-white text-opacity-75 text-xs">DB Size</div>
                    <div class="text-3xl font-bold">
                        <?php
                        $bytes = $this->dbStats['db_size_bytes'] ?? 0;
                        echo $bytes > 1048576
                            ? number_format($bytes / 1048576, 1) . ' MB'
                            : number_format($bytes / 1024, 1) . ' KB';
                        ?>
                    </div>
                    <div class="text-white text-opacity-75 text-xs">Connections: <?php echo (int)($this->dbStats['connections_active'] ?? 0); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm flex justify-between items-center">
            <span>Database</span>
            <a href="<?php echo sURL; ?>dashboard/database" class="text-blue-600 text-xs font-normal hover:underline">View Details &rarr;</a>
        </div>
        <div class="p-5">
            <table class="w-full text-sm">
                <tbody>
                    <tr>
                        <td class="py-1 pr-4 text-gray-500 w-2/5">Server</td>
                        <td class="py-1 font-semibold"><?php $__t=$this->dbStats['type']??''; echo htmlspecialchars($this->dbStats['version'] ?? (['postgresql'=>'PostgreSQL','mysql'=>'MySQL'][$__t] ?? ($__t ?: '—'))); ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 pr-4 text-gray-500">Database size</td>
                        <td class="py-1"><?php
                            $bytes = $this->dbStats['db_size_bytes'] ?? 0;
                            echo $bytes > 1048576 ? number_format($bytes / 1048576, 2) . ' MB' : number_format($bytes / 1024, 1) . ' KB';
                        ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 pr-4 text-gray-500">Connections</td>
                        <td class="py-1"><?php echo (int)($this->dbStats['connections_active'] ?? 0); ?> active / <?php echo (int)($this->dbStats['connections_total'] ?? 0); ?> total</td>
                    </tr>
                    <?php if (isset($this->dbStats['cache_hit_ratio'])): ?>
                    <tr>
                        <td class="py-1 pr-4 text-gray-500">Cache hit ratio</td>
                        <td class="py-1"><?php echo number_format((float)$this->dbStats['cache_hit_ratio'], 1); ?>%</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm flex justify-between items-center">
            <span>Cache</span>
            <a href="<?php echo sURL; ?>dashboard/cache" class="text-blue-600 text-xs font-normal hover:underline">View Details &rarr;</a>
        </div>
        <div class="px-5 py-4 text-gray-500 text-sm">
            Cache management: view namespaces, browse items, and clear the cache.
        </div>
    </div>

    <?php if (!empty($this->healthResults)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Health Checks</div>
        <div >
            <ul class="divide-y divide-gray-100">
                <?php foreach ($this->healthResults as $name => $result): ?>
                <li class="flex justify-between items-center px-5 py-3">
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
