<?php
/**
 * Rotate log files (plain-CSS theme).
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
<div class="page-section">
    <h2 style="margin:0 0 16px">Rotate Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card">
        <div class="card-body">
        <?php if (!empty($this->results)): ?>
            <div class="badge badge-info" style="display:block;padding:12px 16px;margin-bottom:16px">
                <div style="font-weight:600;margin-bottom:4px">Rotation Results:</div>
                <ul style="list-style:disc;padding-left:20px;margin:0">
                    <?php foreach ($this->results as $file => $rotated): ?>
                        <li>
                            <?php echo htmlspecialchars($file); ?>:
                            <?php if ($rotated): ?>
                                <span style="color:#15803d">Rotated successfully</span>
                            <?php else: ?>
                                <span style="color:#888">No rotation needed (file size below threshold)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:16px">
                <div>
                    <label for="max_size" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Maximum Size (MB):</label>
                    <input type="number" id="max_size" name="max_size" value="<?php echo (int) $this->maxSize; ?>" min="1" max="1000"
                           style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
                    <p style="font-size:12px;color:#aaa;margin:4px 0 0">Log files larger than this will be rotated</p>
                </div>
                <div>
                    <label for="max_backups" style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Maximum Backup Files:</label>
                    <input type="number" id="max_backups" name="max_backups" value="<?php echo (int) $this->maxBackups; ?>" min="1" max="20"
                           style="width:100%;border:1px solid #ccc;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box">
                    <p style="font-size:12px;color:#aaa;margin:4px 0 0">Number of backup files to keep</p>
                </div>
            </div>

            <div style="margin-bottom:16px">
                <label style="display:block;font-size:14px;font-weight:600;color:#555;margin-bottom:4px">Select Log Files to Rotate:</label>
                <div style="overflow-x:auto;border:1px solid #eee;border-radius:8px">
                    <table style="width:100%;border-collapse:collapse;font-size:14px">
                        <thead>
                            <tr style="text-align:left;color:#888;border-bottom:1px solid #eee;background:#f7f7f7">
                                <th style="padding:8px 12px;width:48px">
                                    <input type="checkbox" id="selectAll">
                                </th>
                                <th style="padding:8px 12px">File Name</th>
                                <th style="padding:8px 12px">Size</th>
                                <th style="padding:8px 12px">Last Modified</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->stats as $stat): ?>
                                <tr style="border-bottom:1px solid #f0f0f0">
                                    <td style="padding:8px 12px">
                                        <input type="checkbox" name="files[]" value="<?php echo htmlspecialchars($stat['name']); ?>"
                                               <?php echo in_array($stat['name'], $this->selectedFiles) ? 'checked' : ''; ?>>
                                    </td>
                                    <td style="padding:8px 12px;color:#555"><?php echo htmlspecialchars($stat['name']); ?></td>
                                    <td style="padding:8px 12px;color:#555"><?php echo htmlspecialchars($stat['size_formatted']); ?></td>
                                    <td style="padding:8px 12px;color:#555"><?php echo htmlspecialchars($stat['modified_formatted']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <input type="hidden" name="action" value="rotate">
            <button type="submit" class="btn" style="background:#2563eb;color:#fff">
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
</div>
