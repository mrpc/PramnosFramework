<?php
/**
 * User groups list (Tailwind theme) — the usergroups feature.
 *
 * Variables:
 *   $this->groups — rows of usergroups, each with a `members` count
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'groups'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex justify-between items-center gap-2 mb-4">
        <h2>Groups</h2>
        <a href="<?php echo adminUrl('Groups/edit'); ?>" class="btn btn-primary btn-sm">+ New group</a>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5">
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase"><tr><th>Name</th><th>Description</th><th>Members</th></tr></thead>
            <tbody>
            <?php foreach (($this->groups ?? []) as $g): ?>
                <tr>
                    <td><a href="<?php echo adminUrl('Groups/view/') . (int) $g['groupid']; ?>"><?php echo htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td class="text-base-content/60"><?php echo htmlspecialchars((string) ($g['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($g['members'] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->groups)): ?>
                <tr><td colspan="3" class="text-center text-base-content/60 py-6">No groups yet. A group is a named set of accounts that permissions and mail can address.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
