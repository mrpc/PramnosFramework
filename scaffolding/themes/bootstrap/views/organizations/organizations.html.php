<?php
/**
 * Organizations list (Bootstrap theme).
 *
 * Variables:
 *   $this->organizations — iterable rows
 *   $this->page          — current page
 *   $this->total         — total count
 */
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Organizations</h2>
        <a href="<?php echo sURL; ?>Organizations/edit" class="btn btn-primary">+ New Organization</a>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>ID</th><th>Name</th><th>Description</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->organizations ?? []) as $org): ?>
                    <tr>
                        <td><?php echo (int)$org['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($org['name'] ?? ''); ?></strong></td>
                        <td class="text-muted small"><?php echo htmlspecialchars(substr($org['description'] ?? '', 0, 80)); ?></td>
                        <td><?php echo ($org['is_active'] ?? 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                        <td class="text-end">
                            <a href="<?php echo sURL; ?>Organizations/members/<?php echo (int)$org['id']; ?>" class="btn btn-sm btn-outline-info">Members</a>
                            <a href="<?php echo sURL; ?>Organizations/edit/<?php echo (int)$org['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Organizations/delete/<?php echo (int)$org['id']; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Deactivate this organization?">Deactivate</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->organizations)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No organizations found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
