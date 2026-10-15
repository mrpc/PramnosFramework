<?php
/**
 * The administration dashboard (plain-CSS theme): the signed-in person's widgets, in their order and
 * widths, and the controls to change them — Customise, then move, resize, hide or add a card.
 * pf-utils.js does the editing through the data-pf-dashboard* attributes and saves each change.
 *
 * Variables:
 *   $this->widgets  — array[] {id, size (half|full), title, link|null, html}
 *   $this->addable  — array   id => title: the widgets this person may add
 *   $this->saveUrl  — string  where a layout is saved (POST, JSON)
 *   $this->resetUrl — string  where it goes back to the default (POST)
 *   ($this->activeUsers, dbStats, apiStats, healthResults — the figures, for a view of the older shape)
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
?>
<div class="container">
    <div class="pf-dashboard-head">
        <h2><?php echo $e($t('Dashboard')); ?></h2>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-pf-dashboard-edit aria-pressed="false"><?php echo $e($t('Customise')); ?></button>
    </div>

    <div class="card pf-dashboard-bar" data-pf-dashboard-bar hidden>
        <div class="card-body pf-ui-row">
            <span class="small"><?php echo $e($t('Move, resize or hide each card; add the ones you want.')); ?></span>
            <?php if (!empty($this->addable)): ?>
            <label class="pf-ui-row small"><?php echo $e($t('Add')); ?>
                <select class="form-control pf-ui-input-sm" data-pf-dashboard-add>
                    <option value=""><?php echo $e($t('Choose a card')); ?></option>
                    <?php foreach ($this->addable as $id => $title): ?><option value="<?php echo $e($id); ?>"><?php echo $e($t($title)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <?php echo \Pramnos\Html\Icon::postControl($this->resetUrl, $e($t('Back to the default')), ['class' => 'btn btn-link btn-sm', 'data-confirm' => $t('Go back to the default dashboard?')]); ?>
            <button type="button" class="btn btn-primary btn-sm" data-pf-dashboard-done><?php echo $e($t('Done')); ?></button>
        </div>
    </div>

    <p class="pf-visually-hidden" data-pf-dashboard-status aria-live="polite"></p>
    <div class="pf-dashboard" data-pf-dashboard data-save="<?php echo $e($this->saveUrl); ?>">
        <?php foreach ($this->widgets as $widget): ?>
        <section class="card<?php echo $widget['size'] === 'full' ? ' pf-dashboard-full' : ''; ?>"
                 data-pf-widget="<?php echo $e($widget['id']); ?>" data-size="<?php echo $e($widget['size']); ?>" aria-label="<?php echo $e($t($widget['title'])); ?>">
            <div class="card-body">
                <div class="pf-dashboard-head">
                    <h3><?php if ($widget['link']): ?><a href="<?php echo $e($widget['link']); ?>"><?php echo $e($t($widget['title'])); ?></a><?php else: echo $e($t($widget['title'])); endif; ?></h3>
                    <div class="pf-dashboard-tools btn-group" data-pf-widget-tools hidden>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-pf-widget-move="-1" aria-label="<?php echo $e($t('Move up')); ?>">&uarr;</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-pf-widget-move="1" aria-label="<?php echo $e($t('Move down')); ?>">&darr;</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-pf-widget-resize aria-label="<?php echo $e($t('Wide or narrow')); ?>">&harr;</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-pf-widget-hide aria-label="<?php echo $e($t('Hide')); ?>">&times;</button>
                    </div>
                </div>
                <?php echo $widget['html'] !== '' ? $widget['html'] : '<p class="pf-muted">' . $e($t('Nothing to show today.')) . '</p>'; ?>
            </div>
        </section>
        <?php endforeach; ?>
        <p class="pf-muted pf-dashboard-full" data-pf-dashboard-empty<?php echo $this->widgets ? ' hidden' : ''; ?>><?php echo $e($t('Your dashboard is empty: choose Customise, then add a card.')); ?></p>
    </div>
</div>
