<?php
/**
 * Email log (Tailwind theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'emails'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div role="status" class="alert alert-success mb-4"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-error mb-4"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="flex justify-between items-center mb-4">
        <h2><?php echo htmlspecialchars(t('Email history'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <a href="<?php echo adminUrl('MailTemplates'); ?>" class="btn btn-ghost btn-sm"
           title="<?php echo htmlspecialchars(t('The templates these are rendered from'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('Mail templates'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
    <?php
    /*
     * Scoped to one address, when the operator arrived from an account's own page.
     *
     * Said out loud, with a way out. A filtered list looks exactly like a short one, and a
     * short mail log reads as "nothing was ever sent" — which is the wrong conclusion to
     * hand somebody who came here to find out whether a code was delivered.
     */
    $scopedTo = (string) ($this->scopedTo ?? '');
    ?>
    <?php if ($scopedTo !== ''): ?>
        <div role="status" class="alert alert-info mb-4 flex flex-wrap items-center gap-3">
            <span>
                <?php echo htmlspecialchars(t('Showing only mail sent to'), ENT_QUOTES, 'UTF-8'); ?>
                <strong class="font-mono"><?php echo htmlspecialchars($scopedTo, ENT_QUOTES, 'UTF-8'); ?></strong>.
                <?php echo htmlspecialchars(t('Mail to an address this account used before it was changed is not here.'), ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <a href="<?php echo htmlspecialchars((string) ($this->clearUrl ?? adminUrl('emails')), ENT_QUOTES, 'UTF-8'); ?>"
               class="btn btn-ghost btn-xs ms-auto"><?php echo htmlspecialchars(t('Show all'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    <?php endif; ?>
    <?php echo $this->datatable->render(); ?>
</div>
