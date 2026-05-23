<?php
/**
 * Organization create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->organization — row array (null when creating)
 */
$org = $this->organization ?? [];
$isNew = empty($org['id']);
?>
<div class="page-section"max-width:640px">
    <h2 style="margin-bottom:16px"><?php echo $isNew ? 'New Organization' : 'Edit Organization'; ?></h2>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <form method="post" action="<?php echo sURL; ?>Organizations/save">
                <?php if (!$isNew): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$org['id']; ?>">
                <?php endif; ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Name</label>
                    <input type="text" name="name" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required value="<?php echo htmlspecialchars($org['name'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Description</label>
                    <textarea name="description" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" rows="3"><?php echo htmlspecialchars($org['description'] ?? ''); ?></textarea>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Organizations" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
