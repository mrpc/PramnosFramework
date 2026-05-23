<?php
/**
 * Health Checks compact table — plain-CSS theme.
 *
 * Variables (set by Health::display() or custom controller):
 *   $this->overallStatus — 'ok' | 'degraded' | 'down'
 *   $this->checks        — array<name, array{status, message, details}>
 */

$badgeStyle = match ($this->overallStatus) {
    'ok'       => 'background:#d4edda;color:#155724;border:1px solid #c3e6cb',
    'degraded' => 'background:#fff3cd;color:#856404;border:1px solid #ffeeba',
    'down'     => 'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb',
    default    => 'background:#e2e3e5;color:#383d41;border:1px solid #d6d8db',
};
?>
<div class="health-check-summary">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
        <strong style="font-size:13px">Status:</strong>
        <span class="status-badge status-<?php echo htmlspecialchars($this->overallStatus); ?>"
              style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;<?php echo $badgeStyle; ?>">
            <?php echo strtoupper(htmlspecialchars($this->overallStatus)); ?>
        </span>
    </div>
    <table class="health-table" style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid #ddd">
        <thead>
            <tr style="background:#f5f5f5;text-align:left">
                <th style="padding:8px 12px;border-bottom:1px solid #ddd">Check</th>
                <th style="padding:8px 12px;border-bottom:1px solid #ddd">Status</th>
                <th style="padding:8px 12px;border-bottom:1px solid #ddd">Message</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($this->checks)): ?>
            <tr><td colspan="3" style="padding:10px 12px;color:#888">No health checks registered.</td></tr>
        <?php else: ?>
            <?php foreach ($this->checks as $name => $check): ?>
            <?php
                $rb = match ($check['status']) {
                    'ok'       => 'background:#d4edda;color:#155724;border:1px solid #c3e6cb',
                    'degraded' => 'background:#fff3cd;color:#856404;border:1px solid #ffeeba',
                    'down'     => 'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb',
                    default    => 'background:#e2e3e5;color:#383d41;border:1px solid #d6d8db',
                };
            ?>
            <tr style="border-bottom:1px solid #eee">
                <td style="padding:8px 12px"><?php echo htmlspecialchars($name); ?></td>
                <td style="padding:8px 12px">
                    <span class="status-badge status-<?php echo htmlspecialchars($check['status']); ?>"
                          style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;<?php echo $rb; ?>">
                        <?php echo strtoupper(htmlspecialchars($check['status'])); ?>
                    </span>
                </td>
                <td style="padding:8px 12px;color:#555"><?php echo htmlspecialchars($check['message'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
