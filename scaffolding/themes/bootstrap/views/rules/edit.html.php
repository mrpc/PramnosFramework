<?php
/**
 * A rule's editor (Bootstrap theme): its name, and when, who, what and until in the editor.
 *
 * Variables:
 *   $this->rule — Rules::get(), or null when creating
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rule  = $this->rule ?? null;
$isNew = $rule === null;
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'rules_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-3"><?php echo $e($isNew ? t('New rule') : t('Edit rule')); ?></h2>
    <form method="post" action="<?php echo $e(adminUrl('Rules/save')); ?>" class="card mb-3"><div class="card-body d-grid gap-3">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="ruleid" value="<?php echo (int) $rule['id']; ?>">
        <?php endif; ?>
        <label class="d-grid gap-1">
            <span class="form-label mb-0"><?php echo $e(t('Name')); ?></span>
            <input type="text" name="name" maxlength="150" required class="form-control form-control-sm" value="<?php echo $e($rule['name'] ?? ''); ?>">
        </label>
        <?php echo \Pramnos\Html\RuleEditor::render($rule['definition'] ?? null); ?>
        <p class="small text-muted"><?php echo $e(t('Saving makes a new version: people already on their way finish on theirs. A new rule is a draft until it is switched on.')); ?></p>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(t('Save')); ?></button>
            <a href="<?php echo $e($isNew ? adminUrl('Rules') : adminUrl('Rules/view/') . (int) $rule['id']); ?>" class="btn btn-outline-secondary btn-sm"><?php echo $e(t('Cancel')); ?></a>
        </div>
    </div></form>
</div>
