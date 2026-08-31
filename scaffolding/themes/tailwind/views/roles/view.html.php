<?php
/**
 * One role (Tailwind theme).
 *
 * Variables:
 *   $this->role         — \Pramnos\Auth\Role
 *   $this->organisation — "System-wide" or the organisation's name
 *   $this->permissions  — permission rows granted to this role
 *   $this->holders      — [userid, username, email, granted_at]
 *
 * The permissions are here because a role is otherwise an opaque name: "operator"
 * tells nobody what an operator may do.
 */
$role = $this->role;
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'roles_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex justify-between items-center mb-4">
        <h2><?php echo htmlspecialchars((string) $role->role_name, ENT_QUOTES, 'UTF-8'); ?></h2>
        <span class="flex gap-2">
            <a href="<?php echo adminUrl('Roles/edit/') . (int) $role->roleid; ?>" class="btn btn-outline btn-sm">Edit</a>
            <a href="<?php echo adminUrl('Roles/members/') . (int) $role->roleid; ?>" class="btn btn-primary btn-sm">Holders</a>
        </span>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Details</div>
        <div class="p-5">
            <div class="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
                <div class="font-medium">ID</div><div><?php echo (int) $role->roleid; ?></div>
                <div class="font-medium">Organisation</div><div><?php echo htmlspecialchars((string) $this->organisation, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="font-medium">Description</div><div><?php echo htmlspecialchars((string) ($role->description ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="font-medium">Active</div><div><?php echo ((int) $role->is_active) === 1 ? 'Yes' : 'No'; ?></div>
            </div>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Permissions granted to this role</div>
        <div class="p-5">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Object</th><th>Instance</th><th>Action</th><th>Grant</th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->permissions ?? []) as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $p['object_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $p['object_id'] === null || $p['object_id'] === ''
                            ? '<em>all</em>'
                            : htmlspecialchars((string) $p['object_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $p['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $p['grant_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->permissions)): ?>
                    <tr><td colspan="4" class="text-center text-base-content/60 py-8">This role grants nothing yet.
                        Add entries from the Permissions screen with subject
                        <code>role</code> and id <?php echo (int) $role->roleid; ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="px-5 py-3 bg-base-200 border-b border-base-300 font-semibold text-sm">Held by <?php echo count($this->holders ?? []); ?> user(s)</div>
        <div class="p-5">
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase"><tr><th>User ID</th><th>Username</th><th>Email</th></tr></thead>
                <tbody>
                <?php foreach (($this->holders ?? []) as $h): ?>
                    <tr>
                        <td><?php echo (int) $h['userid']; ?></td>
                        <td><?php echo htmlspecialchars((string) $h['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $h['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->holders)): ?>
                    <tr><td colspan="3" class="text-center text-base-content/60 py-8">Nobody holds this role.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
