<?php
/**
 * Settings list (Bootstrap theme).
 *
 * Variables:
 *   $this->settings — iterable rows (skey, svalue, autoload)
 *   $this->page     — current page
 *   $this->total    — total count
 */
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Settings</h2>
        <a href="<?php echo sURL; ?>Settings/edit" class="btn btn-primary">+ New Setting</a>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Key</th><th>Value</th><th>Autoload</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->settings ?? []) as $s): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($s['skey'] ?? ''); ?></code></td>
                        <td class="text-truncate" style="max-width:300px"><?php echo htmlspecialchars($s['svalue'] ?? ''); ?></td>
                        <td><?php echo $s['autoload'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-light text-dark">No</span>'; ?></td>
                        <td class="text-end">
                            <a href="<?php echo sURL; ?>Settings/edit/<?php echo urlencode($s['skey'] ?? ''); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Settings/delete/<?php echo urlencode($s['skey'] ?? ''); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this setting?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->settings)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No settings found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
