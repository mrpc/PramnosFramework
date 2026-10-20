<?php
/**
 * Queue jobs list (plain-CSS theme).
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable, fed by Queue/data (server-side)
 *   $this->statuses  — the statuses a task can be in
 *
 * A DataTable over a table of tens of thousands of rows: the status filter, search over the
 * type, the error and the payload, the page size and the sorting are the server's. Clear takes
 * the status to clear here, since the list's filter lives in the table, not in the URL.
 */
$clearable = ['failed', 'completed', 'stopped', 'deleted'];
?>
<div class="page-section">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:16px">
        <h2 style="margin:0">Queue</h2>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
            <a href="<?php echo adminUrl('Queue/stats'); ?>" class="btn">Stats</a>
            <form method="post" action="<?php echo adminUrl('Queue/retryall'); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn" data-confirm="Retry all failed jobs?">Retry all failed</button></form>
            <form method="post" action="<?php echo adminUrl('Queue/clear'); ?>" style="display:flex;align-items:center;gap:4px;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <select name="status" aria-label="Status to clear">
                    <?php foreach ($clearable as $status): ?><option value="<?php echo $status; ?>"><?php echo ucfirst($status); ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn" data-confirm="Clear every job in that status?">Clear</button>
            </form>
        </div>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
