<?php
/**
 * OAuth2 Application create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->application — app row array (null when creating)
 */
$app = $this->application ?? [];
$isNew = empty($app['appid']);
?>
<div class="max-w-2xl mx-auto py-6 px-4">
    <h2 class="mb-6"><?php echo $isNew ? 'New Application' : 'Edit Application'; ?></h2>
    <?php if (!$isNew && !empty($app['apikey'])): ?>
        <div class="alert alert-info small">
            <strong>API Key:</strong> <code><?php echo htmlspecialchars($app['apikey'] ?? ''); ?></code><br>
            <em>Secret is not shown. Use Rotate to generate a new one.</em>
        </div>
    <?php endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-5">
            <form method="post" action="<?php echo sURL; ?>Applications/save">
                <?php if (!$isNew): ?>
                    <input type="hidden" name="appid" value="<?php echo (int)$app['appid']; ?>">
                <?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" name="name" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" required value="<?php echo htmlspecialchars($app['name'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" rows="2"><?php echo htmlspecialchars($app['description'] ?? ''); ?></textarea>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Redirect URI</label>
                    <input type="url" name="redirecturi" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" value="<?php echo htmlspecialchars($app['redirecturi'] ?? ''); ?>">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">Save</button>
                    <a href="<?php echo sURL; ?>Applications" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded hover:bg-gray-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
