<?php
/**
 * User group create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->group — usergroups row, or null when creating
 */
$group = $this->group ?? [];
$isNew = empty($group['groupid']);
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'groups_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2><?php echo $isNew ? 'New group' : 'Edit group'; ?></h2>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5">
        <form method="post" action="<?php echo adminUrl('Groups/save'); ?>">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php if (!$isNew): ?>
                <input type="hidden" name="groupid" value="<?php echo (int) $group['groupid']; ?>">
            <?php endif; ?>
            <div class="mb-4">
                <label class="block text-sm font-medium text-base-content mb-1" for="group-name">Name</label>
                <input type="text" id="group-name" name="name" maxlength="80" required class="input input-sm w-full" value="<?php echo htmlspecialchars((string) ($group['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-base-content mb-1" for="group-description">Description</label>
                <textarea id="group-description" name="description" rows="3" class="input input-sm w-full"><?php echo htmlspecialchars((string) ($group['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-base-content mb-1" for="group-order">Order</label>
                <input type="number" id="group-order" name="order" min="-127" max="127" step="1" class="input input-sm w-40" value="<?php echo isset($group['order']) && $group['order'] !== null ? (int) $group['order'] : ''; ?>">
                <p class="text-xs text-base-content/60 mt-1">Where the group comes in the list, from -127 to 127; empty puts it after those with one.</p>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                <a href="<?php echo $isNew ? adminUrl('Groups') : adminUrl('Groups/view/') . (int) $group['groupid']; ?>" class="btn btn-outline btn-sm">Cancel</a>
            </div>
        </form>
    </div></div>
</div>
