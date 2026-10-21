<?php
/**
 * A segment's form (plain-CSS theme): its name, what it is for, and the filter with the live count.
 *
 * Variables:
 *   $this->segment — Segments::get(), or null when creating
 */
$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment = $this->segment ?? null;
$isNew   = $segment === null;
?>
<div class="page-section">
    <?php $this->activeNav = 'segments_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 style="margin-bottom:16px"><?php echo $e($isNew ? t('New segment') : t('Edit segment')); ?></h2>
    <form method="post" action="<?php echo $e(adminUrl('Segments/save')); ?>" class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px;display:grid;gap:16px">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="segmentid" value="<?php echo (int) $segment['id']; ?>">
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr));gap:1rem">
            <label style="display:grid;gap:4px">
                <span style="font-weight:600"><?php echo $e(t('Name')); ?></span>
                <input type="text" name="name" maxlength="150" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo $e($segment['name'] ?? ''); ?>">
            </label>
            <label style="display:grid;gap:4px">
                <span style="font-weight:600"><?php echo $e(t('What it is for')); ?></span>
                <input type="text" name="description" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo $e($segment['description'] ?? ''); ?>">
            </label>
        </div>
        <?php echo \Pramnos\Html\AudienceBuilder::filter('filter', $segment['filter'] ?? null); ?>
        <p style="opacity:.8"><?php echo $e(t('Saving works it out at once. A new segment records who is in it without announcing them; after that, everybody who enters or leaves is an event a rule can act on.')); ?></p>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary"><?php echo $e(t('Save')); ?></button>
            <a href="<?php echo $e($isNew ? adminUrl('Segments') : adminUrl('Segments/view/') . (int) $segment['id']); ?>" class="btn btn-outline-secondary"><?php echo $e(t('Cancel')); ?></a>
        </div>
    </div></form>
</div>
