<?php
/**
 * Logs analytics dashboard (Tailwind theme).
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
<div class="px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="text-2xl font-bold text-base-content">Logs Dashboard</h2>
        <div class="inline-flex rounded-md shadow-sm overflow-hidden border border-base-300">
            <?php foreach ($spans as $key => $label): ?>
                <a href="<?php echo adminUrl(($this->logsRoute ?? 'Logs') . '/dashboard'); ?>?timespan=<?php echo $key; ?>"
                   class="px-3 py-1.5 text-sm <?php echo $this->timespan === $key ? 'bg-primary text-white' : 'bg-base-100 text-base-content hover:bg-base-200'; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php $this->insert('_toolbar'); ?>

    <?php if (!empty($this->truncated)): ?>
        <div class="rounded-md bg-warning/10 text-warning px-4 py-3 text-sm mb-6">
            &#9888; Some log files are very large — only their most recent entries were analysed.
        </div>
    <?php endif; ?>

    <?php
    /*
     * The numbers, whether or not there is a Chart.js to draw them with.
     *
     * These two cards were a heading and an empty canvas on any installation without the
     * `chartjs` handle — a project scaffolded before that library was added to the catalogue,
     * which is a real state and one nothing announced. A blank box with a title is the worst of
     * the available failures: it looks broken and says nothing, so the reader concludes the
     * screen is broken rather than that an asset is missing.
     *
     * The data is on the server either way. Without the library it is a table, which is not as
     * quick to read and is considerably better than nothing.
     */
    $hasCharts   = ($this->hasCharts ?? true) !== false;
    $trendLabels = (array) ($this->trendLabels ?? []);
    $trendValues = (array) ($this->trendValues ?? []);
    $levelLabels = (array) ($this->levelLabels ?? []);
    $levelValues = (array) ($this->levelValues ?? []);
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="card bg-base-100 border border-base-300 shadow-sm p-5">
            <h3 class="font-semibold text-base-content mb-3">Log Entry Trends</h3>
            <?php echo \Pramnos\Html\Chart::render('line', $trendLabels, [
                ['label' => 'Log Entries', 'data' => $trendValues],
            ], ['decimals' => 0]); ?>
        </div>
        <div class="card bg-base-100 border border-base-300 shadow-sm p-5">
            <h3 class="font-semibold text-base-content mb-3">Log Levels Distribution</h3>
            <?php echo \Pramnos\Html\Chart::render('doughnut', $levelLabels, [
                ['label' => 'Entries', 'data' => $levelValues, 'colors' => (array) ($this->chartColors ?? [])],
            ], ['decimals' => 0, 'chart' => ['cutout' => '60%', 'plugins' => ['legend' => ['position' => 'bottom']]]]); ?>
        </div>
    </div>

    <?php if (!$hasCharts): ?>
    <div class="rounded-md bg-info/10 text-info px-4 py-3 text-sm mb-6">
        The numbers above are shown as tables because this installation has no
        <code>chartjs</code> asset registered. Vendor it into
        <code>www/assets/vendor/chartjs/</code> and register the handle in
        <code>Application::registerVendorLibraries()</code> — the framework's asset catalogue
        lists the version it expects.
    </div>
    <?php endif; ?>

    <!-- Top errors -->
    <div class="card bg-base-100 border border-base-300 shadow-sm p-5 mb-6">
        <h3 class="font-semibold text-base-content mb-3">Top Errors</h3>
        <?php if (empty($this->topErrors)): ?>
            <div class="rounded-md bg-info/10 text-info px-4 py-3 text-sm">No errors found in the selected time period.</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="table table-sm min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-base-content/70 border-b border-base-300">
                            <th class="px-3 py-2 font-medium">Error Message</th>
                            <th class="px-3 py-2 font-medium w-24">Count</th>
                            <th class="px-3 py-2 font-medium w-40">Log File</th>
                            <th class="px-3 py-2 font-medium w-40">Last Seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-300">
                        <?php foreach ($this->topErrors as $error): ?>
                            <tr class="hover:bg-base-200 align-top">
                                <td class="px-3 py-2 text-base-content">
                                    <?php echo htmlspecialchars(substr($error['message'], 0, 200)); ?><?php echo strlen($error['message']) > 200 ? '<span class="text-base-content/60">...</span>' : ''; ?>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span class="badge badge-error badge-sm inline-flex items-center"><?php echo (int) $error['count']; ?></span>
                                </td>
                                <td class="px-3 py-2">
                                    <a class="text-primary hover:underline" href="<?php echo adminUrl(($this->logsRoute ?? 'Logs') . '/viewer/' . (htmlspecialchars($error['file']))); ?>">
                                        <?php echo htmlspecialchars($error['file']); ?>
                                    </a>
                                </td>
                                <td class="px-3 py-2 text-base-content/80"><?php echo htmlspecialchars($error['last_seen']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- System status -->
    <div class="card bg-base-100 border border-base-300 shadow-sm p-5">
        <h3 class="font-semibold text-base-content mb-3">System Status</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($this->systemStatus as $file => $status): ?>
                <div class="border border-base-300 rounded-lg p-4">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="flex items-center justify-center w-9 h-9 rounded-full bg-error/10 text-error font-semibold">
                            <?php echo strtoupper(substr($file, 0, 1)); ?>
                        </span>
                        <div>
                            <a class="font-medium text-base-content hover:underline" href="<?php echo adminUrl(($this->logsRoute ?? 'Logs') . '/viewer/' . (htmlspecialchars($file))); ?>">
                                <?php echo htmlspecialchars($file); ?>
                            </a>
                            <div class="text-xs text-base-content/60">
                                <?php echo !empty($status['last_entry']) ? 'Last activity: ' . htmlspecialchars($status['last_entry']) : 'No recent activity'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 text-center">
                        <div>
                            <div class="text-lg font-bold text-base-content"><?php echo number_format($status['total_entries']); ?></div>
                            <div class="text-xs text-base-content/60">Entries</div>
                        </div>
                        <div>
                            <div class="text-lg font-bold <?php echo $status['error_rate'] > 0 ? 'text-error' : 'text-base-content'; ?>"><?php echo $status['error_rate']; ?>%</div>
                            <div class="text-xs text-base-content/60" title="Share of lines matching error/exception/fatal or an error log level">Error lines</div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

