<?php
/**
 * Services / Workers list (plain-CSS theme).
 *
 * Variables:
 *   $this->services — array of enriched service entries
 *                     {id, daemon, profile, workerId, pid, status, lockFile, updatedAt}
 */
?>
<div class="page-section">
    <h2 style="margin-bottom:16px">Services</h2>
    <?php if (!empty($_GET['msg'])): ?>
        <div class="alert" style="background:#e8f4fd;border:1px solid #bee5eb;padding:12px 16px;border-radius:4px;margin-bottom:12px"><?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>Service</th><th>Worker ID</th><th>PID</th><th>Status</th><th>Updated</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->services ?? []) as $svc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($svc['daemon'] ?? ''); ?></strong>
                            <?php if (!empty($svc['profile'])): ?>
                                <small style="color:#888">(<?php echo htmlspecialchars($svc['profile']); ?>)</small>
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
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars($svc['updatedAt'] ?? ''); ?></td>
                        <td style="text-align:right">
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
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:24px">No services registered.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
