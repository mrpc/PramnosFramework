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
    <?php if (!empty($this->success)): ?>
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 rounded text-green-800 text-sm"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded text-red-800 text-sm"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="flex justify-between items-center mb-4">
        <h2>Organizations</h2>
        <a href="<?php echo sURL; ?>Organizations/edit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">+ New Organization</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
