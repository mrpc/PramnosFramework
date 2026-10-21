<?php
/**
 * A segment's form (Tailwind theme): its name, what it is for, and the filter with the live count.
 *
 * Variables:
 *   $this->segment — Segments::get(), or null when creating
 */
$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment = $this->segment ?? null;
$isNew   = $segment === null;
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'segments_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo $e($isNew ? t('New segment') : t('Edit segment')); ?></h2>
    <form method="post" action="<?php echo $e(adminUrl('Segments/save')); ?>" class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5 grid gap-4">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="segmentid" value="<?php echo (int) $segment['id']; ?>">
        <?php endif; ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <label class="grid gap-1">
                <span class="text-sm font-medium"><?php echo $e(t('Name')); ?></span>
                <input type="text" name="name" maxlength="150" required class="input input-sm input-bordered w-full" value="<?php echo $e($segment['name'] ?? ''); ?>">
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-medium"><?php echo $e(t('What it is for')); ?></span>
                <input type="text" name="description" class="input input-sm input-bordered w-full" value="<?php echo $e($segment['description'] ?? ''); ?>">
            </label>
        </div>
        <?php echo \Pramnos\Html\AudienceBuilder::filter('filter', $segment['filter'] ?? null); ?>
        <p class="text-sm text-base-content/70"><?php echo $e(t('Saving works it out at once. A new segment records who is in it without announcing them; after that, everybody who enters or leaves is an event a rule can act on.')); ?></p>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(t('Save')); ?></button>
            <a href="<?php echo $e($isNew ? adminUrl('Segments') : adminUrl('Segments/view/') . (int) $segment['id']); ?>" class="btn btn-outline btn-sm"><?php echo $e(t('Cancel')); ?></a>
        </div>
    </div></form>
</div>
