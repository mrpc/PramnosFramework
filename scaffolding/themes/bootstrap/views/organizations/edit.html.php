<?php
/**
 * Organization create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->organization — row array (null when creating)
 */
$org = $this->organization ?? [];
$isNew = empty($org['organization_id']);
?>
<div class="container py-4" style="max-width:640px">
    <h2 class="mb-4"><?php echo $isNew ? 'New Organization' : 'Edit Organization'; ?></h2>
    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo sURL; ?>Organizations/save">
                <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
                <?php if (!$isNew): ?>
                    <input type="hidden" name="organization_id" value="<?php echo (int)$org['organization_id']; ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($org['name'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($org['description'] ?? ''); ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Organizations" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
