<?php
/**
 * Raw key-value settings DataTable (Tailwind theme).
 *
 * Variables:
 *   $this->settings — array of ['key', 'value', 'readonly']
 */
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <h2 class="text-xl font-semibold">Raw Settings</h2>
            <p class="text-sm text-gray-400">All key-value pairs stored in the settings table.</p>
        </div>
        <div class="flex gap-2">
            <a href="<?php echo sURL; ?>settings/edit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">+ New Setting</a>
            <a href="<?php echo sURL; ?>settings" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded hover:bg-gray-50">System Settings</a>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                <tr>
                    <th class="px-4 py-3 text-left">Key</th>
                    <th class="px-4 py-3 text-left">Value</th>
                    <th class="px-4 py-3 text-right w-32"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach (($this->settings ?? []) as $row): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded"><?php echo htmlspecialchars($row['key'] ?? ''); ?></code></td>
                    <td class="px-4 py-2 truncate max-w-xs text-gray-600"><?php echo htmlspecialchars($row['value'] ?? ''); ?></td>
                    <td class="px-4 py-2 text-right">
                        <?php if (!($row['readonly'] ?? false)): ?>
                            <a href="<?php echo sURL; ?>settings/edit/<?php echo urlencode($row['key'] ?? ''); ?>" class="text-xs px-2 py-1 border border-gray-300 text-gray-700 rounded hover:bg-gray-50">Edit</a>
                            <a href="<?php echo sURL; ?>settings/delete/<?php echo urlencode($row['key'] ?? ''); ?>" class="text-xs px-2 py-1 border border-red-300 text-red-700 rounded hover:bg-red-50"
                               onclick="return confirm('Delete this setting?')">Delete</a>
                        <?php else: ?>
                            <span class="text-xs text-gray-400">Read-only</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->settings)): ?>
                <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">No settings found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
