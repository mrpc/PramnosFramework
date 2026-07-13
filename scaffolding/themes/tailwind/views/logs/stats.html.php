<?php
/**
 * Log statistics (Tailwind theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->stats          — array[] {name, size_formatted, lines, json_percentage,
 *                                    level_distribution[level=>count], modified_formatted, size}
 *   $this->totalSizeHuman — string  formatted total size
 *   $this->totalLines     — int
 *   $this->totalFiles     — int
 *   $this->jsonPercent     — float   average JSON percentage
 */
$sURL = defined('sURL') ? sURL : '';
?>
<div class="px-4 py-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">Log Statistics</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
        <?php if (empty($this->stats)): ?>
            <div class="rounded-md bg-sky-50 text-sky-800 px-4 py-3 text-sm">No log files found.</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200">
                            <th class="px-3 py-2 font-medium">File Name</th>
                            <th class="px-3 py-2 font-medium">Size</th>
                            <th class="px-3 py-2 font-medium">Lines</th>
                            <th class="px-3 py-2 font-medium">Structured JSON</th>
                            <th class="px-3 py-2 font-medium">Last Modified</th>
                            <th class="px-3 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($this->stats as $stat): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2">
                                    <a class="text-blue-600 hover:underline" href="<?php echo $sURL; ?>Logs/viewer/<?php echo htmlspecialchars($stat['name']); ?>">
                                        <?php echo htmlspecialchars($stat['name']); ?>
                                    </a>
                                </td>
                                <td class="px-3 py-2 text-gray-700"><?php echo htmlspecialchars($stat['size_formatted']); ?></td>
                                <td class="px-3 py-2 text-gray-700"><?php echo number_format($stat['lines']); ?></td>
                                <td class="px-3 py-2 text-gray-700">
                                    <?php echo $stat['json_percentage']; ?>%
                                    <?php if (!empty($stat['level_distribution'])): ?>
                                        <div class="text-xs text-gray-400">
                                            <?php
                                            $levels = [];
                                            foreach ($stat['level_distribution'] as $level => $count) {
                                                $levels[] = ucfirst($level) . ': ' . $count;
                                            }
                                            echo htmlspecialchars(implode(', ', $levels));
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-gray-700"><?php echo htmlspecialchars($stat['modified_formatted']); ?></td>
                                <td class="px-3 py-2">
                                    <div class="flex gap-1">
                                        <a href="<?php echo $sURL; ?>Logs/viewer/<?php echo htmlspecialchars($stat['name']); ?>"
                                           class="px-2 py-1 rounded bg-sky-600 hover:bg-sky-700 text-white text-xs" title="View">View</a>
                                        <a href="<?php echo $sURL; ?>Logs/clearFile/<?php echo htmlspecialchars($stat['name']); ?>"
                                           class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" title="Clear"
                                           data-confirm="Are you sure you want to clear this log?">Clear</a>
                                        <a href="<?php echo $sURL; ?>Logs/raw?file=<?php echo htmlspecialchars($stat['name']); ?>"
                                           class="px-2 py-1 rounded bg-gray-600 hover:bg-gray-700 text-white text-xs" title="Raw View" target="_blank">Raw</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                <div class="rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500">Total Size</div>
                    <div class="text-xl font-bold text-gray-800 mt-1"><?php echo htmlspecialchars($this->totalSizeHuman); ?></div>
                </div>
                <div class="rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500">Total Lines</div>
                    <div class="text-xl font-bold text-gray-800 mt-1"><?php echo number_format($this->totalLines); ?></div>
                </div>
                <div class="rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500">JSON Entries</div>
                    <div class="text-xl font-bold text-gray-800 mt-1"><?php echo $this->jsonPercent; ?>%</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500">Log Files</div>
                    <div class="text-xl font-bold text-gray-800 mt-1"><?php echo (int) $this->totalFiles; ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
