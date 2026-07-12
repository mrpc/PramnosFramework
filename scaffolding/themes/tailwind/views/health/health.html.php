<?php
/**
 * Health Dashboard — Tailwind theme.
 *
 * Variables (set by Health::display()):
 *   $this->overallStatus — 'ok' | 'degraded' | 'down'
 *   $this->checks        — array<name, array{status, message, details}>
 *   $this->dbType        — ucfirst DB type string or 'not connected'
 *   $this->dbVersion     — DB version string or '—'
 *   $this->cacheAdapter  — cache adapter name or '—'
 *   $this->activeUsers   — active session count or '—'
 *   $this->peakMemory    — formatted peak memory string
 */

$badgeColor = [
    'ok'       => 'bg-green-100 text-green-800',
    'degraded' => 'bg-yellow-100 text-yellow-800',
    'down'     => 'bg-red-100 text-red-800',
][$this->overallStatus] ?? 'bg-gray-100 text-gray-800';

$rowBadgeColor = static function (string $status): string {
    return match ($status) {
        'ok'       => 'bg-green-100 text-green-800',
        'degraded' => 'bg-yellow-100 text-yellow-800',
        'down'     => 'bg-red-100 text-red-800',
        default    => 'bg-gray-100 text-gray-800',
    };
};
?>
<div class="px-4 py-6 health-dashboard">
    <div class="flex items-center gap-3 mb-6">
        <h2 class="text-2xl font-semibold text-gray-800">System Health</h2>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo $badgeColor; ?> status-badge status-<?php echo htmlspecialchars($this->overallStatus); ?>">
            <?php echo strtoupper(htmlspecialchars($this->overallStatus)); ?>
        </span>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200">
            <h3 class="font-medium text-gray-700">Health Checks</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="health-table w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <th class="px-4 py-3 border-b">Check</th>
                        <th class="px-4 py-3 border-b">Status</th>
                        <th class="px-4 py-3 border-b">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php if (empty($this->checks)): ?>
                    <tr>
                        <td colspan="3" class="px-4 py-4 text-gray-400 text-center">No health checks registered.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($this->checks as $name => $check): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-700"><?php echo htmlspecialchars($name); ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $rowBadgeColor($check['status']); ?> status-badge status-<?php echo htmlspecialchars($check['status']); ?>">
                                <?php echo strtoupper(htmlspecialchars($check['status'])); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($check['message'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200">
            <h3 class="font-medium text-gray-700">System Info</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="health-info-table w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr class="hover:bg-gray-50">
                        <th class="px-4 py-3 text-left font-medium text-gray-600 bg-gray-50 w-48">PHP Version</th>
                        <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars(PHP_VERSION); ?></td>
                    </tr>
                    <tr class="hover:bg-gray-50">
                        <th class="px-4 py-3 text-left font-medium text-gray-600 bg-gray-50">Database</th>
                        <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($this->dbType); ?> <?php echo htmlspecialchars($this->dbVersion); ?></td>
                    </tr>
                    <tr class="hover:bg-gray-50">
                        <th class="px-4 py-3 text-left font-medium text-gray-600 bg-gray-50">Cache Adapter</th>
                        <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($this->cacheAdapter); ?></td>
                    </tr>
                    <tr class="hover:bg-gray-50">
                        <th class="px-4 py-3 text-left font-medium text-gray-600 bg-gray-50">Active Sessions</th>
                        <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($this->activeUsers); ?></td>
                    </tr>
                    <tr class="hover:bg-gray-50">
                        <th class="px-4 py-3 text-left font-medium text-gray-600 bg-gray-50">Memory (peak)</th>
                        <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($this->peakMemory); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
