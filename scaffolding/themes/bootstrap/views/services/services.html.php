<?php
/**
 * Services / Workers list (Bootstrap theme).
 *
 * Variables:
 *   $this->services — array of enriched service entries
 *                     {id, daemon, profile, workerId, pid, status, lockFile, updatedAt}
 */
?>
<div class="container-fluid py-4">
    <h2 class="mb-4">Services</h2>
    <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Service</th><th>Worker ID</th><th>PID</th><th>Status</th><th>Updated</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->services ?? []) as $svc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($svc['daemon'] ?? ''); ?></strong>
                            <?php if (!empty($svc['profile'])): ?>
                                <small class="text-muted">(<?php echo htmlspecialchars($svc['profile']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($svc['workerId'] ?? ''); ?></td>
                        <td><?php echo !empty($svc['pid']) ? (int)$svc['pid'] : '—'; ?></td>
                        <td>
                            <?php if ($svc['status'] === 'running'): ?>
                                <span class="badge bg-success">Running</span>
                            <?php elseif ($svc['status'] === 'error'): ?>
                                <span class="badge bg-warning text-dark">Stop Pending</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Stopped</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?php echo htmlspecialchars($svc['updatedAt'] ?? ''); ?></td>
                        <td class="text-end">
                            <a href="<?php echo sURL; ?>Services/logs/<?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-sm btn-outline-secondary">Logs</a>
                            <?php if ($svc['status'] === 'running'): ?>
                                <a href="<?php echo sURL; ?>Services/stop/<?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-sm btn-outline-warning">Stop</a>
                            <?php else: ?>
                                <a href="<?php echo sURL; ?>Services/start/<?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-sm btn-outline-success">Start</a>
                            <?php endif; ?>
                            <a href="<?php echo sURL; ?>Services/restart/<?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-sm btn-outline-danger">Restart</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->services)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No services registered.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
