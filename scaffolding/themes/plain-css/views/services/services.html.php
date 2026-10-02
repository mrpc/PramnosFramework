<?php
/**
 * Services / Workers (plain-CSS theme).
 *
 * Variables:
 *   $this->services     — enriched service entries {id, daemon, profile, workerId, pid, status, lockFile, updatedAt}
 *   $this->orchestrator — the supervisor's own state {running, pid, heartbeat_age_seconds}
 *   $this->pools        — pools as the supervisor last saw them, plus saved rows it has not seen
 *   $this->batches      — batches of the last day, counted by status
 *   $this->stopped      — services an operator stopped, by id
 *
 * Every change is a POST with the session's token; the supervisor applies it on its next
 * cycle. The numbers refresh from Services/status every ten seconds without a reload, so an
 * open form is not lost.
 */
$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$pools  = $this->pools ?? [];
$stopped = $this->stopped ?? [];
$poolFields = [
    'types'        => ['Task types', 'comma-separated; blank = every type'],
    'floor'        => ['Floor', 'always at least'],
    'ceiling'      => ['Ceiling', 'never more than'],
    'grow_above'   => ['Grow above', 'pending tasks'],
    'shrink_below' => ['Shrink below', 'pending tasks'],
    'load_percent' => ['Load %', 'per core; stop growing past it'],
    'cooldown'     => ['Cooldown', 'cycles after a change'],
];
?>
<div class="page-section" id="services-screen" data-status-url="<?php echo $e(adminUrl('Services/status')); ?>">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Services</h2>
        <form method="post" action="<?php echo $e(adminUrl('Services/restartall')); ?>" class="m-0"
              onsubmit="return confirm('Restart every running service? Each finishes its current task first.');">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <button type="submit" class="btn btn-outline-danger btn-sm" title="For a deploy the supervisor cannot see: composer update, a copied build">Restart all</button>
        </form>
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
            <strong>The orchestrator is not running.</strong>
            The controls below are read by the supervisor on its next cycle, so until it runs
            nothing changes. Stop still takes effect, because a daemon checks its own stop file.
            <p class="mb-0 mt-2">
                <a href="https://mrpc.github.io/PramnosFramework/Pramnos_Workers_And_Daemons_Guide/#creating-the-orchestrator-service" target="_blank" rel="noopener">How to create the orchestrator service &rarr;</a>
                <span class="text-muted">— a systemd unit for Ubuntu / Debian, and the Docker equivalent.</span>
            </p>
        </div>
    <?php elseif ($heartbeat !== null && $heartbeat > 120): ?>
        <div role="status" class="alert alert-warning">
            <strong>The orchestrator has not cycled for <?php echo (int) $heartbeat; ?>s</strong>
            (pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?>). A live process with a
            stale heartbeat is stuck rather than healthy.
        </div>
    <?php else: ?>
        <p class="text-muted small" data-field="supervisor">
            Supervisor running, pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?><?php
            if ($heartbeat !== null) {
                echo ', last cycle ' . (int) $heartbeat . 's ago';
            }
            ?>.
        </p>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Worker pools</strong>
            <small class="text-muted">Sized every cycle from the backlog of their task types</small>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Pool</th><th>Types</th><th class="text-end">Workers</th><th class="text-end">Backlog</th><th class="text-end">Load</th><th>Decision</th><th>State</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pools as $pool): ?>
                    <?php
                    $name   = (string) $pool['name'];
                    $row    = $pool['row'] ?? null;
                    $config = (array) ($pool['config'] ?? []);
                    $types  = (array) ($pool['types'] ?? []);
                    ?>
                    <tr data-pool="<?php echo $e($name); ?>">
                        <td>
                            <strong><?php echo $e($name); ?></strong>
                            <span class="badge <?php echo ($pool['source'] ?? '') === 'code' ? 'bg-light text-dark border' : 'bg-info text-dark'; ?>"><?php echo ($pool['source'] ?? '') === 'code' ? 'code' : 'screen'; ?></span>
                        </td>
                        <td class="small"><?php echo $types === [] ? '<span class="text-muted">every type</span>' : $e(implode(', ', $types)); ?></td>
                        <td class="text-end" data-field="size"><?php echo $pool['size'] === null ? '—' : (int) $pool['size']; ?></td>
                        <td class="text-end" data-field="backlog"><?php echo $pool['backlog'] === null ? '—' : (int) $pool['backlog']; ?></td>
                        <td class="text-end" data-field="load"><?php echo $pool['load'] === null ? '—' : $e(sprintf('%.2f', (float) $pool['load'])); ?></td>
                        <td class="small text-muted" data-field="decision" style="max-width:28rem"><?php echo $e($pool['decision'] ?? ''); ?></td>
                        <td>
                            <?php if (empty($pool['enabled'])): ?>
                                <span class="badge bg-secondary">Stopped</span>
                            <?php else: ?>
                                <span class="badge bg-success">Running</span>
                            <?php endif; ?>
                            <?php if (!empty($pool['waiting'])): ?>
                                <span class="badge bg-warning text-dark" data-field="waiting" title="Saved after the supervisor's last cycle">waiting for supervisor</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (empty($pool['enabled'])): ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/poolstart/') . urlencode($name)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-success">Start</button></form>
                            <?php else: ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/poolstop/') . urlencode($name)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-warning">Stop</button></form>
                            <?php endif; ?>
                            <?php if ($row !== null): ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/pooldelete/') . urlencode($name)); ?>" class="d-inline m-0"
                                      onsubmit="return confirm(<?php echo $e(json_encode(($pool['source'] ?? '') === 'code' ? 'Go back to the limits declared in code?' : 'Remove this pool? Its workers are stopped.')); ?>);">
                                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo ($pool['source'] ?? '') === 'code' ? 'Reset' : 'Remove'; ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="table-light">
                        <td colspan="8" class="py-1">
                            <details>
                                <summary class="small text-muted">Edit limits<?php echo ($pool['source'] ?? '') === 'code' ? ' — blank keeps the value declared in code' : ''; ?></summary>
                                <form method="post" action="<?php echo $e(adminUrl('Services/poolsave')); ?>" class="row g-2 align-items-end py-2">
                                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                    <input type="hidden" name="name" value="<?php echo $e($name); ?>">
                                    <?php foreach ($poolFields as $field => [$label, $hint]): ?>
                                        <?php
                                        $value = $row[$field] ?? '';
                                        $declared = $field === 'types'
                                            ? implode(',', $types)
                                            : ($field === 'load_percent'
                                                ? (isset($config['load_ceiling']) ? (string) round((float) $config['load_ceiling'] * 100) : '')
                                                : (string) ($config[$field] ?? ''));
                                        ?>
                                        <div class="<?php echo $field === 'types' ? 'col-md-3' : 'col-md-1'; ?>">
                                            <label class="form-label small mb-0" title="<?php echo $e($hint); ?>"><?php echo $e($label); ?></label>
                                            <input class="form-control form-control-sm" name="<?php echo $e($field); ?>" value="<?php echo $e($value); ?>" placeholder="<?php echo $e($declared); ?>"
                                                   <?php echo $field === 'types' ? '' : 'inputmode="numeric"'; ?>>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary">Save</button></div>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pools === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">No pools. Declare one with <code>queuePool()</code> in code, or add one below.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            <details>
                <summary>Add a pool</summary>
                <form method="post" action="<?php echo $e(adminUrl('Services/poolsave')); ?>" class="row g-2 align-items-end pt-2">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <div class="col-md-2">
                        <label class="form-label small mb-0">Name</label>
                        <input class="form-control form-control-sm" name="name" required pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,59}" placeholder="mail">
                    </div>
                    <?php foreach ($poolFields as $field => [$label, $hint]): ?>
                        <div class="<?php echo $field === 'types' ? 'col-md-3' : 'col-md-1'; ?>">
                            <label class="form-label small mb-0" title="<?php echo $e($hint); ?>"><?php echo $e($label); ?></label>
                            <input class="form-control form-control-sm" name="<?php echo $e($field); ?>" placeholder="<?php echo $e($hint); ?>"
                                   <?php echo $field === 'types' ? '' : 'inputmode="numeric"'; ?>>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-md-12"><button type="submit" class="btn btn-sm btn-primary">Add pool</button></div>
                </form>
            </details>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Services</strong></div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Service</th><th>Worker ID</th><th>PID</th><th>Status</th><th>Updated</th><th></th></tr>
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
                                <span class="badge bg-secondary" title="Stopped by <?php echo $e($stopped[$id]['stopped_by'] ?? 'an operator'); ?>">Stopped by operator</span>
                            <?php elseif ($svc['status'] === 'running'): ?>
                                <span class="badge bg-success">Running</span>
                            <?php elseif ($svc['status'] === 'error'): ?>
                                <span class="badge bg-warning text-dark">Stop Pending</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Stopped</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?php echo $e($svc['updatedAt'] ?? ''); ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?php echo $e(adminUrl('Services/logs/') . urlencode($id)); ?>" class="btn btn-sm btn-outline-secondary">Logs</a>
                            <?php if ($svc['status'] === 'running' && !isset($stopped[$id])): ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/stop/') . urlencode($id)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-warning">Stop</button></form>
                            <?php else: ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/start/') . urlencode($id)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-success">Start</button></form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo $e(adminUrl('Services/restart/') . urlencode($id)); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-danger">Restart</button></form>
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

    <?php if (!empty($this->batches)): ?>
    <div class="card">
        <div class="card-header"><strong>Batches</strong> <small class="text-muted">— the last 24 hours</small></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Batch</th><th>Queued</th><th class="text-end">Pending</th><th class="text-end">Processing</th><th class="text-end">Done</th><th class="text-end">Failed</th><th></th></tr>
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
                        <td data-field="finished"><?php echo $batch['finished'] ? '<span class="badge bg-success">done</span>' : '<span class="badge bg-primary">running</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<style>
/* The few classes this screen uses that the plain-CSS theme does not define, kept to it. */
#services-screen .d-flex { display: flex; } #services-screen .justify-content-between { justify-content: space-between; }
#services-screen .align-items-center { align-items: center; } #services-screen .align-items-end { align-items: flex-end; }
#services-screen .row { display: flex; flex-wrap: wrap; gap: 8px; } #services-screen .col-md-1 { width: 6rem; }
#services-screen .col-md-2 { width: 10rem; } #services-screen .col-md-3 { width: 14rem; } #services-screen .col-md-12 { width: 100%; }
#services-screen .card { margin-bottom: 16px; } #services-screen .card-header, #services-screen .card-footer { padding: 10px 14px; background: #f7f7f9; }
#services-screen .card-footer { border-top: 1px solid #e5e5e5; } #services-screen .table-light { background: #f7f7f9; }
#services-screen .text-end { text-align: right; } #services-screen .text-nowrap { white-space: nowrap; } #services-screen .d-inline { display: inline; }
#services-screen .m-0 { margin: 0; } #services-screen .text-muted { color: #6c757d; } #services-screen .small { font-size: .85em; }
#services-screen .text-danger { color: #b02a37; } #services-screen .bg-info { background: #cff4fc; } #services-screen .bg-primary { background: #cfe2ff; }
#services-screen .bg-light { background: #f8f9fa; } #services-screen table { width: 100%; border-collapse: collapse; }
#services-screen td, #services-screen th { padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: middle; }
#services-screen .form-control { width: 100%; box-sizing: border-box; padding: 4px 6px; }
</style>
<?php include __DIR__ . '/refresh.php'; ?>
