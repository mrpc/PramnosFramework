<?php
/**
 * Organization create/edit form (Tailwind theme).
 *
 * Variables:
 *   $this->organization — row array (null when creating)
 */
$org = $this->organization ?? [];
$isNew = empty($org['organization_id']);
?>
<div class="max-w-2xl mx-auto py-6 px-4">
    <h2 class="mb-6"><?php echo $isNew ? 'New Organization' : 'Edit Organization'; ?></h2>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div class="p-5">
            <form method="post" action="<?php echo adminUrl('Organizations/save'); ?>">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <?php if (!$isNew): ?>
                    <input type="hidden" name="organization_id" value="<?php echo (int)$org['organization_id']; ?>">
                <?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Name</label>
                    <input type="text" name="name" class="input input-sm w-full" required value="<?php echo htmlspecialchars($org['name'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-base-content mb-1">Description</label>
                    <textarea name="description" class="input input-sm w-full" rows="3"><?php echo htmlspecialchars($org['description'] ?? ''); ?></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <a href="<?php echo adminUrl('Organizations'); ?>" class="btn btn-outline btn-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
