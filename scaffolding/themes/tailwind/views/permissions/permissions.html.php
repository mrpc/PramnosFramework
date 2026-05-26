<?php
/**
 * Permissions / RBAC grants list (Tailwind theme).
 *
 * Variables:
 *   $this->permissions — iterable rows
 *   $this->page        — current page
 *   $this->total       — total count
 */
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 >Permissions</h2>
        <a href="<?php echo sURL; ?>Permissions/edit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">+ New Permission</a>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>ID</th><th>Subject</th><th>Object Type</th><th>Action</th><th>Grant</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->permissions ?? []) as $p): ?>
                    <tr>
                        <td><?php echo (int)$p['id']; ?></td>
                        <td>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($p['subject_type'] ?? ''); ?></span>
                            #<?php echo htmlspecialchars((string)($p['subject_id'] ?? '')); ?>
                        </td>
                        <td><?php echo htmlspecialchars($p['object_type'] ?? ''); ?></td>
                        <td><code><?php echo htmlspecialchars($p['action'] ?? ''); ?></code></td>
                        <td>
                            <?php echo ($p['grant_type'] ?? 'allow') === 'allow'
                                ? '<span class="badge bg-success">Allow</span>'
                                : '<span class="badge bg-danger">Deny</span>'; ?>
                        </td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Permissions/edit/<?php echo (int)$p['id']; ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Edit</a>
                            <a href="<?php echo sURL; ?>Permissions/delete/<?php echo (int)$p['id']; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" data-confirm="Delete permission?">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->permissions)): ?>
                    <tr><td colspan="6" class="text-center text-gray-400 py-8">No permissions found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
