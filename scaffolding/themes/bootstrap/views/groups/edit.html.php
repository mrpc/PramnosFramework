<?php
/**
 * User group create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->group — usergroups row, or null when creating
 */
$group = $this->group ?? [];
$isNew = empty($group['groupid']);
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'groups_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-0"><?php echo $isNew ? 'New group' : 'Edit group'; ?></h2>
    <div class="card mb-3"><div class="card-body">
        <form method="post" action="<?php echo adminUrl('Groups/save'); ?>">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php if (!$isNew): ?>
                <input type="hidden" name="groupid" value="<?php echo (int) $group['groupid']; ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="group-name">Name</label>
                <input type="text" id="group-name" name="name" maxlength="80" required class="form-control" value="<?php echo htmlspecialchars((string) ($group['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="group-description">Description</label>
                <textarea id="group-description" name="description" rows="3" class="form-control"><?php echo htmlspecialchars((string) ($group['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="<?php echo $isNew ? adminUrl('Groups') : adminUrl('Groups/view/') . (int) $group['groupid']; ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div></div>
</div>
