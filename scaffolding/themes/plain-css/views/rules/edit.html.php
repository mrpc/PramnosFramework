<?php
/**
 * A rule's editor (plain-CSS theme): its name, and when, who, what and until in the editor.
 *
 * Variables:
 *   $this->rule — Rules::get(), or null when creating
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rule  = $this->rule ?? null;
$isNew = $rule === null;
?>
<div class="page-section">
    <?php $this->activeNav = 'rules_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 style="margin-bottom:16px"><?php echo $e($isNew ? t('New rule') : t('Edit rule')); ?></h2>
    <form method="post" action="<?php echo $e(adminUrl('Rules/save')); ?>" class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px;display:grid;gap:16px">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="ruleid" value="<?php echo (int) $rule['id']; ?>">
        <?php endif; ?>
        <label style="display:grid;gap:4px">
            <span style="font-weight:600"><?php echo $e(t('Name')); ?></span>
            <input type="text" name="name" maxlength="150" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" value="<?php echo $e($rule['name'] ?? ''); ?>">
        </label>
        <?php echo \Pramnos\Html\RuleEditor::render($rule['definition'] ?? null); ?>
        <p style="opacity:.8"><?php echo $e(t('Saving makes a new version: people already on their way finish on theirs. A new rule is a draft until it is switched on.')); ?></p>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary"><?php echo $e(t('Save')); ?></button>
            <a href="<?php echo $e($isNew ? adminUrl('Rules') : adminUrl('Rules/view/') . (int) $rule['id']); ?>" class="btn btn-outline-secondary"><?php echo $e(t('Cancel')); ?></a>
        </div>
    </div></form>
</div>
