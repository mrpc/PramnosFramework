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
    <?php $this->activeNav = 'tokens'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">OAuth2 Tokens</h2>
        <form method="get" class="d-flex gap-2">
            <input type="number" name="user_id" class="form-control form-control-sm" placeholder="User ID" value="<?php echo (int)($_GET['user_id'] ?? 0) ?: ''; ?>">
            <input type="number" name="app_id" class="form-control form-control-sm" placeholder="App ID" value="<?php echo (int)($_GET['app_id'] ?? 0) ?: ''; ?>">
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
                    <?php $actionsUrl = sURL . 'TokenActions?token_id=' . (int) $tok['tokenid'] . '&from=tokens'; ?>
                    <tr style="cursor:pointer" data-href="<?php echo $actionsUrl; ?>" title="View token actions">
                        <td><?php echo (int)$tok['tokenid']; ?></td>
                        <td><?php echo htmlspecialchars($tok['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($tok['app_name'] ?? ('— ' . ($tok['tokentype'] ?? ''))); ?></td>
                        <td><?php
                            $sc = trim((string) ($tok['scope'] ?? ''));
                            echo ($sc === '' || $sc === '[]') ? '—' : htmlspecialchars($sc);
                        ?></td>
                        <td class="text-muted small"><?php
                            $lu = (int) ($tok['lastused'] ?? 0);
                            echo $lu > 0 ? htmlspecialchars(date('Y-m-d H:i', $lu)) : '—';
                        ?></td>
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
