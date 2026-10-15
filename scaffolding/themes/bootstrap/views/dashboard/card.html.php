<?php
/**
 * One dashboard card (Bootstrap theme), frame and all — drawn for each card of the dashboard, and
 * alone by DashboardController::card() for a card added or redrawn without a reload.
 *
 * Variables: `$widget` (or `$this->widget`) — {id, size (s|m|l|full), title, link|null, settings,
 * options (name => [value => label], or null for a free value), html}.
 */
$widget = $widget ?? $this->widget;
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$options = (array) ($widget['options'] ?? []);
$settings = (array) ($widget['settings'] ?? []);
?>
<section class="card shadow-sm<?php echo $widget['size'] === 'full' ? ' pf-dashboard-full' : ''; ?>"
         data-pf-widget="<?php echo $e($widget['id']); ?>" data-size="<?php echo $e($widget['size']); ?>"
         data-settings="<?php echo $e(json_encode((object) $settings)); ?>" aria-label="<?php echo $e($t($widget['title'])); ?>">
    <div class="card-body">
        <div class="pf-dashboard-head mb-3">
            <h3 class="h6 mb-0"><?php if ($widget['link']): ?><a href="<?php echo $e($widget['link']); ?>"><?php echo $e($t($widget['title'])); ?></a><?php else: echo $e($t($widget['title'])); endif; ?></h3>
            <div class="pf-dashboard-tools btn-group btn-group-sm" data-pf-widget-tools hidden>
                <span class="btn btn-outline-secondary pf-dashboard-grip" data-pf-widget-grip aria-hidden="true" title="<?php echo $e($t('Drag to move')); ?>">&#10303;</span>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-move="-1" aria-label="<?php echo $e($t('Move up')); ?>">&uarr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-move="1" aria-label="<?php echo $e($t('Move down')); ?>">&darr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-resize aria-label="<?php echo $e($t('Width')); ?>">&harr;</button>
                <?php if ($options): ?><button type="button" class="btn btn-outline-secondary" data-pf-widget-settings-toggle aria-expanded="false" aria-label="<?php echo $e($t('Settings')); ?>">&#9881;</button><?php endif; ?>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-hide aria-label="<?php echo $e($t('Hide')); ?>">&times;</button>
            </div>
        </div>
        <?php if ($options): ?>
        <form class="pf-dashboard-settings d-flex flex-wrap align-items-end gap-2 mb-3" data-pf-widget-settings hidden>
            <?php foreach ($options as $name => $choices): ?>
            <label class="small"><?php echo $e($t(ucfirst(str_replace('_', ' ', (string) $name)))); ?>
                <?php if (is_array($choices)): ?>
                <select class="form-select form-select-sm" name="<?php echo $e($name); ?>">
                    <?php foreach ($choices as $value => $label): ?><option value="<?php echo $e($value); ?>"<?php echo (string) ($settings[$name] ?? '') === (string) $value ? ' selected' : ''; ?>><?php echo $e($t((string) $label)); ?></option><?php endforeach; ?>
                </select>
                <?php else: ?>
                <input class="form-control form-control-sm" name="<?php echo $e($name); ?>" value="<?php echo $e(is_scalar($settings[$name] ?? null) ? $settings[$name] : ''); ?>">
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-sm btn-primary"><?php echo $e($t('Apply')); ?></button>
        </form>
        <?php endif; ?>
        <?php echo $widget['html'] !== '' ? $widget['html'] : '<p class="small text-body-secondary mb-0">' . $e($t('Nothing to show today.')) . '</p>'; ?>
    </div>
    <span class="pf-dashboard-size-handle" data-pf-widget-size-handle aria-hidden="true" title="<?php echo $e($t('Drag to resize')); ?>" hidden></span>
</section>
