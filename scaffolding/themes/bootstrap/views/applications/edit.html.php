<?php
/**
 * OAuth2 Application create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->application — app row array (null when creating)
 */
$app = $this->application ?? [];
$isNew = empty($app['appid']);
?>
<div class="container py-4" style="max-width:640px">
    <h2 class="mb-4"><?php echo $isNew ? 'New Application' : 'Edit Application'; ?></h2>
    <?php if (!$isNew && !empty($app['apikey'])): ?>
        <div class="alert alert-info small">
            <strong>API Key:</strong> <code><?php echo htmlspecialchars($app['apikey'] ?? ''); ?></code><br>
            <em>Secret is not shown. Use Rotate to generate a new one.</em>
        </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo sURL; ?>Applications/save">
                <?php if (!$isNew): ?>
                    <input type="hidden" name="appid" value="<?php echo (int)$app['appid']; ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($app['name'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($app['description'] ?? ''); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Redirect URI</label>
                    <input type="url" name="redirecturi" class="form-control" value="<?php echo htmlspecialchars($app['redirecturi'] ?? ''); ?>">
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Applications" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
