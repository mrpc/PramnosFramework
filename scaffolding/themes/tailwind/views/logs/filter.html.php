<?php
/**
 * Filter log entries (Tailwind theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->whitelist       — string[] available log files
 *   $this->availableLevels — array level=>Label
 *   $this->file, $this->startDate, $this->endDate, $this->query — string
 *   $this->levels          — string[] selected levels
 *   $this->limit           — int
 *   $this->results         — array[] {id, timestamp, level, message, context}
 *   $this->hasResults      — bool  whether a filter run happened
 */
$levelBadge = static function (string $level): string {
    switch (strtolower($level)) {
        case 'emergency':
        case 'alert':
        case 'critical':
        case 'error':   return 'bg-error/10 text-error';
        case 'warning': return 'bg-warning/10 text-warning';
        case 'notice':
        case 'info':    return 'bg-info/10 text-info';
        default:        return 'bg-base-200 text-base-content';
    }
};
?>
<div class="px-4 py-6">
    <h2 class="text-2xl font-bold text-base-content mb-4">Filter Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card bg-base-100 border border-base-300 shadow-sm p-5">
        <form method="post" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="file" class="block text-sm font-medium text-base-content mb-1">Select Log File:</label>
                    <select name="file" id="file"
                            class="input input-sm w-full">
                        <option value="">-- Select Log File --</option>
                        <?php foreach ($this->whitelist as $log): ?>
                            <option value="<?php echo htmlspecialchars($log); ?>" <?php echo $this->file === $log ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($log); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="limit" class="block text-sm font-medium text-base-content mb-1">Maximum Results:</label>
                    <select name="limit" id="limit"
                            class="input input-sm w-full">
                        <?php foreach ([100, 250, 500, 1000] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $this->limit === $opt ? 'selected' : ''; ?>><?php echo $opt; ?> entries</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-base-content mb-1">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($this->startDate); ?>"
                           class="input input-sm w-full">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-base-content mb-1">End Date:</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($this->endDate); ?>"
                           class="input input-sm w-full">
                </div>
            </div>

            <div>
                <span class="block text-sm font-medium text-base-content mb-1">Log Levels:</span>
                <div class="flex flex-wrap gap-3">
                    <?php foreach ($this->availableLevels as $level => $label): ?>
                        <label class="inline-flex items-center gap-2 text-sm text-base-content">
                            <input type="checkbox" name="levels[]" value="<?php echo $level; ?>"
                                   <?php echo in_array($level, $this->levels) ? 'checked' : ''; ?>
                                   class="rounded border-base-300 text-primary focus:ring-primary">
                            <?php echo htmlspecialchars($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-base-content/60 mt-1">Leave empty to include all levels</p>
            </div>

            <div>
                <label for="query" class="block text-sm font-medium text-base-content mb-1">Search Query:</label>
                <input type="text" id="query" name="query" value="<?php echo htmlspecialchars($this->query); ?>" placeholder="Search in log messages"
                       class="input input-sm w-full">
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
        </form>

        <?php if ($this->hasResults): ?>
            <hr class="my-6 border-base-300">
            <h3 class="text-lg font-semibold text-base-content mb-3">
                Filter Results <span class="text-sm font-normal text-base-content/60"><?php echo count($this->results); ?> entries found</span>
            </h3>

            <?php if (empty($this->results)): ?>
                <div class="rounded-md bg-info/10 text-info px-4 py-3 text-sm">No log entries match the specified filters.</div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="table table-sm min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-base-content/70 border-b border-base-300">
                                <th class="px-3 py-2 font-medium w-44">Timestamp</th>
                                <th class="px-3 py-2 font-medium w-24">Level</th>
                                <th class="px-3 py-2 font-medium">Message</th>
                                <th class="px-3 py-2 font-medium w-24">Context</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-300">
                            <?php foreach ($this->results as $entry): ?>
                                <tr class="hover:bg-base-200 align-top">
                                    <td class="px-3 py-2 text-base-content/80 font-mono text-xs"><?php echo htmlspecialchars($entry['timestamp'] ?? ''); ?></td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $levelBadge($entry['level'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(ucfirst($entry['level'] ?? 'info')); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-base-content"><?php echo htmlspecialchars($entry['message'] ?? ''); ?></td>
                                    <td class="px-3 py-2">
                                        <?php if (!empty($entry['context'])): ?>
                                            <details>
                                                <summary class="cursor-pointer text-primary hover:underline text-xs">View</summary>
                                                <pre class="mt-2 bg-base-200 border border-base-300 rounded p-2 text-xs overflow-x-auto"><?php echo htmlspecialchars(json_encode($entry['context'], JSON_PRETTY_PRINT)); ?></pre>
                                            </details>
                                        <?php else: ?>
                                            <span class="text-base-content/60 text-xs">None</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
