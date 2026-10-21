<?php
/**
 * An A/B test's form (Bootstrap theme): what the code asks for, the variants and their shares, who
 * takes part, and the goal.
 *
 * Variables:
 *   $this->test     — Experiments::get(), or null when creating
 *   $this->segments — Segments::all(), to limit the test to one
 *   $this->events   — PersonEvents::all(), for the goal
 */
$e        = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$test     = $this->test ?? null;
$isNew    = $test === null;
$variants = $test['variants'] ?? [['key' => 'A', 'share' => 50, 'label' => ''], ['key' => 'B', 'share' => 50, 'label' => '']];
$field    = 'form-control form-control-sm';
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'experiments_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-3"><?php echo $e($isNew ? t('New A/B test') : t('Edit A/B test')); ?></h2>
    <form method="post" action="<?php echo $e(adminUrl('Experiments/save')); ?>" class="card mb-3"><div class="card-body d-grid gap-3">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?><input type="hidden" name="experimentid" value="<?php echo (int) $test['id']; ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr));gap:1rem">
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Name')); ?></span>
                <input type="text" name="name" maxlength="150" required class="<?php echo $field; ?>" value="<?php echo $e($test['name'] ?? ''); ?>">
            </label>
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Name for the code')); ?></span>
                <input type="text" name="slug" maxlength="64" pattern="[a-z0-9][a-z0-9._-]*" <?php echo $isNew ? 'required' : 'readonly'; ?> class="<?php echo $field; ?>" value="<?php echo $e($test['slug'] ?? ''); ?>" placeholder="premium-annual-first">
            </label>
        </div>
        <fieldset class="d-grid gap-2">
            <legend class="form-label"><?php echo $e(t('Variants and their shares (adding up to 100)')); ?></legend>
            <?php for ($i = 0; $i < \Pramnos\Application\Controllers\Experiments::FORM_VARIANTS; $i++): $v = $variants[$i] ?? ['key' => '', 'share' => '', 'label' => '']; ?>
                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.5rem">
                    <input type="text" name="variants[<?php echo $i; ?>][key]" maxlength="32" class="<?php echo $field; ?>" value="<?php echo $e($v['key']); ?>" placeholder="<?php echo $e(t('Key')); ?>" aria-label="<?php echo $e(t('Key')); ?>">
                    <input type="text" name="variants[<?php echo $i; ?>][label]" class="<?php echo $field; ?>" value="<?php echo $e($v['label']); ?>" placeholder="<?php echo $e(t('What it is')); ?>" aria-label="<?php echo $e(t('What it is')); ?>">
                    <input type="number" name="variants[<?php echo $i; ?>][share]" min="0" max="100" class="<?php echo $field; ?>" value="<?php echo $e($v['share']); ?>" aria-label="<?php echo $e(t('Share %')); ?>">
                </div>
            <?php endfor; ?>
        </fieldset>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem">
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Who takes part')); ?></span>
                <select name="segment" class="form-select form-select-sm">
                    <option value=""><?php echo $e(t('Everybody, visitors too')); ?></option>
                    <?php foreach ($this->segments as $segment): ?>
                        <option value="<?php echo (int) $segment['id']; ?>"<?php echo ($test['segment'] ?? null) === $segment['id'] ? ' selected' : ''; ?>><?php echo $e($segment['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Goal')); ?></span>
                <input type="text" name="goalevent" list="experiment-events" class="<?php echo $field; ?>" value="<?php echo $e($test['goalEvent'] ?? ''); ?>">
                <datalist id="experiment-events"><?php foreach ($this->events as $key => $event): ?><option value="<?php echo $e($key); ?>"><?php echo $e($event['label']); ?></option><?php endforeach; ?></datalist>
            </label>
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Within days')); ?></span>
                <input type="number" name="goaldays" min="1" class="<?php echo $field; ?>" value="<?php echo (int) ($test['goalDays'] ?? 7); ?>">
            </label>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(t('Save')); ?></button>
            <a href="<?php echo $e($isNew ? adminUrl('Experiments') : adminUrl('Experiments/view/') . (int) $test['id']); ?>" class="btn btn-outline-secondary btn-sm"><?php echo $e(t('Cancel')); ?></a>
        </div>
    </div></form>
</div>
