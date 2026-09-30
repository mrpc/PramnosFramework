<?php
/**
 * User groups list (plain-CSS theme) — the usergroups feature.
 *
 * Variables:
 *   $this->groups — rows of usergroups, each with a `members` count
 */
?>
<div class="page-section">
    <?php $this->activeNav = 'groups'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px">
        <h2>Groups</h2>
        <a href="<?php echo adminUrl('Groups/edit'); ?>" class="btn btn-primary">+ New group</a>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px">
        <table style="width:100%;border-collapse:collapse">
            <thead style="background:#f5f5f5"><tr><th>Name</th><th>Description</th><th>Members</th></tr></thead>
            <tbody>
            <?php foreach (($this->groups ?? []) as $g): ?>
                <tr>
                    <td><a href="<?php echo adminUrl('Groups/view/') . (int) $g['groupid']; ?>"><?php echo htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td style="color:#888"><?php echo htmlspecialchars((string) ($g['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($g['members'] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->groups)): ?>
                <tr><td colspan="3" style="text-align:center;color:#888;padding:24px">No groups yet. A group is a named set of accounts that permissions and mail can address.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
