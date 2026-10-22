<?php
/**
 * Queue jobs list (plain-CSS theme).
 *
 * Variables:
 *   $this->datatable  — \Pramnos\Html\Datatable, fed by Queue/data (server-side)
 *   $this->statuses   — [status => name], in the reader's language
 *   $this->counts     — [status => tasks], and `total`, counted on this request
 *   $this->byType     — per task type: running, pending, per_minute, net_per_minute, clears_in, health
 *   $this->rateWindow — the window those rates are measured over, in seconds
 *   $this->streams    — the event stream's consumer groups (StreamConsumer::status()), or none
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
<div class="page-section">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:16px">
        <h2><?php echo $e(t('Queue')); ?></h2>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
            <a href="<?php echo adminUrl('Queue/history'); ?>" data-pf-modal class="btn"><?php echo $e(t('Statistics — last 48 hours')); ?></a>
            <form method="post" action="<?php echo adminUrl('Queue/retryall'); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn" data-confirm="<?php echo $e(t('Retry all failed jobs?')); ?>"><?php echo $e(t('Retry all failed')); ?></button></form>
            <form method="post" action="<?php echo adminUrl('Queue/clear'); ?>" style="display:flex;align-items:center;gap:4px;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <select name="status" aria-label="<?php echo $e(t('Status to clear')); ?>">
                    <?php foreach ($clearable as $status): ?><option value="<?php echo $status; ?>"><?php echo $e($this->statuses[$status] ?? $status); ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn" data-confirm="<?php echo $e(t('Clear every job in that status?')); ?>"><?php echo $e(t('Clear')); ?></button>
            </form>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin-bottom:16px" data-field="counts">
        <?php foreach (['pending', 'processing', 'completed', 'warning', 'failed', 'stopped', 'deleted'] as $status): ?>
            <div class="card" style="padding:8px">
                <div style="font-size:.8em;color:#666"><?php echo $e($this->statuses[$status] ?? $status); ?></div>
                <div style="font-size:1.25em;font-weight:600"><?php echo number_format((int) ($counts[$status] ?? 0)); ?>
                    <?php if ($status === 'completed' && $total > 0): ?><small style="font-size:.8em;color:#666"><?php echo round($done / $total * 100, 1); ?>%</small><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="card" style="padding:8px">
            <div style="font-size:.8em;color:#666"><?php echo $e(t('Total')); ?></div>
            <div style="font-size:1.25em;font-weight:600"><?php echo number_format($total); ?></div>
        </div>
    </div>

    <?php if (!empty($this->byType)): ?>
        <div class="card" style="margin-bottom:16px;overflow-x:auto">
            <table class="table" data-field="rates">
                <thead><tr>
                    <th><?php echo $e(t('Task type')); ?></th>
                    <th style="text-align:right"><?php echo $e(t('Running')); ?></th>
                    <th style="text-align:right"><?php echo $e(t('Pending')); ?></th>
                    <th style="text-align:right"><?php echo $e(t('Done/min')); ?></th>
                    <th style="text-align:right"><?php echo $e(t('Net/min')); ?></th>
                    <th><?php echo $e(t('Clears in')); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($this->byType as $row): ?>
                        <tr<?php echo $row['health'] === 'behind' ? ' style="color:#b91c1c"' : ''; ?>>
                            <td><code><?php echo $e($row['type']); ?></code></td>
                            <td style="text-align:right"><?php echo (int) $row['running']; ?></td>
                            <td style="text-align:right"><?php echo number_format((int) $row['pending']); ?></td>
                            <td style="text-align:right"><?php echo $e($row['per_minute']); ?></td>
                            <td style="text-align:right"><?php echo ($row['net_per_minute'] > 0 ? '+' : '') . $e($row['net_per_minute']); ?></td>
                            <td><?php echo match ($row['health']) {
                                'draining'   => $e($row['clears_in']),
                                'keeping_up' => '<span style="color:#666">' . $e(t('keeping up')) . '</span>',
                                default      => '<strong>' . $e(t('growing — needs workers')) . '</strong>',
                            }; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:.8em;color:#666;padding:0 12px 8px"><?php echo $e(sprintf(t('Over the last %d minutes. Keeping up means arrivals match completions and the backlog is under a minute of the type\'s own output. Growing is the one worth reading: work is waiting that the type is not clearing.'), intdiv((int) ($this->rateWindow ?? 600), 60))); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($this->streams)): ?>
        <?php // The event stream: each consumer group's position, what waits for it, and what died. ?>
        <section class="pf-ui pf-embedded" data-field="streams">
            <div class="pf-card pf-card-flush">
                <div class="pf-card-head"><h3 class="pf-section-title"><?php echo $e(t('Event streams')); ?></h3>
                    <?php // A lease taken in the last minute means a consumer is reading; none, and a stuck stream would look healthy. ?>
                    <?php if (max(array_column($this->streams, 'leasedUntil')) < time() - 60): ?>
                        <span class="pf-badge pf-tone-warn" data-field="no-daemon"><?php echo $e(t('No events:consume daemon is reading the stream')); ?></span>
                    <?php endif; ?>
                </div>
                <table class="pf-table">
                    <thead><tr>
                        <th><?php echo $e(t('Consumer group')); ?></th>
                        <th class="pf-num"><?php echo $e(t('Waiting to be read')); ?></th>
                        <th class="pf-num"><?php echo $e(t('To be tried again')); ?></th>
                        <th class="pf-num"><?php echo $e(t('Dead')); ?></th>
                        <th><?php echo $e(t('Oldest waiting')); ?></th>
                        <th><?php echo $e(t('Read by')); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($this->streams as $stream): ?>
                        <tr data-group="<?php echo $e($stream['group']); ?>">
                            <?php $short = substr((string) strrchr('\\' . $stream['group'], '\\'), 1); ?>
                            <td><strong title="<?php echo $e($stream['group']); ?>"><?php echo $e($short); ?></strong>
                                <span class="pf-row-desc"><?php echo $e(substr($stream['group'], 0, -strlen($short)) . ' · ' . t('position %s', number_format($stream['position']))); ?></span></td>
                            <td class="pf-num"><?php echo $stream['lag'] > 0 ? '<span class="pf-badge pf-tone-warn">' . $e(number_format($stream['lag']) . ($stream['lagCapped'] ? '+' : '')) . '</span>' : '<span class="pf-zero">0</span>'; ?></td>
                            <td class="pf-num"><?php echo $stream['pending'] > 0 ? '<span class="pf-badge pf-tone-warn">' . $e(number_format($stream['pending'])) . '</span>' : '<span class="pf-zero">0</span>'; ?></td>
                            <td class="pf-num"><?php echo $stream['dead'] > 0 ? '<span class="pf-badge pf-tone-bad">' . $e(number_format($stream['dead'])) . '</span>' : '<span class="pf-zero">0</span>'; ?></td>
                            <td class="pf-muted"><?php echo $stream['oldestPending'] === null ? '—' : $e(date('Y-m-d H:i', $stream['oldestPending'])); ?></td>
                            <td class="pf-muted"><?php echo $stream['owner'] !== null && $stream['leasedUntil'] >= time() ? $e($stream['owner']) : $e(t('nobody now')); ?></td>
                            <td class="pf-num">
                                <?php if ($stream['dead'] > 0): ?>
                                    <form method="post" action="<?php echo $e(adminUrl('Queue/streamretry')); ?>" class="pf-inline-form"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="group" value="<?php echo $e($stream['group']); ?>"><button type="submit" class="pf-btn pf-btn-sm"><?php echo $e(t('Try the dead again')); ?></button></form>
                                    <form method="post" action="<?php echo $e(adminUrl('Queue/streamdrop')); ?>" class="pf-inline-form" data-confirm="<?php echo $e(t('Delete this group\'s dead entries?')); ?>"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="group" value="<?php echo $e($stream['group']); ?>"><button type="submit" class="pf-btn pf-btn-sm pf-btn-ghost pf-btn-danger"><?php echo $e(t('Delete the dead')); ?></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php echo $this->datatable->render(); ?>
</div>
