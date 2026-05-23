<?php
/**
 * Organization create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->organization — row array (null when creating)
 */
$org = $this->organization ?? [];
$isNew = empty($org['id']);
?>
<div class="max-w-2xl mx-auto py-6 px-4">
    <h2 class="mb-6"><?php echo $isNew ? 'New Organization' : 'Edit Organization'; ?></h2>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-5">
            <form method="post" action="<?php echo sURL; ?>Organizations/save">
                <?php if (!$isNew): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$org['id']; ?>">
                <?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" name="name" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" required value="<?php echo htmlspecialchars($org['name'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" rows="3"><?php echo htmlspecialchars($org['description'] ?? ''); ?></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">Save</button>
                    <a href="<?php echo sURL; ?>Organizations" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded hover:bg-gray-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
