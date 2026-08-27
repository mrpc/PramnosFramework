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
    <?php /*
     * No inline <style> here any more, and that is the fix rather than tidying.
     *
     * This view carried its own table and badge rules in hardcoded greys — #f9fafb
     * headers, #f1f5f9 borders, #6b7280 text — on top of daisyUI's `table`, which it also
     * asked for. Two consequences: the two fought over the same cells, and the hardcoded
     * half wins in *both* themes, so the dark theme rendered pale grey text on pale grey
     * headers. That is the failure this project's styling rules exist to prevent, and it
     * is invisible to whoever wrote it in a light browser.
     *
     * daisyUI's `table` and `badge` carry the active theme's tokens instead, in both
     * directions, and there is nothing left to keep in step.
     */ ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs overflow-x-auto">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>ID</th><th>User</th><th>Application</th><th>Scope</th><th>Last Used</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->tokens ?? []) as $tok): ?>
                    <?php // The row opens the token's own screen — everything about it, with its
                    // actions on the same page. The actions list stays one click away.
                    $tokenUrl = adminUrl('Tokens/view/') . (int) $tok['tokenid']; ?>
                    <tr class="cursor-pointer hover:bg-base-200" data-href="<?php echo $tokenUrl; ?>" title="Open this token">
                        <td><a class="link" href="<?php echo $tokenUrl; ?>"><?php echo (int)$tok['tokenid']; ?></a></td>
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
                                ? '<span class="badge badge-sm badge-success">Active</span>'
                                : '<span class="badge badge-sm badge-ghost">Revoked</span>'; ?>
                        </td>
                        <td class="text-right">
                            <?php
                            echo \Pramnos\Html\Icon::link($tokenUrl, 'view', 'Open this token');
                            echo \Pramnos\Html\Icon::link(
                                adminUrl('TokenActions') . '?token_id=' . (int) $tok['tokenid'] . '&from=tokens',
                                'log',
                                'Actions on this token'
                            );
                            if ((int) ($tok['status'] ?? 1) === 1) {
                                echo \Pramnos\Html\Icon::link(
                                    adminUrl('Tokens/revoke/') . (int) $tok['tokenid'],
                                    'deactivate',
                                    'Revoke this token',
                                    ['data-confirm' => 'Revoke this token?', 'class' => 'pf-action-danger']
                                );
                            }
                            ?>
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
