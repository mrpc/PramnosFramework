<?php
/**
 * Permission create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->permission — permission row array (null when creating)
 */
$p = $this->permission ?? [];
$isNew = empty($p['permissionid']);
?>
<div class="container py-4" style="max-width:640px">
    <h2 class="mb-4"><?php echo $isNew ? 'New Permission' : 'Edit Permission'; ?></h2>
    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo adminUrl('Permissions/save'); ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!$isNew): ?>
                    <input type="hidden" name="permissionid" value="<?php echo (int)$p['permissionid']; ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Subject Type</label>
                        <select name="subject_type" class="form-select">
                            <option value="user" <?php echo ($p['subject_type'] ?? 'user') === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="role" <?php echo ($p['subject_type'] ?? '') === 'role' ? 'selected' : ''; ?>>Role</option>
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
                    <div class="col-md-6">
                        <label class="form-label">Priority</label>
                        <input type="number" name="priority" min="0" class="form-control" value="<?php echo (int)($p['priority'] ?? 100); ?>">
                        <div class="form-text">The higher priority decides; a deny wins a tie.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Application ID</label>
                        <input type="number" name="app_id" min="1" class="form-control" value="<?php echo htmlspecialchars((string)($p['app_id'] ?? '')); ?>" placeholder="Leave blank for every application">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Expires</label>
                        <input type="datetime-local" name="expires_at" class="form-control" value="<?php echo htmlspecialchars(substr(str_replace(' ', 'T', (string)($p['expires_at'] ?? '')), 0, 16)); ?>">
                        <div class="form-text">Leave blank for a permanent grant.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Conditions (JSON)</label>
                        <textarea name="conditions" rows="3" class="form-control" placeholder='{"location_id": [1, 2]}'><?php echo htmlspecialchars((string)($p['conditions'] ?? '')); ?></textarea>
                        <div class="form-text">Passed to the application with the grant, which evaluates it. Leave blank for an unconditional grant.</div>
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo adminUrl('Permissions'); ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
