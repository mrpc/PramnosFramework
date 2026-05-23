<?php
/**
 * Queue jobs list (plain-CSS theme).
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
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >Queue</h2>
        <div style="display:flex;gap:8px">
            <a href="<?php echo sURL; ?>Queue/stats" class="btn btn-sm btn-outline-info">Stats</a>
            <a href="<?php echo sURL; ?>Queue/retryall" class="btn btn-sm btn-outline-warning" onclick="return confirm('Retry all failed jobs?')">Retry All Failed</a>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body py-2">
            <form method="get" style="display:flex;gap:8px;align-items:center">
                <select name="status" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px" style="max-width:160px">
                    <option value="">All statuses</option>
                    <?php foreach (['pending','processing','completed','failed','deleted'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-secondary">Filter</button>
                <?php if ($filterStatus === 'failed' || $filterStatus === 'completed' || $filterStatus === 'deleted'): ?>
                    <a href="<?php echo sURL; ?>Queue/clear?status=<?php echo $filterStatus; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Clear all <?php echo $filterStatus; ?> jobs?')">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>ID</th><th>Type</th><th>Status</th><th>Attempts</th><th>Created</th><th>Next Run</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->jobs ?? []) as $job): ?>
                    <tr>
                        <td><?php echo (int)$job['id']; ?></td>
                        <td><?php echo htmlspecialchars($job['type'] ?? $job['classname'] ?? ''); ?></td>
                        <td><?php echo $statusBadge($job['status'] ?? ''); ?></td>
                        <td><?php echo (int)($job['attempts'] ?? 0); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars($job['createdat'] ?? ''); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars($job['nextrun'] ?? ''); ?></td>
                        <td style="text-align:right">
                            <?php if (($job['status'] ?? '') === 'failed'): ?>
                                <a href="<?php echo sURL; ?>Queue/retry/<?php echo (int)$job['id']; ?>" class="btn btn-sm btn-outline-warning">Retry</a>
                            <?php endif; ?>
                            <a href="<?php echo sURL; ?>Queue/delete/<?php echo (int)$job['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete job?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->jobs)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#888;padding:24px">No jobs found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
