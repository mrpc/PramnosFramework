<?php
/**
 * One user group and its members (plain-CSS theme).
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
<div class="page-section">
    <?php $this->activeNav = 'groups_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px">
        <h2><?php echo htmlspecialchars((string) ($group['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
        <div style="display:flex;gap:8px">
            <a href="<?php echo adminUrl('Groups/edit/') . $gid; ?>" class="btn btn-outline-secondary">Edit</a>
            <form method="post" action="<?php echo adminUrl('Groups/delete/') . $gid; ?>" style="display:inline;margin:0">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete this group? Its members keep their accounts.">Delete</button>
            </form>
        </div>
    </div>
    <?php if (trim((string) ($group['description'] ?? '')) !== ''): ?>
        <p style="color:#888"><?php echo htmlspecialchars((string) ($group['description']), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px">
        <form method="post" action="<?php echo adminUrl('Groups/addmember/') . $gid; ?>" style="display:flex;gap:8px">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <label for="group-member" class="sr-only" style="position:absolute;left:-9999px">Add a member</label>
            <input type="text" id="group-member" name="user" required placeholder="Username, email or user id" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
            <button type="submit" class="btn btn-primary">Add member</button>
        </form>
    </div></div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px">
        <table style="width:100%;border-collapse:collapse">
            <thead style="background:#f5f5f5"><tr><th>Username</th><th>Name</th><th>Email</th><th></th></tr></thead>
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
                <tr><td colspan="4" style="text-align:center;color:#888;padding:24px">No members yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
    <?php if (!empty($this->rolesEnabled)): ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px">
        <h3 style="font-size:15px;margin:0 0 8px">Roles</h3>
        <p style="color:#888">Every member holds these roles, as if each had been given them directly.</p>
        <table style="width:100%;border-collapse:collapse">
            <thead style="background:#f5f5f5"><tr><th>Role</th><th>Description</th><th></th></tr></thead>
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
                <tr><td colspan="3" style="text-align:center;color:#888;padding:16px">This group holds no roles.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($this->availableRoles)): ?>
        <form method="post" action="<?php echo adminUrl('Groups/addrole/') . $gid; ?>" style="display:flex;gap:8px;margin-top:12px">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <label for="group-role" style="position:absolute;left:-9999px">Give a role</label>
            <select id="group-role" name="roleid" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box">
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
