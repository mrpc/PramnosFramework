<?php
/**
 * One dashboard card (Tailwind theme), frame and all — drawn for each card of the dashboard, and
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
// The fields, as DashboardController::cardFor() describes them; a card drawn by an older
// controller has only `options` (a list, or null for a free value), read the same way.
$fields = (array) ($widget['fields'] ?? array_map(static fn ($choices): array => ['input' => is_array($choices) ? 'select' : 'text', 'label' => null, 'values' => (array) $choices, 'when' => []], $options));
/** Whether a field is shown for these settings: every setting it names has the value it names. */
$shown = static fn (array $field): bool => array_reduce(array_keys((array) ($field['when'] ?? [])), static fn (bool $all, string $other): bool => $all && (string) ($settings[$other] ?? '') === (string) $field['when'][$other], true);
?>
<section class="card bg-base-100 border border-base-300 min-w-0<?php echo $widget['size'] === 'full' ? ' pf-dashboard-full' : ''; ?>"
         data-pf-widget="<?php echo $e($widget['id']); ?>" data-size="<?php echo $e($widget['size']); ?>"
         data-settings="<?php echo $e(json_encode((object) $settings)); ?>" aria-label="<?php echo $e($t($widget['title'])); ?>">
    <div class="card-body p-4 gap-3">
        <div class="pf-dashboard-head">
            <h3 class="card-title text-base"><?php if ($widget['link']): ?><a class="hover:underline" href="<?php echo $e($widget['link']); ?>"><?php echo $e($t($widget['title'])); ?></a><?php else: echo $e($t($widget['title'])); endif; ?></h3>
            <div class="pf-dashboard-tools join" data-pf-widget-tools hidden>
                <span class="btn btn-xs join-item pf-dashboard-grip" data-pf-widget-grip aria-hidden="true" title="<?php echo $e($t('Drag to move')); ?>">&#10303;</span>
                <button type="button" class="btn btn-xs join-item" data-pf-widget-move="-1" aria-label="<?php echo $e($t('Move up')); ?>">&uarr;</button>
                <button type="button" class="btn btn-xs join-item" data-pf-widget-move="1" aria-label="<?php echo $e($t('Move down')); ?>">&darr;</button>
                <button type="button" class="btn btn-xs join-item" data-pf-widget-resize aria-label="<?php echo $e($t('Width')); ?>">&harr;</button>
                <?php if ($fields): ?><button type="button" class="btn btn-xs join-item" data-pf-widget-settings-toggle aria-expanded="false" aria-label="<?php echo $e($t('Settings')); ?>">&#9881;</button><?php endif; ?>
                <button type="button" class="btn btn-xs join-item" data-pf-widget-hide aria-label="<?php echo $e($t('Hide')); ?>">&times;</button>
            </div>
        </div>
        <?php if ($fields): ?>
        <form class="pf-dashboard-settings flex flex-wrap items-end gap-2" data-pf-widget-settings hidden>
            <?php foreach ($fields as $name => $field): ?>
            <label class="flex items-center gap-2 text-sm"<?php echo $field['when'] ? ' data-pf-when="' . $e(json_encode((object) $field['when'])) . '"' . ($shown($field) ? '' : ' hidden') : ''; ?>><?php echo $e($t((string) ($field['label'] ?? ucfirst(str_replace('_', ' ', (string) $name))))); ?>
                <?php if ($field['input'] === 'select'): ?>
                <select class="select select-sm select-bordered" name="<?php echo $e($name); ?>">
                    <?php foreach ($field['values'] as $value => $label): ?><option value="<?php echo $e($value); ?>"<?php echo (string) ($settings[$name] ?? '') === (string) $value ? ' selected' : ''; ?>><?php echo $e($t((string) $label)); ?></option><?php endforeach; ?>
                </select>
                <?php else: ?>
                <input class="input input-sm input-bordered" type="<?php echo $e($field['input']); ?>" name="<?php echo $e($name); ?>" value="<?php echo $e(is_scalar($settings[$name] ?? null) ? $settings[$name] : ''); ?>">
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-sm btn-primary"><?php echo $e($t('Apply')); ?></button>
        </form>
        <?php endif; ?>
        <?php echo $widget['html'] !== '' ? $widget['html'] : '<p class="text-sm opacity-60">' . $e($t('Nothing to show today.')) . '</p>'; ?>
    </div>
    <span class="pf-dashboard-size-handle" data-pf-widget-size-handle aria-hidden="true" title="<?php echo $e($t('Drag to resize')); ?>" hidden></span>
</section>
