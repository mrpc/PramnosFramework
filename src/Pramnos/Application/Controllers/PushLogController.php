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

    /** One record of this screen, for the side panel: see Controller::$assistSubject. */
    protected string $assistSubject = 'push notification';

    /** The usertype floor; 0 is the administration area's — {@see \Pramnos\Auth\AdminAccess::defaultUsertype()}, the `super` role unless set. */
    protected int $requiredUserType = 0;

    /** How many rows one page of this screen shows. */
    public const PAGE = 200;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'view', 'test']);
        // POST with the session's token: it sends a notification to somebody's phone.
        $this->addWriteAction(['test']);
        parent::__construct($application);
    }

    /**
     * The recent attempts, with the week's shape above them.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = t('Push notifications');

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

        $rows    = $this->rows(static::PAGE, $filter);
        $summary = $this->withDevices($this->deviceSummary());

        $view          = $this->getView('pushlog');
        $view->rows    = $rows;
        $view->stats   = $this->stats();
        $view->userId  = $userId;
        $view->only    = $only;
        $view->names   = $this->names(array_merge(array_column($rows, 'userid'), array_column($summary, 'userid')));
        $view->summary = $summary;

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
        if ($this->requireMinUserType($this->adminFloor())) {
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
        // A test notification's receipt: whether the device itself said it arrived.
        $token              = \Pramnos\Push\TestPush::tokenOf($row);
        $view->receipt      = $token === null ? null : $this->testPush()->status($token);

        return $view->display('view');
    }

    /**
     * Send a test notification to the device a logged push went to, and open the test's page.
     *
     * The answer to "the log says delivered and they saw nothing": a test whose page then shows
     * whether the device itself confirmed it arrived.
     */
    public function test(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $row  = $this->find((int) \Pramnos\Http\Request::staticGetOption());
        $hash = (string) ($row['endpoint_hash'] ?? '');
        if ($row === null || $hash === '') {
            $this->addError('There is no device to send a test to.');
            $this->redirect(adminUrl('PushLog'));
            return null;
        }

        $tests = $this->testPush()->send((int) $row['userid'], $hash);
        if ($tests === []) {
            $this->addError('That device is no longer subscribed, so a test cannot reach it.');
            $this->redirect(adminUrl('PushLog/view/') . (int) $row['pushid']);
            return null;
        }

        \Pramnos\Logs\Logger::log(
            'Push test sent to account ' . (int) $row['userid'] . ' by '
            . (string) (\Pramnos\User\User::getCurrentUser()->username ?? 'an administrator'),
            'auth'
        );

        $logged = $this->rows(1, ['tag' => \Pramnos\Push\Notifications\TestPushNotification::TAG_PREFIX . $tests[0]['token']]);
        $this->addMessage('Test sent: ' . $tests[0]['outcome']['label'] . '. This page shows when the device confirms it.');
        $this->redirect(adminUrl('PushLog/view/') . (int) ($logged[0]['pushid'] ?? $row['pushid']));

        return null;
    }

    /** The test sender, as a seam. */
    protected function testPush(): \Pramnos\Push\TestPush
    {
        return new \Pramnos\Push\TestPush();
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

    /** {@see Log::deviceSummary()}, as a seam. @return list<array<string, mixed>> */
    protected function deviceSummary(): array
    {
        return Log::deviceSummary();
    }

    /**
     * {@see deviceSummary()}'s rows with each one's `device` label added — a lookup per row of a
     * page capped at {@see Log::deviceSummary()}'s own limit (50), not per row of `pushlog`, which
     * is the lookup worth batching and already is one query in `deviceSummary()` itself.
     *
     * @param  list<array<string, mixed>> $summary
     * @return list<array<string, mixed>>
     */
    protected function withDevices(array $summary): array
    {
        foreach ($summary as &$row) {
            $device       = $this->subscription((int) $row['userid'], (string) $row['endpoint_hash']);
            $row['device'] = $device === null ? t('Subscription gone') : static::describeUserAgent((string) ($device['user_agent'] ?? ''));
        }

        return $summary;
    }

    /**
     * A name for each account id — not `Conversations\People`, so this screen carries no
     * dependency on a feature push notifications do not need. Firstname and lastname when
     * there are any, the username otherwise, `#id` for neither — never a bare id alone, which
     * is unreadable to whoever is looking at this screen to find out who did not get a push.
     *
     * @param list<int> $userIds
     * @return array<int, string>
     */
    protected function names(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        try {
            $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
                ->select(['userid', 'username', 'firstname', 'lastname'])->whereIn('userid', $userIds)->getAllOrFail();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $full = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
            $out[(int) $row['userid']] = $full !== '' ? $full : ((string) ($row['username'] ?? '') ?: ('#' . (int) $row['userid']));
        }

        return $out;
    }

    /**
     * A device, in words, from its subscription's `user_agent`: phone or computer, OS, browser —
     * enough to tell "the owner's phone" from "a forgotten desktop session" without the raw
     * string, which is unreadable on this screen's width. A sniff, not exact: good enough for an
     * operator's summary, not a security decision.
     */
    public static function describeUserAgent(string $userAgent): string
    {
        if ($userAgent === '') {
            return t('Unknown device');
        }
        $kind = preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $userAgent) === 1 ? t('Phone') : t('Computer');
        $os   = match (true) {
            preg_match('/Android/i', $userAgent) === 1 => 'Android',
            preg_match('/iPhone|iPad|iPod/i', $userAgent) === 1 => 'iOS',
            preg_match('/Windows/i', $userAgent) === 1 => 'Windows',
            preg_match('/Macintosh|Mac OS X/i', $userAgent) === 1 => 'macOS',
            preg_match('/Linux/i', $userAgent) === 1 => 'Linux',
            default => '',
        };
        $browser = match (true) {
            preg_match('/Edg\//i', $userAgent) === 1 => 'Edge',
            preg_match('/OPR\/|Opera/i', $userAgent) === 1 => 'Opera',
            preg_match('/Firefox\//i', $userAgent) === 1 => 'Firefox',
            preg_match('/Chrome\//i', $userAgent) === 1 => 'Chrome',
            preg_match('/Safari\//i', $userAgent) === 1 => 'Safari',
            default => '',
        };

        return trim($kind . ' · ' . trim($os . ' ' . $browser), " \xc2\xb7");
    }
}
