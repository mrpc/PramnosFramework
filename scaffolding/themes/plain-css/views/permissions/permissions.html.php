<?php
/**
 * Permissions / RBAC grants list (plain-CSS theme).
 *
 * Variables:
 *   $this->permissions — iterable rows
 *   $this->problems    — permissionid => why that grant no longer matches what its application declares
 *   $this->page        — current page
 *   $this->total       — total count
 */
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >Permissions</h2>
        <a href="<?php echo adminUrl('Permissions/edit'); ?>" class="btn btn-primary">+ New Permission</a>
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
                        <td><?php echo (int)$p['permissionid']; ?></td>
                        <td>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($p['subject_type'] ?? ''); ?></span>
                            #<?php echo htmlspecialchars((string)($p['subject_id'] ?? '')); ?>
                        </td>
                        <td><?php echo htmlspecialchars($p['object_type'] ?? ''); ?><?php if (isset($this->problems[(int) $p['permissionid']])): ?><span style="display:block;font-size:12px;color:#b00020">&#9888; <?php echo htmlspecialchars($this->problems[(int) $p['permissionid']], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></td>
                        <td><code><?php echo htmlspecialchars($p['action'] ?? ''); ?></code></td>
                        <td>
                            <?php echo ($p['grant_type'] ?? 'allow') === 'allow'
                                ? '<span class="badge bg-success">Allow</span>'
                                : '<span class="badge bg-danger">Deny</span>'; ?>
                        </td>
                        <td style="text-align:right">
                            <a href="<?php echo adminUrl('Permissions' . '/edit/' . ((int)$p['permissionid'])); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="post" action="<?php echo adminUrl('Permissions' . '/delete/' . ((int)$p['permissionid'])); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete permission?">Delete</button></form>
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
