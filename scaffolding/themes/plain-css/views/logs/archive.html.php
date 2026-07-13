<?php
/**
 * Archive log files (plain-CSS theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->days   — int    current "older than N days" value
 *   $this->result — array|null {archived:int, archive_file:string, errors:string[]}
 */
?>
<div class="page-section">
    <h2 style="margin:0 0 16px">Archive Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card" style="max-width:576px">
        <div class="card-body">
        <?php if ($this->result): ?>
            <?php if ($this->result['archived'] > 0): ?>
                <div class="badge badge-success" style="display:block;padding:12px 16px;margin-bottom:16px">
                    Successfully archived <?php echo (int) $this->result['archived']; ?> log files to
                    <?php echo htmlspecialchars($this->result['archive_file']); ?>
                </div>
            <?php elseif (!empty($this->result['errors'])): ?>
                <div class="badge badge-danger" style="display:block;padding:12px 16px;margin-bottom:16px">
                    <strong>Error:</strong> <?php echo htmlspecialchars(implode(', ', $this->result['errors'])); ?>
                </div>
            <?php else: ?>
                <div class="badge badge-info" style="display:block;padding:12px 16px;margin-bottom:16px">
                    No log files older than <?php echo (int) $this->days; ?> days were found.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post">
            <label for="days" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Archive logs older than (days):</label>
            <input type="number" id="days" name="days" value="<?php echo (int) $this->days; ?>" min="1" max="365"
                   style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box;margin-bottom:16px">
            <input type="hidden" name="action" value="archive">
            <button type="submit" class="btn" style="background:#2563eb;color:#fff">
                Archive Log Files
            </button>
        </form>
        </div>
    </div>
</div>
