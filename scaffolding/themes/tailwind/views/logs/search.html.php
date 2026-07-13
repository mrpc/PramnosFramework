<?php
/**
 * Search across log files (Tailwind theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->searchText    — string
 *   $this->caseSensitive — bool
 *   $this->contextLines  — int
 *   $this->results       — array|null  [ {file, count, matches:[ {context:[lineNum=>{match:bool,text:string}]} ]} ]
 */
?>
<div class="px-4 py-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">Search Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
        <form method="post" class="space-y-4">
            <div>
                <label for="query" class="block text-sm font-medium text-gray-700 mb-1">Search Text:</label>
                <input type="text" id="query" name="query" required value="<?php echo htmlspecialchars($this->searchText); ?>"
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="context" class="block text-sm font-medium text-gray-700 mb-1">Context Lines:</label>
                    <input type="number" id="context" name="context" value="<?php echo (int) $this->contextLines; ?>" min="0" max="10"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="case_sensitive" value="1" <?php echo $this->caseSensitive ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Case Sensitive
                    </label>
                </div>
            </div>
            <button type="submit" class="px-4 py-2 rounded-md bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium">Search</button>
        </form>

        <?php if ($this->results !== null): ?>
            <hr class="my-6 border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800 mb-3">
                Search Results for "<?php echo htmlspecialchars($this->searchText); ?>"
            </h3>

            <?php if (empty($this->results)): ?>
                <div class="rounded-md bg-sky-50 text-sky-800 px-4 py-3 text-sm">No results found.</div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($this->results as $fileResult): ?>
                        <div class="border border-gray-200 rounded-lg overflow-hidden">
                            <div class="bg-gray-50 px-4 py-2 flex items-center gap-2 text-sm font-medium text-gray-700">
                                <?php echo htmlspecialchars($fileResult['file']); ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-sky-100 text-sky-800">
                                    <?php echo (int) $fileResult['count']; ?> matches
                                </span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-gray-500 border-b border-gray-200">
                                            <th class="px-3 py-2 font-medium w-20">Line</th>
                                            <th class="px-3 py-2 font-medium">Content</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($fileResult['matches'] as $match): ?>
                                            <?php foreach ($match['context'] as $lineNum => $lineData): ?>
                                                <tr class="<?php echo $lineData['match'] ? 'bg-amber-50' : ''; ?>">
                                                    <td class="px-3 py-1.5 text-right text-gray-400 font-mono"><?php echo (int) $lineNum; ?></td>
                                                    <td class="px-3 py-1.5 font-mono text-gray-700">
                                                        <?php
                                                        if ($lineData['match']) {
                                                            echo preg_replace(
                                                                '/(' . preg_quote($this->searchText, '/') . ')/i',
                                                                '<mark class="bg-amber-200">$1</mark>',
                                                                htmlspecialchars($lineData['text'])
                                                            );
                                                        } else {
                                                            echo htmlspecialchars($lineData['text']);
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr><td colspan="2" class="bg-gray-50 py-1"></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
