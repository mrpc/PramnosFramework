<?php
/**
 * Raw key-value settings DataTable (Bootstrap theme).
 *
 * Variables:
 *   $this->settings — array of ['key', 'value', 'readonly']
 */
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-0">Raw Settings</h2>
            <small class="text-muted">All key-value pairs stored in the settings table.</small>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo sURL; ?>settings/edit" class="btn btn-primary btn-sm">+ New Setting</a>
            <a href="<?php echo sURL; ?>settings" class="btn btn-outline-secondary btn-sm">System Settings</a>
        </div>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Key</th>
                        <th>Value</th>
                        <th class="text-end" style="width:140px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (($this->settings ?? []) as $row): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($row['key'] ?? ''); ?></code></td>
                        <td class="text-truncate" style="max-width:360px"><?php echo htmlspecialchars($row['value'] ?? ''); ?></td>
                        <td class="text-end">
                            <?php if (!($row['readonly'] ?? false)): ?>
                                <a href="<?php echo sURL; ?>settings/edit/<?php echo urlencode($row['key'] ?? ''); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <a href="<?php echo sURL; ?>settings/delete/<?php echo urlencode($row['key'] ?? ''); ?>" class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete setting <?php echo htmlspecialchars(addslashes($row['key'] ?? '')); ?>?')">Delete</a>
                            <?php else: ?>
                                <span class="badge bg-secondary">Read-only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->settings)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No settings found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
