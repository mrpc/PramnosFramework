<?php
/**
 * Services / Workers (Bootstrap theme) — the state of the workers, nothing to configure.
 *
 * Variables:
 *   $this->services     — enriched service entries {id, daemon, profile, workerId, pid, status, lockFile, updatedAt}
 *   $this->orchestrator — the supervisor's own state {running, pid, heartbeat_age_seconds}
 *   $this->pools        — pools as the supervisor last saw them, plus saved rows it has not seen
 *   $this->batches      — batches of the last day, counted by status
 *   $this->stopped      — services an operator stopped, by id
 *
 * In reading order: the supervisor, the services it runs, the pools sizing them, the batches.
 * A pool's limits are set on Services/pools. Every change is a POST with the session's token;
 * the numbers refresh from Services/status every ten seconds without a reload.
 */
$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$pools  = $this->pools ?? [];
$stopped = $this->stopped ?? [];
?>
<div class="container-fluid py-4" id="services-screen" data-status-url="<?php echo $e(adminUrl('Services/status')); ?>"
     data-stale="<?php echo $e($e(t('Something new is running.')) . ' <a href="">' . $e(t('Reload')) . '</a>'); ?>">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><?php echo $e(t('Services')); ?></h2>
        <div class="d-flex gap-2 align-items-center">
            <a href="<?php echo $e(adminUrl('Services/pools')); ?>" class="btn btn-outline-secondary btn-sm"><?php echo $e(t('Configure pools')); ?></a>
            <form method="post" action="<?php echo $e(adminUrl('Services/restartall')); ?>" class="m-0">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="<?php echo $e(t('Restart every running service? Each finishes its current task first.')); ?>" title="<?php echo $e(t('For a deploy the supervisor cannot see: composer update, a copied build')); ?>"><?php echo $e(t('Restart all')); ?></button>
            </form>
        </div>
    </div>
    <?php
    /**
     * The supervisor's own state, before the list of what it supervises. Every control on
     * this page is something the orchestrator acts on: with none running, nothing happens.
     */
    $orchestrator = $this->orchestrator ?? null;
    $supervising  = is_array($orchestrator) && !empty($orchestrator['running']);
    $heartbeat    = is_array($orchestrator) ? $orchestrator['heartbeat_age_seconds'] : null;
    ?>
    <?php if (!$supervising): ?>
        <div role="status" class="alert alert-warning">
            <strong><?php echo $e(t('The orchestrator is not running.')); ?></strong>
            <?php echo $e(t('The controls below are read by the supervisor on its next cycle, so until it runs nothing changes. Stop still takes effect, because a daemon checks its own stop file.')); ?>
            <p class="mb-0 mt-2">
                <a href="https://mrpc.github.io/PramnosFramework/Pramnos_Workers_And_Daemons_Guide/#creating-the-orchestrator-service" target="_blank" rel="noopener"><?php echo $e(t('How to create the orchestrator service')); ?> &rarr;</a>
                <span class="text-muted"><?php echo $e(t('— a systemd unit for Ubuntu / Debian, and the Docker equivalent.')); ?></span>
            </p>
        </div>
    <?php elseif ($heartbeat !== null && $heartbeat > 120): ?>
        <div role="status" class="alert alert-warning">
            <strong><?php echo $e(sprintf(t('The orchestrator has not cycled for %ds'), (int) $heartbeat)); ?></strong>
            <?php echo $e(sprintf(t('(pid %d). A live process with a stale heartbeat is stuck rather than healthy.'), (int) ($orchestrator['pid'] ?? 0))); ?>
        </div>
    <?php else: ?>
        <p class="text-muted small" data-field="supervisor" data-template="<?php echo $e(t('Supervisor running, pid %d, last cycle %ds ago.')); ?>">
            <?php echo $e($heartbeat !== null
                ? sprintf(t('Supervisor running, pid %d, last cycle %ds ago.'), (int) ($orchestrator['pid'] ?? 0), (int) $heartbeat)
                : sprintf(t('Supervisor running, pid %d.'), (int) ($orchestrator['pid'] ?? 0))); ?>
        </p>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><strong><?php echo $e(t('Services')); ?></strong></div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th><?php echo $e(t('Service')); ?></th><th><?php echo $e(t('Worker ID')); ?></th><th><?php echo $e(t('PID')); ?></th><th><?php echo $e(t('Status')); ?></th><th><?php echo $e(t('Updated')); ?></th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->services ?? []) as $svc): ?>
                    <?php $id = (string) ($svc['id'] ?? ''); ?>
                    <tr>
                        <td><strong><?php echo $e($svc['daemon'] ?? ''); ?></strong>
                            <?php if (!empty($svc['profile'])): ?>
                                <small class="text-muted">(<?php echo $e($svc['profile']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $e($svc['workerId'] ?? ''); ?></td>
                        <td><?php echo !empty($svc['pid']) ? (int) $svc['pid'] : '—'; ?></td>
                        <td>
                            <?php if (isset($stopped[$id])): ?>
                                <span class="badge bg-secondary" title="<?php echo $e(sprintf(t('Stopped by %s'), $stopped[$id]['stopped_by'] ?? t('an operator'))); ?>"><?php echo $e(t('Stopped by operator')); ?></span>
                            <?php elseif ($svc['status'] === 'running'): ?>
                                <span class="badge bg-success"><?php echo $e(t('Running')); ?></span>
                            <?php elseif ($svc['status'] === 'error'): ?>
                                <span class="badge bg-warning text-dark"><?php echo $e(t('Stop Pending')); ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?php echo $e(t('Stopped')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?php echo $e($svc['updatedAt'] ?? ''); ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?php echo $e(adminUrl('Services/logs/') . urlencode($id)); ?>" class="btn btn-sm btn-outline-secondary"><?php echo $e(t('Logs')); ?></a>
                            <?php if ($svc['status'] === 'running' && !isset($stopped[$id])): ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/stop/') . urlencode($id)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-warning"><?php echo $e(t('Stop')); ?></button></form>
                            <?php else: ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/start/') . urlencode($id)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-success"><?php echo $e(t('Start')); ?></button></form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo $e(adminUrl('Services/restart/') . urlencode($id)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-danger"><?php echo $e(t('Restart')); ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->services)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?php echo $e(t('No services registered.')); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?php echo $e(t('Worker pools')); ?></strong>
            <a href="<?php echo $e(adminUrl('Services/pools')); ?>" class="small"><?php echo $e(t('Configure pools')); ?></a>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th><?php echo $e(t('Pool')); ?></th><th><?php echo $e(t('Task types')); ?></th><th class="text-end"><?php echo $e(t('Workers')); ?></th><th class="text-end"><?php echo $e(t('Backlog')); ?></th><th class="text-end"><?php echo $e(t('Load')); ?></th><th><?php echo $e(t('Decision')); ?></th><th><?php echo $e(t('State')); ?></th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pools as $pool): ?>
                    <?php
                    $name  = (string) $pool['name'];
                    $types = (array) ($pool['types'] ?? []);
                    ?>
                    <tr data-pool="<?php echo $e($name); ?>">
                        <td>
                            <strong><?php echo $e($name); ?></strong>
                            <span class="badge <?php echo ($pool['source'] ?? '') === 'code' ? 'bg-light text-dark border' : 'bg-info text-dark'; ?>"><?php echo $e(($pool['source'] ?? '') === 'code' ? t('code') : t('screen')); ?></span>
                        </td>
                        <td class="small"><?php echo $types === [] ? '<span class="text-muted">' . $e(t('every type')) . '</span>' : $e(implode(', ', $types)); ?></td>
                        <td class="text-end" data-field="size"><?php echo $pool['size'] === null ? '—' : (int) $pool['size']; ?></td>
                        <td class="text-end" data-field="backlog"><?php echo $pool['backlog'] === null ? '—' : (int) $pool['backlog']; ?></td>
                        <td class="text-end" data-field="load"><?php echo $pool['load'] === null ? '—' : $e(sprintf('%.2f', (float) $pool['load'])); ?></td>
                        <td class="small text-muted" data-field="decision" style="max-width:28rem"><?php echo $e($pool['decision'] ?? ''); ?></td>
                        <td>
                            <?php if (empty($pool['enabled'])): ?>
                                <span class="badge bg-secondary"><?php echo $e(t('Stopped')); ?></span>
                            <?php else: ?>
                                <span class="badge bg-success"><?php echo $e(t('Running')); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($pool['waiting'])): ?>
                                <span class="badge bg-warning text-dark" data-field="waiting" title="<?php echo $e(t("Saved after the supervisor's last cycle")); ?>"><?php echo $e(t('waiting for supervisor')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (empty($pool['enabled'])): ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/poolstart/') . urlencode($name)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-success"><?php echo $e(t('Start')); ?></button></form>
                            <?php else: ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/poolstop/') . urlencode($name)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-warning"><?php echo $e(t('Stop')); ?></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pools === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3"><?php echo $e(t('No pools.')); ?> <a href="<?php echo $e(adminUrl('Services/pools')); ?>"><?php echo $e(t('Add one')); ?></a></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($this->batches)): ?>
    <div class="card">
        <div class="card-header"><strong><?php echo $e(t('Batches')); ?></strong> <small class="text-muted"><?php echo $e(t('— the last 24 hours')); ?></small></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th><?php echo $e(t('Batch')); ?></th><th><?php echo $e(t('Queued')); ?></th><th class="text-end"><?php echo $e(t('Pending')); ?></th><th class="text-end"><?php echo $e(t('Processing')); ?></th><th class="text-end"><?php echo $e(t('Done')); ?></th><th class="text-end"><?php echo $e(t('Failed')); ?></th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($this->batches as $batch): ?>
                    <tr data-batch="<?php echo $e($batch['id']); ?>">
                        <td><?php echo $batch['name'] === '' ? '<span class="text-muted">' . $e(substr($batch['id'], 0, 8)) . '</span>' : $e($batch['name']); ?></td>
                        <td class="small text-muted"><?php echo $e($batch['queued_at']); ?></td>
                        <td class="text-end" data-field="pending"><?php echo (int) $batch['pending']; ?></td>
                        <td class="text-end" data-field="processing"><?php echo (int) $batch['processing']; ?></td>
                        <td class="text-end" data-field="done"><?php echo (int) $batch['completed'] + (int) $batch['warning']; ?></td>
                        <td class="text-end <?php echo $batch['failed'] > 0 ? 'text-danger' : ''; ?>" data-field="failed"><?php echo (int) $batch['failed']; ?></td>
                        <td data-field="finished"><?php echo $batch['finished'] ? '<span class="badge bg-success">' . $e(t('done')) . '</span>' : '<span class="badge bg-primary">' . $e(t('running')) . '</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/refresh.php'; ?>
