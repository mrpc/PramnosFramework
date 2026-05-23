<?php
/**
 * Token Actions audit log list (Tailwind theme).
 *
 * Variables:
 *   $this->actions — iterable rows
 *   $this->page    — current page
 *   $this->total   — total count
 */
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 >API Audit Log</h2>
        <div class="flex gap-2">
            <a href="<?php echo sURL; ?>TokenActions/stats" class="btn btn-outline-info btn-sm">Stats</a>
            <a href="<?php echo sURL; ?>TokenActions/export<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn btn-outline-secondary btn-sm">Export CSV</a>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-4">
        <div class="px-4 py-2">
            <form method="get" class="flex flex-wrap gap-2 items-end">
                <div >
                    <input type="number" name="token_id" class="px-2 py-1 border border-gray-300 rounded text-sm" placeholder="Token ID" value="<?php echo (int)($_GET['token_id'] ?? 0) ?: ''; ?>">
                </div>
                <div >
                    <input type="number" name="user_id" class="px-2 py-1 border border-gray-300 rounded text-sm" placeholder="User ID" value="<?php echo (int)($_GET['user_id'] ?? 0) ?: ''; ?>">
                </div>
                <div >
                    <input type="number" name="status_code" class="px-2 py-1 border border-gray-300 rounded text-sm" placeholder="HTTP Status" value="<?php echo (int)($_GET['status_code'] ?? 0) ?: ''; ?>">
                </div>
                <div >
                    <input type="date" name="date_from" class="px-2 py-1 border border-gray-300 rounded text-sm" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div >
                    <input type="date" name="date_to" class="px-2 py-1 border border-gray-300 rounded text-sm" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div >
                    <button class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Filter</button>
                </div>
            </form>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>ID</th><th>Token</th><th>Endpoint</th><th>Status</th><th>Time (ms)</th><th>When</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->actions ?? []) as $a): ?>
                    <tr>
                        <td><?php echo (int)$a['id']; ?></td>
                        <td class="text-gray-400 text-xs"><?php echo (int)($a['token_id'] ?? 0); ?></td>
                        <td class="truncate max-w-xs"><?php echo htmlspecialchars($a['endpoint'] ?? $a['action'] ?? ''); ?></td>
                        <td>
                            <?php $sc = (int)($a['status_code'] ?? 0); ?>
                            <span class="badge <?php echo $sc >= 500 ? 'bg-danger' : ($sc >= 400 ? 'bg-warning text-dark' : 'bg-success'); ?>"><?php echo $sc ?: '—'; ?></span>
                        </td>
                        <td><?php echo number_format($a['execution_time'] ?? 0, 0); ?></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars($a['servertime'] ?? $a['created_at'] ?? ''); ?></td>
                        <td><a href="<?php echo sURL; ?>TokenActions/show/<?php echo (int)$a['id']; ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->actions)): ?>
                    <tr><td colspan="7" class="text-center text-gray-400 py-8">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
