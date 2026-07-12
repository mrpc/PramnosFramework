<?php
/**
 * Health Dashboard — plain-CSS theme.
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

$badgeStyle = match ($this->overallStatus) {
    'ok'       => 'background:#d4edda;color:#155724;border:1px solid #c3e6cb',
    'degraded' => 'background:#fff3cd;color:#856404;border:1px solid #ffeeba',
    'down'     => 'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb',
    default    => 'background:#e2e3e5;color:#383d41;border:1px solid #d6d8db',
};

$rowBadgeStyle = static function (string $status): string {
    return match ($status) {
        'ok'       => 'background:#d4edda;color:#155724;border:1px solid #c3e6cb',
        'degraded' => 'background:#fff3cd;color:#856404;border:1px solid #ffeeba',
        'down'     => 'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb',
        default    => 'background:#e2e3e5;color:#383d41;border:1px solid #d6d8db',
    };
};
?>
<div class="page-section health-dashboard">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
        <h2 style="margin:0">System Health</h2>
        <span class="status-badge status-<?php echo htmlspecialchars($this->overallStatus); ?>"
              style="display:inline-block;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;<?php echo $badgeStyle; ?>">
            <?php echo strtoupper(htmlspecialchars($this->overallStatus)); ?>
        </span>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:24px">
        <div class="card-header" style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd">
            <strong>Health Checks</strong>
        </div>
        <div class="card-body" style="padding:0">
            <table class="health-table" style="width:100%;border-collapse:collapse;font-size:14px">
                <thead>
                    <tr style="background:#fafafa;text-align:left">
                        <th style="padding:10px 14px;border-bottom:1px solid #ddd">Check</th>
                        <th style="padding:10px 14px;border-bottom:1px solid #ddd">Status</th>
                        <th style="padding:10px 14px;border-bottom:1px solid #ddd">Message</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($this->checks)): ?>
                    <tr>
                        <td colspan="3" style="padding:12px 14px;color:#888;text-align:center">No health checks registered.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($this->checks as $name => $check): ?>
                    <tr style="border-bottom:1px solid #eee">
                        <td style="padding:10px 14px"><strong><?php echo htmlspecialchars($name); ?></strong></td>
                        <td style="padding:10px 14px">
                            <span class="status-badge status-<?php echo htmlspecialchars($check['status']); ?>"
                                  style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;<?php echo $rowBadgeStyle($check['status']); ?>">
                                <?php echo strtoupper(htmlspecialchars($check['status'])); ?>
                            </span>
                        </td>
                        <td style="padding:10px 14px;color:#555"><?php echo htmlspecialchars($check['message'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px">
        <div class="card-header" style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd">
            <strong>System Info</strong>
        </div>
        <div class="card-body" style="padding:0">
            <table class="health-info-table" style="width:100%;border-collapse:collapse;font-size:14px">
                <tbody>
                    <tr style="border-bottom:1px solid #eee">
                        <th style="padding:10px 14px;text-align:left;background:#fafafa;width:180px">PHP Version</th>
                        <td style="padding:10px 14px"><?php echo htmlspecialchars(PHP_VERSION); ?></td>
                    </tr>
                    <tr style="border-bottom:1px solid #eee">
                        <th style="padding:10px 14px;text-align:left;background:#fafafa">Database</th>
                        <td style="padding:10px 14px"><?php echo htmlspecialchars($this->dbType); ?> <?php echo htmlspecialchars($this->dbVersion); ?></td>
                    </tr>
                    <tr style="border-bottom:1px solid #eee">
                        <th style="padding:10px 14px;text-align:left;background:#fafafa">Cache Adapter</th>
                        <td style="padding:10px 14px"><?php echo htmlspecialchars($this->cacheAdapter); ?></td>
                    </tr>
                    <tr style="border-bottom:1px solid #eee">
                        <th style="padding:10px 14px;text-align:left;background:#fafafa">Active Sessions</th>
                        <td style="padding:10px 14px"><?php echo htmlspecialchars($this->activeUsers); ?></td>
                    </tr>
                    <tr>
                        <th style="padding:10px 14px;text-align:left;background:#fafafa">Memory (peak)</th>
                        <td style="padding:10px 14px"><?php echo htmlspecialchars($this->peakMemory); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
