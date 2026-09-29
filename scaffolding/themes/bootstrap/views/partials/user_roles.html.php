<?php
/**
 * "Roles" — the roles this account holds, on its page (Bootstrap theme).
 *
 * Data: `$this->heldRoles` from `Role::heldBy()` — role, organisation (empty for a system-wide
 * role), granted, expires, and whether it counts now (active role, not expired). A role that does
 * not count is still listed: it is the answer to "why is the permission missing".
 */
$_roles = $this->heldRoles ?? null;
if (!is_array($_roles)) {
    return;
}
$_e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="card mb-4" id="user-roles">
    <div class="card-header fw-semibold">Roles</div>
    <div class="card-body">
        <?php if ($_roles === []): ?>
            <p class="small text-muted">No role. What this account may do comes from its usertype and its own permissions.</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead><tr><th>Role</th><th>Organisation</th><th>Granted</th><th>Expires</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($_roles as $_r): ?>
                <tr>
                    <td><a href="<?php echo adminUrl('roles/view/') . (int) $_r['roleid']; ?>"><?php echo $_e($_r['role_name']); ?></a></td>
                    <td><?php echo $_r['organization'] !== '' ? $_e($_r['organization']) : '<span class="small text-muted">system-wide</span>'; ?></td>
                    <td><?php echo $_e($_r['granted_at']); ?></td>
                    <td><?php echo $_r['expires_at'] !== '' ? $_e($_r['expires_at']) : '—'; ?></td>
                    <td><?php echo !empty($_r['counts']) ? '' : '<span class="badge text-bg-warning">does not count</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
