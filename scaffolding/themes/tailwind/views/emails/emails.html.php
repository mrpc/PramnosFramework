<?php
/**
 * Email log (Tailwind theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *
 * Was a hand-rolled table with page links: no sorting, no search, fifty rows at a time
 * over a table that grows with every mail the site sends. "Did the code reach this
 * address" — the question this screen exists for — could only be answered by paging.
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'emails'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success mb-4"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-error mb-4"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="flex justify-between items-center mb-4">
        <h2>Email history</h2>
        <a href="<?php echo adminUrl('MailTemplates'); ?>" class="btn btn-ghost btn-sm"
           title="The templates these are rendered from">Mail templates</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
