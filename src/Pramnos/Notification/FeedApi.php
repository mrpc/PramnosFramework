<?php

declare(strict_types=1);

namespace Pramnos\Notification;

use Pramnos\Routing\Router;

/**
 * The signed-in person's notifications as JSON, for a front end that draws its own — a SPA, a
 * mobile application — with the API's credentials: a bearer token, or the page's session.
 *
 * Mounted with one line in the application's API routes:
 *
 * ```php
 * \Pramnos\Notification\FeedApi::routes($r);
 * ```
 *
 *   - `GET  /notifications?page=1&per_page=20&unread=1&group=tasks`   one page
 *   - `GET  /notifications/count`                                      `{unread, groups}`
 *   - `POST /notifications/read`  `{id}`                                one read
 *   - `POST /notifications/readall`  `{group}`                          every unread one read
 *
 * What {@see \Pramnos\Application\Controllers\NotificationsController} answers a server-rendered
 * page, here in the API's shape: `['status' => …, 'data' => …]` or `['status' => …, 'error' => …]`.
 */
class FeedApi
{
    /** Mount the four routes, under `$path`. */
    public static function routes(Router $r, string $path = '/notifications'): void
    {
        $path = '/' . trim($path, '/');

        $r->get($path . '/count', static fn () => (new static())->count());
        $r->post($path . '/read', static fn () => (new static())->read());
        $r->post($path . '/readall', static fn () => (new static())->readAll());
        $r->get($path, static fn () => (new static())->list());
    }

    /** One page of the feed, newest first. */
    public function list(): array
    {
        $feed = $this->feed();
        if ($feed === null) {
            return self::refused();
        }

        return ['status' => 200, 'data' => $feed->list(
            (int) ($_GET['page'] ?? 1),
            (int) ($_GET['per_page'] ?? 20),
            !empty($_GET['unread']),
            isset($_GET['group']) && $_GET['group'] !== '' ? (string) $_GET['group'] : null
        )];
    }

    /** Unread counts: in all, and by the groups app.php declares. */
    public function count(): array
    {
        $feed = $this->feed();
        if ($feed === null) {
            return self::refused();
        }
        $counts = $feed->unreadByGroup();
        $all    = $counts[''];
        unset($counts['']);

        return ['status' => 200, 'data' => ['unread' => $all, 'groups' => (object) $counts]];
    }

    /** Mark one read: a posted `id`. */
    public function read(): array
    {
        $feed = $this->feed();
        if ($feed === null) {
            return self::refused();
        }
        $id = (string) ($this->input()['id'] ?? '');

        return ['status' => 200, 'data' => ['read' => $feed->markRead($id), 'unread' => $feed->unreadCount()]];
    }

    /** Mark every unread one read, in a posted `group` or in all. */
    public function readAll(): array
    {
        $feed = $this->feed();
        if ($feed === null) {
            return self::refused();
        }
        $group = (string) ($this->input()['group'] ?? '');

        return ['status' => 200, 'data' => ['marked' => $feed->markAllRead($group === '' ? null : $group), 'unread' => $feed->unreadCount()]];
    }

    /** The signed-in person's feed, or null for nobody. */
    protected function feed(): ?Feed
    {
        $user = \Pramnos\User\User::current();
        $id   = is_object($user) ? (int) ($user->userid ?? 0) : 0;

        return $id > 1 ? new Feed($id) : null;
    }

    /**
     * The request's fields: a form post, or a JSON body as a SPA sends one.
     *
     * @return array<string, mixed>
     */
    protected function input(): array
    {
        if ($_POST !== []) {
            return $_POST;
        }
        $json = json_decode((string) file_get_contents('php://input'), true);

        return is_array($json) ? $json : $_GET;
    }

    /** @return array{status: int, error: string} */
    private static function refused(): array
    {
        return ['status' => 401, 'error' => 'Sign in to see your notifications.'];
    }
}
