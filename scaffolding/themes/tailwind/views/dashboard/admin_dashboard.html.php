<?php
/**
 * The administration dashboard (Tailwind theme): the signed-in person's widgets, in their order and
 * widths, and the controls to change them — Customise, then move, resize, hide or add a card.
 * pf-utils.js does the editing through the data-pf-dashboard* attributes and saves each change.
 *
 * Variables:
 *   $this->widgets  — array[] {id, size (s|m|l|full), title, link|null, settings, options, html},
 *                     each drawn by card.html.php
 *   $this->addable  — array   id => title: the widgets this person may add
 *   $this->catalogue — array  id => {title, description}: the same, with what each shows
 *   $this->cardUrl  — string  where one card is drawn alone (GET ?id=)
 *   $this->saveUrl  — string  where a layout is saved (POST, JSON)
 *   $this->resetUrl — string  where it goes back to the default (POST)
 *   ($this->activeUsers, dbStats, apiStats, healthResults — the figures, for a view of the older shape)
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$t = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
?>
<div class="px-4 py-6">
    <div class="flex items-center gap-2 mb-4">
        <h2 class="text-2xl font-semibold"><?php echo $e($t('Dashboard')); ?></h2>
        <button type="button" class="btn btn-sm btn-ghost ms-auto" data-pf-dashboard-edit aria-pressed="false"><?php echo $e($t('Customise')); ?></button>
    </div>

    <div class="card bg-base-100 border border-dashed border-primary mb-4 pf-dashboard-bar" data-pf-dashboard-bar hidden>
        <div class="card-body p-3 flex-row flex-wrap items-center gap-3">
            <span class="text-sm"><?php echo $e($t('Move, resize, set or hide each card; add the ones you want.')); ?></span>
            <?php if (!empty($this->addable)): ?>
            <label class="flex items-center gap-2 text-sm"><?php echo $e($t('Add')); ?>
                <select class="select select-sm select-bordered" data-pf-dashboard-add>
                    <option value=""><?php echo $e($t('Choose a card')); ?></option>
                    <?php foreach ($this->addable as $id => $title): ?><option value="<?php echo $e($id); ?>" title="<?php echo $e($t((string) ($this->catalogue[$id]['description'] ?? ''))); ?>"><?php echo $e($t($title)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <span class="ms-auto"><?php echo \Pramnos\Html\Icon::postControl($this->resetUrl, $e($t('Back to the default')), ['class' => 'btn btn-sm btn-ghost', 'data-confirm' => $t('Go back to the default dashboard?')]); ?></span>
            <button type="button" class="btn btn-sm btn-primary" data-pf-dashboard-done><?php echo $e($t('Done')); ?></button>
        </div>
    </div>

    <p class="sr-only" data-pf-dashboard-status aria-live="polite"></p>
    <div class="pf-dashboard" data-pf-dashboard data-save="<?php echo $e($this->saveUrl); ?>" data-card="<?php echo $e($this->cardUrl ?? ''); ?>">
        <?php foreach ($this->widgets as $widget): ?>
        <?php $this->insert('card', ['widget' => $widget]); ?>
        <?php endforeach; ?>
        <p class="opacity-70 pf-dashboard-full" data-pf-dashboard-empty<?php echo $this->widgets ? ' hidden' : ''; ?>><?php echo $e($t('Your dashboard is empty: choose Customise, then add a card.')); ?></p>
    </div>
</div>
