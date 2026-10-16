<?php
/**
 * The administration dashboard (plain-CSS theme): the signed-in person's widgets, in their order and
 * widths, and the controls to change them — Customise, then move, resize, hide or add a card.
 * pf-utils.js does the editing through the data-pf-dashboard* attributes and saves each change.
 *
 * Variables:
 *   $this->widgets  — array[] {id, size (s|m|l|full), title, link|null, settings, options, html},
 *                     each drawn by card.html.php
 *   $this->addable  — array   id => title: the widgets this person may add (a view of the older shape)
 *   $this->catalogue — array  id => {title, description, placed}: every card this person may have,
 *                     for the catalogue to add from (a dialog, searched)
 *   $this->cardUrl  — string  where one card is drawn alone (GET ?id=)
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
            <span class="small"><?php echo $e($t('Move, resize, set or hide each card; add the ones you want.')); ?></span>
            <?php if (!empty($this->catalogue)): ?>
            <button type="button" class="btn btn-primary btn-sm" data-pf-dashboard-catalogue><?php echo $e($t('Add a card')); ?></button>
            <?php endif; ?>
            <?php echo \Pramnos\Html\Icon::postControl($this->resetUrl, $e($t('Back to the default')), ['class' => 'btn btn-link btn-sm', 'data-confirm' => $t('Go back to the default dashboard?')]); ?>
            <button type="button" class="btn btn-primary btn-sm" data-pf-dashboard-done><?php echo $e($t('Done')); ?></button>
        </div>
    </div>

    <p class="pf-visually-hidden" data-pf-dashboard-status aria-live="polite"></p>
    <div class="pf-dashboard" data-pf-dashboard data-save="<?php echo $e($this->saveUrl); ?>" data-card="<?php echo $e($this->cardUrl ?? ''); ?>" data-pf-dashboard-say="<?php echo $e(json_encode(['saved' => $t('saved'), 'hidden' => $t('hidden'), 'added' => $t('added'), 'failed' => $t('The dashboard could not be saved.'), 'sizes' => ['s' => $t('small'), 'm' => $t('medium'), 'l' => $t('large'), 'full' => $t('full width')]], JSON_UNESCAPED_UNICODE)); ?>">
        <?php foreach ($this->widgets as $widget): ?>
        <?php $this->insert('card', ['widget' => $widget]); ?>
        <?php endforeach; ?>
        <p class="pf-muted pf-dashboard-full" data-pf-dashboard-empty<?php echo $this->widgets ? ' hidden' : ''; ?>><?php echo $e($t('Your dashboard is empty: choose Customise, then add a card.')); ?></p>
    </div>
    <?php if (!empty($this->catalogue)): ?>
    <dialog class="card" data-pf-dashboard-catalogue-dialog aria-labelledby="pf-dashboard-catalogue-title">
        <div class="card-body">
            <h3 id="pf-dashboard-catalogue-title"><?php echo $e($t('Add a card')); ?></h3>
            <input type="search" class="form-control" data-pf-dashboard-catalogue-search placeholder="<?php echo $e($t('Search the cards')); ?>" aria-label="<?php echo $e($t('Search the cards')); ?>">
            <ul style="list-style:none;padding:0;margin:.75rem 0;max-height:60vh;overflow:auto">
                <?php foreach ($this->catalogue as $id => $one): ?>
                <li class="pf-ui-row" data-pf-dashboard-option="<?php echo $e($id); ?>">
                    <div style="flex:1;min-width:12rem">
                        <strong><?php echo $e($t((string) $one['title'])); ?></strong>
                        <?php if (($one['description'] ?? '') !== ''): ?><div class="pf-muted"><?php echo $e($t((string) $one['description'])); ?></div><?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" data-pf-dashboard-add="<?php echo $e($id); ?>"<?php echo !empty($one['placed']) ? ' hidden' : ''; ?>><?php echo $e($t('Add')); ?></button>
                    <span class="badge" data-pf-dashboard-present<?php echo !empty($one['placed']) ? '' : ' hidden'; ?>><?php echo $e($t('On the board')); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-pf-dashboard-catalogue-close><?php echo $e($t('Close')); ?></button>
        </div>
    </dialog>
    <?php endif; ?>
</div>
