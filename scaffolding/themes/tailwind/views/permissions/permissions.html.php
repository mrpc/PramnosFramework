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
        <a href="<?php echo adminUrl('Permissions/edit'); ?>" class="btn btn-primary btn-sm">+ New Permission</a>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>ID</th><th>Subject</th><th>Object Type</th><th>Action</th><th>Grant</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->permissions ?? []) as $p): ?>
                    <tr>
                        <td><?php echo (int)$p['permissionid']; ?></td>
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
                            <a href="<?php echo adminUrl('Permissions' . '/edit/' . ((int)$p['permissionid'])); ?>" class="btn btn-outline btn-xs">Edit</a>
                            <a href="<?php echo adminUrl('Permissions' . '/delete/' . ((int)$p['permissionid'])); ?>" class="btn btn-outline btn-error btn-xs" data-confirm="Delete permission?">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->permissions)): ?>
                    <tr><td colspan="6" class="text-center text-base-content/60 py-8">No permissions found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
