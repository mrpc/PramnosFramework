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
            <a href="<?php echo sURL; ?>Queue/stats" class="px-3 py-1 border border-blue-300 text-blue-700 text-xs rounded hover:bg-blue-50">Stats</a>
            <a href="<?php echo sURL; ?>Queue/retryall" class="px-3 py-1 border border-yellow-400 text-yellow-700 text-xs rounded hover:bg-yellow-50" onclick="return confirm('Retry all failed jobs?')">Retry All Failed</a>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-4">
        <div class="px-4 py-2">
            <form method="get" class="flex gap-2 items-center">
                <select name="status" class="px-2 py-1 border border-gray-300 rounded text-sm" style="max-width:160px">
                    <option value="">All statuses</option>
                    <?php foreach (['pending','processing','completed','failed','deleted'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Filter</button>
                <?php if ($filterStatus === 'failed' || $filterStatus === 'completed' || $filterStatus === 'deleted'): ?>
                    <a href="<?php echo sURL; ?>Queue/clear?status=<?php echo $filterStatus; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" onclick="return confirm('Clear all <?php echo $filterStatus; ?> jobs?')">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>ID</th><th>Type</th><th>Status</th><th>Attempts</th><th>Created</th><th>Next Run</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->jobs ?? []) as $job): ?>
                    <tr>
                        <td><?php echo (int)$job['id']; ?></td>
                        <td><?php echo htmlspecialchars($job['type'] ?? $job['classname'] ?? ''); ?></td>
                        <td><?php echo $statusBadge($job['status'] ?? ''); ?></td>
                        <td><?php echo (int)($job['attempts'] ?? 0); ?></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars($job['createdat'] ?? ''); ?></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars($job['nextrun'] ?? ''); ?></td>
                        <td class="text-right">
                            <?php if (($job['status'] ?? '') === 'failed'): ?>
                                <a href="<?php echo sURL; ?>Queue/retry/<?php echo (int)$job['id']; ?>" class="px-3 py-1 border border-yellow-400 text-yellow-700 text-xs rounded hover:bg-yellow-50">Retry</a>
                            <?php endif; ?>
                            <a href="<?php echo sURL; ?>Queue/delete/<?php echo (int)$job['id']; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" onclick="return confirm('Delete job?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->jobs)): ?>
                    <tr><td colspan="7" class="text-center text-gray-400 py-8">No jobs found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
