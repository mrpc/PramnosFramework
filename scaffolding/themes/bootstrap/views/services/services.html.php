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
    <?php
    /**
     * The supervisor's own state, before the list of what it supervises. Stop, Start and
     * Restart write a sentinel file that the orchestrator acts on: with no orchestrator
     * running, Start and Restart do nothing at all, with no error and no message.
     */
    $orchestrator = $this->orchestrator ?? null;
    $supervising  = is_array($orchestrator) && !empty($orchestrator['running']);
    $heartbeat    = is_array($orchestrator) ? $orchestrator['heartbeat_age_seconds'] : null;
    ?>
    <?php if (!$supervising): ?>
        <div class="alert alert-warning">
            <strong>The orchestrator is not running.</strong>
            Start and Restart below have nothing to act on — they write a request the
            supervisor reads on its next cycle. Stop still takes effect, because a daemon
            checks its own stop file.
            <p class="mb-0 mt-2">
                <a href="https://mrpc.github.io/PramnosFramework/Pramnos_Workers_And_Daemons_Guide/#creating-the-orchestrator-service" target="_blank" rel="noopener">How to create the orchestrator service &rarr;</a>
                <span class="text-muted">— a systemd unit for Ubuntu / Debian, and the Docker equivalent.</span>
            </p>
        </div>
    <?php elseif ($heartbeat !== null && $heartbeat > 120): ?>
        <div class="alert alert-warning">
            <strong>The orchestrator has not cycled for <?php echo (int) $heartbeat; ?>s</strong>
            (pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?>). A live process with a
            stale heartbeat is stuck rather than healthy.
        </div>
    <?php else: ?>
        <p class="text-muted small">
            Supervisor running, pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?><?php
            if ($heartbeat !== null) {
                echo ', last cycle ' . (int) $heartbeat . 's ago';
            }
            ?>.
        </p>
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
                            <a href="<?php echo adminUrl('Services/logs/'); ?><?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-sm btn-outline-secondary">Logs</a>
                            <?php if ($svc['status'] === 'running'): ?>
                                <a href="<?php echo adminUrl('Services/stop/'); ?><?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-sm btn-outline-warning">Stop</a>
                            <?php else: ?>
                                <a href="<?php echo adminUrl('Services/start/'); ?><?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-sm btn-outline-success">Start</a>
                            <?php endif; ?>
                            <a href="<?php echo adminUrl('Services/restart/'); ?><?php echo urlencode($svc['id'] ?? ''); ?>" class="btn btn-sm btn-outline-danger">Restart</a>
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
