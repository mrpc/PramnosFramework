<?php
/**
 * Roles list (plain CSS theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
?>
<div class="page-section">
    <?php $this->activeNav = 'roles'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2>Roles</h2>
        <a href="<?php echo adminUrl('Roles/edit'); ?>" class="btn btn-primary">+ New Role</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
