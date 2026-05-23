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
        <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded mb-4"><?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>Service</th><th>Worker ID</th><th>PID</th><th>Status</th><th>Updated</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->services ?? []) as $svc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($svc['daemon'] ?? ''); ?></strong>
                            <?php if (!empty($svc['profile'])): ?>
                                <small class="text-gray-500">(<?php echo htmlspecialchars($svc['profile']); ?>)</small>
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
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars($svc['updatedAt'] ?? ''); ?></td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Services/logs/<?php echo urlencode($svc['id'] ?? ''); ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Logs</a>
                            <?php if ($svc['status'] === 'running'): ?>
                                <a href="<?php echo sURL; ?>Services/stop/<?php echo urlencode($svc['id'] ?? ''); ?>" class="px-3 py-1 border border-yellow-400 text-yellow-700 text-xs rounded hover:bg-yellow-50">Stop</a>
                            <?php else: ?>
                                <a href="<?php echo sURL; ?>Services/start/<?php echo urlencode($svc['id'] ?? ''); ?>" class="px-3 py-1 border border-green-400 text-green-700 text-xs rounded hover:bg-green-50">Start</a>
                            <?php endif; ?>
                            <a href="<?php echo sURL; ?>Services/restart/<?php echo urlencode($svc['id'] ?? ''); ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50">Restart</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->services)): ?>
                    <tr><td colspan="6" class="text-center text-gray-400 py-8">No services registered.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
