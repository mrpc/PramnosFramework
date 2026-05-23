<?php
/**
 * Setting create/edit form (plain-CSS theme).
 *
 * Variables:
 *   $this->key    — setting key (string, empty when new)
 *   $this->value  — current value
 *   $this->isNew  — bool
 *   $this->error  — string error message
 */
?>
<div class="page-section"max-width:640px">
    <h2 style="margin-bottom:16px"><?php echo $this->isNew ? 'New Setting' : 'Edit Setting'; ?></h2>
    <?php if (!empty($this->error)): ?>
        <div class="alert" style="background:#fde8e8;border:1px solid #f5c6cb;padding:12px 16px;border-radius:4px;margin-bottom:12px"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <form method="post" action="<?php echo sURL; ?>Settings/save">
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Key</label>
                    <input type="text" name="skey" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" required
                        value="<?php echo htmlspecialchars($this->key ?? ''); ?>"
                        <?php echo !$this->isNew ? 'readonly' : ''; ?>>
                </div>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-weight:600;margin-bottom:4px">Value</label>
                    <textarea name="svalue" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" rows="4"><?php echo htmlspecialchars($this->value ?? ''); ?></textarea>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?php echo sURL; ?>Settings" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
