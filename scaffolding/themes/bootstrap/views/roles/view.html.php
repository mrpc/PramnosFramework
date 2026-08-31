<?php
/**
 * One role (Bootstrap theme).
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
<div class="container py-4">
    <?php $this->activeNav = 'roles_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><?php echo htmlspecialchars((string) $role->role_name, ENT_QUOTES, 'UTF-8'); ?></h2>
        <span class="d-flex gap-2">
            <a href="<?php echo adminUrl('Roles/edit/') . (int) $role->roleid; ?>" class="btn btn-outline-secondary">Edit</a>
            <a href="<?php echo adminUrl('Roles/members/') . (int) $role->roleid; ?>" class="btn btn-primary">Holders</a>
        </span>
    </div>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Details</div>
        <div class="card-body">
            <div class="row g-2 small">
                <div class="fw-semibold">ID</div><div><?php echo (int) $role->roleid; ?></div>
                <div class="fw-semibold">Organisation</div><div><?php echo htmlspecialchars((string) $this->organisation, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="fw-semibold">Description</div><div><?php echo htmlspecialchars((string) ($role->description ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="fw-semibold">Active</div><div><?php echo ((int) $role->is_active) === 1 ? 'Yes' : 'No'; ?></div>
            </div>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Permissions granted to this role</div>
        <div class="card-body">
            <table class="table table-sm">
                <thead class="table-light">
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
                    <tr><td colspan="4" class="text-center text-muted py-4">This role grants nothing yet.
                        Add entries from the Permissions screen with subject
                        <code>role</code> and id <?php echo (int) $role->roleid; ?>.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Held by <?php echo count($this->holders ?? []); ?> user(s)</div>
        <div class="card-body">
            <table class="table table-sm">
                <thead class="table-light"><tr><th>User ID</th><th>Username</th><th>Email</th></tr></thead>
                <tbody>
                <?php foreach (($this->holders ?? []) as $h): ?>
                    <tr>
                        <td><?php echo (int) $h['userid']; ?></td>
                        <td><?php echo htmlspecialchars((string) $h['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $h['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->holders)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">Nobody holds this role.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
