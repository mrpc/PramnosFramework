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
    <?php
    /**
     * The supervisor's own state. Stop, Start and Restart write a sentinel file the
     * orchestrator acts on: with none running, Start and Restart do nothing at all.
     */
    $orchestrator = $this->orchestrator ?? null;
    $supervising  = is_array($orchestrator) && !empty($orchestrator['running']);
    $heartbeat    = is_array($orchestrator) ? $orchestrator['heartbeat_age_seconds'] : null;
    ?>
    <?php if (!$supervising): ?>
        <div style="background:#fff8e1;border:1px solid #ffe082;padding:12px 16px;border-radius:4px;margin-bottom:12px">
            <strong>The orchestrator is not running.</strong>
            Start and Restart below have nothing to act on — they write a request the
            supervisor reads on its next cycle. Stop still takes effect, because a daemon
            checks its own stop file.
            <p style="margin:8px 0 0">
                <a href="https://mrpc.github.io/PramnosFramework/Pramnos_Workers_And_Daemons_Guide/#creating-the-orchestrator-service" target="_blank" rel="noopener">How to create the orchestrator service &rarr;</a>
                <span style="color:#666">— a systemd unit for Ubuntu / Debian, and the Docker equivalent.</span>
            </p>
        </div>
    <?php elseif ($heartbeat !== null && $heartbeat > 120): ?>
        <div style="background:#fff8e1;border:1px solid #ffe082;padding:12px 16px;border-radius:4px;margin-bottom:12px">
            <strong>The orchestrator has not cycled for <?php echo (int) $heartbeat; ?>s</strong>
            (pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?>). A live process with a
            stale heartbeat is stuck rather than healthy.
        </div>
    <?php else: ?>
        <p style="color:#666;font-size:13px">
            Supervisor running, pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?><?php
            if ($heartbeat !== null) {
                echo ', last cycle ' . (int) $heartbeat . 's ago';
            }
            ?>.
        </p>
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
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:24px">No services registered.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
