<?php
/**
 * Queue jobs list (Tailwind theme).
 *
 * Variables:
 *   $this->jobs  — iterable rows
 *   $this->page  — current page
 *   $this->total — total count
 */
$statusBadge = fn($s) => match($s) {
    'pending'    => '<span class="badge bg-secondary">Pending</span>',
    'processing' => '<span class="badge bg-primary">Processing</span>',
    'completed'  => '<span class="badge bg-success">Completed</span>',
    'failed'     => '<span class="badge bg-danger">Failed</span>',
    'deleted'    => '<span class="badge bg-light text-dark">Deleted</span>',
    default      => '<span class="badge bg-light text-dark">' . htmlspecialchars($s) . '</span>',
};
$filterStatus = htmlspecialchars($_GET['status'] ?? '');
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 >Queue</h2>
        <div class="flex gap-2">
            <a href="<?php echo adminUrl('Queue/stats'); ?>" class="btn btn-outline btn-primary btn-xs">Stats</a>
            <a href="<?php echo adminUrl('Queue/retryall'); ?>" class="btn btn-outline btn-warning btn-xs" data-confirm="Retry all failed jobs?">Retry All Failed</a>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4">
        <div class="px-4 py-2">
            <form method="get" class="flex gap-2 items-center">
                <select name="status" class="input input-sm" style="max-width:160px">
                    <option value="">All statuses</option>
                    <?php foreach (['pending','processing','completed','failed','deleted'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline btn-xs">Filter</button>
                <?php if ($filterStatus === 'failed' || $filterStatus === 'completed' || $filterStatus === 'deleted'): ?>
                    <a href="<?php echo adminUrl('Queue/clear'); ?>?status=<?php echo $filterStatus; ?>" class="btn btn-outline btn-error btn-xs" data-confirm="Clear all <?php echo $filterStatus; ?> jobs?">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>ID</th><th>Type</th><th>Status</th><th>Attempts</th><th>Created</th><th>Next Run</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->jobs ?? []) as $job): ?>
                    <tr>
                        <td><?php echo (int)$job['taskid']; ?></td>
                        <td><?php echo htmlspecialchars($job['type'] ?? $job['classname'] ?? ''); ?>
                            <?php if (($job['error'] ?? '') !== ''): ?>
                                <?php /* The reason a job failed was selected by the
                                         controller and rendered nowhere, so the screen
                                         said a job had failed and not why — with the
                                         answer already in hand. */ ?>
                                <div class="text-xs text-error mt-1" title="<?php echo htmlspecialchars((string) $job['error']); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth((string) $job['error'], 0, 160, '…')); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $statusBadge($job['status'] ?? ''); ?></td>
                        <td><?php echo (int)($job['attempts'] ?? 0); ?></td>
                        <td class="text-base-content/60 text-xs"><?php echo htmlspecialchars($job['createdat'] ?? ''); ?></td>
                        <td class="text-base-content/60 text-xs"><?php echo htmlspecialchars($job['nextrun'] ?? ''); ?></td>
                        <td class="text-right">
                            <?php if (($job['status'] ?? '') === 'failed'): ?>
                                <a href="<?php echo adminUrl('Queue' . '/retry/' . ((int)$job['taskid'])); ?>" class="btn btn-outline btn-warning btn-xs">Retry</a>
                            <?php endif; ?>
                            <a href="<?php echo adminUrl('Queue' . '/delete/' . ((int)$job['taskid'])); ?>" class="btn btn-outline btn-error btn-xs" data-confirm="Delete job?">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->jobs)): ?>
                    <tr><td colspan="7" class="text-center text-base-content/60 py-8">No jobs found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
