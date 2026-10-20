<?php
/**
 * Queue jobs list (Tailwind theme).
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
<div class="px-4 py-6">
    <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
        <h2>Queue</h2>
        <div class="flex flex-wrap items-center gap-2">
            <a href="<?php echo adminUrl('Queue/stats'); ?>" class="btn btn-outline btn-primary btn-xs">Stats</a>
            <form method="post" action="<?php echo adminUrl('Queue/retryall'); ?>" style="display:inline;margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-outline btn-warning btn-xs" data-confirm="Retry all failed jobs?">Retry all failed</button></form>
            <form method="post" action="<?php echo adminUrl('Queue/clear'); ?>" class="flex items-center gap-1" style="margin:0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <select name="status" class="select select-xs" aria-label="Status to clear">
                    <?php foreach ($clearable as $status): ?><option value="<?php echo $status; ?>"><?php echo ucfirst($status); ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-error btn-xs" data-confirm="Clear every job in that status?">Clear</button>
            </form>
        </div>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
