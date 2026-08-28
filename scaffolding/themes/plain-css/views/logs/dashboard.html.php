<?php
/**
 * Logs analytics dashboard (plain-CSS theme).
 *
 * Charts are drawn with Chart.js v4, enqueued by the controller from the
 * locally-bundled asset (CSP-safe). If Chart.js is unavailable the canvases
 * stay empty but the tables below still render every metric.
 *
 * Variables:
 *   $this->toolbar, $this->clearList — toolbar
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
<div class="page-section">
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px">
        <h2 style="margin:0">Logs Dashboard</h2>
        <div style="display:inline-flex;border:1px solid #eee;border-radius:6px;overflow:hidden">
            <?php foreach ($spans as $key => $label): ?>
                <a href="<?php echo adminUrl('Logs/dashboard'); ?>?timespan=<?php echo $key; ?>"
                   style="padding:6px 12px;font-size:14px;text-decoration:none;<?php echo $this->timespan === $key ? 'background:#2563eb;color:#fff' : 'background:#fff;color:#555'; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php $this->insert('_toolbar'); ?>

    <?php if (!empty($this->truncated)): ?>
        <div style="background:#fffbeb;color:#92400e;padding:12px 16px;border-radius:6px;font-size:14px;margin-bottom:24px">
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
            $rows .= '<tr><td style="padding:4px 8px">'
                . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8')
                . '</td><td style="padding:4px 8px;text-align:right;font-family:monospace">'
                . (int) ($values[$i] ?? 0) . '</td></tr>';
        }

        return $rows;
    };
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px;margin-bottom:24px">
        <div class="card">
            <div class="card-body">
                <h3 style="font-weight:600;color:#555;margin:0 0 12px">Log Entry Trends</h3>
                <?php if ($hasCharts): ?>
                <div style="height:300px"><canvas id="log_trends_chart"></canvas></div>
                <?php else: ?>
                <div style="max-height:300px;overflow:auto"><table style="width:100%;border-collapse:collapse">
                    <thead><tr><th style="text-align:left;padding:4px 8px">When</th><th style="text-align:right;padding:4px 8px">Entries</th></tr></thead>
                    <tbody><?php echo $pairs($trendLabels, $trendValues); ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h3 style="font-weight:600;color:#555;margin:0 0 12px">Log Levels Distribution</h3>
                <?php if ($hasCharts): ?>
                <div style="height:300px"><canvas id="log_levels_chart"></canvas></div>
                <?php else: ?>
                <div style="max-height:300px;overflow:auto"><table style="width:100%;border-collapse:collapse">
                    <thead><tr><th style="text-align:left;padding:4px 8px">Level</th><th style="text-align:right;padding:4px 8px">Entries</th></tr></thead>
                    <tbody><?php echo $pairs($levelLabels, $levelValues); ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if (!$hasCharts): ?>
    <p style="background:#e8f4fd;border:1px solid #b6e0fe;padding:10px 12px;border-radius:4px;font-size:13px">
        Shown as tables because this installation has no <code>chartjs</code> asset registered —
        vendor it into <code>www/assets/vendor/chartjs/</code> and register the handle in
        <code>Application::registerVendorLibraries()</code>.
    </p>
    <?php endif; ?>

    <!-- Top errors -->
    <div class="card" style="margin-bottom:24px">
        <div class="card-body">
        <h3 style="font-weight:600;color:#555;margin:0 0 12px">Top Errors</h3>
        <?php if (empty($this->topErrors)): ?>
            <div class="badge badge-info" style="display:block;padding:12px 16px">No errors found in the selected time period.</div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="text-align:left;color:#888;border-bottom:1px solid #eee">
                            <th style="padding:8px 12px">Error Message</th>
                            <th style="padding:8px 12px;width:96px">Count</th>
                            <th style="padding:8px 12px;width:160px">Log File</th>
                            <th style="padding:8px 12px;width:160px">Last Seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->topErrors as $error): ?>
                            <tr style="border-bottom:1px solid #f0f0f0;vertical-align:top">
                                <td style="padding:8px 12px;color:#555">
                                    <?php echo htmlspecialchars(substr($error['message'], 0, 200)); ?><?php echo strlen($error['message']) > 200 ? '<span style="color:#aaa">...</span>' : ''; ?>
                                </td>
                                <td style="padding:8px 12px;text-align:center">
                                    <span class="badge badge-danger"><?php echo (int) $error['count']; ?></span>
                                </td>
                                <td style="padding:8px 12px">
                                    <a style="color:#2563eb" href="<?php echo adminUrl('Logs' . '/viewer/' . (htmlspecialchars($error['file']))); ?>">
                                        <?php echo htmlspecialchars($error['file']); ?>
                                    </a>
                                </td>
                                <td style="padding:8px 12px;color:#666"><?php echo htmlspecialchars($error['last_seen']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- System status -->
    <div class="card">
        <div class="card-body">
        <h3 style="font-weight:600;color:#555;margin:0 0 12px">System Status</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
            <?php foreach ($this->systemStatus as $file => $status): ?>
                <div style="border:1px solid #eee;border-radius:8px;padding:16px">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                        <span style="display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:#fee2e2;color:#b91c1c;font-weight:600">
                            <?php echo strtoupper(substr($file, 0, 1)); ?>
                        </span>
                        <div>
                            <a style="font-weight:500;color:#333" href="<?php echo adminUrl('Logs' . '/viewer/' . (htmlspecialchars($file))); ?>">
                                <?php echo htmlspecialchars($file); ?>
                            </a>
                            <div style="font-size:12px;color:#aaa">
                                <?php echo !empty($status['last_entry']) ? 'Last activity: ' . htmlspecialchars($status['last_entry']) : 'No recent activity'; ?>
                            </div>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(2,1fr);text-align:center">
                        <div>
                            <div style="font-size:18px;font-weight:bold;color:#333"><?php echo number_format($status['total_entries']); ?></div>
                            <div style="font-size:12px;color:#aaa">Entries</div>
                        </div>
                        <div>
                            <div style="font-size:18px;font-weight:bold;color:<?php echo $status['error_rate'] > 0 ? '#dc2626' : '#333'; ?>"><?php echo $status['error_rate']; ?>%</div>
                            <div style="font-size:12px;color:#aaa" title="Share of lines matching error/exception/fatal or an error log level">Error lines</div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') { return; }

        var trendCanvas = document.getElementById('log_trends_chart');
        if (trendCanvas) {
            new Chart(trendCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($this->trendLabels); ?>,
                    datasets: [{
                        label: 'Log Entries',
                        data: <?php echo json_encode($this->trendValues); ?>,
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        borderColor: 'rgba(37, 99, 235, 1)',
                        borderWidth: 2,
                        pointRadius: 3,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        var levelsCanvas = document.getElementById('log_levels_chart');
        if (levelsCanvas) {
            new Chart(levelsCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($this->levelLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($this->levelValues); ?>,
                        backgroundColor: <?php echo json_encode($this->chartColors); ?>,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }
    });
</script>
