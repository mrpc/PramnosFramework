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
    <h2 class="text-2xl font-bold text-base-content mb-4">Archive Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card bg-base-100 border border-base-300 shadow-sm p-5 max-w-xl">
        <?php if ($this->result): ?>
            <?php if ($this->result['archived'] > 0): ?>
                <div class="rounded-md bg-success/10 text-success px-4 py-3 text-sm mb-4">
                    Successfully archived <?php echo (int) $this->result['archived']; ?> log files to
                    <?php echo htmlspecialchars($this->result['archive_file']); ?>
                </div>
            <?php elseif (!empty($this->result['errors'])): ?>
                <div class="rounded-md bg-error/10 text-error px-4 py-3 text-sm mb-4">
                    <strong>Error:</strong> <?php echo htmlspecialchars(implode(', ', $this->result['errors'])); ?>
                </div>
            <?php else: ?>
                <div class="rounded-md bg-info/10 text-info px-4 py-3 text-sm mb-4">
                    No log files older than <?php echo (int) $this->days; ?> days were found.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post">
            <label for="days" class="block text-sm font-medium text-base-content mb-1">Archive logs older than (days):</label>
            <input type="number" id="days" name="days" value="<?php echo (int) $this->days; ?>" min="1" max="365"
                   class="input input-sm w-full mb-4">
            <input type="hidden" name="action" value="archive">
            <button type="submit" class="btn btn-primary btn-sm">
                Archive Log Files
            </button>
        </form>
    </div>
</div>
