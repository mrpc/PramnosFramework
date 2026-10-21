<?php
/**
 * A segment's form (Bootstrap theme): its name, what it is for, and the filter with the live count.
 *
 * Variables:
 *   $this->segment — Segments::get(), or null when creating
 */
$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment = $this->segment ?? null;
$isNew   = $segment === null;
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'segments_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-3"><?php echo $e($isNew ? t('New segment') : t('Edit segment')); ?></h2>
    <form method="post" action="<?php echo $e(adminUrl('Segments/save')); ?>" class="card mb-3"><div class="card-body d-grid gap-3">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="segmentid" value="<?php echo (int) $segment['id']; ?>">
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr));gap:1rem">
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Name')); ?></span>
                <input type="text" name="name" maxlength="150" required class="form-control form-control-sm" value="<?php echo $e($segment['name'] ?? ''); ?>">
            </label>
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('What it is for')); ?></span>
                <input type="text" name="description" class="form-control form-control-sm" value="<?php echo $e($segment['description'] ?? ''); ?>">
            </label>
        </div>
        <?php echo \Pramnos\Html\AudienceBuilder::filter('filter', $segment['filter'] ?? null); ?>
        <p class="small text-muted"><?php echo $e(t('Saving works it out at once. A new segment records who is in it without announcing them; after that, everybody who enters or leaves is an event a rule can act on.')); ?></p>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(t('Save')); ?></button>
            <a href="<?php echo $e($isNew ? adminUrl('Segments') : adminUrl('Segments/view/') . (int) $segment['id']); ?>" class="btn btn-outline-secondary btn-sm"><?php echo $e(t('Cancel')); ?></a>
        </div>
    </div></form>
</div>
