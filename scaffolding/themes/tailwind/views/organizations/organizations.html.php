<?php
/**
 * Organizations list (Tailwind theme).
 *
 * Variables:
 *   $this->organizations — iterable rows
 *   $this->page          — current page
 *   $this->total         — total count
 */
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 >Organizations</h2>
        <a href="<?php echo sURL; ?>Organizations/edit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">+ New Organization</a>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>ID</th><th>Name</th><th>Description</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->organizations ?? []) as $org): ?>
                    <tr>
                        <td><?php echo (int)$org['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($org['name'] ?? ''); ?></strong></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars(substr($org['description'] ?? '', 0, 80)); ?></td>
                        <td><?php echo ($org['is_active'] ?? 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Organizations/members/<?php echo (int)$org['id']; ?>" class="px-3 py-1 border border-blue-300 text-blue-700 text-xs rounded hover:bg-blue-50">Members</a>
                            <a href="<?php echo sURL; ?>Organizations/edit/<?php echo (int)$org['id']; ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Edit</a>
                            <a href="<?php echo sURL; ?>Organizations/delete/<?php echo (int)$org['id']; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" data-confirm="Deactivate this organization?">Deactivate</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->organizations)): ?>
                    <tr><td colspan="5" class="text-center text-gray-400 py-8">No organizations found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
