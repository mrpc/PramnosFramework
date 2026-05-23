<?php
/**
 * Tokens list (plain-CSS theme).
 *
 * Variables:
 *   $this->tokens — iterable rows
 *   $this->page   — current page
 *   $this->total  — total count
 */
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >OAuth2 Tokens</h2>
        <form method="get" style="display:flex;gap:8px">
            <input type="number" name="userid" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px" placeholder="User ID" value="<?php echo (int)($_GET['userid'] ?? 0) ?: ''; ?>">
            <input type="number" name="applicationid" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px" placeholder="App ID" value="<?php echo (int)($_GET['applicationid'] ?? 0) ?: ''; ?>">
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>ID</th><th>User</th><th>Application</th><th>Scope</th><th>Last Used</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->tokens ?? []) as $tok): ?>
                    <tr>
                        <td><?php echo (int)$tok['tokenid']; ?></td>
                        <td><?php echo (int)($tok['userid'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($tok['appname'] ?? (string)($tok['applicationid'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($tok['scope'] ?? ''); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars($tok['lastused'] ?? ''); ?></td>
                        <td>
                            <?php echo (int)($tok['status'] ?? 1) === 1
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Revoked</span>'; ?>
                        </td>
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Tokens/revoke/<?php echo (int)$tok['tokenid']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revoke token?')">Revoke</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->tokens)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#888;padding:24px">No tokens found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
