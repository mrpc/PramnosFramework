<?php
/**
 * "Roles" — the roles this account holds, on its page (Tailwind theme).
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
<div class="card bg-base-100 border border-base-300 shadow-xs mb-4" id="user-roles">
    <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Roles</div>
    <div class="p-5">
        <?php if ($_roles === []): ?>
            <p class="text-xs text-base-content/60">No role. What this account may do comes from its usertype and its own permissions.</p>
        <?php else: ?>
        <table class="table table-sm text-sm">
            <thead><tr><th>Role</th><th>Organisation</th><th>Granted</th><th>Expires</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($_roles as $_r): ?>
                <tr>
                    <td><a href="<?php echo adminUrl('roles/view/') . (int) $_r['roleid']; ?>"><?php echo $_e($_r['role_name']); ?></a></td>
                    <td><?php echo $_r['organization'] !== '' ? $_e($_r['organization']) : '<span class="text-xs text-base-content/60">system-wide</span>'; ?></td>
                    <td><?php echo $_e($_r['granted_at']); ?></td>
                    <td><?php echo $_r['expires_at'] !== '' ? $_e($_r['expires_at']) : '—'; ?></td>
                    <td><?php echo !empty($_r['counts']) ? '' : '<span class="badge badge-warning badge-sm">does not count</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
