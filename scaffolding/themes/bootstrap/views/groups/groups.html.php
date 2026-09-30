<?php
/**
 * User groups list (Bootstrap theme) — the usergroups feature.
 *
 * Variables:
 *   $this->groups — rows of usergroups, each with a `members` count
 */
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'groups'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 class="mb-0">Groups</h2>
        <a href="<?php echo adminUrl('Groups/edit'); ?>" class="btn btn-primary">+ New group</a>
    </div>
    <div class="card mb-3"><div class="card-body">
        <table class="table table-sm mb-0">
            <thead><tr><th>Name</th><th>Description</th><th>Members</th></tr></thead>
            <tbody>
            <?php foreach (($this->groups ?? []) as $g): ?>
                <tr>
                    <td><a href="<?php echo adminUrl('Groups/view/') . (int) $g['groupid']; ?>"><?php echo htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td class="text-muted"><?php echo htmlspecialchars((string) ($g['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($g['members'] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->groups)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No groups yet. A group is a named set of accounts that permissions and mail can address.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
