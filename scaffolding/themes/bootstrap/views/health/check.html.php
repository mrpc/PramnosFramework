<?php
/**
 * Health Checks compact table — Bootstrap theme.
 *
 * Standalone compact view of check results.  Suitable as a partial or
 * as an HTML alternative to the JSON check() endpoint.
 *
 * Variables (set by Health::display() or custom controller):
 *   $this->overallStatus — 'ok' | 'degraded' | 'down'
 *   $this->checks        — array<name, array{status, message, details}>
 */

$badgeClass = [
    'ok'       => 'success',
    'degraded' => 'warning',
    'down'     => 'danger',
][$this->overallStatus] ?? 'secondary';
?>
<div class="health-check-summary">
    <div class="d-flex align-items-center gap-2 mb-3">
        <strong>Status:</strong>
        <span class="badge bg-<?php echo $badgeClass; ?> status-badge status-<?php echo htmlspecialchars($this->overallStatus); ?>">
            <?php echo strtoupper(htmlspecialchars($this->overallStatus)); ?>
        </span>
    </div>
    <table class="health-table table table-sm table-bordered">
        <thead class="table-light">
            <tr><th>Check</th><th>Status</th><th>Message</th></tr>
        </thead>
        <tbody>
        <?php if (empty($this->checks)): ?>
            <tr><td colspan="3" class="text-muted">No health checks registered.</td></tr>
        <?php else: ?>
            <?php foreach ($this->checks as $name => $check): ?>
            <?php
                $rb = [
                    'ok'       => 'success',
                    'degraded' => 'warning',
                    'down'     => 'danger',
                ][$check['status']] ?? 'secondary';
            ?>
            <tr>
                <td><?php echo htmlspecialchars($name); ?></td>
                <td>
                    <span class="badge bg-<?php echo $rb; ?> status-badge status-<?php echo htmlspecialchars($check['status']); ?>">
                        <?php echo strtoupper(htmlspecialchars($check['status'])); ?>
                    </span>
                </td>
                <td><?php echo htmlspecialchars($check['message'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
