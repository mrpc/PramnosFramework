<?php
/**
 * Setting create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->key    — setting key (string, empty when new)
 *   $this->value  — current value
 *   $this->isNew  — bool
 *   $this->error  — string error message
 */
?>
<div class="max-w-2xl mx-auto py-6 px-4">
    <h2 class="mb-6"><?php echo $this->isNew ? 'New Setting' : 'Edit Setting'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded mb-4"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-5">
            <form method="post" action="<?php echo sURL; ?>Settings/save">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Key</label>
                    <input type="text" name="skey" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" required
                        value="<?php echo htmlspecialchars($this->key ?? ''); ?>"
                        <?php echo !$this->isNew ? 'readonly' : ''; ?>>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Value</label>
                    <textarea name="svalue" class="w-full px-3 py-2 border border-gray-300 rounded text-sm" rows="4"><?php echo htmlspecialchars($this->value ?? ''); ?></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">Save</button>
                    <a href="<?php echo sURL; ?>Settings" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded hover:bg-gray-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
