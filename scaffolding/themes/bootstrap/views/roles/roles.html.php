<?php
/**
 * Roles list (Bootstrap theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
?>
<div class="container py-4">
    <?php $this->activeNav = 'roles'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Roles</h2>
        <a href="<?php echo adminUrl('Roles/edit'); ?>" class="btn btn-primary">+ New Role</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
