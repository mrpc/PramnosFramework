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
        <div class="alert alert-error mb-4"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="p-5">
            <form method="post" action="<?php echo adminUrl('settings/save'); ?>">
                <?php if (!$this->isNew): ?>
                    <input type="hidden" name="original_key" value="<?php echo htmlspecialchars($this->key ?? ''); ?>">
                <?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Key</label>
                    <input type="text" name="key" class="input input-sm w-full" required
                        value="<?php echo htmlspecialchars($this->key ?? ''); ?>"
                        <?php echo !$this->isNew ? 'readonly' : ''; ?>>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Value</label>
                    <textarea name="value" class="input input-sm w-full" rows="4"><?php echo htmlspecialchars($this->value ?? ''); ?></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <a href="<?php echo adminUrl('settings/list'); ?>" class="btn btn-outline btn-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
