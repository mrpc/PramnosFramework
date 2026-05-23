<?php
/**
 * Permission create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->permission — permission row array (null when creating)
 */
$p = $this->permission ?? [];
$isNew = empty($p['id']);
?>
<div class="container py-4" style="max-width:640px">
    <h2 class="mb-4"><?php echo $isNew ? 'New Permission' : 'Edit Permission'; ?></h2>
    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo sURL; ?>Permissions/save">
                <?php if (!$isNew): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Subject Type</label>
                        <select name="subject_type" class="form-select">
                            <option value="user" <?php echo ($p['subject_type'] ?? 'user') === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="role" <?php echo ($p['subject_type'] ?? '') === 'role' ? 'selected' : ''; ?>>Role</option>
                            <option value="group" <?php echo ($p['subject_type'] ?? '') === 'group' ? 'selected' : ''; ?>>Group</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Subject ID</label>
                        <input type="text" name="subject_id" class="form-control" value="<?php echo htmlspecialchars((string)($p['subject_id'] ?? '')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Object Type</label>
                        <input type="text" name="object_type" class="form-control" value="<?php echo htmlspecialchars($p['object_type'] ?? ''); ?>" placeholder="e.g. resource">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Object ID</label>
                        <input type="text" name="object_id" class="form-control" value="<?php echo htmlspecialchars((string)($p['object_id'] ?? '')); ?>" placeholder="Leave blank for all">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Action</label>
                        <input type="text" name="action" class="form-control" required value="<?php echo htmlspecialchars($p['action'] ?? ''); ?>" placeholder="e.g. read, write, *">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Grant Type</label>
                        <select name="grant_type" class="form-select">
                            <option value="allow" <?php echo ($p['grant_type'] ?? 'allow') === 'allow' ? 'selected' : ''; ?>>Allow</option>
                            <option value="deny" <?php echo ($p['grant_type'] ?? '') === 'deny' ? 'selected' : ''; ?>>Deny</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Permissions" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
