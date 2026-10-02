<?php
/**
 * Services / Workers (Tailwind / daisyUI theme).
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
<div class="px-4 py-6" id="services-screen" data-status-url="<?php echo $e(adminUrl('Services/status')); ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="mb-0">Services</h2>
        <form method="post" action="<?php echo $e(adminUrl('Services/restartall')); ?>" class="m-0"
              onsubmit="return confirm('Restart every running service? Each finishes its current task first.');">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <button type="submit" class="btn btn-outline btn-error btn-sm" title="For a deploy the supervisor cannot see: composer update, a copied build">Restart all</button>
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
        <div role="status" class="alert alert-warning mb-4">
            <strong>The orchestrator is not running.</strong>
            The controls below are read by the supervisor on its next cycle, so until it runs
            nothing changes. Stop still takes effect, because a daemon checks its own stop file.
            <p class="mt-2">
                <a href="https://mrpc.github.io/PramnosFramework/Pramnos_Workers_And_Daemons_Guide/#creating-the-orchestrator-service" target="_blank" rel="noopener">How to create the orchestrator service &rarr;</a>
                <span class="text-base-content/60">— a systemd unit for Ubuntu / Debian, and the Docker equivalent.</span>
            </p>
        </div>
    <?php elseif ($heartbeat !== null && $heartbeat > 120): ?>
        <div role="status" class="alert alert-warning mb-4">
            <strong>The orchestrator has not cycled for <?php echo (int) $heartbeat; ?>s</strong>
            (pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?>). A live process with a
            stale heartbeat is stuck rather than healthy.
        </div>
    <?php else: ?>
        <p class="text-sm text-base-content/60 mb-4" data-field="supervisor">
            Supervisor running, pid <?php echo (int) ($orchestrator['pid'] ?? 0); ?><?php
            if ($heartbeat !== null) {
                echo ', last cycle ' . (int) $heartbeat . 's ago';
            }
            ?>.
        </p>
    <?php endif; ?>

    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-4 py-3 border-b border-base-300 flex justify-between items-center">
            <strong>Worker pools</strong>
            <small class="text-base-content/60">Sized every cycle from the backlog of their task types</small>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Pool</th><th>Types</th><th class="text-right">Workers</th><th class="text-right">Backlog</th><th class="text-right">Load</th><th>Decision</th><th>State</th><th></th></tr>
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
                            <span class="badge <?php echo ($pool['source'] ?? '') === 'code' ? 'badge-ghost' : 'badge-info'; ?>"><?php echo ($pool['source'] ?? '') === 'code' ? 'code' : 'screen'; ?></span>
                        </td>
                        <td class="text-xs"><?php echo $types === [] ? '<span class="text-base-content/60">every type</span>' : $e(implode(', ', $types)); ?></td>
                        <td class="text-right" data-field="size"><?php echo $pool['size'] === null ? '—' : (int) $pool['size']; ?></td>
                        <td class="text-right" data-field="backlog"><?php echo $pool['backlog'] === null ? '—' : (int) $pool['backlog']; ?></td>
                        <td class="text-right" data-field="load"><?php echo $pool['load'] === null ? '—' : $e(sprintf('%.2f', (float) $pool['load'])); ?></td>
                        <td class="text-xs text-base-content/60" data-field="decision" style="max-width:28rem"><?php echo $e($pool['decision'] ?? ''); ?></td>
                        <td>
                            <?php if (empty($pool['enabled'])): ?>
                                <span class="badge badge-neutral">Stopped</span>
                            <?php else: ?>
                                <span class="badge badge-success">Running</span>
                            <?php endif; ?>
                            <?php if (!empty($pool['waiting'])): ?>
                                <span class="badge badge-warning" data-field="waiting" title="Saved after the supervisor's last cycle">waiting for supervisor</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <?php if (empty($pool['enabled'])): ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/poolstart/') . urlencode($name)); ?>" class="inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-outline btn-success btn-xs">Start</button></form>
                            <?php else: ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/poolstop/') . urlencode($name)); ?>" class="inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-outline btn-warning btn-xs">Stop</button></form>
                            <?php endif; ?>
                            <?php if ($row !== null): ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/pooldelete/') . urlencode($name)); ?>" class="inline m-0"
                                      onsubmit="return confirm(<?php echo $e(json_encode(($pool['source'] ?? '') === 'code' ? 'Go back to the limits declared in code?' : 'Remove this pool? Its workers are stopped.')); ?>);">
                                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                    <button type="submit" class="btn btn-outline btn-error btn-xs"><?php echo ($pool['source'] ?? '') === 'code' ? 'Reset' : 'Remove'; ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="bg-base-200 text-xs text-base-content/70 uppercase">
                        <td colspan="8" class="py-1">
                            <details>
                                <summary class="text-xs text-base-content/60">Edit limits<?php echo ($pool['source'] ?? '') === 'code' ? ' — blank keeps the value declared in code' : ''; ?></summary>
                                <form method="post" action="<?php echo $e(adminUrl('Services/poolsave')); ?>" class="flex flex-wrap gap-2 items-end py-2">
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
                                        <div class="<?php echo $field === 'types' ? 'w-56' : 'w-24'; ?>">
                                            <label class="label text-xs" title="<?php echo $e($hint); ?>"><?php echo $e($label); ?></label>
                                            <input class="input input-bordered input-sm w-full" name="<?php echo $e($field); ?>" value="<?php echo $e($value); ?>" placeholder="<?php echo $e($declared); ?>"
                                                   <?php echo $field === 'types' ? '' : 'inputmode="numeric"'; ?>>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="w-40"><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pools === []): ?>
                    <tr><td colspan="8" class="text-center text-base-content/60 py-4">No pools. Declare one with <code>queuePool()</code> in code, or add one below.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-base-300">
            <details>
                <summary class="cursor-pointer">Add a pool</summary>
                <form method="post" action="<?php echo $e(adminUrl('Services/poolsave')); ?>" class="flex flex-wrap gap-2 items-end pt-2">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <div class="w-40">
                        <label class="label text-xs">Name</label>
                        <input class="input input-bordered input-sm w-full" name="name" required pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,59}" placeholder="mail">
                    </div>
                    <?php foreach ($poolFields as $field => [$label, $hint]): ?>
                        <div class="<?php echo $field === 'types' ? 'w-56' : 'w-24'; ?>">
                            <label class="label text-xs" title="<?php echo $e($hint); ?>"><?php echo $e($label); ?></label>
                            <input class="input input-bordered input-sm w-full" name="<?php echo $e($field); ?>" placeholder="<?php echo $e($hint); ?>"
                                   <?php echo $field === 'types' ? '' : 'inputmode="numeric"'; ?>>
                        </div>
                    <?php endforeach; ?>
                    <div class="w-full"><button type="submit" class="btn btn-primary btn-sm">Add pool</button></div>
                </form>
            </details>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs mb-6">
        <div class="px-4 py-3 border-b border-base-300"><strong>Services</strong></div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Service</th><th>Worker ID</th><th>PID</th><th>Status</th><th>Updated</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->services ?? []) as $svc): ?>
                    <?php $id = (string) ($svc['id'] ?? ''); ?>
                    <tr>
                        <td><strong><?php echo $e($svc['daemon'] ?? ''); ?></strong>
                            <?php if (!empty($svc['profile'])): ?>
                                <small class="text-base-content/60">(<?php echo $e($svc['profile']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $e($svc['workerId'] ?? ''); ?></td>
                        <td><?php echo !empty($svc['pid']) ? (int) $svc['pid'] : '—'; ?></td>
                        <td>
                            <?php if (isset($stopped[$id])): ?>
                                <span class="badge badge-neutral" title="Stopped by <?php echo $e($stopped[$id]['stopped_by'] ?? 'an operator'); ?>">Stopped by operator</span>
                            <?php elseif ($svc['status'] === 'running'): ?>
                                <span class="badge badge-success">Running</span>
                            <?php elseif ($svc['status'] === 'error'): ?>
                                <span class="badge badge-warning">Stop Pending</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Stopped</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-base-content/60 mb-4"><?php echo $e($svc['updatedAt'] ?? ''); ?></td>
                        <td class="text-right whitespace-nowrap">
                            <a href="<?php echo $e(adminUrl('Services/logs/') . urlencode($id)); ?>" class="btn btn-outline btn-xs">Logs</a>
                            <?php if ($svc['status'] === 'running' && !isset($stopped[$id])): ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/stop/') . urlencode($id)); ?>" class="inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-outline btn-warning btn-xs">Stop</button></form>
                            <?php else: ?>
                                <form method="post" action="<?php echo $e(adminUrl('Services/start/') . urlencode($id)); ?>" class="inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-outline btn-success btn-xs">Start</button></form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo $e(adminUrl('Services/restart/') . urlencode($id)); ?>" class="inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-outline btn-error btn-xs">Restart</button></form>
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

    <?php if (!empty($this->batches)): ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-4 py-3 border-b border-base-300"><strong>Batches</strong> <small class="text-base-content/60">— the last 24 hours</small></div>
        <div class="overflow-x-auto">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Batch</th><th>Queued</th><th class="text-right">Pending</th><th class="text-right">Processing</th><th class="text-right">Done</th><th class="text-right">Failed</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($this->batches as $batch): ?>
                    <tr data-batch="<?php echo $e($batch['id']); ?>">
                        <td><?php echo $batch['name'] === '' ? '<span class="text-base-content/60">' . $e(substr($batch['id'], 0, 8)) . '</span>' : $e($batch['name']); ?></td>
                        <td class="text-xs text-base-content/60"><?php echo $e($batch['queued_at']); ?></td>
                        <td class="text-right" data-field="pending"><?php echo (int) $batch['pending']; ?></td>
                        <td class="text-right" data-field="processing"><?php echo (int) $batch['processing']; ?></td>
                        <td class="text-right" data-field="done"><?php echo (int) $batch['completed'] + (int) $batch['warning']; ?></td>
                        <td class="text-end <?php echo $batch['failed'] > 0 ? 'text-error' : ''; ?>" data-field="failed"><?php echo (int) $batch['failed']; ?></td>
                        <td data-field="finished"><?php echo $batch['finished'] ? '<span class="badge badge-success">done</span>' : '<span class="badge badge-primary">running</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/refresh.php'; ?>
