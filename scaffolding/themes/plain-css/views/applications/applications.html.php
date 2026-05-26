<?php
/**
 * OAuth2 Applications list (plain-CSS theme).
 *
 * Variables:
 *   $this->applications — iterable rows
 *   $this->page         — current page
 *   $this->total        — total count
 */
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >OAuth2 Applications</h2>
        <a href="<?php echo sURL; ?>Applications/edit" class="btn btn-primary">+ New Application</a>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>ID</th><th>Name</th><th>API Key</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->applications ?? []) as $app): ?>
                    <tr>
                        <td><?php echo (int)$app['appid']; ?></td>
                        <td><strong><?php echo htmlspecialchars($app['name'] ?? ''); ?></strong>
                            <?php if (!empty($app['description'])): ?>
                                <small class="d-block text-muted"><?php echo htmlspecialchars(substr($app['description'], 0, 60)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo htmlspecialchars(substr($app['apikey'] ?? '', 0, 16)) . '…'; ?></code></td>
                        <td><?php echo ($app['status'] ?? 1) == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Deleted</span>'; ?></td>
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Applications/tokens/<?php echo (int)$app['appid']; ?>" class="btn btn-sm btn-outline-info">Tokens</a>
                            <a href="<?php echo sURL; ?>Applications/rotate/<?php echo (int)$app['appid']; ?>" class="btn btn-sm btn-outline-secondary" data-confirm="Rotate secret?">Rotate</a>
                            <a href="<?php echo sURL; ?>Applications/edit/<?php echo (int)$app['appid']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Applications/delete/<?php echo (int)$app['appid']; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Delete application?">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->applications)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#888;padding:24px">No applications found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
