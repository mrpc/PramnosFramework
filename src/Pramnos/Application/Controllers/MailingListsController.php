<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Email\MailingList;
use Pramnos\Http\Response;

/**
 * `/admin/MailingLists` — who is on each opt-in list, and in what state.
 *
 * Every list with its counts (waiting to confirm, confirmed, left), and one list's subscribers,
 * paged and searchable by address. Two things can be done to a row: send a pending address its
 * confirmation again, and take an address off the list — the same way leaving by the footer link
 * does, with the opt-out and the consent trail. The confirmed addresses export as CSV.
 *
 * Nobody is *added* here. An opt-in list holds the people who asked, with the proof that they
 * did; an administrator typing an address in would be a subscriber with no consent behind them.
 */
class MailingListsController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.mailinglists';

    /** The framework's default floor; see AdminAccess::defaultUsertype(). */
    protected int $requiredUserType = 98;

    /** Subscribers on one page. */
    public const PAGE = 50;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'resend', 'unsubscribe', 'export']);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['resend', 'unsubscribe']);
        parent::__construct($application);
    }

    /**
     * The lists and their counts, and the chosen list's subscribers.
     *
     * `?list=` picks the list (the first one otherwise), `?status=` narrows to one state, `?q=`
     * searches addresses, `?page=` pages.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Mailing lists';

        $lists  = $this->mailingList()->lists();
        $list   = (string) ($_GET['list'] ?? '');
        $list   = in_array($list, $lists, true) ? $list : (string) ($lists[0] ?? '');
        $status = (string) ($_GET['status'] ?? '');
        $search = trim((string) ($_GET['q'] ?? ''));
        $page   = max(1, (int) ($_GET['page'] ?? 1));

        $view          = $this->getView('mailinglists');
        $view->lists   = $lists;
        $view->counts  = $this->mailingList()->counts();
        $view->list    = $list;
        $view->status  = $status;
        $view->search  = $search;
        $view->page    = $page;
        $view->perPage = static::PAGE;
        $view->result  = $list !== ''
            ? $this->mailingList()->subscribers($list, $status, $search, $page, static::PAGE)
            : ['rows' => [], 'total' => 0];

        return $view->display();
    }

    /** Send a pending subscriber the confirmation mail again. */
    public function resend(mixed $id = null): void
    {
        $id = (int) \Pramnos\Http\Request::staticGetOption();

        if ($this->mailingList()->resendConfirmation($id)) {
            $this->addMessage('The confirmation was sent again.');
        } else {
            $this->addError('Only an address waiting to confirm can be sent the confirmation again.');
        }
        $this->redirect($this->back());
    }

    /** Take a subscriber off the list. */
    public function unsubscribe(mixed $id = null): void
    {
        $id = (int) \Pramnos\Http\Request::staticGetOption();

        if ($this->mailingList()->unsubscribeSubscriber($id)) {
            $this->addMessage('The address was taken off the list.');
        } else {
            $this->addError('That address is not on the list.');
        }
        $this->redirect($this->back());
    }

    /**
     * The confirmed subscribers of `?list=`, as CSV: address, language, when they confirmed, and
     * the account where there is one.
     */
    public function export(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $list = (string) ($_GET['list'] ?? '');
        if (!in_array($list, $this->mailingList()->lists(), true)) {
            return Response::make('No such list.', 404);
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['email', 'language', 'confirmed_at', 'userid'], ',', '"', '\\');
        foreach ($this->mailingList()->confirmedExport($list) as $row) {
            fputcsv($out, [
                $row['email'],
                $row['language'],
                $row['confirmed_at'] !== null ? date('c', (int) $row['confirmed_at']) : '',
                $row['userid'] ?? '',
            ], ',', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return Response::make($csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $list) . '-' . date('Y-m-d') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** The mailing lists, as a seam. */
    protected function mailingList(): MailingList
    {
        return new MailingList();
    }

    /** Back to the list the row was on, which the forms carry. */
    private function back(): string
    {
        $query = array_filter([
            'list'   => (string) ($_POST['list'] ?? ''),
            'status' => (string) ($_POST['status'] ?? ''),
            'q'      => (string) ($_POST['q'] ?? ''),
            'page'   => (string) ($_POST['page'] ?? ''),
        ], static fn (string $v): bool => $v !== '');

        return adminUrl('MailingLists') . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}
