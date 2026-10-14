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
 *   - `POST /Notifications/read?id=…`                    mark one read
 *   - `POST /Notifications/readall?group=tasks`          mark every unread one read
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
        $this->addAuthAction(['display', 'count', 'read', 'readall']);
        // A write: a POST with the page's token, refused otherwise before it runs.
        $this->addWriteAction(['read', 'readall']);
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
            isset($_GET['group']) ? (string) $_GET['group'] : null
        ));
    }

    /** Unread counts: `unread` in all, and `groups` by the groups app.php declares. */
    public function count(): mixed
    {
        $feed = $this->feed();
        if ($feed === null) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        $counts = $feed->unreadByGroup();
        $all    = $counts[''];
        unset($counts['']);

        return $this->answer(['unread' => $all, 'groups' => (object) $counts]);
    }

    /** Mark one read: `?id=` or a posted `id`. */
    public function read(): mixed
    {
        $feed = $this->feed();
        if ($feed === null) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        $id = (string) ($_POST['id'] ?? $_GET['id'] ?? '');

        return $this->answer(['read' => $feed->markRead($id), 'unread' => $feed->unreadCount()]);
    }

    /** Mark every unread one read, in `?group=` or in all. */
    public function readall(): mixed
    {
        $feed = $this->feed();
        if ($feed === null) {
            return $this->answer(['error' => 'Sign in to see your notifications.'], 401);
        }
        $group = (string) ($_POST['group'] ?? $_GET['group'] ?? '');

        return $this->answer(['marked' => $feed->markAllRead($group === '' ? null : $group), 'unread' => $feed->unreadCount()]);
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
