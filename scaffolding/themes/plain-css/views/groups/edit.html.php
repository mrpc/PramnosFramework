<?php
/**
 * User group create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->group — usergroups row, or null when creating
 */
$group = $this->group ?? [];
$isNew = empty($group['groupid']);
?>
<div class="page-section">
    <?php $this->activeNav = 'groups_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2><?php echo $isNew ? 'New group' : 'Edit group'; ?></h2>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px">
        <form method="post" action="<?php echo adminUrl('Groups/save'); ?>">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php if (!$isNew): ?>
                <input type="hidden" name="groupid" value="<?php echo (int) $group['groupid']; ?>">
            <?php endif; ?>
            <div style="margin-bottom:12px">
                <label style="display:block;font-weight:600;margin-bottom:4px" for="group-name">Name</label>
                <input type="text" id="group-name" name="name" maxlength="80" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars((string) ($group['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div style="margin-bottom:12px">
                <label style="display:block;font-weight:600;margin-bottom:4px" for="group-description">Description</label>
                <textarea id="group-description" name="description" rows="3" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box"><?php echo htmlspecialchars((string) ($group['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div style="margin-bottom:12px">
                <label style="display:block;font-weight:600;margin-bottom:4px" for="group-order">Order</label>
                <input type="number" id="group-order" name="order" min="-127" max="127" step="1" style="width:10rem;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo isset($group['order']) && $group['order'] !== null ? (int) $group['order'] : ''; ?>">
                <p style="color:#888;font-size:.85em;margin:4px 0 0">Where the group comes in the list, from -127 to 127; empty puts it after those with one.</p>
            </div>
            <div style="display:flex;gap:8px">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="<?php echo $isNew ? adminUrl('Groups') : adminUrl('Groups/view/') . (int) $group['groupid']; ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div></div>
</div>
