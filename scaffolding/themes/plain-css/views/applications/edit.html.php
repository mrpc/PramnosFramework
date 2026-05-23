<?php
/**
 * OAuth2 Application create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->application — app row array (null when creating)
 */
$app = $this->application ?? [];
$isNew = empty($app['appid']);
?>
<div class="page-section"max-width:640px">
    <h2 style="margin-bottom:16px"><?php echo $isNew ? 'New Application' : 'Edit Application'; ?></h2>
    <?php if (!$isNew && !empty($app['apikey'])): ?>
        <div class="alert alert-info small">
            <strong>API Key:</strong> <code><?php echo htmlspecialchars($app['apikey'] ?? ''); ?></code><br>
            <em>Secret is not shown. Use Rotate to generate a new one.</em>
        </div>
    <?php endif; ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <form method="post" action="<?php echo sURL; ?>Applications/save">
                <?php if (!$isNew): ?>
                    <input type="hidden" name="appid" value="<?php echo (int)$app['appid']; ?>">
                <?php endif; ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Name</label>
                    <input type="text" name="name" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required value="<?php echo htmlspecialchars($app['name'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Description</label>
                    <textarea name="description" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" rows="2"><?php echo htmlspecialchars($app['description'] ?? ''); ?></textarea>
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Redirect URI</label>
                    <input type="url" name="redirecturi" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo htmlspecialchars($app['redirecturi'] ?? ''); ?>">
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Applications" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
