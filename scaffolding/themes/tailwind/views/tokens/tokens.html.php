<?php
/**
 * Tokens list (Tailwind theme).
 *
 * Variables:
 *   $this->tokens — iterable rows
 *   $this->page   — current page
 *   $this->total  — total count
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'tokens'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex justify-between items-center mb-4">
        <h2 >OAuth2 Tokens</h2>
        <form method="get" class="flex gap-2">
            <input type="number" name="user_id" class="input input-sm" placeholder="User ID" value="<?php echo (int)($_GET['user_id'] ?? 0) ?: ''; ?>">
            <input type="number" name="app_id" class="input input-sm" placeholder="App ID" value="<?php echo (int)($_GET['app_id'] ?? 0) ?: ''; ?>">
            <button class="btn btn-outline btn-xs">Filter</button>
        </form>
    </div>
    <style>
    .pf-tw-table{width:100%;border-collapse:collapse}
    .pf-tw-table th,.pf-tw-table td{text-align:left;padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
    .pf-tw-table thead th{background:#f9fafb;border-bottom:1px solid #e5e7eb;font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;color:#6b7280}
    .pf-tw-table tbody tr:hover{background:#f9fafb}
    .pf-tw-table td:last-child,.pf-tw-table th:last-child{text-align:right}
    .pf-badge{display:inline-block;padding:2px 8px;border-radius:9999px;font-size:.72rem;font-weight:600}
    </style>
    <div class="card bg-base-100 border border-base-300 shadow-xs overflow-x-auto">
        <div >
            <table class="table table-sm pf-tw-table text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>ID</th><th>User</th><th>Application</th><th>Scope</th><th>Last Used</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->tokens ?? []) as $tok): ?>
                    <?php $actionsUrl = adminUrl('TokenActions') . '?token_id=' . (int) $tok['tokenid'] . '&from=tokens'; ?>
                    <tr class="cursor-pointer hover:bg-base-200" data-href="<?php echo $actionsUrl; ?>" title="View token actions">
                        <td><?php echo (int)$tok['tokenid']; ?></td>
                        <td><?php echo htmlspecialchars($tok['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($tok['app_name'] ?? ('— ' . ($tok['tokentype'] ?? ''))); ?></td>
                        <td><?php
                            $sc = trim((string) ($tok['scope'] ?? ''));
                            echo ($sc === '' || $sc === '[]') ? '—' : htmlspecialchars($sc);
                        ?></td>
                        <td class="text-base-content/60 text-xs"><?php
                            $lu = (int) ($tok['lastused'] ?? 0);
                            echo $lu > 0 ? htmlspecialchars(date('Y-m-d H:i', $lu)) : '—';
                        ?></td>
                        <td>
                            <?php echo (int)($tok['status'] ?? 1) === 1
                                ? '<span class="pf-badge badge-success/10 text-success">Active</span>'
                                : '<span class="pf-badge bg-base-200 text-base-content/80">Revoked</span>'; ?>
                        </td>
                        <td class="text-right">
                            <a href="<?php echo adminUrl('Tokens' . '/revoke/' . ((int)$tok['tokenid'])); ?>" class="btn btn-outline btn-error btn-xs" data-confirm="Revoke token?">Revoke</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->tokens)): ?>
                    <tr><td colspan="7" class="text-center text-base-content/60 py-8">No tokens found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
