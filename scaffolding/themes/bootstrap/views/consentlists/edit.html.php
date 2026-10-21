<?php
/**
 * A consent list's form (Bootstrap theme).
 *
 * Variables:
 *   $this->list     — ConsentLists::get(), or null when creating
 *   $this->sources  — the declared sources' labels, by key
 *   $this->channels — the channels' words, by key
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$list  = $this->list ?? null;
$isNew = $list === null;
$field = 'form-control form-control-sm';
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'consentlists_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-3"><?php echo $e($isNew ? t('New consent list') : t('Edit consent list')); ?></h2>
    <form method="post" action="<?php echo $e(adminUrl('ConsentLists/save')); ?>" class="card mb-3"><div class="card-body d-grid gap-3">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="listid" value="<?php echo (int) $list['id']; ?>">
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr));gap:1rem">
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Name')); ?></span>
                <input type="text" name="name" maxlength="150" required class="<?php echo $field; ?>" value="<?php echo $e($list['name'] ?? ''); ?>">
            </label>
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Channel')); ?></span>
                <select name="channel" class="form-select form-select-sm">
                    <?php foreach ($this->channels as $key => $word): ?>
                        <option value="<?php echo $e($key); ?>"<?php echo ($list['channel'] ?? 'email') === $key ? ' selected' : ''; ?>><?php echo $e($word); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Read from')); ?></span>
                <select name="source" class="form-select form-select-sm">
                    <?php foreach ($this->sources as $key => $label): ?>
                        <option value="<?php echo $e($key); ?>"<?php echo ($list['source'] ?? 'everyone') === $key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="d-grid gap-1">
                <span class="form-label mb-0"><?php echo $e(t('Email opt-out list (optional)')); ?></span>
                <input type="text" name="optoutlist" maxlength="64" class="<?php echo $field; ?>" value="<?php echo $e($list['optOutList'] ?? ''); ?>" placeholder="newsletter">
            </label>
        </div>
        <label class="d-grid gap-1">
            <span class="form-label mb-0"><?php echo $e(t('What it means, as the person reads it')); ?></span>
            <input type="text" name="description" class="<?php echo $field; ?>" value="<?php echo $e($list['description'] ?? ''); ?>">
        </label>
        <label class="form-check d-flex align-items-center gap-2">
            <input type="checkbox" name="defaulton" value="1" class="form-check-input"<?php echo !empty($list['defaultOn']) ? ' checked' : ''; ?>>
            <span><?php echo $e(t('New accounts start in this list')); ?></span>
        </label>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(t('Save')); ?></button>
            <a href="<?php echo $e(adminUrl('ConsentLists')); ?>" class="btn btn-outline-secondary btn-sm"><?php echo $e(t('Cancel')); ?></a>
        </div>
    </div></form>
</div>
