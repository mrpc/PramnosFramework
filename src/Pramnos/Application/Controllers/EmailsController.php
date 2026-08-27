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
        $dt = new \Pramnos\Html\Datatable('dt-emails');
        $dt->source           = adminUrl('emails/data');
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
           ->addColumn('', false, false, false, 'html', '', false, 'right', false);

        $view            = $this->getView('emails');
        $view->datatable = $dt;

        return $view->display();
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
            false
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
