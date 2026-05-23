<?php
/**
 * Permissions / RBAC grants list (plain-CSS theme).
 *
 * Variables:
 *   $this->permissions — iterable rows
 *   $this->page        — current page
 *   $this->total       — total count
 */
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >Permissions</h2>
        <a href="<?php echo sURL; ?>Permissions/edit" class="btn btn-primary">+ New Permission</a>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
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
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Permissions/edit/<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Permissions/delete/<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete permission?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->permissions)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:24px">No permissions found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
