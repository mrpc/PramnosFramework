<?php
/**
 * One user group and its members (Tailwind theme).
 *
 * Variables:
 *   $this->group   — usergroups row
 *   $this->members        — \Pramnos\Html\Datatable of the members, server-side from `Groups/members/{id}`
 *   $this->count          — how many members it has
 *   $this->uses           — what uses it: [name, url|null] (`Audience\Groups::uses()`)
 *   $this->membersUrl     — the application's own list of its members, or null (`audience.group_members_url`)
 *   $this->rolesEnabled   — whether the roles store and group_roles exist
 *   $this->roles          — roles the group holds (roleid, role_name, description)
 *   $this->availableRoles — active roles it does not hold
 */
$group = $this->group ?? [];
$gid   = (int) ($group['groupid'] ?? 0);
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'groups_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex justify-between items-center gap-2 mb-4">
        <h2><?php echo htmlspecialchars((string) ($group['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="flex gap-2">
            <a href="<?php echo adminUrl('Groups/edit/') . $gid; ?>" class="btn btn-outline btn-sm">Edit</a>
            <form method="post" action="<?php echo adminUrl('Groups/delete/') . $gid; ?>" style="display:inline;margin:0">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="Delete this group? Its members keep their accounts.">Delete</button>
            </form>
        </div>
    </div>
    <?php if (trim((string) ($group['description'] ?? '')) !== ''): ?>
        <p class="text-base-content/60"><?php echo htmlspecialchars((string) ($group['description']), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5">
        <form method="post" action="<?php echo adminUrl('Groups/addmember/') . $gid; ?>" class="flex gap-2">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <label for="group-member" class="sr-only" style="position:absolute;left:-9999px">Add a member</label>
            <input type="text" id="group-member" name="user" required placeholder="Username, email or user id" class="input input-sm w-full">
            <button type="submit" class="btn btn-primary btn-sm">Add member</button>
        </form>
    </div></div>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5">
        <p class="text-base-content/60"><?php echo (int) ($this->count ?? 0); ?> members<?php if (!empty($this->membersUrl)): ?> · <a href="<?php echo htmlspecialchars((string) $this->membersUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm">Members in the user list</a><?php endif; ?></p>
        <?php if (!empty($this->uses)): ?>
            <p class="text-base-content/60">Used by: <?php foreach ($this->uses as $i => $use): ?><?php echo $i > 0 ? ', ' : ''; ?><?php echo $use['url'] !== null
                ? '<a href="' . htmlspecialchars((string) $use['url'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $use['name'], ENT_QUOTES, 'UTF-8') . '</a>'
                : htmlspecialchars((string) $use['name'], ENT_QUOTES, 'UTF-8'); ?><?php endforeach; ?></p>
        <?php endif; ?>
        <?php echo $this->members->render(); ?>
    </div></div>
    <?php if (!empty($this->rolesEnabled)): ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5">
        <h3 class="font-semibold text-sm mb-2">Roles</h3>
        <p class="text-base-content/60 text-xs">Every member holds these roles, as if each had been given them directly.</p>
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase"><tr><th>Role</th><th>Description</th><th></th></tr></thead>
            <tbody>
            <?php foreach (($this->roles ?? []) as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($r['role_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="text-align:right">
                        <form method="post" action="<?php echo adminUrl('Groups/removerole/') . $gid; ?>" style="display:inline;margin:0">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="roleid" value="<?php echo (int) $r['roleid']; ?>">
                            <button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="Withdraw this role from every member of the group?">Withdraw</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->roles)): ?>
                <tr><td colspan="3" class="text-center text-base-content/60 py-4">This group holds no roles.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($this->availableRoles)): ?>
        <form method="post" action="<?php echo adminUrl('Groups/addrole/') . $gid; ?>" class="flex gap-2 mt-3">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <label for="group-role" style="position:absolute;left:-9999px">Give a role</label>
            <select id="group-role" name="roleid" required class="select select-sm w-full">
                <?php foreach ($this->availableRoles as $r): ?>
                    <option value="<?php echo (int) $r['roleid']; ?>"><?php echo htmlspecialchars((string) $r['role_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Give role</button>
        </form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
