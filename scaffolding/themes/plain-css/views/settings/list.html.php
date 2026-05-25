<?php
/**
 * Raw key-value settings DataTable (plain-CSS theme).
 *
 * Variables:
 *   $this->settings — array of ['key', 'value', 'readonly']
 */
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div>
            <h2>Raw Settings</h2>
            <small style="color:#888">All key-value pairs stored in the settings table.</small>
        </div>
        <div style="display:flex;gap:8px">
            <a href="<?php echo sURL; ?>settings/edit" class="btn btn-primary">+ New Setting</a>
            <a href="<?php echo sURL; ?>settings" class="btn btn-outline-secondary">System Settings</a>
        </div>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr>
                        <th style="padding:10px 12px;text-align:left;font-size:12px">Key</th>
                        <th style="padding:10px 12px;text-align:left;font-size:12px">Value</th>
                        <th style="padding:10px 12px;width:130px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (($this->settings ?? []) as $row): ?>
                    <tr style="border-top:1px solid #f0f0f0">
                        <td style="padding:8px 12px"><code style="font-size:12px;background:#f5f5f5;padding:2px 4px;border-radius:3px"><?php echo htmlspecialchars($row['key'] ?? ''); ?></code></td>
                        <td style="padding:8px 12px;max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#555;font-size:13px"><?php echo htmlspecialchars($row['value'] ?? ''); ?></td>
                        <td style="padding:8px 12px;text-align:right">
                            <?php if (!($row['readonly'] ?? false)): ?>
                                <a href="<?php echo sURL; ?>settings/edit/<?php echo urlencode($row['key'] ?? ''); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <a href="<?php echo sURL; ?>settings/delete/<?php echo urlencode($row['key'] ?? ''); ?>" class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete this setting?')">Delete</a>
                            <?php else: ?>
                                <span style="font-size:11px;color:#888">Read-only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->settings)): ?>
                    <tr><td colspan="3" style="text-align:center;color:#888;padding:24px">No settings found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
