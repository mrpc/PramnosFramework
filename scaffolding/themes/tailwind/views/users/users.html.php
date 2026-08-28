<?php
/**
 * Users list (Tailwind theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'users'; $this->insert('../partials/admin_breadcrumb'); ?>
    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success mb-4"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-error mb-4"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <?php /* The two links are wrapped: `space-between` on three children puts the middle
             one in the centre of the row, which reads as a broken stylesheet rather than as
             a layout — the heading left, "+ New User" floating in the middle, "User types"
             right. Grouped, it is heading | actions again. */ ?>
    <div class="flex justify-between items-center mb-4">
        <h2>Users</h2>
        <div class="flex items-center gap-2">
            <a href="<?php echo adminUrl('Users/types'); ?>" class="btn btn-ghost btn-sm" title="What each user type may do">User types</a>
            <a href="<?php echo adminUrl('Users/edit'); ?>" class="btn btn-primary btn-sm">+ New User</a>
        </div>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
