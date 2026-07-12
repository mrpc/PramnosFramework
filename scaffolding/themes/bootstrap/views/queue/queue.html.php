<?php
/**
 * Queue jobs list (Bootstrap theme).
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
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Queue</h2>
        <div class="d-flex gap-2">
            <a href="<?php echo sURL; ?>Queue/stats" class="btn btn-sm btn-outline-info">Stats</a>
            <a href="<?php echo sURL; ?>Queue/retryall" class="btn btn-sm btn-outline-warning" data-confirm="Retry all failed jobs?">Retry All Failed</a>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="get" class="d-flex gap-2 align-items-center">
                <select name="status" class="form-select form-select-sm" style="max-width:160px">
                    <option value="">All statuses</option>
                    <?php foreach (['pending','processing','completed','failed','deleted'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-secondary">Filter</button>
                <?php if ($filterStatus === 'failed' || $filterStatus === 'completed' || $filterStatus === 'deleted'): ?>
                    <a href="<?php echo sURL; ?>Queue/clear?status=<?php echo $filterStatus; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Clear all <?php echo $filterStatus; ?> jobs?">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>ID</th><th>Type</th><th>Status</th><th>Attempts</th><th>Created</th><th>Next Run</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->jobs ?? []) as $job): ?>
                    <tr>
                        <td><?php echo (int)$job['id']; ?></td>
                        <td><?php echo htmlspecialchars($job['type'] ?? $job['classname'] ?? ''); ?></td>
                        <td><?php echo $statusBadge($job['status'] ?? ''); ?></td>
                        <td><?php echo (int)($job['attempts'] ?? 0); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($job['createdat'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($job['nextrun'] ?? ''); ?></td>
                        <td class="text-end">
                            <?php if (($job['status'] ?? '') === 'failed'): ?>
                                <a href="<?php echo sURL; ?>Queue/retry/<?php echo (int)$job['id']; ?>" class="btn btn-sm btn-outline-warning">Retry</a>
                            <?php endif; ?>
                            <a href="<?php echo sURL; ?>Queue/delete/<?php echo (int)$job['id']; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Delete job?">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->jobs)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No jobs found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
