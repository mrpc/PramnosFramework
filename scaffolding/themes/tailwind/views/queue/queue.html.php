<?php
/**
 * Queue jobs list (Tailwind theme).
 *
 * Variables:
 *   $this->datatable  — \Pramnos\Html\Datatable, fed by Queue/data (server-side)
 *   $this->statuses   — [status => name], in the reader's language
 *   $this->counts     — [status => tasks], and `total`, counted on this request
 *   $this->byType     — per task type: running, pending, per_minute, net_per_minute, clears_in, health
 *   $this->rateWindow — the window those rates are measured over, in seconds
 *
 * The state first — how many tasks are in each status, and per type whether it is keeping up —
 * then the tasks. Clear takes the status to clear here, since the list's filter lives in the
 * table, not in the URL.
 */
$e         = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$counts    = $this->counts ?? [];
$total     = (int) ($counts['total'] ?? 0);
$done      = (int) ($counts['completed'] ?? 0) + (int) ($counts['warning'] ?? 0);
$clearable = ['failed', 'completed', 'stopped', 'deleted'];
?>
<div class="px-4 py-6">
    <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
        <h2><?php echo $e(t('Queue')); ?></h2>
        <div class="flex flex-wrap items-center gap-2">
            <a href="<?php echo adminUrl('Queue/history'); ?>" class="btn btn-outline btn-primary btn-xs"><?php echo $e(t('Statistics — last 48 hours')); ?></a>
            <form method="post" action="<?php echo adminUrl('Queue/retryall'); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-outline btn-warning btn-xs" data-confirm="<?php echo $e(t('Retry all failed jobs?')); ?>"><?php echo $e(t('Retry all failed')); ?></button></form>
            <form method="post" action="<?php echo adminUrl('Queue/clear'); ?>" class="flex items-center gap-1" style="margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <select name="status" class="select select-xs" aria-label="<?php echo $e(t('Status to clear')); ?>">
                    <?php foreach ($clearable as $status): ?><option value="<?php echo $status; ?>"><?php echo $e($this->statuses[$status] ?? $status); ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="<?php echo $e(t('Clear every job in that status?')); ?>"><?php echo $e(t('Clear')); ?></button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-8 gap-2 mb-4" data-field="counts">
        <?php foreach (['pending', 'processing', 'completed', 'warning', 'failed', 'stopped', 'deleted'] as $status): ?>
            <div class="card bg-base-100 border border-base-300 p-3">
                <div class="text-xs text-base-content/60"><?php echo $e($this->statuses[$status] ?? $status); ?></div>
                <div class="text-xl font-semibold"><?php echo number_format((int) ($counts[$status] ?? 0)); ?>
                    <?php if ($status === 'completed' && $total > 0): ?><small class="text-xs text-base-content/60"><?php echo round($done / $total * 100, 1); ?>%</small><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="card bg-base-100 border border-base-300 p-3">
            <div class="text-xs text-base-content/60"><?php echo $e(t('Total')); ?></div>
            <div class="text-xl font-semibold"><?php echo number_format($total); ?></div>
        </div>
    </div>

    <?php if (!empty($this->byType)): ?>
        <div class="card bg-base-100 border border-base-300 mb-4 overflow-x-auto">
            <table class="table table-sm" data-field="rates">
                <thead><tr>
                    <th><?php echo $e(t('Task type')); ?></th>
                    <th class="text-right"><?php echo $e(t('Running')); ?></th>
                    <th class="text-right"><?php echo $e(t('Pending')); ?></th>
                    <th class="text-right"><?php echo $e(t('Done/min')); ?></th>
                    <th class="text-right"><?php echo $e(t('Net/min')); ?></th>
                    <th><?php echo $e(t('Clears in')); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($this->byType as $row): ?>
                        <tr class="<?php echo $row['health'] === 'behind' ? 'text-error' : ''; ?>">
                            <td><code><?php echo $e($row['type']); ?></code></td>
                            <td class="text-right"><?php echo (int) $row['running']; ?></td>
                            <td class="text-right"><?php echo number_format((int) $row['pending']); ?></td>
                            <td class="text-right"><?php echo $e($row['per_minute']); ?></td>
                            <td class="text-right"><?php echo ($row['net_per_minute'] > 0 ? '+' : '') . $e($row['net_per_minute']); ?></td>
                            <td><?php echo match ($row['health']) {
                                'draining'   => $e($row['clears_in']),
                                'keeping_up' => '<span class="text-base-content/60">' . $e(t('keeping up')) . '</span>',
                                default      => '<strong>' . $e(t('growing — needs workers')) . '</strong>',
                            }; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-xs text-base-content/60 px-4 pb-3"><?php echo $e(sprintf(t('Over the last %d minutes. Keeping up means arrivals match completions and the backlog is under a minute of the type\'s own output. Growing is the one worth reading: work is waiting that the type is not clearing.'), intdiv((int) ($this->rateWindow ?? 600), 60))); ?></p>
        </div>
    <?php endif; ?>

    <?php echo $this->datatable->render(); ?>
</div>
