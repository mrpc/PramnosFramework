<?php
/**
 * Search across log files (Bootstrap theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->searchText    — string
 *   $this->caseSensitive — bool
 *   $this->contextLines  — int
 *   $this->results       — array|null  [ {file, count, matches:[ {context:[lineNum=>{match:bool,text:string}]} ]} ]
 */
?>
<div class="container py-4">
    <h2 class="mb-4">Search Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post">
                <div class="mb-3">
                    <label for="query" class="form-label">Search Text:</label>
                    <input type="text" id="query" name="query" required value="<?php echo htmlspecialchars($this->searchText); ?>"
                           class="form-control">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="context" class="form-label">Context Lines:</label>
                        <input type="number" id="context" name="context" value="<?php echo (int) $this->contextLines; ?>" min="0" max="10"
                               class="form-control">
                    </div>
                    <div class="col-12 col-sm-6 d-flex align-items-end">
                        <div class="form-check">
                            <input type="checkbox" id="case_sensitive" name="case_sensitive" value="1" <?php echo $this->caseSensitive ? 'checked' : ''; ?>
                                   class="form-check-input">
                            <label class="form-check-label" for="case_sensitive">Case Sensitive</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Search</button>
            </form>

            <?php if ($this->results !== null): ?>
                <hr class="my-4">
                <h3 class="h5 mb-3">
                    Search Results for "<?php echo htmlspecialchars($this->searchText); ?>"
                </h3>

                <?php if (empty($this->results)): ?>
                    <div role="status" class="alert alert-info">No results found.</div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-4">
                        <?php foreach ($this->results as $fileResult): ?>
                            <div class="card">
                                <div class="card-header d-flex align-items-center gap-2 fw-semibold">
                                    <?php echo htmlspecialchars($fileResult['file']); ?>
                                    <span class="badge rounded-pill bg-info text-dark">
                                        <?php echo (int) $fileResult['count']; ?> matches
                                    </span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-muted" style="width:5rem;">Line</th>
                                                <th class="text-muted">Content</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fileResult['matches'] as $match): ?>
                                                <?php foreach ($match['context'] as $lineNum => $lineData): ?>
                                                    <tr class="<?php echo $lineData['match'] ? 'table-warning' : ''; ?>">
                                                        <td class="text-end text-muted font-monospace"><?php echo (int) $lineNum; ?></td>
                                                        <td class="font-monospace">
                                                            <?php
                                                            if ($lineData['match']) {
                                                                echo preg_replace(
                                                                    '/(' . preg_quote($this->searchText, '/') . ')/i',
                                                                    '<mark>$1</mark>',
                                                                    htmlspecialchars($lineData['text'])
                                                                );
                                                            } else {
                                                                echo htmlspecialchars($lineData['text']);
                                                            }
                                                            ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr><td colspan="2" class="bg-light py-1"></td></tr>
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
</div>
