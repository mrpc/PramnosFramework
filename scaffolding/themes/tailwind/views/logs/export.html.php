<?php
/**
 * Export log files (Tailwind theme) — form only. Actual downloads are streamed
 * by the controller before any view is rendered.
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->whitelist — string[] available log files
 *   $this->result    — array|null {error?: string}
 */
?>
<div class="px-4 py-6">
    <h2 class="text-2xl font-bold text-base-content mb-4">Export Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card bg-base-100 border border-base-300 shadow-sm p-5 max-w-xl">
        <?php if (!empty($this->result['error'])): ?>
            <div class="rounded-md bg-error/10 text-error px-4 py-3 text-sm mb-4">
                <strong>Error:</strong> <?php echo htmlspecialchars($this->result['error']); ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <div>
                <label for="file" class="block text-sm font-medium text-base-content mb-1">Select Log File:</label>
                <select name="file" id="file"
                        class="input input-sm w-full">
                    <?php foreach ($this->whitelist as $log): ?>
                        <option value="<?php echo htmlspecialchars($log); ?>"><?php echo htmlspecialchars($log); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <span class="block text-sm font-medium text-base-content mb-1">Export Format:</span>
                <label class="inline-flex items-center gap-2 text-sm text-base-content mr-4">
                    <input type="radio" name="format" value="csv" checked class="text-primary focus:ring-primary"> CSV (Excel compatible)
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-base-content">
                    <input type="radio" name="format" value="json" class="text-primary focus:ring-primary"> JSON
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Export</button>
        </form>

        <hr class="my-6 border-base-300">

        <h3 class="text-lg font-semibold text-base-content mb-3">Export Multiple Log Files</h3>
        <form method="post" class="space-y-4">
            <div class="space-y-1">
                <?php foreach ($this->whitelist as $log): ?>
                    <label class="flex items-center gap-2 text-sm text-base-content">
                        <input type="checkbox" name="multiple_files[]" value="<?php echo htmlspecialchars($log); ?>"
                               class="rounded border-base-300 text-primary focus:ring-primary">
                        <?php echo htmlspecialchars($log); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="format" value="zip">
            <button type="submit" class="btn btn-primary btn-sm">
                Export as ZIP Archive
            </button>
        </form>
    </div>
</div>
