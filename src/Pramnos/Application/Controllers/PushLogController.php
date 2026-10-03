<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Push\Log;

/**
 * `/admin/PushLog` — what was pushed, to whom, and what came of it.
 *
 * The screen `/admin/Emails` is for email, and there was no equivalent for notifications.
 * Asked directly: «τα push που στάλθηκαν πού τα βλέπω;» — and the answer was nowhere.
 * `pushsubscriptions` says when a browser was last reached and how many failures it has since,
 * which is a fact about the browser rather than about a message; the mass-send path writes
 * `massmessagerecipients`, which covers one path out of two. Everything a `notify()` sent left
 * no trace at all.
 *
 * Read-only by design, like the email history: a notification that has been sent cannot be
 * unsent, and the point of the screen is that the record is intact.
 *
 * ### What it deliberately shows
 *
 * The **refusals**, next to the deliveries and not on a separate screen. "No browser on this
 * account is subscribed" is the single commonest answer to *why did they not get it*, and it is
 * only useful beside the sends that did work — a screen listing only successes would make an
 * installation with no key pair look identical to one where everything is arriving.
 *
 * The endpoint is not shown, because it is not stored: whoever holds it can push to that
 * browser.
 */
class PushLogController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.pushlog';

    /** Minimum usertype. The same floor as the email history it sits beside. */
    protected int $requiredUserType = 80;

    /** How many rows one page of this screen shows. */
    public const PAGE = 200;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'view']);
        parent::__construct($application);
    }

    /**
     * The recent attempts, with the week's shape above them.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Push notifications';

        $request = new \Pramnos\Http\Request();
        $userId  = (int) $request->get('userid', 0, 'get', 'int');
        $only    = trim((string) $request->get('show', '', 'get'));

        $filter = [];

        if ($userId > 0) {
            $filter['userid'] = $userId;
        }

        if ($only === 'failed') {
            $filter['failed'] = true;
        }

        $view          = $this->getView('pushlog');
        $view->rows    = $this->rows(static::PAGE, $filter);
        $view->stats   = $this->stats();
        $view->userId  = $userId;
        $view->only    = $only;

        return $view->display();
    }

    /**
     * One attempt, in full: what was sent, what the push service answered and what that means,
     * the device it went to, and the other attempts to that device.
     *
     * The list answers "what happened"; this answers "why did this person not see it". The
     * device's history is the evidence: a run of deliveries to a browser the person says shows
     * nothing points at the device, a run of failures at the subscription.
     */
    public function view(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $pushId = (int) \Pramnos\Http\Request::staticGetOption();
        $row    = $this->find($pushId);
        if ($row === null) {
            $this->addError('That notification is not in the push log.');
            $this->redirect(adminUrl('PushLog'));
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Push notification #' . $pushId;

        $hash = (string) ($row['endpoint_hash'] ?? '');

        $view               = $this->getView('pushlog');
        $view->row          = $row;
        $view->outcome      = Log::outcome($row);
        $view->subscription = $this->subscription((int) ($row['userid'] ?? 0), $hash);
        $view->history      = $hash === '' ? [] : $this->rows(20, ['endpoint_hash' => $hash]);

        return $view->display('view');
    }

    /**
     * One row, as a seam.
     *
     * @return array<string, mixed>|null
     */
    protected function find(int $pushId): ?array
    {
        return Log::find($pushId);
    }

    /**
     * The subscription a row went to, as a seam.
     *
     * @return array<string, mixed>|null
     */
    protected function subscription(int $userId, string $hash): ?array
    {
        return \Pramnos\Push\Subscriptions::describe($userId, $hash);
    }

    /**
     * The rows, as a seam.
     *
     * @param  array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    protected function rows(int $limit, array $filter): array
    {
        return Log::recent($limit, $filter);
    }

    /** @return array<string, int> */
    protected function stats(): array
    {
        return Log::stats();
    }
}
