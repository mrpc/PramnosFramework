<?php
/**
 * Tokens list (Bootstrap theme).
 *
 * Variables:
 *   $this->tokens — iterable rows
 *   $this->page   — current page
 *   $this->total  — total count
 */
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">OAuth2 Tokens</h2>
        <form method="get" class="d-flex gap-2">
            <input type="number" name="userid" class="form-control form-control-sm" placeholder="User ID" value="<?php echo (int)($_GET['userid'] ?? 0) ?: ''; ?>">
            <input type="number" name="applicationid" class="form-control form-control-sm" placeholder="App ID" value="<?php echo (int)($_GET['applicationid'] ?? 0) ?: ''; ?>">
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>ID</th><th>User</th><th>Application</th><th>Scope</th><th>Last Used</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->tokens ?? []) as $tok): ?>
                    <tr>
                        <td><?php echo (int)$tok['tokenid']; ?></td>
                        <td><?php echo (int)($tok['userid'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($tok['appname'] ?? (string)($tok['applicationid'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($tok['scope'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($tok['lastused'] ?? ''); ?></td>
                        <td>
                            <?php echo (int)($tok['status'] ?? 1) === 1
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Revoked</span>'; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?php echo sURL; ?>Tokens/revoke/<?php echo (int)$tok['tokenid']; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Revoke token?">Revoke</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->tokens)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No tokens found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
