<?php
/**
 * Users list (Bootstrap theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
?>
<div class="container-fluid py-4">
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Users</h2>
        <a href="<?php echo sURL; ?>Users/edit" class="btn btn-primary">+ New User</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
