<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Notification\Feed;

/**
 * `/Notifications` — the signed-in person's feed, as JSON, for the bell `pf-utils.js` drives.
 *
 *   - `GET  /Notifications?page=1&unread=1&group=tasks`  one page: items, total, unread
 *   - `GET  /Notifications/count`                        unread counts, in all and by group
 *   - `GET  /Notifications?type=…&from=Y-m-d&to=Y-m-d&types=1`  the history, narrowed; `types` lists the kinds one has had
 *   - `GET  /Notifications?collapse=1`                   the first page with a group's unread as one item, where app.php collapses it
 *   - `POST /Notifications/read?id=…`                    mark one read, or several: `id=a,b` (a collapsed item's)
 *   - `POST /Notifications/readall?group=tasks`          mark every unread one read
 *   - `POST /Notifications/settle?about=todo:42,…`       mark read one's own about those (`data-pf-settles` on a page)
 *   - `POST /Notifications/snooze?id=…&for=1h|3h|tomorrow`  put one off: back unread then
 *   - `GET  /Notifications/preferences`                  one's choices: each type on or off, reading what is in sight
 *   - `POST /Notifications/preferences`                  `types[<class>]=1|0`, `readOnView=1|0`, `quiet_from`/`quiet_to` (H:i, '' none); with `back`, back there
 *
 * Each takes `except=conversations,…` too: groups the bell leaves out, of its list, its counts and
 * its "mark all read".
 *
 * An MVC controller rather than an API route, on purpose: it answers with the page's own session,
 * and a write with the page's CSRF token (`X-CSRF-Token`, as `pfApiHeaders` sends), so it works in
 * any application that has a session — the site, an administration area, or an administration
 * with a front controller of its own — without an API key or a token. An application puts it on an
 * address with `src/Controllers/Notifications.php` (or under `src/Admin/Controllers/`) extending
 * this class.
 */
class NotificationsController extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'count', 'read', 'readall', 'settle', 'preferences', 'snooze']);
        // A write: a POST with the page's token, refused otherwise before it runs.
        $this->addWriteAction(['read', 'readall', 'settle', 'snooze']);
        parent::__construct($application);
    }

    /** One page of the feed. */
    public function display(): mixed
    {
        $feed = $this->feed();
        if ($feed === null) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }

        return $this->answer($feed->list(
            (int) ($_GET['page'] ?? 1),
            (int) ($_GET['per_page'] ?? 20),
            !empty($_GET['unread']),
            isset($_GET['group']) ? (string) $_GET['group'] : null,
            $this->exceptedGroups(),
            !empty($_GET['collapse']),
            array_filter(['type' => (string) ($_GET['type'] ?? ''), 'from' => (string) ($_GET['from'] ?? ''), 'to' => (string) ($_GET['to'] ?? '')])
        ) + (!empty($_GET['types']) ? ['types' => (object) $feed->typesSeen()] : []));
    }

    /** Unread counts: `unread` in all, and `groups` by the groups app.php declares. */
    public function count(): mixed
    {
        $feed = $this->feed();
        if ($feed === null) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        $counts = $feed->unreadByGroup($this->exceptedGroups());
        $all    = $counts[''];
        unset($counts['']);

        return $this->answer(['unread' => $all, 'groups' => (object) $counts]);
    }

    /** Mark one read, or several — `?id=` or a posted `id`, comma-separated. */
    public function read(): mixed
    {
        $feed = $this->feed();
        if ($feed === null) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        $read = false;
        foreach (array_slice(explode(',', (string) ($_POST['id'] ?? $_GET['id'] ?? '')), 0, 500) as $id) {
            $read = $feed->markRead(trim($id)) || $read;
        }

        return $this->answer(['read' => $read, 'unread' => $feed->unreadCount(null, $this->exceptedGroups())]);
    }

    /** Put one off: `id`, and `for` (1h, 3h, tomorrow) or `until` (unix). */
    public function snooze(): mixed
    {
        $feed = $this->feed();
        if ($feed === null) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        $until = isset($_POST['until']) || isset($_GET['until']) ? (int) ($_POST['until'] ?? $_GET['until']) : (string) ($_POST['for'] ?? $_GET['for'] ?? '');

        return $this->answer(['snoozed' => $feed->snooze((string) ($_POST['id'] ?? $_GET['id'] ?? ''), $until), 'unread' => $feed->unreadCount(null, $this->exceptedGroups())]);
    }

    /**
     * Mark read the signed-in person's notifications about what a page shows — `about=todo:42,…`,
     * as `data-pf-settles` on the page sends it ({@see Feed::settleAbout()}). Their own only.
     */
    public function settle(): mixed
    {
        $user = \Pramnos\User\User::current();
        $id   = is_object($user) ? (int) ($user->userid ?? 0) : 0;
        if ($id <= 1) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        $keys = array_slice(array_map('trim', explode(',', (string) ($_POST['about'] ?? $_GET['about'] ?? ''))), 0, 50);

        return $this->answer(['settled' => Feed::settleAbout($keys, $id)]);
    }

    /** Mark every unread one read, in `?group=` or in all. */
    public function readall(): mixed
    {
        $feed = $this->feed();
        if ($feed === null) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        $group = (string) ($_POST['group'] ?? $_GET['group'] ?? '');

        $except = $this->exceptedGroups();

        return $this->answer(['marked' => $feed->markAllRead($group === '' ? null : $group, $except), 'unread' => $feed->unreadCount(null, $except)]);
    }

    /**
     * The groups the bell leaves out: `except=a,b`, or `except[]=a`, asked or posted.
     *
     * @return list<string>
     */
    protected function exceptedGroups(): array
    {
        $raw = $_POST['except'] ?? $_GET['except'] ?? [];
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(static fn ($g): string => trim((string) $g), $raw), static fn (string $g): bool => $g !== ''));
    }

    /**
     * One's choices about notifications: read them (GET), or change them (POST, with the page's
     * token): `types[<class>]` 1 or 0 for each declared type, `readOnView` 1 or 0. A form that
     * names `back` (a path on this site) is sent back there with a flash; otherwise JSON.
     */
    public function preferences(): mixed
    {
        $user = \Pramnos\User\User::current();
        if (!is_object($user) || (int) ($user->userid ?? 0) <= 1) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isVerifiedWrite()) {
                return $this->answer(['error' => 'The session\'s token is missing.'], 403);
            }
            foreach ((array) ($_POST['types'] ?? []) as $type => $on) {
                \Pramnos\Notification\Preferences::set($user, (string) $type, (string) $on === '1');
            }
            if (isset($_POST['readOnView'])) {
                \Pramnos\Notification\Preferences::setReadOnView($user, (string) $_POST['readOnView'] === '1');
            }
            if (array_key_exists('quiet_from', $_POST) || array_key_exists('quiet_to', $_POST)) {
                \Pramnos\Notification\Preferences::setQuiet($user, (string) ($_POST['quiet_from'] ?? ''), (string) ($_POST['quiet_to'] ?? ''));
            }
            $back = (string) ($_POST['back'] ?? '');
            if (preg_match('~^/(?!/)[^\s]*$~', $back) === 1) {
                $this->addMessage(t('Saved.'));
                $this->redirect($back);

                return null;
            }
        }

        return $this->answer(\Pramnos\Notification\Preferences::of($user));
    }

    /** The signed-in person's feed, or null. */
    protected function feed(): ?Feed
    {
        $user = \Pramnos\User\User::current();
        $id   = is_object($user) ? (int) ($user->userid ?? 0) : 0;

        return $id > 1 ? new Feed($id) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function answer(array $data, int $status = 200): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');

        return \Pramnos\Http\Response::json($data, $status);
    }
}
