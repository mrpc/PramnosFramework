<?php
/**
 * OAuth2 Applications list (Tailwind theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
?>
<div class="px-4 py-6">
    <?php if (!empty($this->success)): ?>
        <div role="status" class="alert alert-success mb-4"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-error mb-4"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="flex justify-between items-center mb-4">
        <h2>OAuth2 Applications</h2>
        <a href="<?php echo adminUrl('Applications/edit'); ?>" class="btn btn-primary btn-sm">+ New Application</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
