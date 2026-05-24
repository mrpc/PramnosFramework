<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;

/**
 * Admin controller for viewing sent email history.
 *
 * Operates on the `mails` table created by the `create_mails_table` migration
 * (messaging feature). This controller is read-only by design — emails that
 * have already been sent cannot be unsent, and the history must remain intact.
 *
 * Actions:
 *   - display()   — paginated DataTable of sent/failed/queued emails
 *   - show($id)   — HTML preview of email content (body rendered in iframe)
 *   - resend($id) — re-queue a failed email for re-delivery
 *
 * All actions require authentication + usertype >= 80.
 *
 * Scaffold wrappers at `src/Controllers/Emails.php` (always scaffolded).
 *
 * @package     PramnosFramework
 * @subpackage  Application\Controllers
 */
class EmailsController extends Controller
{
    /** Minimum usertype to access any emails action. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'show', 'resend']);
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

        $db   = \Pramnos\Framework\Factory::getDatabase();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $qb = $db->queryBuilder()
            ->table('mails')
            ->select(['id', 'status', 'tomail', 'toname', 'subject', 'date', 'module']);

        $filterStatus = isset($_GET['status']) ? (int) $_GET['status'] : null;
        if ($filterStatus !== null) {
            $qb->where('status', $filterStatus);
        }

        $view        = $this->getView('emails');
        $view->mails = $qb->orderBy('date', 'desc')->forPage($page, 50)->getAll();
        $view->total = (clone $qb)->count();
        $view->page  = $page;

        return $view->display();
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

        $mailId = (int) ($id ?? 0);
        if ($mailId <= 0) {
            $this->redirect(sURL . 'emails?error=invalid_id');
            return null;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('mails')
            ->where('id', $mailId)
            ->first();

        if (!$result || $result->numRows === 0) {
            $this->redirect(sURL . 'emails?error=not_found');
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

        $mailId = (int) ($id ?? 0);
        if ($mailId <= 0) {
            $this->redirect(sURL . 'emails?error=invalid_id');
            return;
        }

        $db = \Pramnos\Framework\Factory::getDatabase();

        // Only re-queue failed emails (status=0) — sent/queued emails are ignored
        $db->queryBuilder()
            ->table('mails')
            ->where('id', $mailId)
            ->where('status', 0)
            ->update(['status' => 2]);

        $this->redirect(sURL . 'emails/show/' . $mailId . '?message=requeued');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Redirects to sURL if the current user's usertype is below $minType.
     * Returns true if the redirect was issued (caller should return early).
     */
    protected function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if ($user === null || (int) $user->usertype < $minType) {
            $this->redirect(sURL);
            return true;
        }

        return false;
    }
}
