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
    'ok'       => 'bg-success/10 text-success',
    'degraded' => 'bg-warning/10 text-warning',
    'down'     => 'bg-error/10 text-error',
][$this->overallStatus] ?? 'bg-base-200 text-base-content';

$rowBadgeColor = static function (string $status): string {
    return match ($status) {
        'ok'       => 'bg-success/10 text-success',
        'degraded' => 'bg-warning/10 text-warning',
        'down'     => 'bg-error/10 text-error',
        default    => 'bg-base-200 text-base-content',
    };
};
?>
<div class="px-4 py-6 health-dashboard">
    <div class="flex items-center gap-3 mb-6">
        <h2 class="text-2xl font-semibold text-base-content">System Health</h2>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo $badgeColor; ?> status-badge status-<?php echo htmlspecialchars($this->overallStatus); ?>">
            <?php echo strtoupper(htmlspecialchars($this->overallStatus)); ?>
        </span>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300">
            <h3 class="font-medium text-base-content">Health Checks</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-sm health-table text-sm">
                <thead>
                    <tr class="bg-base-200 text-left text-xs font-medium text-base-content/70 uppercase tracking-wider">
                        <th class="px-4 py-3 border-b">Check</th>
                        <th class="px-4 py-3 border-b">Status</th>
                        <th class="px-4 py-3 border-b">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-300">
                <?php if (empty($this->checks)): ?>
                    <tr>
                        <td colspan="3" class="px-4 py-4 text-base-content/60 text-center">No health checks registered.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($this->checks as $name => $check): ?>
                    <tr class="hover:bg-base-200">
                        <td class="px-4 py-3 font-medium text-base-content"><?php echo htmlspecialchars($name); ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium <?php echo $rowBadgeColor($check['status']); ?> status-badge status-<?php echo htmlspecialchars($check['status']); ?>">
                                <?php echo strtoupper(htmlspecialchars($check['status'])); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-base-content/80"><?php echo htmlspecialchars($check['message'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300">
            <h3 class="font-medium text-base-content">System Info</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-sm health-info-table text-sm">
                <tbody class="divide-y divide-base-300">
                    <tr class="hover:bg-base-200">
                        <th class="px-4 py-3 text-left font-medium text-base-content/80 bg-base-200 w-48">PHP Version</th>
                        <td class="px-4 py-3 text-base-content"><?php echo htmlspecialchars(PHP_VERSION); ?></td>
                    </tr>
                    <tr class="hover:bg-base-200">
                        <th class="px-4 py-3 text-left font-medium text-base-content/80 bg-base-200">Database</th>
                        <td class="px-4 py-3 text-base-content"><?php echo htmlspecialchars($this->dbType); ?> <?php echo htmlspecialchars($this->dbVersion); ?></td>
                    </tr>
                    <tr class="hover:bg-base-200">
                        <th class="px-4 py-3 text-left font-medium text-base-content/80 bg-base-200">Cache Adapter</th>
                        <td class="px-4 py-3 text-base-content"><?php echo htmlspecialchars($this->cacheAdapter); ?></td>
                    </tr>
                    <tr class="hover:bg-base-200">
                        <th class="px-4 py-3 text-left font-medium text-base-content/80 bg-base-200">Active Sessions</th>
                        <td class="px-4 py-3 text-base-content"><?php echo htmlspecialchars($this->activeUsers); ?></td>
                    </tr>
                    <tr class="hover:bg-base-200">
                        <th class="px-4 py-3 text-left font-medium text-base-content/80 bg-base-200">Memory (peak)</th>
                        <td class="px-4 py-3 text-base-content"><?php echo htmlspecialchars($this->peakMemory); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
