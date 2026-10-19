<?php

declare(strict_types=1);

namespace Pramnos\Notification;

use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * One person's in-app notifications: what the `database` channel stored, read back.
 *
 * ```php
 * $feed = new Feed($user->userid);
 * $feed->list(page: 1, unreadOnly: true, group: 'tasks');   // ['items' => …, 'total' => …, 'unread' => …]
 * $feed->unreadCount();
 * $feed->markRead($id);
 * $feed->markAllRead('tasks');
 * ```
 *
 * ## Groups
 *
 * A header shows the feed in tabs — "For me", "Tasks", "System". A notification's group is, in
 * order:
 *
 *   1. the notification classes `'notifications' => ['groups' => ['tasks' => [TaskAssigned::class]]]`
 *      in app.php lists for it — by type, so the index on `type` serves the filter;
 *   2. otherwise the `group` key its `toDatabase()` returned: `['group' => 'tasks', …]`.
 *
 * A group named in the config is filtered by type; any other by the stored JSON. A notification in
 * neither has no group: it is in the feed, and in no tab but "all".
 *
 * Every read and write is the person's own: an id that is somebody else's notification marks
 * nothing.
 */
final class Feed
{
    /** The most one page holds. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly int $userId,
        private readonly ?Database $db = null
    ) {
    }

    /**
     * One page of the feed, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int, unread: int, page: int, pages: int}
     */
    public function list(int $page = 1, int $perPage = 20, bool $unreadOnly = false, ?string $group = null): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $count = $this->query($group);
        if ($unreadOnly) {
            $count->whereNull('read_at');
        }
        $total = (int) $count->count();

        $rows = $this->query($group);
        if ($unreadOnly) {
            $rows->whereNull('read_at');
        }
        $result = $rows->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->limit($perPage)->offset(($page - 1) * $perPage)->get();

        $items = [];
        while ($result && $result->fetch()) {
            $items[] = $this->item($result->fields);
        }

        return [
            'items'  => $items,
            'total'  => $total,
            'unread' => $this->unreadCount($group),
            'page'   => $page,
            'pages'  => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** How many are unread — in a group, or in all. */
    public function unreadCount(?string $group = null): int
    {
        return (int) $this->query($group)->whereNull('read_at')->count();
    }

    /**
     * Unread counts by group, and in all: the figures a tabbed header shows.
     *
     * @return array<string, int> Group => count, with `''` for all of them
     */
    public function unreadByGroup(): array
    {
        $counts = ['' => $this->unreadCount()];
        foreach (array_keys(self::groups()) as $group) {
            $counts[$group] = $this->unreadCount($group);
        }

        return $counts;
    }

    /** Mark one read. False when it is not this person's, or already read. */
    public function markRead(string $id): bool
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return false;
        }

        $query = $this->db()->queryBuilder()->table('#PREFIX#notifications')
            ->where('id', $id)->where('notifiable_id', $this->userId)->whereNull('read_at');
        $found = (int) (clone $query)->count();
        if ($found === 0) {
            return false;
        }
        $query->update(['read_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    /** Mark every unread one read — in a group, or in all. Returns how many. */
    public function markAllRead(?string $group = null): int
    {
        $count = $this->unreadCount($group);
        if ($count > 0) {
            $this->query($group)->whereNull('read_at')->update(['read_at' => date('Y-m-d H:i:s')]);
        }

        return $count;
    }

    /**
     * Mark read what is about something now dealt with — a comment answered, a draft
     * published — without each notification's own row being pressed.
     *
     * ```php
     * // toDatabase(): ['message' => 'A comment is waiting', 'about' => ['comment:42'], …]
     * Feed::settleAbout(['comment:42']);            // everybody's: the comment was answered
     * Feed::settleAbout(['comment:42'], $userId);   // one person's: they opened it
     * ```
     *
     * A notification lists what it is about under `about` — one key or several — and is settled
     * when any of them is. Without it, a feed fills with alerts about things already handled,
     * and a reader learns to ignore the bell.
     *
     * @param list<string> $keys
     * @param int|null     $userId One person's feed, or everybody's when the thing itself was handled
     * @return int How many were marked read
     */
    public static function settleAbout(array $keys, ?int $userId = null, ?Database $db = null): int
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static fn (string $k): bool => $k !== '')));
        if ($keys === []) {
            return 0;
        }
        $db ??= Database::getInstance();
        // Narrowed by text on every database, then checked as JSON here: the stored `data` is
        // text on one and jsonb on another, and only the decoded list says what it is about.
        $query = $db->queryBuilder()->table('#PREFIX#notifications')->select(['id', 'data'])->whereNull('read_at')
            ->where(static function ($q) use ($keys): void {
                foreach ($keys as $key) {
                    $q->orWhere('data', 'LIKE', '%' . self::likeSafe(json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '%');
                }
            });
        if ($userId !== null) {
            $query->where('notifiable_id', $userId);
        }
        $result = $query->get();
        $ids    = [];
        while ($result && $result->fetch()) {
            $data  = json_decode((string) $result->fields['data'], true);
            $about = is_array($data) ? (array) ($data['about'] ?? []) : [];
            if (array_intersect(array_map('strval', $about), $keys) !== []) {
                $ids[] = (string) $result->fields['id'];
            }
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $db->queryBuilder()->table('#PREFIX#notifications')->whereIn('id', $chunk)->update(['read_at' => date('Y-m-d H:i:s')]);
        }

        return count($ids);
    }

    /**
     * Throw away notifications read more than `$days` ago; unread ones are never removed — an
     * alert nobody opened is the one case where age is not a reason to.
     *
     * Nothing calls this unless the application says how long to keep them:
     * `'notifications' => ['keep_read_days' => 180]`, applied daily by `notifications:prune`.
     *
     * @return int How many were removed
     */
    public static function prune(int $days, ?Database $db = null): int
    {
        if ($days < 1) {
            return 0;
        }
        $db ??= Database::getInstance();
        $before = date('Y-m-d H:i:s', time() - $days * 86400);
        $query  = $db->queryBuilder()->table('#PREFIX#notifications')->whereNotNull('read_at')->where('read_at', '<', $before);
        $count  = (int) (clone $query)->count();
        if ($count > 0) {
            $query->delete();
        }

        return $count;
    }

    /**
     * The groups app.php declares, by name: the notification classes in each.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $groups = is_object($app) ? ($app->applicationInfo['notifications']['groups'] ?? []) : [];

        return is_array($groups)
            ? array_map(static fn ($types): array => array_values(array_map('strval', (array) $types)), $groups)
            : [];
    }

    /**
     * A NavItem badge: the signed-in person's unread count.
     *
     * ```php
     * new NavItem('user.notifications', 'Notifications', sURL . 'Notifications', NavSection::User, badge: Feed::badge());
     * ```
     */
    public static function badge(): \Closure
    {
        return static fn (int $userId): int => (new self($userId))->unreadCount();
    }

    /** This person's rows, in a group or all. */
    private function query(?string $group): QueryBuilder
    {
        $query = $this->db()->queryBuilder()->table('#PREFIX#notifications')->where('notifiable_id', $this->userId);
        $group = $group === null ? '' : trim($group);

        if ($group === '') {
            return $query;
        }

        $types = self::groups()[$group] ?? null;
        if ($types !== null) {
            return $types === [] ? $query->whereRaw('1 = 0') : $query->whereIn('type', $types);
        }

        // json_encode() writes `"group":"tasks"` with no space: the stored text holds it as is.
        return $query->where('data', 'LIKE', '%' . self::likeSafe('"group":' . json_encode($group, JSON_UNESCAPED_UNICODE)) . '%');
    }

    /**
     * A row as the feed shows it: the stored data, and the parts a header draws — its title,
     * message and link — read from the keys `toDatabase()` conventionally returns.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function item(array $row): array
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);
        $data = is_array($data) ? $data : [];
        $type = (string) ($row['type'] ?? '');

        $group = null;
        foreach (self::groups() as $name => $types) {
            if (in_array($type, $types, true)) {
                $group = $name;
                break;
            }
        }

        return [
            'id'         => (string) $row['id'],
            'type'       => $type,
            'group'      => $group ?? (isset($data['group']) ? (string) $data['group'] : null),
            'title'      => (string) ($data['title'] ?? ''),
            'message'    => (string) ($data['message'] ?? $data['body'] ?? ''),
            'url'        => isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
            'data'       => $data,
            'read'       => $row['read_at'] !== null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function likeSafe(string $text): string
    {
        return addcslashes($text, '%_\\');
    }

    private function db(): Database
    {
        return $this->db ?? Database::getInstance();
    }
}
