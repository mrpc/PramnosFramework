<?php
/**
 * Export log files (plain-CSS theme) — form only. Actual downloads are streamed
 * by the controller before any view is rendered.
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->whitelist — string[] available log files
 *   $this->result    — array|null {error?: string}
 */
?>
<div class="page-section">
    <h2 style="margin:0 0 16px">Export Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card" style="max-width:576px">
        <div class="card-body">
        <?php if (!empty($this->result['error'])): ?>
            <div class="badge badge-danger" style="display:block;padding:12px 16px;margin-bottom:16px">
                <strong>Error:</strong> <?php echo htmlspecialchars($this->result['error']); ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div style="margin-bottom:16px">
                <label for="file" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Select Log File:</label>
                <select name="file" id="file"
                        style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
                    <?php foreach ($this->whitelist as $log): ?>
                        <option value="<?php echo htmlspecialchars($log); ?>"><?php echo htmlspecialchars($log); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:16px">
                <span style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Export Format:</span>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:14px;color:#555;margin-right:16px">
                    <input type="radio" name="format" value="csv" checked> CSV (Excel compatible)
                </label>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:14px;color:#555">
                    <input type="radio" name="format" value="json"> JSON
                </label>
            </div>
            <button type="submit" class="btn" style="background:#2563eb;color:#fff">Export</button>
        </form>

        <hr style="margin:24px 0;border:none;border-top:1px solid #eee">

        <h3 style="font-size:18px;font-weight:600;color:#333;margin:0 0 12px">Export Multiple Log Files</h3>
        <form method="post">
            <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:16px">
                <?php foreach ($this->whitelist as $log): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;color:#555">
                        <input type="checkbox" name="multiple_files[]" value="<?php echo htmlspecialchars($log); ?>">
                        <?php echo htmlspecialchars($log); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="format" value="zip">
            <button type="submit" class="btn" style="background:#2563eb;color:#fff">
                Export as ZIP Archive
            </button>
        </form>
        </div>
    </div>
</div>
