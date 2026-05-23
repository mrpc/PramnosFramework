<?php
/**
 * Token Actions audit log list (Bootstrap theme).
 *
 * Variables:
 *   $this->actions — iterable rows
 *   $this->page    — current page
 *   $this->total   — total count
 */
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">API Audit Log</h2>
        <div class="d-flex gap-2">
            <a href="<?php echo sURL; ?>TokenActions/stats" class="btn btn-outline-info btn-sm">Stats</a>
            <a href="<?php echo sURL; ?>TokenActions/export<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn btn-outline-secondary btn-sm">Export CSV</a>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-auto">
                    <input type="number" name="token_id" class="form-control form-control-sm" placeholder="Token ID" value="<?php echo (int)($_GET['token_id'] ?? 0) ?: ''; ?>">
                </div>
                <div class="col-auto">
                    <input type="number" name="user_id" class="form-control form-control-sm" placeholder="User ID" value="<?php echo (int)($_GET['user_id'] ?? 0) ?: ''; ?>">
                </div>
                <div class="col-auto">
                    <input type="number" name="status_code" class="form-control form-control-sm" placeholder="HTTP Status" value="<?php echo (int)($_GET['status_code'] ?? 0) ?: ''; ?>">
                </div>
                <div class="col-auto">
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div class="col-auto">
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-outline-secondary">Filter</button>
                </div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>ID</th><th>Token</th><th>Endpoint</th><th>Status</th><th>Time (ms)</th><th>When</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->actions ?? []) as $a): ?>
                    <tr>
                        <td><?php echo (int)$a['id']; ?></td>
                        <td class="text-muted small"><?php echo (int)($a['token_id'] ?? 0); ?></td>
                        <td class="text-truncate" style="max-width:200px"><?php echo htmlspecialchars($a['endpoint'] ?? $a['action'] ?? ''); ?></td>
                        <td>
                            <?php $sc = (int)($a['status_code'] ?? 0); ?>
                            <span class="badge <?php echo $sc >= 500 ? 'bg-danger' : ($sc >= 400 ? 'bg-warning text-dark' : 'bg-success'); ?>"><?php echo $sc ?: '—'; ?></span>
                        </td>
                        <td><?php echo number_format($a['execution_time'] ?? 0, 0); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($a['servertime'] ?? $a['created_at'] ?? ''); ?></td>
                        <td><a href="<?php echo sURL; ?>TokenActions/show/<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->actions)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
