<?php
/**
 * A group of dashboard cards as tabs (bootstrap theme): one frame, a tab per card, only the active
 * tab's card drawn — the others are drawn by the server the first time they are opened
 * (`card?id=…&part=body`). While customising, the group moves, resizes and hides as one card, and
 * its active tab can be moved along the strip or taken out of the group.
 *
 * A theme draws its own tabs by copying this file: the markup the script reads is the
 * data-pf-* attributes — data-pf-widget-group on the frame, data-pf-tabs on the strip (data-active-class: the
 * theme's class for the active tab), data-pf-tab on each tab, and
 * data-pf-tab-panel on each card's panel (data-pf-tab-lazy until it is drawn).
 *
 * Variables: `$widget` — {group: true, id, size, title, active, tabs: [card, …]}, each card as
 * DashboardController::cardFor() gives it, `html` null for one not drawn yet.
 */
$widget = $widget ?? $this->widget;
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
$label = ($widget['title'] ?? '') !== '' ? (string) $widget['title'] : implode(' · ', array_map(static fn (array $tab): string => $t((string) $tab['title']), $widget['tabs']));
?>
<section class="card shadow-sm pf-dashboard-tabs<?php echo $widget['size'] === 'full' ? ' pf-dashboard-full' : ''; ?>"
         data-pf-widget="<?php echo $e($widget['id']); ?>" data-pf-widget-group data-size="<?php echo $e($widget['size']); ?>"
         data-active="<?php echo $e($widget['active']); ?>" data-title="<?php echo $e($widget['title'] ?? ''); ?>" aria-label="<?php echo $e($label); ?>">
    <div class="card-body">
        <div class="pf-dashboard-head mb-2" data-pf-widget-head>
            <h3 class="h6 mb-0"><?php echo ($widget['title'] ?? '') !== '' ? $e($widget['title']) : ''; ?></h3>
            <div class="pf-dashboard-tools btn-group btn-group-sm" data-pf-widget-tools hidden>
                <span class="btn btn-outline-secondary pf-dashboard-grip" data-pf-widget-grip aria-hidden="true" title="<?php echo $e($t('Drag to move')); ?>">&#10303;</span>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-move="-1" aria-label="<?php echo $e($t('Move up')); ?>">&uarr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-move="1" aria-label="<?php echo $e($t('Move down')); ?>">&darr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-resize aria-label="<?php echo $e($t('Width')); ?>">&harr;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-tab-move="-1" aria-label="<?php echo $e($t('Move this tab left')); ?>">&laquo;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-tab-move="1" aria-label="<?php echo $e($t('Move this tab right')); ?>">&raquo;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-tab-ungroup aria-label="<?php echo $e($t('Take this tab out of the group')); ?>">&#8689;</button>
                <button type="button" class="btn btn-outline-secondary" data-pf-widget-hide aria-label="<?php echo $e($t('Hide')); ?>">&times;</button>
            </div>
        </div>
        <div class="nav nav-tabs mb-3" role="tablist" data-pf-tabs data-active-class="active">
            <?php foreach ($widget['tabs'] as $tab): $on = $tab['id'] === $widget['active']; ?>
            <button type="button" role="tab" class="nav-link<?php echo $on ? ' active' : ''; ?>" data-pf-tab="<?php echo $e($tab['id']); ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>"><?php echo $e($t((string) $tab['title'])); ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($widget['tabs'] as $tab): $on = $tab['id'] === $widget['active']; ?>
        <div role="tabpanel" data-pf-tab-panel="<?php echo $e($tab['id']); ?>" data-settings="<?php echo $e(json_encode((object) ($tab['settings'] ?? []))); ?>" aria-label="<?php echo $e($t((string) $tab['title'])); ?>"<?php echo $on ? '' : ' hidden'; ?><?php echo $tab['html'] === null ? ' data-pf-tab-lazy' : ''; ?>>
            <?php if ($tab['html'] !== null): ?>
            <?php echo $tab['html'] !== '' ? $tab['html'] : '<p class="small text-body-secondary mb-0">' . $e($t('Nothing to show today.')) . '</p>'; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <span class="pf-dashboard-size-handle" data-pf-widget-size-handle aria-hidden="true" title="<?php echo $e($t('Drag to resize')); ?>" hidden></span>
</section>
