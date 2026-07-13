<?php
/**
 * Filter log entries (Bootstrap theme).
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
        case 'error':   return 'bg-danger';
        case 'warning': return 'bg-warning text-dark';
        case 'notice':
        case 'info':    return 'bg-info';
        default:        return 'bg-secondary';
    }
};
?>
<div class="container py-4">
    <h2 class="mb-4">Filter Log Files</h2>
    <?php $this->insert('_toolbar'); ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="file" class="form-label">Select Log File:</label>
                        <select name="file" id="file" class="form-select">
                            <option value="">-- Select Log File --</option>
                            <?php foreach ($this->whitelist as $log): ?>
                                <option value="<?php echo htmlspecialchars($log); ?>" <?php echo $this->file === $log ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($log); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="limit" class="form-label">Maximum Results:</label>
                        <select name="limit" id="limit" class="form-select">
                            <?php foreach ([100, 250, 500, 1000] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $this->limit === $opt ? 'selected' : ''; ?>><?php echo $opt; ?> entries</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="start_date" class="form-label">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($this->startDate); ?>"
                               class="form-control">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="end_date" class="form-label">End Date:</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($this->endDate); ?>"
                               class="form-control">
                    </div>
                </div>

                <div class="mb-3">
                    <span class="form-label d-block">Log Levels:</span>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($this->availableLevels as $level => $label): ?>
                            <div class="form-check">
                                <input type="checkbox" id="level_<?php echo htmlspecialchars($level); ?>" name="levels[]" value="<?php echo $level; ?>"
                                       <?php echo in_array($level, $this->levels) ? 'checked' : ''; ?>
                                       class="form-check-input">
                                <label class="form-check-label" for="level_<?php echo htmlspecialchars($level); ?>"><?php echo htmlspecialchars($label); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="form-text">Leave empty to include all levels</p>
                </div>

                <div class="mb-3">
                    <label for="query" class="form-label">Search Query:</label>
                    <input type="text" id="query" name="query" value="<?php echo htmlspecialchars($this->query); ?>" placeholder="Search in log messages"
                           class="form-control">
                </div>

                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </form>

            <?php if ($this->hasResults): ?>
                <hr class="my-4">
                <h3 class="h5 mb-3">
                    Filter Results <span class="small fw-normal text-muted"><?php echo count($this->results); ?> entries found</span>
                </h3>

                <?php if (empty($this->results)): ?>
                    <div class="alert alert-info">No log entries match the specified filters.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-top">
                            <thead>
                                <tr>
                                    <th style="width:11rem;">Timestamp</th>
                                    <th style="width:6rem;">Level</th>
                                    <th>Message</th>
                                    <th style="width:6rem;">Context</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->results as $entry): ?>
                                    <tr>
                                        <td class="text-muted font-monospace small"><?php echo htmlspecialchars($entry['timestamp'] ?? ''); ?></td>
                                        <td>
                                            <span class="badge rounded-pill <?php echo $levelBadge($entry['level'] ?? ''); ?>">
                                                <?php echo htmlspecialchars(ucfirst($entry['level'] ?? 'info')); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($entry['message'] ?? ''); ?></td>
                                        <td>
                                            <?php if (!empty($entry['context'])): ?>
                                                <details>
                                                    <summary class="text-primary small" style="cursor:pointer;">View</summary>
                                                    <pre class="mt-2 bg-light border rounded p-2 small"><?php echo htmlspecialchars(json_encode($entry['context'], JSON_PRETTY_PRINT)); ?></pre>
                                                </details>
                                            <?php else: ?>
                                                <span class="text-muted small">None</span>
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
</div>
