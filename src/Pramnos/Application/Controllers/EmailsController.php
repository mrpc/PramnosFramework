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
 *   - bounce($id) — clear or block the recipient's address ({@see \Pramnos\Email\Bounces})
 *
 * All actions require authentication the administration floor (the `super` role, 98, unless set).
 *
 * Scaffold wrappers at `src/Controllers/Emails.php` (always scaffolded).
 *
 */
class EmailsController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.emails';

    /** One record of this screen, for the side panel: see Controller::$assistSubject. */
    protected string $assistSubject = 'email';

    /** The usertype floor; 0 is the administration area's — {@see \Pramnos\Auth\AdminAccess::defaultUsertype()}, the `super` role unless set. */
    protected int $requiredUserType = 0;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'data', 'show', 'resend', 'bounce']);
        // POST with the session's token: a resend of a sent message queues a new one, and a link
        // that does that is one anybody can make a signed-in administrator follow.
        $this->addWriteAction(['resend']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Paginated DataTable of email records (sent, failed, or queued).
     * Supports optional GET filter: status (1=sent, 0=failed, 2=queued).
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Email History');

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
        $user    = $this->scopedUser();
        $query   = array_filter(['tomail' => $address, 'user' => $user > 0 ? (string) $user : ''], static fn ($v): bool => $v !== '');
        $recordsUser = \Pramnos\Email\MailLog::recordsUser();

        $dt = new \Pramnos\Html\Datatable('dt-emails');
        $dt->source           = adminUrl('emails/data')
            . ($query === [] ? '' : '?' . http_build_query($query));
        $dt->bootstrap        = false;
        $dt->footerTextSearch = true;

        // Status is enumerated, so it gets a dropdown: a text box under a column whose
        // values are 0, 1 and 2 asks the reader to guess which number means what.
        $statusFilter = new \Pramnos\Html\Select('status_filter');
        $statusFilter->id = 'dt-emails-status';
        $options = ['' => t('Any status')];
        foreach ($this->statuses() as $value => $label) {
            $options[(string) $value] = $label;
        }
        $statusFilter->addOptions($options);

        $dt->addColumn(t('ID'),        true, true, true, 'num-html', '', true, 'left', true)
           ->addColumn(t('Recipient'), true, true, true, '',         '', true, 'left', true);
        // The account it was for, where the log records it.
        if ($recordsUser) {
            $dt->addColumn(t('User'), true, true, false, 'html', '', true, 'left', false);
        }
        $dt
           ->addColumn(t('Subject'),   true, true, true, 'html',     '', true, 'left', true)
           ->addColumn(t('Module'),    true, true, true, '',         '', true, 'left', true)
           ->addColumn(t('Date'),      true, true, true, '',         '', true, 'left', false)
           ->addColumn(
               t('Status'),
               true,
               true,
               true,
               'html',
               $statusFilter->render(),
               true,
               'left',
               'dt-emails-status',
               (string) \Pramnos\Http\Request::staticGet('status_filter', '', 'get')
           );
        // The application's own columns, after the status: shown and searched as text, escaped.
        foreach (array_keys($this->applicationColumns()) as $label) {
            $dt->addColumn((string) $label, true, false, true, 'html', '', true, 'left', true);
        }
        /*
         * `true` is the second argument, and it is `bVisible`: `false` goes straight into
         * DataTables' column config and hides the column outright. The rest of this framework's
         * screens declare an actions column as `('Actions', true, false, false, 'html')`:
         * visible, unsortable, unsearchable.
         */
        $dt->addColumn(t('Opens'), true, false, false, 'html', '', true, 'left', false)
           ->addColumn(t('Actions'), true, false, false, 'html', '', false, 'right', false);

        $view            = $this->getView('emails');
        $view->datatable = $dt;
        // So the screen can say it is showing one account's mail rather than all of it. A
        // short list with no explanation reads as an empty log.
        $view->scopedTo   = $address !== '' ? $address : ($user > 0 ? $this->userLabel($user) : '');
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
     * The account this screen was opened for (`?user=`), or 0 — the account's own mail,
     * whatever address it went to, where the log records accounts ({@see \Pramnos\Email\MailLog}).
     */
    protected function scopedUser(): int
    {
        return max(0, (int) \Pramnos\Http\Request::staticGet('user', 0, 'get'));
    }

    /** An account as the screen names it: its username, or its number. */
    protected function userLabel(int $userId): string
    {
        $names = $this->usernames([$userId]);

        return $names[$userId] ?? ('#' . $userId);
    }

    /**
     * Usernames by id, in one query.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    protected function usernames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        try {
            $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
                ->select(['userid', 'username'])->whereIn('userid', $ids)->getAll();
        } catch (\Throwable) {
            return [];
        }

        return array_column($rows, 'username', 'userid');
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
        // An account's own mail, where the log records accounts — with what went to the address
        // too, for messages logged before it did.
        $user = \Pramnos\Email\MailLog::recordsUser() ? $this->scopedUser() : 0;

        if ($address === '' && $user === 0) {
            return '';
        }

        // Not the query builder: Datasource::getList() takes its condition as SQL. The values
        // are an integer and a quoted string.
        $byAddress = $address === '' ? '' : \Pramnos\Framework\Factory::getDatabase()->prepareQuery(
            'LOWER(tomail) = LOWER(%s)',
            $address
        );
        if ($user === 0) {
            return $byAddress;
        }

        return $byAddress === '' ? 'userid = ' . $user : '(userid = ' . $user . ' OR ' . $byAddress . ')';
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
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        \Pramnos\Framework\Factory::getDocument('json');

        $extra  = $this->applicationColumns();
        $recordsUser = \Pramnos\Email\MailLog::recordsUser();
        // `extrainfo` (the error a failed badge carries in its title) and `userid` last, read but
        // not shown as they are.
        $fields = array_merge(['id', 'tomail', 'subject', 'module', 'date', 'status'], array_values($extra), ['extrainfo'], $recordsUser ? ['userid'] : []);
        $result = \Pramnos\Html\Datatable\Datasource::getList(
            'mails',
            $fields,
            false,
            $this->addressCondition()
        );

        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        // The accounts the page's rows were for, named in one query.
        $names = [];
        if ($recordsUser) {
            $at    = array_search('userid', $fields, true);
            $names = $this->usernames(array_map(static fn (array $row): int => (int) (array_values($row)[$at] ?? 0), $result[$dataKey]));
        }
        foreach ($result[$dataKey] as &$row) {
            unset($row['DT_RowId']);
            $mail    = array_combine($fields, array_slice(array_values($row), 0, count($fields)));
            $id      = (int) $mail['id'];
            $status  = (int) $mail['status'];
            $showUrl = adminUrl('emails/show/') . $id;

            $cells = [
                '<a href="' . $showUrl . '">' . $id . '</a>',
                self::e($mail['tomail']),
            ];
            if ($recordsUser) {
                $uid     = (int) ($mail['userid'] ?? 0);
                $cells[] = $uid > 0
                    ? '<a href="' . self::e(adminUrl('users/view/') . $uid) . '">' . self::e($names[$uid] ?? ('#' . $uid)) . '</a>'
                    : '';
            }
            $cells = array_merge($cells, [
                '<a href="' . $showUrl . '">' . self::e($mail['subject']) . '</a>',
                self::e($mail['module']),
                // The column is a Unix integer. Printed raw it is a number nobody reads, which
                // is what this screen was doing.
                (int) $mail['date'] > 0 ? date('Y-m-d H:i', (int) $mail['date']) : '',
                $this->statusBadge($status, $mail),
            ]);
            foreach ($extra as $column) {
                $cells[] = self::e($mail[$column] ?? '');
            }
            $cells[] = $this->trackingCell($id);
            $cells[] = Icon::link($showUrl, 'view', t('Open this email'))
                . ($this->mayResend($mail)
                    ? Icon::postButton(
                        adminUrl('emails/resend/') . $id,
                        'retry',
                        t('Send this email again'),
                        ['data-confirm' => t('Send this email again?')]
                    )
                    : '');
            $row = $cells;
        }
        unset($row);

        return \Pramnos\Http\Response::json($result);
    }

    /**
     * The statuses a message has, as `[value => label]` — the filter's options and the badges'
     * words. An application with statuses of its own adds them:
     *
     * ```php
     * protected function statuses(): array
     * {
     *     return parent::statuses() + [3 => t('Canceled')];
     * }
     * ```
     *
     * @return array<int, string>
     */
    protected function statuses(): array
    {
        return [
            \Pramnos\Messaging\Mail::STATUS_FAILED => t('Failed'),
            \Pramnos\Messaging\Mail::STATUS_SENT   => t('Sent'),
            \Pramnos\Messaging\Mail::STATUS_QUEUED => t('Queued'),
        ];
    }

    /**
     * A row's status, as the badge in its cell. A failed message carries its error in the title.
     *
     * @param array<string, mixed> $row The row as read: its columns by name
     */
    protected function statusBadge(int $status, array $row): string
    {
        $label = $this->statuses()[$status] ?? (string) $status;
        $class = match ($status) {
            \Pramnos\Messaging\Mail::STATUS_SENT   => 'pf-state pf-state-on',
            \Pramnos\Messaging\Mail::STATUS_FAILED => 'pf-state pf-state-off',
            default                                  => 'pf-state',
        };
        $error = $status === \Pramnos\Messaging\Mail::STATUS_FAILED ? $this->errorOf($row) : '';

        return '<span class="' . $class . '"' . ($error !== '' ? ' title="' . self::e($error) . '"' : '') . '>'
            . self::e($label) . '</span>';
    }

    /**
     * Why a message failed, from its `extrainfo` — which holds whatever wrote it last: the
     * outbox's JSON (its `last_error` after a retry), an application's serialized array (its
     * `error`), or the reason as text.
     *
     * @param array<string, mixed> $row
     */
    protected function errorOf(array $row): string
    {
        $raw  = trim((string) ($row['extrainfo'] ?? ''));
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return (string) ($json['last_error'] ?? $json['error'] ?? $json['note'] ?? '');
        }
        $serialized = str_starts_with($raw, 'a:') ? @unserialize($raw, ['allowed_classes' => false]) : false;
        if (is_array($serialized)) {
            return (string) ($serialized['error'] ?? '');
        }

        return $raw;
    }

    /**
     * Columns of the application's own to show in the list, as `[label => column]`.
     *
     * Empty here. A wrapper overrides it:
     *
     * ```php
     * protected function extraColumns(): array
     * {
     *     return ['From' => 'frommail', 'Sender name' => 'fromname', 'Recipient name' => 'toname'];
     * }
     * ```
     *
     * Shown and searched as text, escaped; not sortable.
     *
     * @return array<string, string>
     */
    protected function extraColumns(): array
    {
        return [];
    }

    /**
     * Whether a message may be sent again. Not one that went — sending it twice is the one thing a
     * recipient notices — unless an application says otherwise ("it never arrived"); a resend of
     * a sent message queues a new one and leaves the record of the first.
     *
     * @param array<string, mixed> $row
     */
    protected function mayResend(array $row): bool
    {
        return (int) ($row['status'] ?? -1) !== \Pramnos\Messaging\Mail::STATUS_SENT;
    }

    /**
     * {@see extraColumns()} with only names a column can have: the list builds its query from them.
     *
     * @return array<string, string>
     */
    private function applicationColumns(): array
    {
        return array_filter(
            $this->extraColumns(),
            static fn ($column): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $column) === 1
        );
    }

    /** Escaped for HTML. */
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
            return '<span class="pf-muted" title="' . self::e(t('This message was not tracked')) . '">—</span>';
        }

        $parts = [];

        if ((int) $row['clicks'] > 0) {
            $parts[] = '<span class="pf-state pf-state-on" title="'
                . self::e(t('Links followed. A click is a person — no proxy follows a link.')) . '">'
                . self::e(t('%d clicked', (int) $row['clicks'])) . '</span>';
        }

        if ((int) $row['opens'] > 0) {
            $parts[] = '<span class="pf-state" title="'
                . self::e(t('Fetches that did not come from a known mailbox proxy')) . '">'
                . self::e(t('%d opened', (int) $row['opens'])) . '</span>';
        }

        if ((int) $row['proxy_opens'] > 0) {
            $parts[] = '<span class="pf-state pf-state-off" title="'
                . self::e(t('Fetched by a mailbox provider on delivery — Apple Mail Privacy Protection and the like. Not a reader.')) . '">'
                . self::e(t('%d prefetched', (int) $row['proxy_opens'])) . '</span>';
        }

        return $parts === []
            ? '<span class="pf-muted" title="' . self::e(t('Tracked, and nothing has come back')) . '">' . self::e(t('tracked')) . '</span>'
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
                ->table('pramnos.emailtracking')
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
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $mailId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($mailId <= 0) {
            $this->addError(t('The id in that link is not valid.'));
            $this->redirect(adminUrl('emails'));
            return null;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#mails')
            ->where('id', $mailId)
            ->first();

        if (!$result || $result->numRows === 0) {
            $this->addError(t('That record no longer exists.'));
            $this->redirect(adminUrl('emails'));
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Email Preview — %s', htmlspecialchars((string) ($result->fields['subject'] ?? ''), ENT_QUOTES));

        $view       = $this->getView('emails');
        // The body as it was kept: in the row, or in the file its path names.
        $mail            = (array) $result->fields;
        $mail['content'] = \Pramnos\Email\Body::of($mail);
        $view->mail      = $mail;
        // The same words as the list: a failed message reads as failed, with its error.
        $view->statusBadge = $this->statusBadge((int) ($mail['status'] ?? 0), $mail);
        // "← Back" goes where the reader came from — an account's page, a filtered list — when
        // that was a page of this site and not this one; the list otherwise.
        $view->backUrl = $this->backUrl();

        /*
         * Everything else that is knowable about this message.
         *
         * The body was stored as it was sent, so it can be read back: the pixel it carries, the
         * actions Gmail would draw from it, where each link really goes, and what a text-only
         * client sees. None of that is visible from the sending code — a template that lost its
         * unsubscribe link and one that kept it look identical from there.
         */
        $view->report = new \Pramnos\Email\MessageReport(
            $mail,
            $this->trackingFor($mailId)
        );
        // Whether mail still goes to this address, and the two things an operator does about it.
        $view->bounce = \Pramnos\Email\Bounces::available() && \Pramnos\Email\Bounces::hash((string) ($mail['tomail'] ?? '')) !== null ? [
            'refusal' => \Pramnos\Email\Bounces::refusal((string) $mail['tomail']),
            'action'  => adminUrl('emails/bounce/') . $mailId,
            'csrf'    => \Pramnos\Http\Session::getInstance()->getCsrfToken(),
        ] : null;

        return $view->display('show');
    }

    /** The page a message was opened from, when it is one of this site's; the list otherwise. */
    protected function backUrl(): string
    {
        $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $base     = defined('sURL') ? rtrim((string) sURL, '/') : '';
        if ($referrer !== '' && $base !== ''
            && \Pramnos\DevPanel\DevPanelController::isReturnable($referrer, $base)
            && !str_contains(strtolower((string) parse_url($referrer, PHP_URL_PATH)), '/emails/show/')
        ) {
            return $referrer;
        }

        return adminUrl('emails');
    }

    /**
     * Send a message again — one {@see mayResend()} allows.
     *
     * A message that never went is queued again in place, dated now so the outbox's deadline
     * counts from the resend rather than from the first attempt. One that went — when an
     * application allows it — is queued as a **new** row, a copy, and the record of the first
     * stays as it was: the history says it was sent twice.
     */
    public function resend(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $mailId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($mailId <= 0) {
            $this->addError(t('The id in that link is not valid.'));
            $this->redirect(adminUrl('emails'));
            return;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()->table('#PREFIX#mails')->where('id', $mailId)->first();
        if (!$result || $result->numRows === 0) {
            $this->addError(t('That record no longer exists.'));
            $this->redirect(adminUrl('emails'));
            return;
        }
        $row = (array) $result->fields;

        if (!$this->mayResend($row)) {
            $this->addError(t('This email is not sent again.'));
            $this->redirect(adminUrl('emails/show/') . $mailId);
            return;
        }

        if ((int) $row['status'] !== \Pramnos\Messaging\Mail::STATUS_SENT) {
            $db->queryBuilder()->table('#PREFIX#mails')->where('id', $mailId)
                ->update(['status' => \Pramnos\Messaging\Mail::STATUS_QUEUED, 'date' => time()]);
            $this->addMessage(t('Queued again.'));
            $this->redirect(adminUrl('emails/show/') . $mailId);
            return;
        }

        $this->addMessage(t('Queued again, as a new message.'));
        $this->redirect(adminUrl('emails/show/') . $this->queueCopy($row));
    }

    /**
     * A sent message as a new queued row: every column it has — an application's own included —
     * with its body read back from wherever it was kept and written into the row, what it was
     * queued with, and none of its sending.
     *
     * @param array<string, mixed> $row
     * @return int The new row's id
     */
    protected function queueCopy(array $row): int
    {
        $body  = \Pramnos\Email\Body::of($row);
        $kept  = json_decode((string) ($row['extrainfo'] ?? ''), true);
        $copy  = array_diff_key($row, array_flip(['id', 'status', 'date', 'content', 'path', 'hash', 'extrainfo', 'bodyvars']));
        $copy  = array_filter($copy, static fn ($key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
        $db    = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()->table('#PREFIX#mails')->insert($copy + [
            'status'    => \Pramnos\Messaging\Mail::STATUS_QUEUED,
            'date'      => time(),
            'content'   => $body,
            'path'      => '',
            'hash'      => md5($body),
            // What it was queued with — copies, attachment, headers — and not its retries.
            'extrainfo' => is_array($kept) && is_array($kept['outbox'] ?? null)
                ? (string) json_encode(['outbox' => $kept['outbox']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '',
        ]);

        return (int) $db->getInsertId();
    }

    /**
     * Clear the recipient's bounces, or stop all mail to it — `op` = `clear` | `block`, posted
     * from the message's page, with an optional `reason`.
     */
    public function bounce(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $mailId = (int) \Pramnos\Http\Request::staticGetOption();
        $back   = adminUrl('emails/show/') . $mailId;
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
            || !\Pramnos\Http\Session::getInstance()->verifyCsrfToken((string) ($_POST['_csrf_token'] ?? ''))) {
            $this->addError(t('That form had expired. Please try again.'));
            $this->redirect($back);
            return;
        }

        $row = $mailId > 0
            ? \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#mails')->select(['tomail'])->where('id', $mailId)->first()
            : null;
        if (!$row || $row->numRows === 0) {
            $this->addError(t('That record no longer exists.'));
            $this->redirect(adminUrl('emails'));
            return;
        }

        $address = (string) $row->fields['tomail'];
        $op      = (string) ($_POST['op'] ?? '');
        $done    = match ($op) {
            'clear' => \Pramnos\Email\Bounces::clear($address),
            'block' => \Pramnos\Email\Bounces::block($address, trim((string) ($_POST['reason'] ?? '')) ?: t('Blocked from the email history')),
            default => false,
        };
        if ($done) {
            $this->addMessage($op === 'clear' ? t('Mail goes to %s again.', $address) : t('No mail goes to %s now.', $address));
        } else {
            $this->addError(t('Nothing was changed for %s.', $address));
        }
        $this->redirect($back);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

}
