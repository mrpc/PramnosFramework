<?php
/**
 * Export log files (Bootstrap theme) — form only. Actual downloads are streamed
 * by the controller before any view is rendered.
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->whitelist — string[] available log files
 *   $this->result    — array|null {error?: string}
 */
?>
<div class="container py-4">
    <h2 class="mb-4">Export Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card shadow-sm" style="max-width:36rem;">
        <div class="card-body">
            <?php if (!empty($this->result['error'])): ?>
                <div role="alert" class="alert alert-danger">
                    <strong>Error:</strong> <?php echo htmlspecialchars($this->result['error']); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <label for="file" class="form-label">Select Log File:</label>
                    <select name="file" id="file" class="form-select">
                        <?php foreach ($this->whitelist as $log): ?>
                            <option value="<?php echo htmlspecialchars($log); ?>"><?php echo htmlspecialchars($log); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <span class="form-label d-block">Export Format:</span>
                    <div class="form-check form-check-inline">
                        <input type="radio" id="format_csv" name="format" value="csv" checked class="form-check-input">
                        <label class="form-check-label" for="format_csv">CSV (Excel compatible)</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input type="radio" id="format_json" name="format" value="json" class="form-check-input">
                        <label class="form-check-label" for="format_json">JSON</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Export</button>
            </form>

            <hr class="my-4">

            <h3 class="h5 mb-3">Export Multiple Log Files</h3>
            <form method="post">
                <div class="mb-3">
                    <?php foreach ($this->whitelist as $log): ?>
                        <div class="form-check">
                            <input type="checkbox" id="multi_<?php echo htmlspecialchars($log); ?>" name="multiple_files[]" value="<?php echo htmlspecialchars($log); ?>"
                                   class="form-check-input">
                            <label class="form-check-label" for="multi_<?php echo htmlspecialchars($log); ?>"><?php echo htmlspecialchars($log); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="format" value="zip">
                <button type="submit" class="btn btn-primary">
                    Export as ZIP Archive
                </button>
            </form>
        </div>
    </div>
</div>
