<?php
/**
 * Rotate log files (Tailwind theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->maxSize       — int   max size (MB)
 *   $this->maxBackups    — int   backups to keep
 *   $this->selectedFiles — string[] files checked in the last submit
 *   $this->results       — array  file => bool (rotated?) — may be empty
 *   $this->stats         — array[] {name, size_formatted, modified_formatted}
 */
?>
<div class="px-4 py-6">
    <h2 class="text-2xl font-bold text-base-content mb-4">Rotate Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card bg-base-100 border border-base-300 shadow-sm p-5">
        <?php if (!empty($this->results)): ?>
            <div class="rounded-md bg-info/10 text-info px-4 py-3 text-sm mb-4">
                <div class="font-semibold mb-1">Rotation Results:</div>
                <ul class="list-disc list-inside space-y-0.5">
                    <?php foreach ($this->results as $file => $rotated): ?>
                        <li>
                            <?php echo htmlspecialchars($file); ?>:
                            <?php if ($rotated): ?>
                                <span class="text-success">Rotated successfully</span>
                            <?php else: ?>
                                <span class="text-base-content/70">No rotation needed (file size below threshold)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="max_size" class="block text-sm font-medium text-base-content mb-1">Maximum Size (MB):</label>
                    <input type="number" id="max_size" name="max_size" value="<?php echo (int) $this->maxSize; ?>" min="1" max="1000"
                           class="input input-sm w-full">
                    <p class="text-xs text-base-content/60 mt-1">Log files larger than this will be rotated</p>
                </div>
                <div>
                    <label for="max_backups" class="block text-sm font-medium text-base-content mb-1">Maximum Backup Files:</label>
                    <input type="number" id="max_backups" name="max_backups" value="<?php echo (int) $this->maxBackups; ?>" min="1" max="20"
                           class="input input-sm w-full">
                    <p class="text-xs text-base-content/60 mt-1">Number of backup files to keep</p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-base-content mb-1">Select Log Files to Rotate:</label>
                <div class="overflow-x-auto border border-base-300 rounded-lg">
                    <table class="table table-sm min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-base-content/70 border-b border-base-300 bg-base-200">
                                <th class="px-3 py-2 w-12">
                                    <input type="checkbox" id="selectAll" class="rounded border-base-300 text-primary focus:ring-primary">
                                </th>
                                <th class="px-3 py-2 font-medium">File Name</th>
                                <th class="px-3 py-2 font-medium">Size</th>
                                <th class="px-3 py-2 font-medium">Last Modified</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-300">
                            <?php foreach ($this->stats as $stat): ?>
                                <tr class="hover:bg-base-200">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" name="files[]" value="<?php echo htmlspecialchars($stat['name']); ?>"
                                               <?php echo in_array($stat['name'], $this->selectedFiles) ? 'checked' : ''; ?>
                                               class="rounded border-base-300 text-primary focus:ring-primary">
                                    </td>
                                    <td class="px-3 py-2 text-base-content"><?php echo htmlspecialchars($stat['name']); ?></td>
                                    <td class="px-3 py-2 text-base-content"><?php echo htmlspecialchars($stat['size_formatted']); ?></td>
                                    <td class="px-3 py-2 text-base-content"><?php echo htmlspecialchars($stat['modified_formatted']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <input type="hidden" name="action" value="rotate">
            <button type="submit" class="btn btn-primary btn-sm">
                Rotate Selected Log Files
            </button>
        </form>

        <script>
            document.getElementById('selectAll').addEventListener('change', function () {
                document.querySelectorAll('input[name="files[]"]').forEach(function (cb) {
                    cb.checked = this.checked;
                }, this);
            });
        </script>
    </div>
</div>
