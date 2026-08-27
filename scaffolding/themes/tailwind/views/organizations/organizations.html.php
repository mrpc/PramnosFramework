<?php
/**
 * Organizations list (Tailwind theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'organizations'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success mb-4"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-error mb-4"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="flex justify-between items-center mb-4">
        <h2>Organizations</h2>
        <a href="<?php echo adminUrl('Organizations/edit'); ?>" class="btn btn-primary btn-sm">+ New Organization</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
