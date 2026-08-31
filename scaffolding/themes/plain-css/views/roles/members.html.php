<?php
/**
 * Who holds a role (plain CSS theme).
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
<div class="page-section">
    <?php $this->activeNav = 'roles_members'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div style="display:flex;gap:8px" style="margin-bottom:16px;align-items:center">
        <a href="<?php echo adminUrl('Roles'); ?>" class="btn btn-outline-secondary">&larr; Back</a>
        <h2>Holders — <?php echo htmlspecialchars((string) $role->role_name, ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div style="padding:10px 16px;background:#f5f5f5;border-bottom:1px solid #ddd;font-weight:600">Add a holder</div>
        <div class="card-body" style="padding:16px">
            <?php if ($orgId > 0): ?>
                <p>This role belongs to
                <strong><?php echo htmlspecialchars((string) $this->organisation, ENT_QUOTES, 'UTF-8'); ?></strong>,
                so only members of that organisation can hold it.</p>
            <?php endif; ?>
            <form method="post" action="<?php echo adminUrl('Roles/addmember/') . $rid; ?>" style="display:flex;gap:8px">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <input type="number" name="userid" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" placeholder="User ID" required style="max-width:180px">
                <button type="submit" class="btn btn-success">Add</button>
            </form>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5;text-align:left">
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
                               class="btn btn-outline-danger" data-confirm="Remove this role from the user?">Remove</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->holders)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#777;padding:24px">Nobody holds this role.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
