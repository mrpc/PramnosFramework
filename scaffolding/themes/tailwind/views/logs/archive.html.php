<?php
/**
 * Archive log files (Tailwind theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->days   — int    current "older than N days" value
 *   $this->result — array|null {archived:int, archive_file:string, errors:string[]}
 */
?>
<div class="px-4 py-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">Archive Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 max-w-xl">
        <?php if ($this->result): ?>
            <?php if ($this->result['archived'] > 0): ?>
                <div class="rounded-md bg-green-50 text-green-800 px-4 py-3 text-sm mb-4">
                    Successfully archived <?php echo (int) $this->result['archived']; ?> log files to
                    <?php echo htmlspecialchars($this->result['archive_file']); ?>
                </div>
            <?php elseif (!empty($this->result['errors'])): ?>
                <div class="rounded-md bg-red-50 text-red-800 px-4 py-3 text-sm mb-4">
                    <strong>Error:</strong> <?php echo htmlspecialchars(implode(', ', $this->result['errors'])); ?>
                </div>
            <?php else: ?>
                <div class="rounded-md bg-sky-50 text-sky-800 px-4 py-3 text-sm mb-4">
                    No log files older than <?php echo (int) $this->days; ?> days were found.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post">
            <label for="days" class="block text-sm font-medium text-gray-700 mb-1">Archive logs older than (days):</label>
            <input type="number" id="days" name="days" value="<?php echo (int) $this->days; ?>" min="1" max="365"
                   class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 mb-4">
            <input type="hidden" name="action" value="archive">
            <button type="submit" class="px-4 py-2 rounded-md bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium">
                Archive Log Files
            </button>
        </form>
    </div>
</div>
