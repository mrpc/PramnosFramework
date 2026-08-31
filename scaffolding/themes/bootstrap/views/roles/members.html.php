<?php
/**
 * Who holds a role (Bootstrap theme).
 *
 * Variables:
 *   $this->role         — \Pramnos\Auth\Role
 *   $this->organisation — "System-wide" or the organisation's name
 *   $this->holders      — [userid, username, email, granted_at]
 *
 * Adding somebody to an organisation's role is refused unless they are already a
 * member of it. The refusal arrives as a flash message saying so.
 */
$role  = $this->role;
$rid   = (int) $role->roleid;
$orgId = (int) ($role->organization_id ?? 0);
?>
<div class="container py-4">
    <?php $this->activeNav = 'roles_members'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="d-flex gap-2" style="margin-bottom:16px;align-items:center">
        <a href="<?php echo adminUrl('Roles'); ?>" class="btn btn-outline-secondary btn-sm">&larr; Back</a>
        <h2>Holders — <?php echo htmlspecialchars((string) $role->role_name, ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Add a holder</div>
        <div class="card-body">
            <?php if ($orgId > 0): ?>
                <p>This role belongs to
                <strong><?php echo htmlspecialchars((string) $this->organisation, ENT_QUOTES, 'UTF-8'); ?></strong>,
                so only members of that organisation can hold it.</p>
            <?php endif; ?>
            <form method="post" action="<?php echo adminUrl('Roles/addmember/') . $rid; ?>" class="d-flex gap-2">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <input type="number" name="userid" class="form-control" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="btn btn-success">Add</button>
            </form>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-body">
            <table class="table table-sm">
                <thead class="table-light">
                    <tr><th>User ID</th><th>Username</th><th>Email</th><th>Granted</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->holders ?? []) as $h): ?>
                    <tr>
                        <td><?php echo (int) $h['userid']; ?></td>
                        <td><?php echo htmlspecialchars((string) $h['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $h['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $h['granted_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="text-align:right">
                            <a href="<?php echo adminUrl('Roles/removemember/') . $rid; ?>?userid=<?php echo (int) $h['userid']; ?>"
                               class="btn btn-outline-danger btn-sm" data-confirm="Remove this role from the user?">Remove</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->holders)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nobody holds this role.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
