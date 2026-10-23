<?php
/**
 * One user group: its name and description, how many are in it, its order and what uses it; the
 * members a page at a time, with adding and removing; and the roles it holds, where groups are
 * given roles here. The same markup in every theme: it is dressed by the `pf-*` rules in
 * components.css.
 *
 * Variables:
 *   $this->group          — usergroups row
 *   $this->members        — \Pramnos\Html\Datatable of the members, server-side from `Groups/members/{id}`
 *   $this->count          — how many members it has
 *   $this->uses           — what uses it: [name, url|null] (`Audience\Groups::uses()`)
 *   $this->membersUrl     — the application's own list of its members, or null (`audience.group_members_url`)
 *   $this->rolesEnabled   — whether the roles section is shown: the roles store, a role to hold or give, and not `audience.group_roles => false`
 *   $this->roles          — roles the group holds (roleid, role_name, description)
 *   $this->availableRoles — active roles it does not hold
 */
use Pramnos\Html\Icon;

$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$group = $this->group ?? [];
$gid   = (int) ($group['groupid'] ?? 0);
$uses  = (array) ($this->uses ?? []);
$token = static fn (): string => \Pramnos\Http\Session::getInstance()->getTokenField();
?>
<div class="pf-ui">
    <?php $this->activeNav = 'groups_view'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e($group['name'] ?? ''); ?></h1>
            <?php if (trim((string) ($group['description'] ?? '')) !== ''): ?><p class="pf-page-sub"><?php echo $e($group['description']); ?></p><?php endif; ?>
        </div>
        <div class="pf-page-actions">
            <form method="post" action="<?php echo $e(adminUrl('Groups/delete/') . $gid); ?>" class="pf-inline-form" data-confirm="<?php echo $e(t('Delete this group? Its members keep their accounts.')); ?>">
                <?php echo $token(); ?>
                <button type="submit" class="pf-btn pf-btn-ghost pf-btn-danger" <?php echo $uses === [] ? '' : 'disabled title="' . $e(t('Used by: %s', implode(', ', array_column($uses, 'name')))) . '"'; ?>><?php echo $e(t('Delete')); ?></button>
            </form>
            <?php if (!empty($this->membersUrl)): ?>
                <a href="<?php echo $e($this->membersUrl); ?>" class="pf-btn"><?php echo Icon::svg('members', ''); ?><?php echo $e(t('Members in the user list')); ?></a>
            <?php endif; ?>
            <a href="<?php echo $e(adminUrl('Groups/edit/') . $gid); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('edit', ''); ?><?php echo $e(t('Edit')); ?></a>
        </div>
    </header>

    <div class="pf-card"><div class="pf-card-body pf-stats">
        <div><span class="pf-label"><?php echo $e(t('Members in it')); ?></span><span class="pf-stat-value" data-field="members"><?php echo $e(number_format((int) ($this->count ?? 0))); ?></span></div>
        <div><span class="pf-label"><?php echo $e(t('Order in the list')); ?></span><span class="pf-stat-value"><?php echo isset($group['order']) && $group['order'] !== null ? (int) $group['order'] : '—'; ?></span></div>
        <div><span class="pf-label"><?php echo $e(t('Used by')); ?></span>
            <?php if ($uses === []): ?>
                <span class="pf-muted"><?php echo $e(t('Nothing uses it.')); ?></span>
            <?php else: ?>
                <span class="pf-chips"><?php foreach ($uses as $use): ?><?php echo $use['url'] !== null
                    ? '<a class="pf-chip" href="' . $e($use['url']) . '">' . $e($use['name']) . '</a>'
                    : '<span class="pf-chip">' . $e($use['name']) . '</span>'; ?><?php endforeach; ?></span>
            <?php endif; ?>
        </div>
    </div></div>

    <div class="pf-card pf-mt"><div class="pf-card-body">
        <form method="post" action="<?php echo $e(adminUrl('Groups/addmember/') . $gid); ?>" class="pf-rule-sentence">
            <?php echo $token(); ?>
            <label class="pf-visually-hidden" for="group-member"><?php echo $e(t('Add a member to the group')); ?></label>
            <input type="text" id="group-member" name="user" required class="pf-input" placeholder="<?php echo $e(t('Username, email or account id')); ?>">
            <button type="submit" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('Add to the group')); ?></button>
        </form>
    </div></div>

    <div class="pf-card pf-card-flush pf-mt">
        <?php echo $this->members->render(); ?>
    </div>

    <?php if (!empty($this->rolesEnabled)): ?>
    <div class="pf-card pf-mt"><div class="pf-card-body">
        <h2 class="pf-section-title"><?php echo $e(t('Roles the group holds')); ?></h2>
        <p class="pf-muted"><?php echo $e(t('Every member holds these roles, as if each had been given them directly.')); ?></p>
        <?php if (empty($this->roles)): ?>
            <p class="pf-muted"><?php echo $e(t('This group holds no roles.')); ?></p>
        <?php else: ?>
            <table class="pf-table">
                <thead><tr><th><?php echo $e(t('Role name')); ?></th><th><?php echo $e(t('What it is for')); ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($this->roles as $r): ?>
                    <tr>
                        <td><?php echo $e($r['role_name'] ?? ''); ?></td>
                        <td><?php echo $e($r['description'] ?? ''); ?></td>
                        <td class="pf-num">
                            <form method="post" action="<?php echo $e(adminUrl('Groups/removerole/') . $gid); ?>" class="pf-inline-form" data-confirm="<?php echo $e(t('Withdraw this role from every member of the group?')); ?>">
                                <?php echo $token(); ?>
                                <input type="hidden" name="roleid" value="<?php echo (int) $r['roleid']; ?>">
                                <button type="submit" class="pf-btn pf-btn-ghost pf-btn-danger"><?php echo $e(t('Withdraw the role')); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($this->availableRoles)): ?>
            <form method="post" action="<?php echo $e(adminUrl('Groups/addrole/') . $gid); ?>" class="pf-rule-sentence pf-mt">
                <?php echo $token(); ?>
                <label class="pf-visually-hidden" for="group-role"><?php echo $e(t('Give the group a role')); ?></label>
                <select id="group-role" name="roleid" required class="pf-input">
                    <?php foreach ($this->availableRoles as $r): ?>
                        <option value="<?php echo (int) $r['roleid']; ?>"><?php echo $e($r['role_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="pf-btn"><?php echo $e(t('Give the role')); ?></button>
            </form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
