<?php
/**
 * Organizations list (plain-CSS theme).
 *
 * Variables:
 *   $this->organizations — iterable rows
 *   $this->page          — current page
 *   $this->total         — total count
 */
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 >Organizations</h2>
        <a href="<?php echo sURL; ?>Organizations/edit" class="btn btn-primary">+ New Organization</a>
    </div>
    <?php
    $_doc = \Pramnos\Framework\Factory::getDocument();
    $_hasDt = $_doc->isScriptRegistered('datatables');
    if ($_hasDt) { $_doc->enqueueScript('datatables'); if ($_doc->isStyleRegistered('datatables')) { $_doc->enqueueStyle('datatables'); } }
    ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table id="dt-organizations" style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>ID</th><th>Name</th><th>Description</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->organizations ?? []) as $org): ?>
                    <tr>
                        <td><?php echo (int)($org['organization_id'] ?? 0); ?></td>
                        <td><strong><?php echo htmlspecialchars($org['name'] ?? ''); ?></strong></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars(substr($org['description'] ?? '', 0, 80)); ?></td>
                        <td><?php echo ($org['is_active'] ?? 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Organizations/members/<?php echo (int)($org['organization_id'] ?? 0); ?>" class="btn btn-sm btn-outline-info">Members</a>
                            <a href="<?php echo sURL; ?>Organizations/edit/<?php echo (int)($org['organization_id'] ?? 0); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo sURL; ?>Organizations/delete/<?php echo (int)($org['organization_id'] ?? 0); ?>" class="btn btn-sm btn-outline-danger" data-confirm="Deactivate this organization?">Deactivate</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->organizations)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#888;padding:24px">No organizations found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php if ($_hasDt ?? false): $_doc->addInlineScript("$(document).ready(function(){ $('#dt-organizations').DataTable({pageLength:25,order:[]}); });"); endif; ?>
