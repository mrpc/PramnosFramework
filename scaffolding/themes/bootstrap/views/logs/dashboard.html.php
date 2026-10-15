<?php
/**
 * Logs analytics dashboard (Bootstrap theme).
 *
 * Charts are Html\Chart figures: drawn by pf-utils.js with the locally-bundled
 * Chart.js in the theme's colours, with no inline script. Without Chart.js each
 * one is a table of the same numbers.
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
 *   $this->logsRoute    — the address the log screens answer under (`Logs`)
 *   $this->timespan     — string  active timespan key (1h|6h|24h|7d|30d)
 *   $this->trendLabels  — string[] x-axis labels
 *   $this->trendValues  — int[]    entry counts
 *   $this->levelLabels  — string[] level names (already ucfirst)
 *   $this->levelValues  — int[]    per-level counts
 *   $this->chartColors  — string[] hex color per level
 *   $this->topErrors    — array[] {message, count, file, last_seen}
 *   $this->systemStatus — array file => {last_entry, error_rate, success_rate, total_entries}
 */
// The area's own base — every link on this screen is another admin screen.
$sURL = adminUrl();
$spans = ['1h' => 'Last Hour', '6h' => '6 Hours', '24h' => '24 Hours', '7d' => '7 Days', '30d' => '30 Days'];
?>
<div class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <h2 class="mb-0">Logs Dashboard</h2>
        <div class="btn-group" role="group">
            <?php foreach ($spans as $key => $label): ?>
                <a href="<?php echo adminUrl(($this->logsRoute ?? 'Logs') . '/dashboard'); ?>?timespan=<?php echo $key; ?>"
                   class="btn btn-sm <?php echo $this->timespan === $key ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php $this->insert('_toolbar'); ?>

    <?php if (!empty($this->truncated)): ?>
        <div role="status" class="alert alert-warning">
            &#9888; Some log files are very large — only their most recent entries were analysed.
        </div>
    <?php endif; ?>

    <?php
    /*
     * The numbers, whether or not there is a Chart.js to draw them with. See the Tailwind copy
     * of this view for the reasoning: an empty `<canvas>` under a heading looks broken and says
     * nothing, and an installation without the `chartjs` handle is a real state.
     */
    $hasCharts   = ($this->hasCharts ?? true) !== false;
    $trendLabels = (array) ($this->trendLabels ?? []);
    $trendValues = (array) ($this->trendValues ?? []);
    $levelLabels = (array) ($this->levelLabels ?? []);
    $levelValues = (array) ($this->levelValues ?? []);
    $pairs = static function (array $labels, array $values): string {
        $rows = '';
        foreach ($labels as $i => $label) {
            $rows .= '<tr><td>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8')
                . '</td><td style="text-align:right;font-family:monospace">'
                . (int) ($values[$i] ?? 0) . '</td></tr>';
        }

        return $rows;
    };
    ?>
    <div class="row g-4 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h3 class="h6 fw-semibold mb-3">Log Entry Trends</h3>
                    <?php echo \Pramnos\Html\Chart::render('line', $trendLabels, [
                ['label' => 'Log Entries', 'data' => $trendValues],
            ], ['decimals' => 0]); ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h3 class="h6 fw-semibold mb-3">Log Levels Distribution</h3>
                    <?php echo \Pramnos\Html\Chart::render('doughnut', $levelLabels, [
                ['label' => 'Entries', 'data' => $levelValues, 'colors' => (array) ($this->chartColors ?? [])],
            ], ['decimals' => 0, 'chart' => ['cutout' => '60%', 'plugins' => ['legend' => ['position' => 'bottom']]]]); ?>
                </div>
            </div>
        </div>
    </div>
    <?php if (!$hasCharts): ?>
    <div role="status" class="alert alert-info small">
        Shown as tables because this installation has no <code>chartjs</code> asset registered —
        vendor it into <code>www/assets/vendor/chartjs/</code> and register the handle in
        <code>Application::registerVendorLibraries()</code>.
    </div>
    <?php endif; ?>

    <!-- Top errors -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6 fw-semibold mb-3">Top Errors</h3>
            <?php if (empty($this->topErrors)): ?>
                <div role="status" class="alert alert-info mb-0">No errors found in the selected time period.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped align-top">
                        <thead>
                            <tr>
                                <th>Error Message</th>
                                <th style="width:6rem;">Count</th>
                                <th style="width:10rem;">Log File</th>
                                <th style="width:10rem;">Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->topErrors as $error): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars(substr($error['message'], 0, 200)); ?><?php echo strlen($error['message']) > 200 ? '<span class="text-muted">...</span>' : ''; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge rounded-pill bg-danger"><?php echo (int) $error['count']; ?></span>
                                    </td>
                                    <td>
                                        <a href="<?php echo adminUrl(($this->logsRoute ?? 'Logs') . '/viewer/' . (htmlspecialchars($error['file']))); ?>">
                                            <?php echo htmlspecialchars($error['file']); ?>
                                        </a>
                                    </td>
                                    <td class="text-muted"><?php echo htmlspecialchars($error['last_seen']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- System status -->
    <div class="card shadow-sm">
        <div class="card-body">
            <h3 class="h6 fw-semibold mb-3">System Status</h3>
            <div class="row g-4">
                <?php foreach ($this->systemStatus as $file => $status): ?>
                    <div class="col-12 col-sm-6 col-lg-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <span class="d-flex align-items-center justify-content-center rounded-circle bg-danger-subtle text-danger fw-semibold" style="width:2.25rem;height:2.25rem;">
                                        <?php echo strtoupper(substr($file, 0, 1)); ?>
                                    </span>
                                    <div>
                                        <a class="fw-medium" href="<?php echo adminUrl(($this->logsRoute ?? 'Logs') . '/viewer/' . (htmlspecialchars($file))); ?>">
                                            <?php echo htmlspecialchars($file); ?>
                                        </a>
                                        <div class="small text-muted">
                                            <?php echo !empty($status['last_entry']) ? 'Last activity: ' . htmlspecialchars($status['last_entry']) : 'No recent activity'; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="fs-5 fw-bold"><?php echo number_format($status['total_entries']); ?></div>
                                        <div class="small text-muted">Entries</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="fs-5 fw-bold <?php echo $status['error_rate'] > 0 ? 'text-danger' : ''; ?>"><?php echo $status['error_rate']; ?>%</div>
                                        <div class="small text-muted" title="Share of lines matching error/exception/fatal or an error log level">Error lines</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

