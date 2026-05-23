<?php
/**
 * Settings list (Tailwind theme).
 *
 * Variables:
 *   $this->settings — iterable rows (skey, svalue, autoload)
 *   $this->page     — current page
 *   $this->total    — total count
 */
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 >Settings</h2>
        <a href="<?php echo sURL; ?>Settings/edit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">+ New Setting</a>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>Key</th><th>Value</th><th>Autoload</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->settings ?? []) as $s): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($s['skey'] ?? ''); ?></code></td>
                        <td class="truncate max-w-xs"><?php echo htmlspecialchars($s['svalue'] ?? ''); ?></td>
                        <td><?php echo $s['autoload'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-light text-dark">No</span>'; ?></td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Settings/edit/<?php echo urlencode($s['skey'] ?? ''); ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Edit</a>
                            <a href="<?php echo sURL; ?>Settings/delete/<?php echo urlencode($s['skey'] ?? ''); ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" onclick="return confirm('Delete this setting?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->settings)): ?>
                    <tr><td colspan="4" class="text-center text-gray-400 py-8">No settings found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
