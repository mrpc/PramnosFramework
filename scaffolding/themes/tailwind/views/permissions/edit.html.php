<?php
/**
 * Permission create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->permission — permission row array (null when creating)
 */
$p = $this->permission ?? [];
$isNew = empty($p['id']);
?>
<div class="max-w-2xl mx-auto py-6 px-4">
    <h2 class="mb-6"><?php echo $isNew ? 'New Permission' : 'Edit Permission'; ?></h2>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-5">
            <form method="post" action="<?php echo sURL; ?>Permissions/save">
                <?php if (!$isNew): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                <?php endif; ?>
                <div class="grid md:grid-cols-2 gap-4">
                    <div >
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subject Type</label>
                        <select name="subject_type" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                            <option value="user" <?php echo ($p['subject_type'] ?? 'user') === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="role" <?php echo ($p['subject_type'] ?? '') === 'role' ? 'selected' : ''; ?>>Role</option>
                            <option value="group" <?php echo ($p['subject_type'] ?? '') === 'group' ? 'selected' : ''; ?>>Group</option>
                        </select>
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subject ID</label>
                        <input type="text" name="subject_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" value="<?php echo htmlspecialchars((string)($p['subject_id'] ?? '')); ?>">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-gray-700 mb-1">Object Type</label>
                        <input type="text" name="object_type" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" value="<?php echo htmlspecialchars($p['object_type'] ?? ''); ?>" placeholder="e.g. resource">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-gray-700 mb-1">Object ID</label>
                        <input type="text" name="object_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" value="<?php echo htmlspecialchars((string)($p['object_id'] ?? '')); ?>" placeholder="Leave blank for all">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-gray-700 mb-1">Action</label>
                        <input type="text" name="action" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" required value="<?php echo htmlspecialchars($p['action'] ?? ''); ?>" placeholder="e.g. read, write, *">
                    </div>
                    <div >
                        <label class="block text-sm font-medium text-gray-700 mb-1">Grant Type</label>
                        <select name="grant_type" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                            <option value="allow" <?php echo ($p['grant_type'] ?? 'allow') === 'allow' ? 'selected' : ''; ?>>Allow</option>
                            <option value="deny" <?php echo ($p['grant_type'] ?? '') === 'deny' ? 'selected' : ''; ?>>Deny</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">Save</button>
                    <a href="<?php echo sURL; ?>Permissions" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded hover:bg-gray-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
