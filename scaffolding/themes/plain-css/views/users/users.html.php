<?php
/**
 * Users list (plain-CSS theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable instance (server-side AJAX)
 *   $this->success   — optional success flash message
 *   $this->error     — optional error flash message
 */
?>
<div class="page-section">
    <?php if (!empty($this->success)): ?>
        <div style="padding:10px 14px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;margin-bottom:12px;color:#155724"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div style="padding:10px 14px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;margin-bottom:12px;color:#721c24"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2>Users</h2>
        <a href="<?php echo sURL; ?>Users/edit" class="btn btn-primary">+ New User</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
