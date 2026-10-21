<?php
/**
 * A consent list's form (Tailwind theme).
 *
 * Variables:
 *   $this->list     — ConsentLists::get(), or null when creating
 *   $this->sources  — the declared sources' labels, by key
 *   $this->channels — the channels' words, by key
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$list  = $this->list ?? null;
$isNew = $list === null;
$field = 'input input-sm input-bordered w-full';
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'consentlists_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo $e($isNew ? t('New consent list') : t('Edit consent list')); ?></h2>
    <form method="post" action="<?php echo $e(adminUrl('ConsentLists/save')); ?>" class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5 grid gap-4">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="listid" value="<?php echo (int) $list['id']; ?>">
        <?php endif; ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <label class="grid gap-1">
                <span class="text-sm font-medium"><?php echo $e(t('Name')); ?></span>
                <input type="text" name="name" maxlength="150" required class="<?php echo $field; ?>" value="<?php echo $e($list['name'] ?? ''); ?>">
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-medium"><?php echo $e(t('Channel')); ?></span>
                <select name="channel" class="select select-sm select-bordered w-full">
                    <?php foreach ($this->channels as $key => $word): ?>
                        <option value="<?php echo $e($key); ?>"<?php echo ($list['channel'] ?? 'email') === $key ? ' selected' : ''; ?>><?php echo $e($word); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-medium"><?php echo $e(t('Read from')); ?></span>
                <select name="source" class="select select-sm select-bordered w-full">
                    <?php foreach ($this->sources as $key => $label): ?>
                        <option value="<?php echo $e($key); ?>"<?php echo ($list['source'] ?? 'everyone') === $key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-medium"><?php echo $e(t('Email opt-out list (optional)')); ?></span>
                <input type="text" name="optoutlist" maxlength="64" class="<?php echo $field; ?>" value="<?php echo $e($list['optOutList'] ?? ''); ?>" placeholder="newsletter">
            </label>
        </div>
        <label class="grid gap-1">
            <span class="text-sm font-medium"><?php echo $e(t('What it means, as the person reads it')); ?></span>
            <input type="text" name="description" class="<?php echo $field; ?>" value="<?php echo $e($list['description'] ?? ''); ?>">
        </label>
        <label class="flex items-center gap-2">
            <input type="checkbox" name="defaulton" value="1" class="checkbox checkbox-sm"<?php echo !empty($list['defaultOn']) ? ' checked' : ''; ?>>
            <span><?php echo $e(t('New accounts start in this list')); ?></span>
        </label>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(t('Save')); ?></button>
            <a href="<?php echo $e(adminUrl('ConsentLists')); ?>" class="btn btn-outline btn-sm"><?php echo $e(t('Cancel')); ?></a>
        </div>
    </div></form>
</div>
