<?php
/**
 * A segment's form: its name, what it is for, and the filter with the live count beside it. The
 * buttons stay in reach at the foot of the window. The same markup in every theme.
 *
 * Variables:
 *   $this->segment — Segments::get(), or null when creating
 *   $this->draft   — a new one's name, description and filter from Ask's draft, or null
 */
$e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$segment = $this->segment ?? null;
$isNew   = $segment === null;
$values  = $segment ?? ($this->draft ?? null) ?? [];
?>
<div class="pf-ui">
    <?php $this->activeNav = 'segments_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e($isNew ? t('New segment') : t('Edit segment')); ?></h1>
        </div>
    </header>
    <form method="post" action="<?php echo $e(adminUrl('Segments/save')); ?>">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="segmentid" value="<?php echo (int) $segment['id']; ?>">
        <?php endif; ?>
        <div class="pf-card"><div class="pf-card-body pf-stack">
            <div class="pf-grid-2">
                <label class="pf-field">
                    <span class="pf-label"><?php echo $e(t('Name')); ?></span>
                    <input type="text" name="name" maxlength="150" required class="pf-input" value="<?php echo $e($values['name'] ?? ''); ?>" placeholder="<?php echo $e(t('Professionals without a photo')); ?>">
                </label>
                <label class="pf-field">
                    <span class="pf-label"><?php echo $e(t('What it is for')); ?></span>
                    <input type="text" name="description" class="pf-input" value="<?php echo $e($values['description'] ?? ''); ?>">
                    <span class="pf-hint"><?php echo $e(t('Shown under the name in the list.')); ?></span>
                </label>
            </div>
        </div></div>
        <div class="pf-card"><div class="pf-card-body">
            <h2 class="pf-section-title"><?php echo $e(t('Who is in it')); ?></h2>
            <?php echo \Pramnos\Html\AudienceBuilder::filter('filter', $values['filter'] ?? null); ?>
        </div></div>
        <div class="pf-form-footer">
            <p class="pf-hint"><?php echo $e($isNew ? t('Saving works it out at once, without announcing the people already in it.') : t('Saving works it out again.')); ?></p>
            <a href="<?php echo $e($isNew ? adminUrl('Segments') : adminUrl('Segments/view/') . (int) $segment['id']); ?>" class="pf-btn pf-btn-ghost"><?php echo $e(t('Cancel')); ?></a>
            <button type="submit" class="pf-btn pf-btn-primary"><?php echo $e(t('Save')); ?></button>
        </div>
    </form>
</div>
