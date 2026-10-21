<?php
/**
 * An A/B test's form: what the code asks for, the variants and their shares, who takes part, and
 * the goal — an event picked from the searchable list — within days. The same markup in every theme.
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
$groups   = [];
foreach ($this->events as $key => $event) {
    $heading = (string) ($event['group'] ?? '') !== '' ? (string) $event['group'] : t('Other');
    $groups[$heading] ??= ['label' => $heading, 'options' => []];
    $groups[$heading]['options'][] = ['value' => (string) $key, 'label' => (string) $event['label'], 'hint' => (string) $key];
}
?>
<div class="pf-ui">
    <?php $this->activeNav = 'experiments_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e($isNew ? t('New A/B test') : t('Edit A/B test')); ?></h1>
        </div>
    </header>
    <form method="post" action="<?php echo $e(adminUrl('Experiments/save')); ?>">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?><input type="hidden" name="experimentid" value="<?php echo (int) $test['id']; ?>"><?php endif; ?>
        <div class="pf-card"><div class="pf-card-body pf-grid-2">
            <label class="pf-field">
                <span class="pf-label"><?php echo $e(t('Name')); ?></span>
                <input type="text" name="name" maxlength="150" required class="pf-input" value="<?php echo $e($test['name'] ?? ''); ?>">
            </label>
            <label class="pf-field">
                <span class="pf-label"><?php echo $e(t('Name for the code')); ?></span>
                <input type="text" name="slug" maxlength="64" pattern="[a-z0-9][a-z0-9._-]*" <?php echo $isNew ? 'required' : 'readonly'; ?> class="pf-input" value="<?php echo $e($test['slug'] ?? ''); ?>" placeholder="premium-annual-first">
                <span class="pf-hint"><?php echo $e(t('What the code asks for: lower-case letters, digits, . _ -. It cannot change later.')); ?></span>
            </label>
        </div></div>
        <div class="pf-card"><div class="pf-card-body">
            <h2 class="pf-section-title"><?php echo $e(t('Variants')); ?></h2>
            <div class="pf-stack">
                <?php for ($i = 0; $i < \Pramnos\Application\Controllers\Experiments::FORM_VARIANTS; $i++): $v = $variants[$i] ?? ['key' => '', 'share' => '', 'label' => '']; ?>
                    <div class="pf-rule-sentence">
                        <input type="text" name="variants[<?php echo $i; ?>][key]" maxlength="32" class="pf-input pf-input-short" value="<?php echo $e($v['key']); ?>" placeholder="<?php echo $e(t('Key')); ?>" aria-label="<?php echo $e(t('Key')); ?>">
                        <input type="text" name="variants[<?php echo $i; ?>][label]" class="pf-input pf-input-grow" value="<?php echo $e($v['label']); ?>" placeholder="<?php echo $e(t('What it is')); ?>" aria-label="<?php echo $e(t('What it is')); ?>">
                        <input type="number" name="variants[<?php echo $i; ?>][share]" min="0" max="100" class="pf-input pf-input-short" value="<?php echo $e($v['share']); ?>" aria-label="<?php echo $e(t('Share %')); ?>">
                        <span class="pf-rule-word">%</span>
                    </div>
                <?php endfor; ?>
            </div>
            <p class="pf-hint"><?php echo $e(t('Two variants or more, their shares adding up to 100. Leave a row empty to leave it out.')); ?></p>
        </div></div>
        <div class="pf-card"><div class="pf-card-body pf-grid-2">
            <label class="pf-field">
                <span class="pf-label"><?php echo $e(t('Who takes part')); ?></span>
                <select name="segment" class="pf-select">
                    <option value=""><?php echo $e(t('Everybody, visitors too')); ?></option>
                    <?php foreach ($this->segments as $segment): ?>
                        <option value="<?php echo (int) $segment['id']; ?>"<?php echo ($test['segment'] ?? null) === $segment['id'] ? ' selected' : ''; ?>><?php echo $e($segment['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="pf-field">
                <span class="pf-label"><?php echo $e(t('Goal')); ?></span>
                <div class="pf-rule-sentence">
                    <div class="pf-combo-field" data-pf-combobox data-groups="<?php echo $e((string) json_encode(array_values($groups), JSON_UNESCAPED_UNICODE)); ?>"
                         data-placeholder="<?php echo $e(t('Choose an event…')); ?>" data-label="<?php echo $e(t('Goal')); ?>" data-search="<?php echo $e(t('Search…')); ?>" data-empty="<?php echo $e(t('Nothing found')); ?>">
                        <input type="hidden" name="goalevent" value="<?php echo $e($test['goalEvent'] ?? ''); ?>">
                    </div>
                    <span class="pf-rule-word"><?php echo $e(t('within')); ?></span>
                    <input type="number" name="goaldays" min="1" class="pf-input pf-input-short" value="<?php echo (int) ($test['goalDays'] ?? 7); ?>" aria-label="<?php echo $e(t('Within days')); ?>">
                    <span class="pf-rule-word"><?php echo $e(t('days')); ?></span>
                </div>
                <span class="pf-hint"><?php echo $e(t('Counted from when each person is given a variant.')); ?></span>
            </div>
        </div></div>
        <div class="pf-form-footer">
            <a href="<?php echo $e($isNew ? adminUrl('Experiments') : adminUrl('Experiments/view/') . (int) $test['id']); ?>" class="pf-btn pf-btn-ghost"><?php echo $e(t('Cancel')); ?></a>
            <button type="submit" class="pf-btn pf-btn-primary"><?php echo $e(t('Save')); ?></button>
        </div>
    </form>
    <script src="<?php echo $e(assetUrl('assets/js/pf-audience.js')); ?>" defer></script>
</div>
