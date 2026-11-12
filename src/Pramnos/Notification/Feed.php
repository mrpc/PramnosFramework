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
 * A bell can leave groups out — `$except`, by the same names: a page whose own screen shows the
 * conversations keeps their notifications off its bell. "Mark all read" there leaves them as
 * they are.
 *
 * Whatever marks notifications read — one, all, or {@see settleAbout()} — tells each person's
 * channel `notification.read`, so a bell open in another window refreshes at once rather than at
 * its next poll.
 *
 * Every read and write is the person's own: an id that is somebody else's notification marks
 * nothing.
 */
final class Feed
{
    /** How the stored data of one that must be acknowledged reads: `toDatabase()` returned `'requires_ack' => true`. */
    private const ACK_PATTERN = '%"requires_ack":true%';

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
     * @param list<string> $except Groups left out
     * @return array{items: list<array<string, mixed>>, total: int, unread: int, page: int, pages: int}
     */
    public function list(int $page = 1, int $perPage = 20, bool $unreadOnly = false, ?string $group = null, array $except = [], bool $collapse = false): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $count = $this->query($group, $except);
        if ($unreadOnly) {
            $count->whereNull('read_at');
        }
        $total = (int) $count->count();

        $rows = $this->query($group, $except);
        if ($unreadOnly) {
            $rows->whereNull('read_at');
        }
        $result = $rows->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->limit($perPage)->offset(($page - 1) * $perPage)->get();

        $items = [];
        while ($result && $result->fetch()) {
            $items[] = $this->item($result->fields);
        }

        if ($collapse && $page === 1) {
            $items = $this->collapsed($items, $group, $except);
        }

        return [
            'items'  => $items,
            'total'  => $total,
            'unread' => $this->unreadCount($group, $except),
            'page'   => $page,
            'pages'  => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * How many are unread — in a group, or in all.
     *
     * @param list<string> $except Groups left out
     */
    public function unreadCount(?string $group = null, array $except = []): int
    {
        return (int) $this->query($group, $except)->whereNull('read_at')->count();
    }

    /**
     * Unread counts by group, and in all: the figures a tabbed header shows.
     *
     * @param list<string> $except Groups left out — of the count of all, and of their own
     * @return array<string, int> Group => count, with `''` for all of them
     */
    public function unreadByGroup(array $except = []): array
    {
        $counts = ['' => $this->unreadCount(null, $except)];
        foreach (array_keys(self::groups()) as $group) {
            $counts[$group] = $this->unreadCount($group, $except);
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
        self::announceRead([$this->userId]);

        return true;
    }

    /**
     * Mark every unread one read — in a group, or in all, but those in `$except`, and those that
     * must be acknowledged (`requires_ack` in their data): a click on each reads those. Returns how many.
     *
     * @param list<string> $except Groups left as they are
     */
    public function markAllRead(?string $group = null, array $except = []): int
    {
        $count = (int) $this->query($group, $except)->whereNull('read_at')->where('data', 'NOT LIKE', self::ACK_PATTERN)->count();
        if ($count > 0) {
            $this->query($group, $except)->whereNull('read_at')->where('data', 'NOT LIKE', self::ACK_PATTERN)->update(['read_at' => date('Y-m-d H:i:s')]);
            self::announceRead([$this->userId]);
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
        $query = $db->queryBuilder()->table('#PREFIX#notifications')->select(['id', 'notifiable_id', 'data'])->whereNull('read_at')
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
        $people = [];
        while ($result && $result->fetch()) {
            $data  = json_decode((string) $result->fields['data'], true);
            $about = is_array($data) ? (array) ($data['about'] ?? []) : [];
            if (array_intersect(array_map('strval', $about), $keys) !== []) {
                $ids[] = (string) $result->fields['id'];
                $people[(int) $result->fields['notifiable_id']] = true;
            }
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $db->queryBuilder()->table('#PREFIX#notifications')->whereIn('id', $chunk)->update(['read_at' => date('Y-m-d H:i:s')]);
        }
        self::announceRead(array_keys($people));

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
     * The unread of a group shown as one item where app.php collapses it and there are enough —
     * "You have 3 to-dos", linking to their list — in place of each:
     * `'notifications' => ['collapse' => ['todo' => ['title' => 'You have %d to-dos', 'url' => '/Todo', 'min' => 2]]]`.
     * The item's `id` is every one of theirs, comma-separated, so reading it reads them all; it is
     * where the newest of them was, and carries `count` and `collapsed: true`.
     *
     * @param list<array<string, mixed>> $items
     * @param list<string>               $except
     * @return list<array<string, mixed>>
     */
    private function collapsed(array $items, ?string $group, array $except): array
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $rules = is_object($app) ? ($app->applicationInfo['notifications']['collapse'] ?? []) : [];
        foreach (is_array($rules) ? $rules : [] as $name => $rule) {
            $name = (string) $name;
            $rule = is_array($rule) ? $rule : [];
            if (($group !== null && $group !== '' && $group !== $name) || in_array($name, $except, true)) {
                continue;
            }
            $unread = $this->query($name, $except)->whereNull('read_at')->where('data', 'NOT LIKE', self::ACK_PATTERN)->select(['id', 'created_at'])->orderBy('created_at', 'desc')->limit(500)->getAllOrFail();
            if (count($unread) < max(2, (int) ($rule['min'] ?? 2))) {
                continue;
            }
            $ids  = array_map(static fn (array $r): string => (string) $r['id'], $unread);
            $at   = null;
            $kept = [];
            foreach ($items as $item) {
                if (in_array($item['id'], $ids, true)) {
                    $at ??= count($kept);
                    continue;
                }
                $kept[] = $item;
            }
            $one = ['id' => implode(',', $ids), 'type' => '', 'group' => $name, 'count' => count($ids), 'collapsed' => true,
                'title' => sprintf(self::t((string) ($rule['title'] ?? '%d new')), count($ids)), 'message' => '',
                'url' => isset($rule['url']) ? (string) $rule['url'] : null, 'data' => [], 'read' => false, 'created_at' => (string) $unread[0]['created_at']];
            array_splice($kept, $at ?? 0, 0, [$one]);
            $items = $kept;
        }

        return $items;
    }

    private static function t(string $words): string
    {
        return function_exists('t') ? (string) t($words) : $words;
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

    /**
     * This person's rows, in a group or all, without the groups in `$except`.
     *
     * @param list<string> $except
     */
    private function query(?string $group, array $except = []): QueryBuilder
    {
        $query = $this->db()->queryBuilder()->table('#PREFIX#notifications')->where('notifiable_id', $this->userId);
        foreach (array_unique(array_filter(array_map(static fn ($g): string => trim((string) $g), $except), static fn (string $g): bool => $g !== '')) as $out) {
            $types = self::groups()[$out] ?? null;
            if ($types === null) {
                $query->where('data', 'NOT LIKE', self::groupPattern($out));
            } elseif ($types !== []) {
                $query->whereNotIn('type', $types);
            }
        }
        $group = $group === null ? '' : trim($group);

        if ($group === '') {
            return $query;
        }

        $types = self::groups()[$group] ?? null;
        if ($types !== null) {
            return $types === [] ? $query->whereRaw('1 = 0') : $query->whereIn('type', $types);
        }

        return $query->where('data', 'LIKE', self::groupPattern($group));
    }

    /** The LIKE pattern for a group named in a notification's stored JSON. */
    private static function groupPattern(string $group): string
    {
        // json_encode() writes `"group":"tasks"` with no space: the stored text holds it as is.
        return '%' . self::likeSafe('"group":' . json_encode($group, JSON_UNESCAPED_UNICODE)) . '%';
    }

    /**
     * Tell each person's channel their notifications were read — only that: a bell re-reads its
     * feed with its own session, so nothing private travels over the socket. Best effort, and
     * nothing where the application has no broadcasting.
     *
     * @param list<int> $userIds
     */
    private static function announceRead(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        try {
            $app       = \Pramnos\Application\Application::currentInstance();
            $container = $app?->getContainer();
            $manager   = $container !== null && $container->has('broadcasting') ? $container->get('broadcasting') : null;
            $manager   = $manager instanceof \Pramnos\Broadcasting\BroadcastingManager ? $manager : \Pramnos\Broadcasting\BroadcastingManager::currentInstance();
            if ($manager === null) {
                return;
            }
            $config = $app?->applicationInfo['broadcasting'] ?? [];
            foreach ($userIds as $userId) {
                $manager->broadcast(\Pramnos\Broadcasting\RealtimeConfig::userChannel((int) $userId, is_array($config) ? $config : []), 'notification.read', []);
            }
        } catch (\Throwable $e) {
            // The rows are read; a bell finds out at its next poll.
            \Pramnos\Logs\Logger::log('Notifications read were not announced: ' . $e->getMessage(), 'broadcasting');
        }
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
            // `subject` is what Notification\Message::toDatabase() writes.
            'title'      => (string) ($data['title'] ?? $data['subject'] ?? ''),
            'message'    => (string) ($data['message'] ?? $data['body'] ?? ''),
            'url'        => isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
            'data'       => $data,
            'read'       => $row['read_at'] !== null,
            // Read by a click on it alone: never by "mark all read", by being in sight, or in a collapsed item.
            'ack'        => !empty($data['requires_ack']),
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
