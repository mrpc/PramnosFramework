<?php
/**
 * OAuth2 Applications list (Tailwind theme).
 *
 * Variables:
 *   $this->applications — iterable rows
 *   $this->page         — current page
 *   $this->total        — total count
 */
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 >OAuth2 Applications</h2>
        <a href="<?php echo sURL; ?>Applications/edit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">+ New Application</a>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
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
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Applications/tokens/<?php echo (int)$app['appid']; ?>" class="px-3 py-1 border border-blue-300 text-blue-700 text-xs rounded hover:bg-blue-50">Tokens</a>
                            <a href="<?php echo sURL; ?>Applications/rotate/<?php echo (int)$app['appid']; ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50" data-confirm="Rotate secret?">Rotate</a>
                            <a href="<?php echo sURL; ?>Applications/edit/<?php echo (int)$app['appid']; ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Edit</a>
                            <a href="<?php echo sURL; ?>Applications/delete/<?php echo (int)$app['appid']; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" data-confirm="Delete application?">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->applications)): ?>
                    <tr><td colspan="5" class="text-center text-gray-400 py-8">No applications found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
