<?php
/**
 * Permission create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->permission — permission row array (null when creating)
 */
$p = $this->permission ?? [];
$isNew = empty($p['permissionid']);
?>
<div class="max-w-2xl mx-auto py-6 px-4">
    <h2 class="mb-6"><?php echo $isNew ? 'New Permission' : 'Edit Permission'; ?></h2>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="p-5">
            <form method="post" action="<?php echo adminUrl('Permissions/save'); ?>">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!$isNew): ?>
                    <input type="hidden" name="permissionid" value="<?php echo (int)$p['permissionid']; ?>">
                <?php endif; ?>
                <div class="grid md:grid-cols-2 gap-4">
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Subject Type</label>
                        <select name="subject_type" class="input input-sm w-full">
                            <option value="user" <?php echo ($p['subject_type'] ?? 'user') === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="role" <?php echo ($p['subject_type'] ?? '') === 'role' ? 'selected' : ''; ?>>Role</option>
                        </select>
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Subject ID</label>
                        <input type="text" name="subject_id" class="input input-sm w-full" value="<?php echo htmlspecialchars((string)($p['subject_id'] ?? '')); ?>">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Object Type</label>
                        <input type="text" name="object_type" class="input input-sm w-full" value="<?php echo htmlspecialchars($p['object_type'] ?? ''); ?>" placeholder="e.g. resource">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Object ID</label>
                        <input type="text" name="object_id" class="input input-sm w-full" value="<?php echo htmlspecialchars((string)($p['object_id'] ?? '')); ?>" placeholder="Leave blank for all">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Action</label>
                        <input type="text" name="action" class="input input-sm w-full" required value="<?php echo htmlspecialchars($p['action'] ?? ''); ?>" placeholder="e.g. read, write, *">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Grant Type</label>
                        <select name="grant_type" class="input input-sm w-full">
                            <option value="allow" <?php echo ($p['grant_type'] ?? 'allow') === 'allow' ? 'selected' : ''; ?>>Allow</option>
                            <option value="deny" <?php echo ($p['grant_type'] ?? '') === 'deny' ? 'selected' : ''; ?>>Deny</option>
                        </select>
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Priority</label>
                        <input type="number" name="priority" min="0" class="input input-sm w-full" value="<?php echo (int)($p['priority'] ?? 100); ?>">
                        <div class="text-xs opacity-70 mt-1">The higher priority decides; a deny wins a tie.</div>
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Application ID</label>
                        <input type="number" name="app_id" min="1" class="input input-sm w-full" value="<?php echo htmlspecialchars((string)($p['app_id'] ?? '')); ?>" placeholder="Leave blank for every application">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-base-content mb-1">Expires</label>
                        <input type="datetime-local" name="expires_at" class="input input-sm w-full" value="<?php echo htmlspecialchars(substr(str_replace(' ', 'T', (string)($p['expires_at'] ?? '')), 0, 16)); ?>">
                        <div class="text-xs opacity-70 mt-1">Leave blank for a permanent grant.</div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-base-content mb-1">Conditions (JSON)</label>
                        <textarea name="conditions" rows="3" class="input input-sm w-full" placeholder='{"location_id": [1, 2]}'><?php echo htmlspecialchars((string)($p['conditions'] ?? '')); ?></textarea>
                        <div class="text-xs opacity-70 mt-1">Passed to the application with the grant, which evaluates it. Leave blank for an unconditional grant.</div>
                    </div>
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <a href="<?php echo adminUrl('Permissions'); ?>" class="btn btn-outline btn-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
