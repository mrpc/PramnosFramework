<?php
/**
 * Token Actions audit log list (plain-CSS theme).
 *
 * Variables:
 *   $this->actions — iterable rows
 *   $this->page    — current page
 *   $this->total   — total count
 */
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >API Audit Log</h2>
        <div style="display:flex;gap:8px">
            <a href="<?php echo sURL; ?>TokenActions/stats" class="btn btn-outline-info btn-sm">Stats</a>
            <a href="<?php echo sURL; ?>TokenActions/export<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn btn-outline-secondary btn-sm">Export CSV</a>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body py-2">
            <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
                <div style="flex:none">
                    <input type="number" name="token_id" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px" placeholder="Token ID" value="<?php echo (int)($_GET['token_id'] ?? 0) ?: ''; ?>">
                </div>
                <div style="flex:none">
                    <input type="number" name="user_id" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px" placeholder="User ID" value="<?php echo (int)($_GET['user_id'] ?? 0) ?: ''; ?>">
                </div>
                <div style="flex:none">
                    <input type="number" name="status_code" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px" placeholder="HTTP Status" value="<?php echo (int)($_GET['status_code'] ?? 0) ?: ''; ?>">
                </div>
                <div style="flex:none">
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div style="flex:none">
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div style="flex:none">
                    <button class="btn btn-sm btn-outline-secondary">Filter</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>ID</th><th>Token</th><th>Endpoint</th><th>Status</th><th>Time (ms)</th><th>When</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->actions ?? []) as $a): ?>
                    <tr>
                        <td><?php echo (int)$a['id']; ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo (int)($a['token_id'] ?? 0); ?></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($a['endpoint'] ?? $a['action'] ?? ''); ?></td>
                        <td>
                            <?php $sc = (int)($a['status_code'] ?? 0); ?>
                            <span class="badge <?php echo $sc >= 500 ? 'bg-danger' : ($sc >= 400 ? 'bg-warning text-dark' : 'bg-success'); ?>"><?php echo $sc ?: '—'; ?></span>
                        </td>
                        <td><?php echo number_format($a['execution_time'] ?? 0, 0); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars($a['servertime'] ?? $a['created_at'] ?? ''); ?></td>
                        <td><a href="<?php echo sURL; ?>TokenActions/show/<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->actions)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#888;padding:24px">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
