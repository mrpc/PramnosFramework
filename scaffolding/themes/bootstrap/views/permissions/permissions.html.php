<?php
/**
 * Permissions / RBAC grants list (Bootstrap theme).
 *
 * Variables:
 *   $this->permissions — iterable rows
 *   $this->page        — current page
 *   $this->total       — total count
 */
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Permissions</h2>
        <a href="<?php echo sURL; ?>Permissions/edit" class="btn btn-primary">+ New Permission</a>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
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
                        <td class="text-end">
                            <a href="<?php echo sURL; ?>Permissions/edit/<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Permissions/delete/<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Delete permission?">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->permissions)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No permissions found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
