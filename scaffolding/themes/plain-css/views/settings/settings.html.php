<?php
/**
 * Settings list (plain-CSS theme).
 *
 * Variables:
 *   $this->settings — iterable rows (skey, svalue, autoload)
 *   $this->page     — current page
 *   $this->total    — total count
 */
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >Settings</h2>
        <a href="<?php echo sURL; ?>Settings/edit" class="btn btn-primary">+ New Setting</a>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>Key</th><th>Value</th><th>Autoload</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->settings ?? []) as $s): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($s['skey'] ?? ''); ?></code></td>
                        <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($s['svalue'] ?? ''); ?></td>
                        <td><?php echo $s['autoload'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-light text-dark">No</span>'; ?></td>
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Settings/edit/<?php echo urlencode($s['skey'] ?? ''); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Settings/delete/<?php echo urlencode($s['skey'] ?? ''); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this setting?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->settings)): ?>
                    <tr><td colspan="4" style="text-align:center;color:#888;padding:24px">No settings found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
