<?php
/**
 * Log statistics (plain-CSS theme).
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
<div class="page-section">
    <h2 style="margin:0 0 16px">Log Statistics</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card">
        <div class="card-body">
        <?php if (empty($this->stats)): ?>
            <div class="badge badge-info" style="display:block;padding:12px 16px">No log files found.</div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="text-align:left;color:#888;border-bottom:1px solid #eee">
                            <th style="padding:8px 12px">File Name</th>
                            <th style="padding:8px 12px">Size</th>
                            <th style="padding:8px 12px">Lines</th>
                            <th style="padding:8px 12px">Structured JSON</th>
                            <th style="padding:8px 12px">Last Modified</th>
                            <th style="padding:8px 12px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->stats as $stat): ?>
                            <tr style="border-bottom:1px solid #f0f0f0">
                                <td style="padding:8px 12px">
                                    <a style="color:#2563eb" href="<?php echo $sURL; ?>Logs/viewer/<?php echo htmlspecialchars($stat['name']); ?>">
                                        <?php echo htmlspecialchars($stat['name']); ?>
                                    </a>
                                </td>
                                <td style="padding:8px 12px;color:#555"><?php echo htmlspecialchars($stat['size_formatted']); ?></td>
                                <td style="padding:8px 12px;color:#555"><?php echo number_format($stat['lines']); ?></td>
                                <td style="padding:8px 12px;color:#555">
                                    <?php echo $stat['json_percentage']; ?>%
                                    <?php if (!empty($stat['level_distribution'])): ?>
                                        <div style="font-size:12px;color:#aaa">
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
                                <td style="padding:8px 12px;color:#555"><?php echo htmlspecialchars($stat['modified_formatted']); ?></td>
                                <td style="padding:8px 12px">
                                    <div style="display:flex;gap:4px">
                                        <a href="<?php echo $sURL; ?>Logs/viewer/<?php echo htmlspecialchars($stat['name']); ?>"
                                           class="btn btn-sm" style="background:#0ea5e9;color:#fff" title="View">View</a>
                                        <a href="<?php echo $sURL; ?>Logs/clearFile/<?php echo htmlspecialchars($stat['name']); ?>"
                                           class="btn btn-sm" style="background:#dc2626;color:#fff" title="Clear"
                                           data-confirm="Are you sure you want to clear this log?">Clear</a>
                                        <a href="<?php echo $sURL; ?>Logs/raw?file=<?php echo htmlspecialchars($stat['name']); ?>"
                                           class="btn btn-sm" style="background:#4b5563;color:#fff" title="Raw View" target="_blank">Raw</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Summary cards -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-top:24px">
                <div style="border:1px solid #eee;border-radius:8px;padding:16px">
                    <div style="font-size:12px;color:#888">Total Size</div>
                    <div style="font-size:20px;font-weight:bold;color:#333;margin-top:4px"><?php echo htmlspecialchars($this->totalSizeHuman); ?></div>
                </div>
                <div style="border:1px solid #eee;border-radius:8px;padding:16px">
                    <div style="font-size:12px;color:#888">Total Lines</div>
                    <div style="font-size:20px;font-weight:bold;color:#333;margin-top:4px"><?php echo number_format($this->totalLines); ?></div>
                </div>
                <div style="border:1px solid #eee;border-radius:8px;padding:16px">
                    <div style="font-size:12px;color:#888">JSON Entries</div>
                    <div style="font-size:20px;font-weight:bold;color:#333;margin-top:4px"><?php echo $this->jsonPercent; ?>%</div>
                </div>
                <div style="border:1px solid #eee;border-radius:8px;padding:16px">
                    <div style="font-size:12px;color:#888">Log Files</div>
                    <div style="font-size:20px;font-weight:bold;color:#333;margin-top:4px"><?php echo (int) $this->totalFiles; ?></div>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>
