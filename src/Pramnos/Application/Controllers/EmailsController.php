<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Html\Icon;

/**
 * Admin controller for viewing sent email history.
 *
 * Operates on the `mails` table created by the `create_mails_table` migration
 * (messaging feature). This controller is read-only by design — emails that
 * have already been sent cannot be unsent, and the history must remain intact.
 *
 * Actions:
 *   - display()   — the list, as a DataTable with per-column search
 *   - data()      — the rows behind it, as JSON
 *   - show($id)   — HTML preview of email content (body rendered in iframe)
 *   - resend($id) — re-queue a failed email for re-delivery
 *
 * All actions require authentication + usertype >= 80.
 *
 * Scaffold wrappers at `src/Controllers/Emails.php` (always scaffolded).
 *
 */
class EmailsController extends Controller
{
    /** Minimum usertype to access any emails action. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'data', 'show', 'resend']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Paginated DataTable of email records (sent, failed, or queued).
     * Supports optional GET filter: status (1=sent, 0=failed, 2=queued).
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Email History';

        /**
         * A DataTable with per-column search, like the other lists.
         *
         * This screen was a hand-rolled table with page links: no sorting, no search, and
         * fifty rows at a time over a table that grows with every mail the site has ever
         * sent. "Did the code reach this address" — the question this screen exists for —
         * could only be answered by paging.
         */
        // The address this screen is scoped to, when it was opened from an account.
        //
        // Carried on the datatable's own source URL, not only on the page's: the table
        // fetches its rows itself, so a filter that lives only in the page's query string is
        // a filter that disappears on the first sort or page change — and the operator is
        // then looking at everybody's mail believing it is one person's.
        $address = $this->scopedAddress();

        $dt = new \Pramnos\Html\Datatable('dt-emails');
        $dt->source           = adminUrl('emails/data')
            . ($address === '' ? '' : '?tomail=' . urlencode($address));
        $dt->bootstrap        = false;
        $dt->footerTextSearch = true;

        // Status is enumerated, so it gets a dropdown: a text box under a column whose
        // values are 0, 1 and 2 asks the reader to guess which number means what.
        $statusFilter = new \Pramnos\Html\Select('status_filter');
        $statusFilter->id = 'dt-emails-status';
        $statusFilter->addOptions([
            ''  => 'Any status',
            '1' => 'Sent',
            '2' => 'Queued',
            '0' => 'Pending',
        ]);

        $dt->addColumn('ID',        true, true, true, 'num-html', '', true, 'left', true)
           ->addColumn('Recipient', true, true, true, '',         '', true, 'left', true)
           ->addColumn('Subject',   true, true, true, 'html',     '', true, 'left', true)
           ->addColumn('Module',    true, true, true, '',         '', true, 'left', true)
           ->addColumn('Date',      true, true, true, '',         '', true, 'left', false)
           ->addColumn(
               'Status',
               true,
               true,
               true,
               'html',
               $statusFilter->render(),
               true,
               'left',
               'dt-emails-status',
               (string) \Pramnos\Http\Request::staticGet('status_filter', '', 'get')
           )
           /*
            * `true` is the second argument, and it is `bVisible`.
            *
            * Both of these were `false`, which goes straight into DataTables' column config and
            * hides the column outright. The Opens column was added and never appeared; the
            * actions column beside it — the view and resend icons — had been invisible since it
            * was written. The rest of this framework's screens declare an actions column as
            * `('Actions', true, false, false, 'html')`: visible, unsortable, unsearchable.
            */
           ->addColumn('Opens', true, false, false, 'html', '', true, 'left', false)
           ->addColumn('Actions', true, false, false, 'html', '', false, 'right', false);

        $view            = $this->getView('emails');
        $view->datatable = $dt;
        // So the screen can say it is showing one account's mail rather than all of it. A
        // short list with no explanation reads as an empty log.
        $view->scopedTo   = $address;
        $view->clearUrl   = adminUrl('emails');

        return $view->display();
    }

    /**
     * The address this screen was opened for, or an empty string.
     *
     * The mail log is keyed by address because `mails` has no `userid`, so "this account's
     * mail" is a filter on `tomail` and nothing else. Which is also the limit: mail sent to
     * an address the account has since changed is not in it.
     */
    protected function scopedAddress(): string
    {
        return trim((string) \Pramnos\Http\Request::staticGet('tomail', '', 'get'));
    }

    /**
     * That address as a `WHERE` fragment, quoted by the driver.
     *
     * `Datasource::getList()` takes SQL rather than bindings, so the value is quoted with
     * `prepareQuery()` — the address arrives in a query string, and building this by
     * concatenation is how a list screen becomes an injection point.
     *
     * Matched case-insensitively, for the same reason the account's own panel is: `=` is
     * case-sensitive on PostgreSQL, and a filter that silently matches nothing looks exactly
     * like an account that was never written to.
     */
    protected function addressCondition(): string
    {
        $address = $this->scopedAddress();

        if ($address === '') {
            return '';
        }

        return \Pramnos\Framework\Factory::getDatabase()->prepareQuery(
            'LOWER(tomail) = LOWER(%s)',
            $address
        );
    }

    /**
     * The rows behind the list, as JSON.
     *
     * The subject and the id both open the preview. A row whose only way in is a button in
     * the last cell makes the rest of the row a target people click at and nothing happens
     * — and the subject is what a person actually aims for, because it is what they
     * recognise.
     */
    public function data(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        \Pramnos\Framework\Factory::getDocument('json');

        $result = \Pramnos\Html\Datatable\Datasource::getList(
            'mails',
            ['id', 'tomail', 'subject', 'module', 'date', 'status'],
            false,
            $this->addressCondition()
        );

        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        foreach ($result[$dataKey] as &$row) {
            $id      = (int) $row[0];
            $status  = (int) $row[5];
            $showUrl = adminUrl('emails/show/') . $id;

            $row[0] = '<a href="' . $showUrl . '">' . $id . '</a>';
            $row[1] = htmlspecialchars((string) $row[1], ENT_QUOTES, 'UTF-8');
            $row[2] = '<a href="' . $showUrl . '">'
                . htmlspecialchars((string) $row[2], ENT_QUOTES, 'UTF-8') . '</a>';
            $row[3] = htmlspecialchars((string) $row[3], ENT_QUOTES, 'UTF-8');

            // The column is a Unix integer. Printed raw it is a number nobody reads, which
            // is what this screen was doing.
            $sent   = (int) $row[4];
            $row[4] = $sent > 0 ? date('Y-m-d H:i', $sent) : '';

            $row[5] = match ($status) {
                1 => '<span class="pf-state pf-state-on">Sent</span>',
                2 => '<span class="pf-state">Queued</span>',
                default => '<span class="pf-state pf-state-off">Pending</span>',
            };

            $row[] = $this->trackingCell($id);

            $row[] = Icon::link($showUrl, 'view', 'Open this email')
                . ($status === 1
                    ? ''
                    : Icon::link(
                        adminUrl('emails/resend/') . $id,
                        'retry',
                        'Send this email again',
                        ['data-confirm' => 'Send this email again?']
                    ));

            unset($row['DT_RowId']);
        }
        unset($row);

        return \Pramnos\Http\Response::json($result);
    }

    /**
     * What is known about this message being read, in one cell.
     *
     * Blank for a message that was not tracked, which is most of them — tracking is off unless
     * the installation switched it on and the message belonged to a list. An empty cell is the
     * honest rendering of "nobody measured this".
     *
     * Opens and **proxy** opens are shown apart, never summed. Apple Mail fetches every remote
     * image on delivery whether or not anybody reads the message, so a single "opened" figure
     * reports a message nobody read as widely read. A click is shown plainly, because a click is
     * a person.
     */
    protected function trackingCell(int $mailId): string
    {
        $row = $this->trackingFor($mailId);

        if ($row === null) {
            return '<span class="pf-muted" title="This message was not tracked">—</span>';
        }

        $parts = [];

        if ((int) $row['clicks'] > 0) {
            $parts[] = '<span class="pf-state pf-state-on" title="Links followed. A click is a '
                . 'person — no proxy follows a link.">' . (int) $row['clicks'] . ' clicked</span>';
        }

        if ((int) $row['opens'] > 0) {
            $parts[] = '<span class="pf-state" title="Fetches that did not come from a known '
                . 'mailbox proxy">' . (int) $row['opens'] . ' opened</span>';
        }

        if ((int) $row['proxy_opens'] > 0) {
            $parts[] = '<span class="pf-state pf-state-off" title="Fetched by a mailbox provider '
                . 'on delivery — Apple Mail Privacy Protection and the like. Not a reader.">'
                . (int) $row['proxy_opens'] . ' prefetched</span>';
        }

        return $parts === []
            ? '<span class="pf-muted" title="Tracked, and nothing has come back">tracked</span>'
            : implode(' ', $parts);
    }

    /**
     * The tracking row for one message, or null.
     *
     * @return ?array<string, mixed>
     */
    protected function trackingFor(int $mailId): ?array
    {
        if ($mailId < 1) {
            return null;
        }

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#emailtracking')
                ->where('mailid', $mailId)
                ->limit(1)
                ->get();
        } catch (\Throwable) {
            // No tracking tables — the feature was never switched on here. Not an error.
            return null;
        }

        return ($result->numRows ?? 0) > 0 ? (array) $result->fields : null;
    }

    /**
     * Detailed view of a single email including the full HTML body.
     * The body is rendered in a sandboxed iframe in the view template to prevent
     * injected scripts from affecting the admin UI.
     */
    public function show(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $mailId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($mailId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('emails'));
            return null;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#mails')
            ->where('id', $mailId)
            ->first();

        if (!$result || $result->numRows === 0) {
            $this->addError('That record no longer exists.');
            $this->redirect(adminUrl('emails'));
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Email Preview — ' . htmlspecialchars((string) ($result->fields['subject'] ?? ''), ENT_QUOTES);

        $view       = $this->getView('emails');
        $view->mail = $result->fields;

        return $view->display('show');
    }

    /**
     * Re-queue a failed email for re-delivery.
     * Sets status=2 (queued) so the mail sender daemon picks it up again.
     * Only failed emails (status=0) can be re-queued.
     */
    public function resend(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $mailId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($mailId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('emails'));
            return;
        }

        $db = \Pramnos\Framework\Factory::getDatabase();

        // Only re-queue failed emails (status=0) — sent/queued emails are ignored
        $db->queryBuilder()
            ->table('#PREFIX#mails')
            ->where('id', $mailId)
            ->where('status', 0)
            ->update(['status' => 2]);

        $this->addMessage('Queued again.');
        $this->redirect(adminUrl('emails/show/') . $mailId);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

}
