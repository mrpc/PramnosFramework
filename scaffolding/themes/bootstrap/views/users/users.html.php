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
    <?php $this->activeNav = 'users'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div role="status" class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <?php /* The two links are wrapped: `space-between` on three children puts the middle
             one in the centre of the row, which reads as a broken stylesheet rather than as
             a layout — the heading left, "+ New User" floating in the middle, "User types"
             right. Grouped, it is heading | actions again. */ ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Users</h2>
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo adminUrl('Users/types'); ?>" class="btn btn-sm btn-link" title="What each user type may do">User types</a>
            <a href="<?php echo adminUrl('Users/edit'); ?>" class="btn btn-primary">+ New User</a>
        </div>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
