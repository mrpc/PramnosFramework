<?php
/**
 * Setting create/edit form (Bootstrap theme).
 *
 * Variables:
 *   $this->key    — setting key (string, empty when new)
 *   $this->value  — current value
 *   $this->isNew  — bool
 *   $this->error  — string error message
 */
?>
<div class="container py-4" style="max-width:640px">
    <h2 class="mb-4"><?php echo $this->isNew ? 'New Setting' : 'Edit Setting'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <form method="post" action="<?php echo sURL; ?>Settings/save">
                <div class="mb-3">
                    <label class="form-label">Key</label>
                    <input type="text" name="skey" class="form-control" required
                        value="<?php echo htmlspecialchars($this->key ?? ''); ?>"
                        <?php echo !$this->isNew ? 'readonly' : ''; ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label">Value</label>
                    <textarea name="svalue" class="form-control" rows="4"><?php echo htmlspecialchars($this->value ?? ''); ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Settings" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
