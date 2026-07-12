<?php
/**
 * Health Dashboard — Bootstrap theme.
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

$badgeClass = [
    'ok'       => 'success',
    'degraded' => 'warning',
    'down'     => 'danger',
][$this->overallStatus] ?? 'secondary';
?>
<div class="container-fluid py-4 health-dashboard">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="mb-0">System Health</h2>
        <span class="badge bg-<?php echo $badgeClass; ?> status-badge status-<?php echo htmlspecialchars($this->overallStatus); ?>">
            <?php echo strtoupper(htmlspecialchars($this->overallStatus)); ?>
        </span>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Health Checks</strong></div>
        <div class="card-body p-0">
            <table class="health-table table table-bordered table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Check</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($this->checks)): ?>
                    <tr>
                        <td colspan="3" class="text-muted text-center py-3">No health checks registered.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($this->checks as $name => $check): ?>
                    <?php
                        $rowBadge = [
                            'ok'       => 'success',
                            'degraded' => 'warning',
                            'down'     => 'danger',
                        ][$check['status']] ?? 'secondary';
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                        <td>
                            <span class="badge bg-<?php echo $rowBadge; ?> status-badge status-<?php echo htmlspecialchars($check['status']); ?>">
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
    </div>

    <div class="card">
        <div class="card-header"><strong>System Info</strong></div>
        <div class="card-body p-0">
            <table class="health-info-table table table-bordered mb-0">
                <tr>
                    <th class="bg-light" style="width:200px">PHP Version</th>
                    <td><?php echo htmlspecialchars(PHP_VERSION); ?></td>
                </tr>
                <tr>
                    <th class="bg-light">Database</th>
                    <td><?php echo htmlspecialchars($this->dbType); ?> <?php echo htmlspecialchars($this->dbVersion); ?></td>
                </tr>
                <tr>
                    <th class="bg-light">Cache Adapter</th>
                    <td><?php echo htmlspecialchars($this->cacheAdapter); ?></td>
                </tr>
                <tr>
                    <th class="bg-light">Active Sessions</th>
                    <td><?php echo htmlspecialchars($this->activeUsers); ?></td>
                </tr>
                <tr>
                    <th class="bg-light">Memory (peak)</th>
                    <td><?php echo htmlspecialchars($this->peakMemory); ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>
