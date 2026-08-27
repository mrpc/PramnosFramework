<?php
/**
 * Services / Workers list (Tailwind theme).
 *
 * Variables:
 *   $this->services — array of enriched service entries
 *                     {id, daemon, profile, workerId, pid, status, lockFile, updatedAt}
 */
?>
<div class="px-4 py-6">
    <h2 class="mb-6">Services</h2>
    <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-info mb-4"><?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Service</th><th>Worker ID</th><th>PID</th><th>Status</th><th>Updated</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->services ?? []) as $svc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($svc['daemon'] ?? ''); ?></strong>
                            <?php if (!empty($svc['profile'])): ?>
                                <small class="text-base-content/70">(<?php echo htmlspecialchars($svc['profile']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($svc['workerId'] ?? ''); ?></td>
                        <td><?php echo !empty($svc['pid']) ? (int)$svc['pid'] : '—'; ?></td>
                        <td>
                            <?php if ($svc['status'] === 'running'): ?>
                                <span class="badge badge-success">Running</span>
                            <?php elseif ($svc['status'] === 'error'): ?>
                                <span class="badge badge-warning">Stop Pending</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Stopped</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-base-content/60 text-xs"><?php echo htmlspecialchars($svc['updatedAt'] ?? ''); ?></td>
                        <td class="text-right">
                            <a href="<?php echo adminUrl('Services/logs/'); ?><?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-outline btn-xs">Logs</a>
                            <?php if ($svc['status'] === 'running'): ?>
                                <a href="<?php echo adminUrl('Services/stop/'); ?><?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-outline btn-warning btn-xs">Stop</a>
                            <?php else: ?>
                                <a href="<?php echo adminUrl('Services/start/'); ?><?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-outline btn-success btn-xs">Start</a>
                            <?php endif; ?>
                            <a href="<?php echo adminUrl('Services/restart/'); ?><?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-outline btn-error btn-xs">Restart</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->services)): ?>
                    <tr><td colspan="6" class="text-center text-base-content/60 py-8">No services registered.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
