<?php

declare(strict_types=1);

namespace Pramnos\Todos;

use Pramnos\Changelog\ChangelogWriter;

/**
 * The to-do list (`pramnos.todos`): a person's own items, and those the application's sources
 * keep on it.
 *
 * ```php
 * Todos::add(['title' => 'Call the supplier https://example.test/s/4', 'due' => '2026-11-12'], by: 7);
 * Todos::update($id, ['status' => Todos::DONE], by: 7);
 * Todos::list(tenant: 0, viewer: 7);          // open first, then by hand, then by day
 *
 * // What should be on the list is the application's knowledge; the engine keeps it in step.
 * Todos::source('followup', fn (int $tenant): iterable => [
 *     'ticket:123' => ['title' => 'Answer Maria', 'link' => '/admin/tickets/123', 'assigned_to' => 4],
 * ]);
 * Todos::sync();                              // every `todos.sync_every` minutes, and on goal.turned
 * Todos::touch($tenant);                      // after an act that changes a source: sync on the next read
 * ```
 *
 * Settings, under `todos` in the application's info:
 *   - `assignable` fn (int $tenant, int $user): bool — who may be given items; anybody by default
 *   - `tenants` fn (): list<int> — the organisations a sync runs for; [0] by default
 *   - `newest_first` bool — within a day, newest first; oldest first by default
 *   - `sync_every` int — minutes between syncs; 5 by default
 */
final class Todos
{
    public const TABLE = 'pramnos.todos';

    public const OPEN        = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const DONE        = 'done';
    public const DISMISSED   = 'dismissed';
    public const STATUSES    = [self::OPEN, self::IN_PROGRESS, self::DONE, self::DISMISSED];

    /** How long a closed item stays on the list. */
    public const CLOSED_DAYS = 7;

    /** The longest title, and the longest link. */
    public const TITLE = 4000;
    public const LINK  = 500;

    /** @var array<string, callable> The sources, by kind */
    private static array $sources = [];

    /**
     * Add an item.
     *
     * @param array<string, mixed> $item `title` (a line: its first web address becomes the link
     *        unless `link` is given), `link`, `assigned_to` (the author by default; 0 for nobody),
     *        `private`, `due` (Y-m-d), `remind_at` (unix), `tenant`, `kind`, `source`,
     *        `messageid`, `conversationid`
     * @param int $by Who adds it; 0 for the system
     * @throws \InvalidArgumentException For an empty title, a bad link or day, or an assignee who may not be given items
     */
    public static function add(array $item, int $by = 0): int
    {
        $tenant  = (int) ($item['tenant'] ?? 0);
        [$title, $link] = self::line((string) ($item['title'] ?? ''), $item['link'] ?? null);
        $private = !empty($item['private']);
        $assign  = $private ? ($by ?: null) : (array_key_exists('assigned_to', $item) ? ((int) $item['assigned_to'] ?: null) : ($by ?: null));
        if ($assign !== null && $assign !== $by) {
            self::assignable($tenant, $assign);
        }
        $now = time();
        $row = ['tenant' => $tenant, 'kind' => (string) ($item['kind'] ?? 'own'), 'source' => $item['source'] ?? null, 'title' => $title, 'link' => $link,
            'status' => self::OPEN, 'assigned_to' => $assign, 'assigned_by_hand' => false, 'due_at' => self::due($item['due'] ?? null, $tenant),
            'remind_at' => isset($item['remind_at']) && (int) $item['remind_at'] > 0 ? (int) $item['remind_at'] : null, 'private' => $private, 'sort' => 0,
            'messageid' => isset($item['messageid']) ? (int) $item['messageid'] : null, 'conversationid' => isset($item['conversationid']) ? (int) $item['conversationid'] : null,
            'created_by' => $by ?: null, 'created_at' => $now, 'updated_at' => $now];
        $db     = self::db();
        $insert = $db->queryBuilder()->table(self::TABLE);
        if ($db->type === 'postgresql') {
            $insert->returning('todoid');
        }
        $result = $insert->insert($row);
        $id     = $db->type === 'postgresql' ? (int) ($result->fields['todoid'] ?? 0) : (int) $db->getInsertId();
        ChangelogWriter::event('todo', $id, 'added', ['title' => mb_substr($title, 0, 200), 'assigned_to' => $assign], by: $by ?: null);

        return $id;
    }

    /**
     * Change an item: `status`, `assigned_to` (0 for nobody), `due` (Y-m-d, '' clears it),
     * `remind_at` (0 clears it), `link`, `sort`; `title` only of one's own; `private` only by its
     * author. Done and dismissed set when it closed; opened again, that is cleared.
     *
     * @param array<string, mixed> $changes
     * @throws \InvalidArgumentException For an item not there or not the person's to change, or a change it cannot take
     */
    public static function update(int $id, array $changes, int $by = 0): void
    {
        $item = self::get($id) ?? throw new \InvalidArgumentException(t('There is no such to-do.'));
        if ($item['private'] && $item['created_by'] !== $by) {
            throw new \InvalidArgumentException(t('There is no such to-do.'));
        }
        $row = [];
        if (isset($changes['status'])) {
            if (!in_array($changes['status'], self::STATUSES, true)) {
                throw new \InvalidArgumentException(t('A to-do is open, in progress, done or dismissed.'));
            }
            $row['status']    = (string) $changes['status'];
            $closes           = in_array($row['status'], [self::DONE, self::DISMISSED], true);
            $row['closed_at'] = $closes ? ($item['closed_at'] ?? time()) : null;
        }
        if (array_key_exists('assigned_to', $changes)) {
            $to = (int) $changes['assigned_to'] ?: null;
            if ($to !== null && $to !== $by) {
                self::assignable($item['tenant'], $to);
            }
            $row += ['assigned_to' => $to, 'assigned_by_hand' => true];
        }
        if (array_key_exists('due', $changes)) {
            $row['due_at']      = self::due($changes['due'], $item['tenant']);
            $row['due_told_at'] = null;
        }
        if (array_key_exists('remind_at', $changes)) {
            $row['remind_at'] = (int) $changes['remind_at'] > 0 ? (int) $changes['remind_at'] : null;
        }
        if (array_key_exists('link', $changes)) {
            $row['link'] = self::line('x', $changes['link'] ?: null)[1];
        }
        if (isset($changes['sort'])) {
            $row['sort'] = (int) $changes['sort'];
        }
        if (isset($changes['title'])) {
            if ($item['kind'] !== 'own' || $item['created_by'] !== $by) {
                throw new \InvalidArgumentException(t('Only your own to-dos can be retitled.'));
            }
            $row['title'] = self::line((string) $changes['title'], $item['link'])[0];
        }
        if (isset($changes['private'])) {
            if ($item['created_by'] !== $by) {
                throw new \InvalidArgumentException(t('Only its author makes a to-do private or public.'));
            }
            $row['private'] = (bool) $changes['private'];
            if ($row['private']) {
                $row['assigned_to'] = $by;
            }
        }
        if ($row === []) {
            return;
        }
        self::db()->queryBuilder()->table(self::TABLE)->where('todoid', $id)->update($row + ['updated_at' => time()]);
        ChangelogWriter::event('todo', $id, 'changed', array_intersect_key($row, array_flip(['status', 'assigned_to', 'due_at', 'private'])), by: $by ?: null);
    }

    /**
     * Delete one's own item. A source's item is hidden (dismissed), never deleted.
     *
     * @throws \InvalidArgumentException For an item not there, or not the person's own
     */
    public static function delete(int $id, int $by): void
    {
        $item = self::get($id);
        if ($item === null || $item['kind'] !== 'own' || $item['created_by'] !== $by) {
            throw new \InvalidArgumentException(t('Only your own to-dos can be deleted.'));
        }
        self::db()->queryBuilder()->table(self::TABLE)->where('todoid', $id)->delete();
        ChangelogWriter::event('todo', $id, 'deleted', ['title' => mb_substr($item['title'], 0, 200)], by: $by);
    }

    /**
     * An item, as kept, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $id): ?array
    {
        $row = self::db()->queryBuilder()->table(self::TABLE)->where('todoid', $id)->first()?->fetch();

        return is_array($row) && $row !== [] ? self::shape($row) : null;
    }

    /**
     * The list a person sees: every public item of the organisation and their own private ones;
     * open first, then by hand, then by day — within a day oldest first, or newest
     * (`todos.newest_first`); closed ones for {@see CLOSED_DAYS} days. Syncs the sources first, at
     * most once per `todos.sync_every` minutes.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(int $tenant, int $viewer, ?int $now = null): array
    {
        $now = $now ?? time();
        self::syncDue($tenant, $now);
        $rows = self::db()->queryBuilder()->table(self::TABLE)->where('tenant', $tenant)
            ->where(static fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $now - self::CLOSED_DAYS * 86400))
            ->where(static fn ($q) => $q->where('private', false)->orWhere('created_by', $viewer))
            ->getAllOrFail();
        $items  = array_map([self::class, 'shape'], $rows);
        $newest = !empty(self::config()['newest_first']);
        usort($items, static function (array $a, array $b) use ($newest): int {
            $closed = static fn (array $i): int => in_array($i['status'], [self::DONE, self::DISMISSED], true) ? 1 : 0;
            $day    = static fn (array $i): int => $i['due_at'] === null ? PHP_INT_MAX : intdiv($i['due_at'], 86400);

            return [$closed($a), $a['sort'], $day($a), $newest ? -$a['created_at'] : $a['created_at'], $a['id']]
                <=> [$closed($b), $b['sort'], $day($b), $newest ? -$b['created_at'] : $b['created_at'], $b['id']];
        });

        return $items;
    }

    /**
     * Declare a source: what it wants on the list, by key —
     * `key => ['title' => …, 'link' => …, 'assigned_to' => …, 'due' => 'Y-m-d', 'tell' => bool]`.
     *
     * @param callable(int): iterable<string, array<string, mixed>> $wants
     */
    public static function source(string $kind, callable $wants): void
    {
        self::$sources[$kind] = $wants;
        // A goal that turns may change what a source wants.
        \Pramnos\Event\Event::listen(\Pramnos\Metrics\GoalTurned::EVENT, [self::class, 'onGoalTurned']);
    }

    /** Forget the sources declared: for a test, or a second application in one process. */
    public static function forgetSources(): void
    {
        self::$sources = [];
    }

    /** A goal turned: keep its organisation's list in step. @param array<string, mixed> $goal */
    public static function onGoalTurned(array $goal): void
    {
        self::sync((int) ($goal['tenant'] ?? 0));
    }

    /**
     * Keep the list in step with the sources, for one organisation or every one (`todos.tenants`):
     *
     *   - a new key opens an item; a done one still wanted opens again; a dismissed one still
     *     wanted stays dismissed;
     *   - the title, the day and the assignee follow the source, unless a person chose the assignee;
     *   - an item no longer wanted is closed as done — a dismissed one stays as it is;
     *   - a source that fails is logged and skipped, the rest still run.
     *
     * @return array{opened: int, changed: int, closed: int, failed: list<string>}
     */
    public static function sync(?int $tenant = null): array
    {
        $out = ['opened' => 0, 'changed' => 0, 'closed' => 0, 'failed' => []];
        if (self::$sources === []) {
            return $out;
        }
        $listed  = self::config()['tenants'] ?? null;
        $tenants = $tenant !== null ? [$tenant] : (is_callable($listed) ? array_map('intval', (array) $listed()) : [0]);
        foreach ($tenants as $t) {
            foreach (self::$sources as $kind => $wants) {
                try {
                    $wanted = [];
                    foreach ($wants($t) as $key => $item) {
                        $wanted[(string) $key] = (array) $item;
                    }
                } catch (\Throwable $e) {
                    \Pramnos\Logs\Logger::log('todos: the source ' . $kind . ' failed: ' . $e->getMessage(), 'todos');
                    $out['failed'][] = $kind;
                    continue;
                }
                self::step($t, (string) $kind, $wanted, $out);
            }
            self::cache()->save((string) time(), self::key($t));
        }

        return $out;
    }

    /** Sync an organisation's list on its next read: after an act that changes a source. */
    public static function touch(int $tenant): void
    {
        self::cache()->delete(self::key($tenant));
    }

    /**
     * One source's wants against its items.
     *
     * @param array<string, array<string, mixed>> $wanted
     * @param array{opened: int, changed: int, closed: int, failed: list<string>} $out
     */
    private static function step(int $tenant, string $kind, array $wanted, array &$out): void
    {
        $table = static fn () => self::db()->queryBuilder()->table(self::TABLE);
        $have  = [];
        foreach ($table()->where('tenant', $tenant)->where('kind', $kind)->whereNotNull('source')->getAllOrFail() as $row) {
            $have[(string) $row['source']] = self::shape($row);
        }
        $now = time();
        foreach ($wanted as $key => $want) {
            $title = mb_substr(trim((string) ($want['title'] ?? $key)), 0, self::TITLE);
            $due   = self::due($want['due'] ?? null, $tenant);
            $to    = isset($want['assigned_to']) ? ((int) $want['assigned_to'] ?: null) : null;
            $item  = $have[$key] ?? null;
            if ($item === null) {
                self::add(['tenant' => $tenant, 'kind' => $kind, 'source' => $key, 'title' => $title, 'link' => $want['link'] ?? null,
                    'assigned_to' => $to ?? 0, 'due' => $want['due'] ?? null]);
                $out['opened']++;
                continue;
            }
            $row = [];
            if ($item['title'] !== $title) {
                $row['title'] = $title;
            }
            if ($item['due_at'] !== $due) {
                $row['due_at'] = $due;
            }
            if (!$item['assigned_by_hand'] && $item['assigned_to'] !== $to) {
                $row['assigned_to'] = $to;
            }
            if ($item['status'] === self::DONE) {
                $row += ['status' => self::OPEN, 'closed_at' => null];
            }
            if ($row !== []) {
                $table()->where('todoid', $item['id'])->update($row + ['updated_at' => $now]);
                $out['changed']++;
            }
        }
        foreach (array_diff_key($have, $wanted) as $item) {
            if (in_array($item['status'], [self::OPEN, self::IN_PROGRESS], true)) {
                $table()->where('todoid', $item['id'])->update(['status' => self::DONE, 'closed_at' => $now, 'updated_at' => $now]);
                $out['closed']++;
            }
        }
    }

    /** Sync before a read, at most once per interval. */
    private static function syncDue(int $tenant, int $now): void
    {
        if (self::$sources === []) {
            return;
        }
        $every = max(1, (int) (self::config()['sync_every'] ?? 5)) * 60;
        $last  = (int) self::cache()->load(self::key($tenant), null, $every);
        if ($last <= 0 || $now - $last >= $every) {
            self::sync($tenant);
        }
    }

    /**
     * A line's title and link: the first web address is the link, unless one is given; the title
     * is the rest, or the address when nothing else is left.
     *
     * @return array{string, ?string}
     */
    private static function line(string $text, mixed $link): array
    {
        $text = trim($text);
        if ($link === null && preg_match('~https?://\S+~u', $text, $m) === 1) {
            $link = $m[0];
            $rest = trim(preg_replace('~\s+~u', ' ', str_replace($m[0], '', $text)) ?? '');
            $text = $rest === '' ? $m[0] : $rest;
        }
        if ($text === '') {
            throw new \InvalidArgumentException(t('A to-do needs a title.'));
        }
        $link = $link === null || $link === '' ? null : trim((string) $link);
        if ($link !== null && (mb_strlen($link) > self::LINK || preg_match('~^(/(?!/)|https?://)\S*$~u', $link) !== 1)) {
            throw new \InvalidArgumentException(t('A to-do\'s link is a path from / or a web address.'));
        }

        return [mb_substr($text, 0, self::TITLE), $link];
    }

    /** A day as the end of it in the organisation's zone; null for none. */
    private static function due(mixed $day, int $tenant): ?int
    {
        if ($day === null || $day === '') {
            return null;
        }
        $zone = \Pramnos\Metrics\Window::zoneOf($tenant);
        $at   = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $day, $zone);
        if ($at === false || $at->format('Y-m-d') !== (string) $day) {
            throw new \InvalidArgumentException(t('A to-do\'s day is YYYY-MM-DD.'));
        }

        return $at->modify('+1 day')->getTimestamp() - 1;
    }

    /** @throws \InvalidArgumentException For somebody who may not be given items */
    private static function assignable(int $tenant, int $user): void
    {
        $may = self::config()['assignable'] ?? null;
        if (is_callable($may) && !$may($tenant, $user)) {
            throw new \InvalidArgumentException(t('That person may not be given to-dos.'));
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shape(array $row): array
    {
        $int = static fn (string $c): ?int => isset($row[$c]) && $row[$c] !== null && $row[$c] !== '' ? (int) $row[$c] : null;

        return ['id' => (int) $row['todoid'], 'tenant' => (int) $row['tenant'], 'kind' => (string) $row['kind'], 'source' => $row['source'] ?? null,
            'title' => (string) $row['title'], 'link' => $row['link'] ?? null, 'status' => (string) $row['status'], 'assigned_to' => $int('assigned_to'),
            'assigned_by_hand' => (bool) $row['assigned_by_hand'], 'due_at' => $int('due_at'), 'remind_at' => $int('remind_at'),
            'due_told_at' => $int('due_told_at'), 'private' => (bool) $row['private'], 'sort' => (int) $row['sort'], 'messageid' => $int('messageid'),
            'conversationid' => $int('conversationid'), 'created_by' => $int('created_by'), 'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'], 'closed_at' => $int('closed_at')];
    }

    /** @return array<string, mixed> The `todos` settings */
    public static function config(): array
    {
        $app = \Pramnos\Application\Application::getInstance();

        return (array) ($app?->applicationInfo['todos'] ?? []);
    }

    /** The connection the list is kept on. */
    private static function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }

    /** Where the last sync of an organisation is remembered. */
    private static function cache(): \Pramnos\Cache\Cache
    {
        return \Pramnos\Cache\Cache::getInstance('todos');
    }

    /** The cache key of an organisation's last sync. */
    private static function key(int $tenant): string
    {
        return 'todos_synced_' . $tenant;
    }
}
