<?php
/**
 * User groups list (Bootstrap theme) — the usergroups feature.
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable, server-side, its rows from `Groups/data`
 */
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'groups'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 class="mb-0">Groups</h2>
        <a href="<?php echo adminUrl('Groups/edit'); ?>" class="btn btn-primary">+ New group</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
