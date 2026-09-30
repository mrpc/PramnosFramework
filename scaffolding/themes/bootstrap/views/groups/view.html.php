<?php
/**
 * One user group and its members (Bootstrap theme).
 *
 * Variables:
 *   $this->group   — usergroups row
 *   $this->members        — users rows (userid, username, email, firstname, lastname)
 *   $this->rolesEnabled   — whether the roles store and group_roles exist
 *   $this->roles          — roles the group holds (roleid, role_name, description)
 *   $this->availableRoles — active roles it does not hold
 */
$group = $this->group ?? [];
$gid   = (int) ($group['groupid'] ?? 0);
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'groups_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 class="mb-0"><?php echo htmlspecialchars((string) ($group['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="d-flex gap-2">
            <a href="<?php echo adminUrl('Groups/edit/') . $gid; ?>" class="btn btn-outline-secondary">Edit</a>
            <form method="post" action="<?php echo adminUrl('Groups/delete/') . $gid; ?>" style="display:inline;margin:0">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete this group? Its members keep their accounts.">Delete</button>
            </form>
        </div>
    </div>
    <?php if (trim((string) ($group['description'] ?? '')) !== ''): ?>
        <p class="text-muted"><?php echo htmlspecialchars((string) ($group['description']), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-body">
        <form method="post" action="<?php echo adminUrl('Groups/addmember/') . $gid; ?>" class="d-flex gap-2">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <label for="group-member" class="sr-only" style="position:absolute;left:-9999px">Add a member</label>
            <input type="text" id="group-member" name="user" required placeholder="Username, email or user id" class="form-control">
            <button type="submit" class="btn btn-primary">Add member</button>
        </form>
    </div></div>
    <div class="card mb-3"><div class="card-body">
        <table class="table table-sm mb-0">
            <thead><tr><th>Username</th><th>Name</th><th>Email</th><th></th></tr></thead>
            <tbody>
            <?php foreach (($this->members ?? []) as $m): ?>
                <tr>
                    <td><a href="<?php echo adminUrl('users/view/') . (int) $m['userid']; ?>"><?php echo htmlspecialchars((string) ($m['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td><?php echo htmlspecialchars((string) (trim(($m['firstname'] ?? '') . ' ' . ($m['lastname'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($m['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="text-align:right">
                        <form method="post" action="<?php echo adminUrl('Groups/removemember/') . $gid; ?>" style="display:inline;margin:0">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="userid" value="<?php echo (int) $m['userid']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Remove this member from the group?">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->members)): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No members yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
    <?php if (!empty($this->rolesEnabled)): ?>
    <div class="card mb-3"><div class="card-body">
        <h3 class="h6 fw-semibold mb-2">Roles</h3>
        <p class="text-muted small">Every member holds these roles, as if each had been given them directly.</p>
        <table class="table table-sm mb-0">
            <thead><tr><th>Role</th><th>Description</th><th></th></tr></thead>
            <tbody>
            <?php foreach (($this->roles ?? []) as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($r['role_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="text-align:right">
                        <form method="post" action="<?php echo adminUrl('Groups/removerole/') . $gid; ?>" style="display:inline;margin:0">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="roleid" value="<?php echo (int) $r['roleid']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Withdraw this role from every member of the group?">Withdraw</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->roles)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">This group holds no roles.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($this->availableRoles)): ?>
        <form method="post" action="<?php echo adminUrl('Groups/addrole/') . $gid; ?>" class="d-flex gap-2 mt-3">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <label for="group-role" style="position:absolute;left:-9999px">Give a role</label>
            <select id="group-role" name="roleid" required class="form-select">
                <?php foreach ($this->availableRoles as $r): ?>
                    <option value="<?php echo (int) $r['roleid']; ?>"><?php echo htmlspecialchars((string) $r['role_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Give role</button>
        </form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
