<?php
/**
 * Log statistics (Bootstrap theme).
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
<div class="container py-4">
    <h2 class="mb-4">Log Statistics</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (empty($this->stats)): ?>
                <div class="alert alert-info mb-0">No log files found.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Size</th>
                                <th>Lines</th>
                                <th>Structured JSON</th>
                                <th>Last Modified</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->stats as $stat): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo $sURL; ?>Logs/viewer/<?php echo htmlspecialchars($stat['name']); ?>">
                                            <?php echo htmlspecialchars($stat['name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($stat['size_formatted']); ?></td>
                                    <td><?php echo number_format($stat['lines']); ?></td>
                                    <td>
                                        <?php echo $stat['json_percentage']; ?>%
                                        <?php if (!empty($stat['level_distribution'])): ?>
                                            <div class="small text-muted">
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
                                    <td><?php echo htmlspecialchars($stat['modified_formatted']); ?></td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="<?php echo $sURL; ?>Logs/viewer/<?php echo htmlspecialchars($stat['name']); ?>"
                                               class="btn btn-sm btn-info" title="View">View</a>
                                            <a href="<?php echo $sURL; ?>Logs/clearFile/<?php echo htmlspecialchars($stat['name']); ?>"
                                               class="btn btn-sm btn-danger" title="Clear"
                                               data-confirm="Are you sure you want to clear this log?">Clear</a>
                                            <a href="<?php echo $sURL; ?>Logs/raw?file=<?php echo htmlspecialchars($stat['name']); ?>"
                                               class="btn btn-sm btn-secondary" title="Raw View" target="_blank">Raw</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Summary cards -->
                <div class="row g-4 mt-2">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="small text-muted">Total Size</div>
                                <div class="fs-4 fw-bold mt-1"><?php echo htmlspecialchars($this->totalSizeHuman); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="small text-muted">Total Lines</div>
                                <div class="fs-4 fw-bold mt-1"><?php echo number_format($this->totalLines); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="small text-muted">JSON Entries</div>
                                <div class="fs-4 fw-bold mt-1"><?php echo $this->jsonPercent; ?>%</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="small text-muted">Log Files</div>
                                <div class="fs-4 fw-bold mt-1"><?php echo (int) $this->totalFiles; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
