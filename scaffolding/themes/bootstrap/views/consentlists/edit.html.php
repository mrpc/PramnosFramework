<?php
/**
 * A consent list's form: its name, channel, where it reads from, and what it means to the person.
 * The same markup in every theme.
 *
 * Variables:
 *   $this->list     — ConsentLists::get(), or null when creating
 *   $this->sources  — the declared sources' labels, by key
 *   $this->channels — the channels' words, by key
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$list  = $this->list ?? null;
$isNew = $list === null;
?>
<div class="pf-ui">
    <?php $this->activeNav = 'consentlists_edit'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e($isNew ? t('New consent list') : t('Edit consent list')); ?></h1>
        </div>
    </header>
    <form method="post" action="<?php echo $e(adminUrl('ConsentLists/save')); ?>">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <?php if (!$isNew): ?>
            <input type="hidden" name="listid" value="<?php echo (int) $list['id']; ?>">
        <?php endif; ?>
        <div class="pf-card"><div class="pf-card-body pf-stack">
            <div class="pf-grid-2">
                <label class="pf-field">
                    <span class="pf-label"><?php echo $e(t('Name')); ?></span>
                    <input type="text" name="name" maxlength="150" required class="pf-input" value="<?php echo $e($list['name'] ?? ''); ?>">
                </label>
                <label class="pf-field">
                    <span class="pf-label"><?php echo $e(t('Channel')); ?></span>
                    <select name="channel" class="pf-select">
                        <?php foreach ($this->channels as $key => $word): ?>
                            <option value="<?php echo $e($key); ?>"<?php echo ($list['channel'] ?? 'email') === $key ? ' selected' : ''; ?>><?php echo $e($word); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="pf-field">
                    <span class="pf-label"><?php echo $e(t('Read from')); ?></span>
                    <select name="source" class="pf-select">
                        <?php foreach ($this->sources as $key => $label): ?>
                            <option value="<?php echo $e($key); ?>"<?php echo ($list['source'] ?? 'everyone') === $key ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="pf-hint"><?php echo $e(t('Where it finds who agreed — the application declares the choices.')); ?></span>
                </label>
                <label class="pf-field">
                    <span class="pf-label"><?php echo $e(t('Email opt-out list (optional)')); ?></span>
                    <input type="text" name="optoutlist" maxlength="64" class="pf-input" value="<?php echo $e($list['optOutList'] ?? ''); ?>" placeholder="newsletter">
                    <span class="pf-hint"><?php echo $e(t('People who unsubscribed from this list are left out too.')); ?></span>
                </label>
            </div>
            <label class="pf-field">
                <span class="pf-label"><?php echo $e(t('What it means, as the person reads it')); ?></span>
                <input type="text" name="description" class="pf-input" value="<?php echo $e($list['description'] ?? ''); ?>">
            </label>
            <label class="pf-check">
                <input type="checkbox" name="defaulton" value="1"<?php echo !empty($list['defaultOn']) ? ' checked' : ''; ?>>
                <span><?php echo $e(t('New accounts start in this list')); ?></span>
            </label>
        </div></div>
        <div class="pf-form-footer">
            <a href="<?php echo $e(adminUrl('ConsentLists')); ?>" class="pf-btn pf-btn-ghost"><?php echo $e(t('Cancel')); ?></a>
            <button type="submit" class="pf-btn pf-btn-primary"><?php echo $e(t('Save')); ?></button>
        </div>
    </form>
</div>
