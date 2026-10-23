<?php
/**
 * User groups list (Tailwind theme) — the usergroups feature.
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable, server-side, its rows from `Groups/data`
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'groups'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex justify-between items-center gap-2 mb-4">
        <h2>Groups</h2>
        <a href="<?php echo adminUrl('Groups/edit'); ?>" class="btn btn-primary btn-sm">+ New group</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
