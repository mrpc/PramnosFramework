<?php
/**
 * A rule's editor: its name, then when, who, what and until as numbered cards. The buttons stay in
 * reach at the foot of the window. The same markup in every theme.
 *
 * Variables:
 *   $this->rule — Rules::get(), or null when creating
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rule  = $this->rule ?? null;
$isNew = $rule === null;
?>
<div class="pf-ui">
    <?php $this->activeNav = 'rules_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e($isNew ? t('New rule') : t('Edit rule')); ?></h1>
        </div>
    </header>
    <form method="post" action="<?php echo $e(adminUrl('Rules/save')); ?>" class="pf-stack">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="ruleid" value="<?php echo (int) $rule['id']; ?>">
        <?php endif; ?>
        <div class="pf-card"><div class="pf-card-body">
            <label class="pf-field">
                <span class="pf-label"><?php echo $e(t('Name')); ?></span>
                <input type="text" name="name" maxlength="150" required class="pf-input" value="<?php echo $e($rule['name'] ?? ''); ?>" placeholder="<?php echo $e(t('Welcome series')); ?>">
            </label>
        </div></div>
        <?php echo \Pramnos\Html\RuleEditor::render($rule['definition'] ?? null); ?>
        <div class="pf-form-footer">
            <p class="pf-hint"><?php echo $e($isNew ? t('A new rule is a draft until it is switched on.') : t('Saving makes a new version: people already on their way finish on theirs.')); ?></p>
            <a href="<?php echo $e($isNew ? adminUrl('Rules') : adminUrl('Rules/view/') . (int) $rule['id']); ?>" class="pf-btn pf-btn-ghost"><?php echo $e(t('Cancel')); ?></a>
            <button type="submit" class="pf-btn pf-btn-primary"><?php echo $e(t('Save')); ?></button>
        </div>
    </form>
</div>
