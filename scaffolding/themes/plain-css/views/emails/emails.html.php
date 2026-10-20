<?php
/**
 * Email history (plain CSS theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX, fed by Emails/data)
 *   $this->scopedTo  — the one address the list is narrowed to, when arrived from an account
 *   $this->clearUrl  — the list without that narrowing
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
$e        = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$scopedTo = (string) ($this->scopedTo ?? '');
?>
<div class="page-section">
    <?php $this->activeNav = 'emails'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div role="status" class="alert alert-success"><?php echo $e($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-danger"><?php echo $e($this->error); ?></div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2><?php echo $e(t('Email history')); ?></h2>
        <a href="<?php echo adminUrl('MailTemplates'); ?>" class="btn btn-sm"
           title="<?php echo $e(t('The templates these are rendered from')); ?>"><?php echo $e(t('Mail templates')); ?></a>
    </div>
    <?php // A filtered list looks like a short one: say it is filtered, and give the way out. ?>
    <?php if ($scopedTo !== ''): ?>
        <div role="status" class="alert alert-info">
            <?php echo $e(t('Showing only mail sent to')); ?>
            <strong><?php echo $e($scopedTo); ?></strong>.
            <?php echo $e(t('Mail to an address this account used before it was changed is not here.')); ?>
            <a href="<?php echo $e($this->clearUrl ?? adminUrl('emails')); ?>" class="btn btn-sm"><?php echo $e(t('Show all')); ?></a>
        </div>
    <?php endif; ?>
    <?php echo $this->datatable->render(); ?>
</div>
