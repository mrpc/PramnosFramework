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
    <h2 class="text-2xl font-bold text-base-content mb-4">Search Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card bg-base-100 border border-base-300 shadow-sm p-5">
        <form method="post" class="space-y-4">
            <div>
                <label for="query" class="block text-sm font-medium text-base-content mb-1">Search Text:</label>
                <input type="text" id="query" name="query" required value="<?php echo htmlspecialchars($this->searchText); ?>"
                       class="input input-sm w-full">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="context" class="block text-sm font-medium text-base-content mb-1">Context Lines:</label>
                    <input type="number" id="context" name="context" value="<?php echo (int) $this->contextLines; ?>" min="0" max="10"
                           class="input input-sm w-full">
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-sm text-base-content">
                        <input type="checkbox" name="case_sensitive" value="1" <?php echo $this->caseSensitive ? 'checked' : ''; ?>
                               class="rounded border-base-300 text-primary focus:ring-primary">
                        Case Sensitive
                    </label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
        </form>

        <?php if ($this->results !== null): ?>
            <hr class="my-6 border-base-300">
            <h3 class="text-lg font-semibold text-base-content mb-3">
                Search Results for "<?php echo htmlspecialchars($this->searchText); ?>"
            </h3>

            <?php if (empty($this->results)): ?>
                <div class="rounded-md bg-info/10 text-info px-4 py-3 text-sm">No results found.</div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($this->results as $fileResult): ?>
                        <div class="border border-base-300 rounded-lg overflow-hidden">
                            <div class="bg-base-200 px-4 py-2 flex items-center gap-2 text-sm font-medium text-base-content">
                                <?php echo htmlspecialchars($fileResult['file']); ?>
                                <span class="badge badge-info badge-sm inline-flex items-center">
                                    <?php echo (int) $fileResult['count']; ?> matches
                                </span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="table table-sm min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-base-content/70 border-b border-base-300">
                                            <th class="px-3 py-2 font-medium w-20">Line</th>
                                            <th class="px-3 py-2 font-medium">Content</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-base-300">
                                        <?php foreach ($fileResult['matches'] as $match): ?>
                                            <?php foreach ($match['context'] as $lineNum => $lineData): ?>
                                                <tr class="<?php echo $lineData['match'] ? 'bg-warning/10' : ''; ?>">
                                                    <td class="px-3 py-1.5 text-right text-base-content/60 font-mono"><?php echo (int) $lineNum; ?></td>
                                                    <td class="px-3 py-1.5 font-mono text-base-content">
                                                        <?php
                                                        if ($lineData['match']) {
                                                            echo preg_replace(
                                                                '/(' . preg_quote($this->searchText, '/') . ')/i',
                                                                '<mark class="bg-warning/20">$1</mark>',
                                                                htmlspecialchars($lineData['text'])
                                                            );
                                                        } else {
                                                            echo htmlspecialchars($lineData['text']);
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr><td colspan="2" class="bg-base-200 py-1"></td></tr>
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
