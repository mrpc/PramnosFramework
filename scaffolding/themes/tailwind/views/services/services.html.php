<?php
/**
 * Services / Workers list (Tailwind theme).
 *
 * Variables:
 *   $this->services     — array of enriched service entries
 *                         {id, daemon, profile, workerId, pid, status, lockFile, updatedAt}
 *   $this->orchestrator — {running, pid, heartbeat_age_seconds} — the supervisor itself
 */
?>
<div class="px-4 py-6">
    <h2 class="mb-6">Services</h2>
    <?php if (!empty($_GET['msg'])): ?>
        <div role="status" class="alert alert-info mb-4"><?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <?php
    /**
     * The supervisor's own state, before the list of what it supervises.
     *
     * Stop, Start and Restart on this page write and remove a sentinel file; the
     * orchestrator is what acts on it. With no orchestrator running, Stop still works —
     * a daemon reads its own stop file — and Start and Restart do nothing at all, with no
     * error and no message. This banner is the difference between "the service is down"
     * and "nothing is listening for the button you just pressed".
     */
    $orchestrator = $this->orchestrator ?? null;
    $supervising  = is_array($orchestrator) && !empty($orchestrator['running']);
    $heartbeat    = is_array($orchestrator) ? $orchestrator['heartbeat_age_seconds'] : null;
    ?>
    <?php if (!$supervising): ?>
        <div role="status" class="alert alert-warning mb-4">
            <div>
                <strong>The orchestrator is not running.</strong>
                Start and Restart below have nothing to act on — they write a request that the
                supervisor reads on its next cycle. Stop still takes effect: a daemon checks
                its own stop file.
                <?php
                /*
                 * The link, because "run the orchestrator" is not an instruction anybody can
                 * follow from here. It is a systemd unit, a user, a writable `var/` and a
                 * crontab line to remove — and the person reading this banner is usually
                 * looking at a screen that has just told them their buttons do nothing.
                 *
                 * To the framework's published guide rather than a page in this application:
                 * these are the same instructions for every installation, and a copy here
                 * would be one nobody updates. The anchor is a heading in that guide with an
                 * explicit id, so it does not move when the section is renamed.
                 */
                ?>
                <p class="mt-2">
                    <a class="link link-primary"
                       href="https://mrpc.github.io/PramnosFramework/Pramnos_Workers_And_Daemons_Guide/#creating-the-orchestrator-service"
                       target="_blank" rel="noopener">
                        How to create the orchestrator service &rarr;
                    </a>
                    <span class="text-base-content/60">
                        — a systemd unit for Ubuntu / Debian, and the Docker equivalent.
                        Offline: <code>vendor/mrpc/pramnosframework/docs/Pramnos_Workers_And_Daemons_Guide.md</code>
                    </span>
                </p>
            </div>
        </div>
    <?php elseif ($heartbeat !== null && $heartbeat > 120): ?>
        <div role="status" class="alert alert-warning mb-4">
            <div>
                <strong>The orchestrator is running but has not cycled for
                <?php echo (int) $heartbeat; ?>s</strong> (pid
                <?php echo (int) ($orchestrator['pid'] ?? 0); ?>). A live process with a
                stale heartbeat is stuck rather than healthy, and it looks identical to
                healthy if you only read the pid.
            </div>
        </div>
    <?php else: ?>
        <p class="text-sm text-base-content/60 mb-4">
            Supervisor running, pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?><?php
            if ($heartbeat !== null) {
                echo ', last cycle ' . (int) $heartbeat . 's ago';
            }
            ?>.
        </p>
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
