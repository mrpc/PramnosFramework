<?php
/**
 * Permission create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->permission — permission row array (null when creating)
 */
$p = $this->permission ?? [];
$isNew = empty($p['id']);
?>
<div class="page-section"max-width:640px">
    <h2 style="margin-bottom:16px"><?php echo $isNew ? 'New Permission' : 'Edit Permission'; ?></h2>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <form method="post" action="<?php echo sURL; ?>Permissions/save">
                <?php if (!$isNew): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:12px">
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Subject Type</label>
                        <select name="subject_type" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px">
                            <option value="user" <?php echo ($p['subject_type'] ?? 'user') === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="role" <?php echo ($p['subject_type'] ?? '') === 'role' ? 'selected' : ''; ?>>Role</option>
                            <option value="group" <?php echo ($p['subject_type'] ?? '') === 'group' ? 'selected' : ''; ?>>Group</option>
                        </select>
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Subject ID</label>
                        <input type="text" name="subject_id" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars((string)($p['subject_id'] ?? '')); ?>">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Object Type</label>
                        <input type="text" name="object_type" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($p['object_type'] ?? ''); ?>" placeholder="e.g. resource">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Object ID</label>
                        <input type="text" name="object_id" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars((string)($p['object_id'] ?? '')); ?>" placeholder="Leave blank for all">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Action</label>
                        <input type="text" name="action" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required value="<?php echo htmlspecialchars($p['action'] ?? ''); ?>" placeholder="e.g. read, write, *">
                    </div>
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-weight:600;margin-bottom:4px">Grant Type</label>
                        <select name="grant_type" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px">
                            <option value="allow" <?php echo ($p['grant_type'] ?? 'allow') === 'allow' ? 'selected' : ''; ?>>Allow</option>
                            <option value="deny" <?php echo ($p['grant_type'] ?? '') === 'deny' ? 'selected' : ''; ?>>Deny</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Permissions" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
