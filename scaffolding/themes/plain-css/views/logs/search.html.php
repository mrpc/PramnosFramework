<?php
/**
 * Search across log files (plain-CSS theme).
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->searchText    — string
 *   $this->caseSensitive — bool
 *   $this->contextLines  — int
 *   $this->results       — array|null  [ {file, count, matches:[ {context:[lineNum=>{match:bool,text:string}]} ]} ]
 */
?>
<div class="page-section">
    <h2 style="margin:0 0 16px">Search Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card">
        <div class="card-body">
        <form method="post">
            <div style="margin-bottom:16px">
                <label for="query" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Search Text:</label>
                <input type="text" id="query" name="query" required value="<?php echo htmlspecialchars($this->searchText); ?>"
                       style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:16px">
                <div>
                    <label for="context" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Context Lines:</label>
                    <input type="number" id="context" name="context" value="<?php echo (int) $this->contextLines; ?>" min="0" max="10"
                           style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
                </div>
                <div style="display:flex;align-items:flex-end">
                    <label style="display:inline-flex;align-items:center;gap:8px;font-size:14px;color:#555">
                        <input type="checkbox" name="case_sensitive" value="1" <?php echo $this->caseSensitive ? 'checked' : ''; ?>>
                        Case Sensitive
                    </label>
                </div>
            </div>
            <button type="submit" class="btn" style="background:#2563eb;color:#fff">Search</button>
        </form>

        <?php if ($this->results !== null): ?>
            <hr style="margin:24px 0;border:none;border-top:1px solid #eee">
            <h3 style="font-size:18px;font-weight:600;color:#333;margin:0 0 12px">
                Search Results for "<?php echo htmlspecialchars($this->searchText); ?>"
            </h3>

            <?php if (empty($this->results)): ?>
                <div class="badge badge-info" style="display:block;padding:12px 16px">No results found.</div>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:16px">
                    <?php foreach ($this->results as $fileResult): ?>
                        <div style="border:1px solid #eee;border-radius:8px;overflow:hidden">
                            <div style="background:#f7f7f7;padding:8px 16px;display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;color:#555">
                                <?php echo htmlspecialchars($fileResult['file']); ?>
                                <span class="badge badge-info">
                                    <?php echo (int) $fileResult['count']; ?> matches
                                </span>
                            </div>
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse;font-size:14px">
                                    <thead>
                                        <tr style="text-align:left;color:#888;border-bottom:1px solid #eee">
                                            <th style="padding:8px 12px;width:80px">Line</th>
                                            <th style="padding:8px 12px">Content</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($fileResult['matches'] as $match): ?>
                                            <?php foreach ($match['context'] as $lineNum => $lineData): ?>
                                                <tr style="<?php echo $lineData['match'] ? 'background:#fef3c7' : ''; ?>">
                                                    <td style="padding:6px 12px;text-align:right;color:#aaa;font-family:monospace"><?php echo (int) $lineNum; ?></td>
                                                    <td style="padding:6px 12px;font-family:monospace;color:#555">
                                                        <?php
                                                        if ($lineData['match']) {
                                                            echo preg_replace(
                                                                '/(' . preg_quote($this->searchText, '/') . ')/i',
                                                                '<mark style="background:#fde68a">$1</mark>',
                                                                htmlspecialchars($lineData['text'])
                                                            );
                                                        } else {
                                                            echo htmlspecialchars($lineData['text']);
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr><td colspan="2" style="background:#f7f7f7;padding:4px 0"></td></tr>
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
