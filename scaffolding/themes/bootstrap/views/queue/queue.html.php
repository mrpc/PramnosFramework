<?php
/**
 * Queue jobs list (Bootstrap theme).
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
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="mb-0">Queue</h2>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a href="<?php echo adminUrl('Queue/stats'); ?>" class="btn btn-sm btn-outline-primary">Stats</a>
            <form method="post" action="<?php echo adminUrl('Queue/retryall'); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-warning" data-confirm="Retry all failed jobs?">Retry all failed</button></form>
            <form method="post" action="<?php echo adminUrl('Queue/clear'); ?>" class="d-flex align-items-center gap-1 m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <select name="status" class="form-select form-select-sm" aria-label="Status to clear">
                    <?php foreach ($clearable as $status): ?><option value="<?php echo $status; ?>"><?php echo ucfirst($status); ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Clear every job in that status?">Clear</button>
            </form>
        </div>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
