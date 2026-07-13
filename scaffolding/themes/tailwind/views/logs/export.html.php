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
    <h2 class="text-2xl font-bold text-gray-800 mb-4">Export Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 max-w-xl">
        <?php if (!empty($this->result['error'])): ?>
            <div class="rounded-md bg-red-50 text-red-800 px-4 py-3 text-sm mb-4">
                <strong>Error:</strong> <?php echo htmlspecialchars($this->result['error']); ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <div>
                <label for="file" class="block text-sm font-medium text-gray-700 mb-1">Select Log File:</label>
                <select name="file" id="file"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($this->whitelist as $log): ?>
                        <option value="<?php echo htmlspecialchars($log); ?>"><?php echo htmlspecialchars($log); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <span class="block text-sm font-medium text-gray-700 mb-1">Export Format:</span>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 mr-4">
                    <input type="radio" name="format" value="csv" checked class="text-blue-600 focus:ring-blue-500"> CSV (Excel compatible)
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="format" value="json" class="text-blue-600 focus:ring-blue-500"> JSON
                </label>
            </div>
            <button type="submit" class="px-4 py-2 rounded-md bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium">Export</button>
        </form>

        <hr class="my-6 border-gray-200">

        <h3 class="text-lg font-semibold text-gray-800 mb-3">Export Multiple Log Files</h3>
        <form method="post" class="space-y-4">
            <div class="space-y-1">
                <?php foreach ($this->whitelist as $log): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="multiple_files[]" value="<?php echo htmlspecialchars($log); ?>"
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <?php echo htmlspecialchars($log); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="format" value="zip">
            <button type="submit" class="px-4 py-2 rounded-md bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium">
                Export as ZIP Archive
            </button>
        </form>
    </div>
</div>
