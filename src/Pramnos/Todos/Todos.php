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
 *   - `url` string|fn (array $item): string — where a notice leads; `/admin/Todo` by default
 *   - `due_notice` bool — tell assignees at 08:05 of items due that day or overdue; off by default
 *   - `allowed` fn (int $tenant): bool — whether an organisation has to-dos at all; yes by default
 *   - `successor` fn (int $tenant, int $user): ?int — who takes a removed account's open items; nobody by default
 */
final class Todos
{
    public const TABLE = 'pramnos.todos';

    public const OPEN        = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const DONE        = 'done';
    public const DISMISSED   = 'dismissed';
    public const STATUSES    = [self::OPEN, self::IN_PROGRESS, self::DONE, self::DISMISSED];

    /** The notices' group in the bell: seeing the list settles them. */
    public const GROUP = 'todos';

    /** When, in an organisation's zone, the day's "due today" notices go. */
    public const DUE_NOTICE_AT = '08:05';

    /** How long a closed item stays on the list. */
    public const CLOSED_DAYS = 7;

    /** How an item may repeat: the next one opens when it is done. */
    public const REPEATS = ['daily', 'weekdays', 'weekly', 'monthly', 'yearly'];

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
     *        `messageid`, `conversationid`, `tell` (false: the assignee was told already),
     *        `repeat` (one of {@see REPEATS}: done, the next opens — it needs a day or a reminder)
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
        $now    = time();
        $remind = isset($item['remind_at']) && (int) $item['remind_at'] > 0 ? (int) $item['remind_at'] : null;
        $due    = self::due($item['due'] ?? null, $tenant);
        $repeat = self::repeat($item['repeat'] ?? null, $due ?? $remind);
        $row = ['tenant' => $tenant, 'kind' => (string) ($item['kind'] ?? 'own'), 'source' => $item['source'] ?? null, 'title' => $title, 'link' => $link,
            'status' => self::OPEN, 'assigned_to' => $assign, 'assigned_by_hand' => false, 'due_at' => $due,
            'remind_at' => $remind, 'private' => $private, 'sort' => 0,
            'messageid' => isset($item['messageid']) ? (int) $item['messageid'] : null, 'conversationid' => isset($item['conversationid']) ? (int) $item['conversationid'] : null,
            'created_by' => $by ?: null, 'created_at' => $now, 'updated_at' => $now]
            // Written only where there is one: an installation that has not migrated keeps adding.
            + ($repeat === null ? [] : ['repeat' => $repeat, 'repeat_from' => isset($item['repeat_from']) ? (int) $item['repeat_from'] : ($remind ?? $due)]);
        $db     = self::db();
        $insert = $db->queryBuilder()->table(self::TABLE);
        if ($db->type === 'postgresql') {
            $insert->returning('todoid');
        }
        $result = $insert->insert($row);
        $id     = $db->type === 'postgresql' ? (int) ($result->fields['todoid'] ?? 0) : (int) $db->getInsertId();
        ChangelogWriter::event('todo', $id, 'added', ['title' => mb_substr($title, 0, 200), 'assigned_to' => $assign], by: $by ?: null);
        if ($assign !== null && ($item['tell'] ?? true) !== false) {
            self::given(['id' => $id, 'title' => $title, 'link' => $link, 'tenant' => $tenant], $assign, $by);
        }
        self::changed($id, [$assign, $by]);

        return $id;
    }

    /**
     * Change an item: `status`, `assigned_to` (0 for nobody), `due` (Y-m-d, '' clears it),
     * `remind_at` (0 clears it), `repeat` ('' stops it), `link`, `sort`; `title` only of one's own; `private` only by its
     * author. Done and dismissed set when it closed; opened again, that is cleared. `note`, a
     * line to whoever handed the item over (kind `assigned`): they are told it, and told when the
     * item is closed — the note kept in the item's history, not in a column.
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
        if (array_key_exists('repeat', $changes)) {
            $when = array_key_exists('remind_at', $row) ? ($row['remind_at'] ?? $item['reminded_at']) : ($item['remind_at'] ?? $item['reminded_at']);
            $row['repeat']      = self::repeat($changes['repeat'], (array_key_exists('due_at', $row) ? $row['due_at'] : $item['due_at']) ?? $when);
            $row['repeat_from'] = $row['repeat'] === null ? null : ($when ?? ($row['due_at'] ?? $item['due_at']));
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
        $note = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($changes['note'] ?? ''))), 0, 500);
        if ($row === [] && $note === '') {
            return;
        }
        if ($row !== []) {
            self::db()->queryBuilder()->table(self::TABLE)->where('todoid', $id)->update($row + ['updated_at' => time()]);
        }
        ChangelogWriter::event('todo', $id, 'changed', array_intersect_key($row, array_flip(['status', 'assigned_to', 'due_at', 'private'])) + ($note !== '' ? ['note' => $note] : []), by: $by ?: null);
        if (isset($row['closed_at'])) {
            self::settle($id);
        }
        // Done, from open: a repeating one opens its next at once.
        if (($row['status'] ?? null) === self::DONE && $item['closed_at'] === null && ($row['repeat'] ?? $item['repeat']) !== null) {
            self::again(self::get($id) ?? $item, $by);
        }
        $to = $row['assigned_to'] ?? null;
        if ($to !== null && $to !== $item['assigned_to'] && empty($row['private'])) {
            self::given($item, $to, $by);
        }
        self::changed($id, [$item['assigned_to'], $item['created_by'], $to, $by]);
        // After its notices were settled: this one is new.
        // Handed over by somebody else: they hear of a note, and of it being closed.
        $from = (int) ($item['created_by'] ?? 0);
        if ($item['kind'] === 'assigned' && $from > 0 && $from !== $by) {
            $who  = $by > 0 ? self::nameOf($by) : '';
            $what = self::short((string) $item['title']);
            if (isset($row['closed_at']) && $item['closed_at'] === null) {
                self::tell($item, $from, sprintf(t('%s closed: %s'), $who, $what) . ($note !== '' ? ' — ' . $note : ''));
            } elseif ($note !== '') {
                self::tell($item, $from, sprintf(t('%s on %s: %s'), $who, $what, $note));
            }
        }
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
        self::settle($id);
        self::changed($id, [$item['assigned_to'], $by], true);
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
     *   - an item no longer wanted is closed as done — a dismissed one quietly, so a new reason
     *     opens it again;
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
     * Send the reminders that are due: each item's `remind_at` reached — to its assignee, else its
     * author — in the bell, as a push, and as a line from "Reminders" in their conversation with
     * the system where the conversations are installed. The time is cleared before anything is
     * sent, so a failure is not sent again every minute, and two runners do not both send it.
     *
     * @return int How many were sent
     */
    public static function remind(?int $now = null): int
    {
        $now  = $now ?? time();
        $sent = 0;
        $rows = self::db()->queryBuilder()->table(self::TABLE)->whereNotNull('remind_at')->where('remind_at', '<=', $now)->getAllOrFail();
        foreach (array_map([self::class, 'shape'], $rows) as $item) {
            if (!self::claim($item['id'], 'remind_at', $item['remind_at'], null)) {
                continue;
            }
            // When it was sent stays: a repeating item counts its next from it.
            if (array_key_exists('reminded_at', $item)) {
                self::db()->queryBuilder()->table(self::TABLE)->where('todoid', $item['id'])->update(['reminded_at' => $item['remind_at']]);
            }
            $person = $item['assigned_to'] ?? $item['created_by'];
            if ($person === null || !in_array($item['status'], [self::OPEN, self::IN_PROGRESS], true)) {
                continue;
            }
            $said = sprintf(t('Reminder: %s'), self::short($item['title']));
            self::tell($item, $person, $said);
            if (\Pramnos\Conversations\Conversations::installed()) {
                try {
                    \Pramnos\Conversations\SystemPost::post($person, $said . (self::url($item) !== '' ? "\n" . self::url($item) : ''), [],
                        ['username' => t('Reminders'), 'source' => 'todo', 'externalid' => 'remind:' . $item['id'] . ':' . $item['remind_at']]);
                } catch (\Throwable $e) {
                    \Pramnos\Logs\Logger::log('todos: reminder ' . $item['id'] . ' not posted: ' . $e->getMessage(), 'todos');
                }
            }
            $sent++;
        }

        return $sent;
    }

    /**
     * Tell assignees, once per item, of items due today or overdue — from {@see DUE_NOTICE_AT} in
     * the organisation's zone, where `todos.due_notice` is on. An item with nobody assigned is
     * told nothing; nor is an assignee who may no longer be given items (`todos.assignable`), nor
     * an organisation without to-dos (`todos.allowed`). Changing the day lets it be told again.
     *
     * @return int How many were told
     */
    public static function dueNotices(?int $now = null): int
    {
        if (empty(self::config()['due_notice'])) {
            return 0;
        }
        $now  = $now ?? time();
        $told = 0;
        $rows = self::db()->queryBuilder()->table(self::TABLE)->whereNull('due_told_at')->whereNotNull('assigned_to')->whereNotNull('due_at')
            ->whereIn('status', [self::OPEN, self::IN_PROGRESS])->where('due_at', '<', $now + 86400)->getAllOrFail();
        foreach (array_map([self::class, 'shape'], $rows) as $item) {
            $local = (new \DateTimeImmutable('@' . $now))->setTimezone(\Pramnos\Metrics\Window::zoneOf($item['tenant']));
            if ($local->format('H:i') < self::DUE_NOTICE_AT || $item['due_at'] > $local->setTime(23, 59, 59)->getTimestamp()) {
                continue;
            }
            if (!self::allowed($item['tenant']) || !self::mayBeGiven($item['tenant'], (int) $item['assigned_to'])) {
                continue;
            }
            if (!self::claim($item['id'], 'due_told_at', null, $now)) {
                continue;
            }
            $said = sprintf($item['due_at'] < $now ? t('Overdue: %s') : t('Due today: %s'), self::short($item['title']));
            self::tell($item, (int) $item['assigned_to'], $said);
            $told++;
        }

        return $told;
    }

    /** Seeing the list settles its notices in the person's bell. @return int How many */
    public static function seen(int $viewer): int
    {
        return (new \Pramnos\Notification\Feed($viewer))->markAllRead(self::GROUP);
    }

    /**
     * An account going: its open items go to its successor (`todos.successor`), or to nobody in
     * particular, and the successor is told; its private items go with it.
     *
     * @return int How many were handed over
     */
    public static function handOver(int $user): int
    {
        $table = static fn () => self::db()->queryBuilder()->table(self::TABLE);
        foreach ($table()->where('created_by', $user)->where('private', true)->select(['todoid'])->getAllOrFail() as $row) {
            self::settle((int) $row['todoid']);
        }
        $table()->where('created_by', $user)->where('private', true)->delete();
        $rows = $table()->where('assigned_to', $user)->whereIn('status', [self::OPEN, self::IN_PROGRESS])->getAllOrFail();
        $next = self::config()['successor'] ?? null;
        foreach (array_map([self::class, 'shape'], $rows) as $item) {
            $to = is_callable($next) ? ((int) $next($item['tenant'], $user) ?: null) : null;
            $to = $to === $user ? null : $to;
            $table()->where('todoid', $item['id'])->update(['assigned_to' => $to, 'assigned_by_hand' => $to !== null, 'updated_at' => time()]);
            self::settle($item['id']);
            if ($to !== null) {
                self::given($item, $to);
            }
        }

        return count($rows);
    }

    /**
     * The rows an export carries: a person's own items, or every own item of an organisation (or
     * of every one, for a null tenant).
     *
     * @return list<array<string, mixed>>
     */
    public static function exportable(?int $tenant, ?int $person = null): array
    {
        $query = self::db()->queryBuilder()->table(self::TABLE)->where('kind', 'own')->orderBy('todoid');
        if ($tenant !== null) {
            $query->where('tenant', $tenant);
        }
        if ($person !== null) {
            $query->where('created_by', $person);
        }
        $keep = ['id', 'tenant', 'title', 'link', 'status', 'assigned_to', 'due_at', 'remind_at', 'private', 'created_by', 'created_at', 'updated_at', 'closed_at'];

        return array_map(static fn (array $row): array => array_intersect_key(self::shape($row), array_flip($keep)), $query->getAllOrFail());
    }

    /** Whether this installation has the to-do list: its table made. */
    public static function installed(): bool
    {
        try {
            // Only a yes is kept: a table made later in the process (a migration, a test) is found.
            if (self::$installed !== true) {
                self::$installed = self::db()->schema()->hasTable(self::TABLE);
            }

            return self::$installed;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @var bool|null Whether the table is there, asked once */
    private static ?bool $installed = null;

    /** The organisation a person's list is of: `todos.tenant`, 0 by default. */
    public static function tenantOf(?object $user): int
    {
        $of = self::config()['tenant'] ?? null;

        return is_callable($of) && $user !== null ? (int) $of($user) : 0;
    }

    /**
     * Whether a person may `read` the list, or `write` to it: `todos.can`, else from the
     * administration's floor up. A role may only read — the content platform's client.
     */
    public static function can(?object $user, string $what = 'write'): bool
    {
        if ($user === null || (int) ($user->userid ?? 0) <= 1) {
            return false;
        }
        $can = self::config()['can'] ?? null;
        if (is_callable($can)) {
            return (bool) $can($user, $what);
        }

        return (int) ($user->usertype ?? 0) >= \Pramnos\Auth\AdminAccess::defaultUsertype();
    }

    /**
     * Who may be given items in an organisation, by id: `todos.people`, else the active accounts
     * from the administration's floor up — those `todos.assignable` allows.
     *
     * @return array<int, string>
     */
    public static function people(int $tenant): array
    {
        $people = self::config()['people'] ?? null;
        if (is_callable($people)) {
            $list = array_map('strval', (array) $people($tenant));
        } else {
            $list = [];
            foreach (self::db()->queryBuilder()->table('#PREFIX#users')->select(['userid', 'username', 'firstname', 'lastname'])
                ->where('usertype', '>=', \Pramnos\Auth\AdminAccess::defaultUsertype())->where('active', 1)->orderBy('username')->limit(500)->getAllOrFail() as $row) {
                $full = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
                $list[(int) $row['userid']] = $full !== '' ? $full : (string) $row['username'];
            }
        }

        return array_filter($list, static fn (string $name, int $id): bool => self::mayBeGiven($tenant, $id), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The header's urgent five: the person's open items, soonest first, and the overdue ones
     * nobody has — or, with `todos.urgent_any_overdue`, every overdue one. `count` is how many there
     * are in all.
     *
     * @return array{items: list<array<string, mixed>>, count: int}
     */
    public static function urgent(int $tenant, int $viewer, int $limit = 5, ?int $now = null): array
    {
        $now  = $now ?? time();
        $any  = !empty(self::config()['urgent_any_overdue']);
        $when = static fn (array $i): ?int => $i['remind_at'] ?? $i['due_at'];
        $hits = array_values(array_filter(self::list($tenant, $viewer, $now), static function (array $i) use ($viewer, $any, $now, $when): bool {
            if (!in_array($i['status'], [self::OPEN, self::IN_PROGRESS], true)) {
                return false;
            }
            $overdue = $i['due_at'] !== null && $i['due_at'] < $now;

            return $i['assigned_to'] === $viewer || ($overdue && ($any || $i['assigned_to'] === null));
        }));
        usort($hits, static fn (array $a, array $b): int => [$when($a) ?? PHP_INT_MAX, $a['id']] <=> [$when($b) ?? PHP_INT_MAX, $b['id']]);

        return ['items' => array_slice($hits, 0, $limit), 'count' => count($hits)];
    }

    /**
     * "To-dos about this": the open items a person sees whose link is a page — its path, with or
     * without the site's address.
     *
     * @return list<array<string, mixed>>
     */
    public static function about(string $link, int $tenant, int $viewer): array
    {
        $path = (string) (parse_url($link, PHP_URL_PATH) ?? $link) . (($q = parse_url($link, PHP_URL_QUERY)) ? '?' . $q : '');

        return array_values(array_filter(self::list($tenant, $viewer), static function (array $i) use ($link, $path): bool {
            if ($i['link'] === null || !in_array($i['status'], [self::OPEN, self::IN_PROGRESS], true)) {
                return false;
            }
            $their = (string) (parse_url($i['link'], PHP_URL_PATH) ?? '') . (($q = parse_url($i['link'], PHP_URL_QUERY)) ? '?' . $q : '');

            return $i['link'] === $link || ($path !== '' && $their === $path);
        }));
    }

    /**
     * The calendar's feed: the items a person sees with a day in the range, as a `pf-calendar`
     * takes them — `[{id, title, start, allDay, url, classNames}]`.
     *
     * @return list<array<string, mixed>>
     */
    public static function feed(int $tenant, int $viewer, string $start, string $end): array
    {
        $from = strtotime($start);
        $to   = strtotime($end);
        if ($from === false || $to === false) {
            return [];
        }
        $zone = \Pramnos\Metrics\Window::zoneOf($tenant);
        $out  = [];
        foreach (self::list($tenant, $viewer) as $i) {
            if ($i['due_at'] === null || $i['due_at'] < $from || $i['due_at'] >= $to + 86400) {
                continue;
            }
            $closed = in_array($i['status'], [self::DONE, self::DISMISSED], true);
            $out[]  = ['id' => $i['id'], 'title' => self::short($i['title']), 'start' => (new \DateTimeImmutable('@' . $i['due_at']))->setTimezone($zone)->format('Y-m-d'),
                'allDay' => true, 'url' => $i['link'], 'classNames' => $closed ? ['pf-todo-closed'] : ($i['due_at'] < time() ? ['pf-todo-overdue'] : [])];
        }

        return $out;
    }

    /**
     * An item dragged to another day on the calendar: `{id, start}`, its day the start's.
     *
     * @param array<string, mixed> $body
     * @return array{ok: bool, due: string}
     */
    public static function move(array $body, int $by): array
    {
        $at = is_string($body['start'] ?? null) ? strtotime($body['start']) : false;
        if ($at === false) {
            throw new \InvalidArgumentException(t('A to-do\'s day is YYYY-MM-DD.'));
        }
        $item = self::get((int) ($body['id'] ?? 0)) ?? throw new \InvalidArgumentException(t('There is no such to-do.'));
        $day  = (new \DateTimeImmutable('@' . $at))->setTimezone(\Pramnos\Metrics\Window::zoneOf($item['tenant']))->format('Y-m-d');
        // A day given as a date alone is that date wherever the reader is.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $body['start']) === 1) {
            $day = (string) $body['start'];
        }
        self::update($item['id'], ['due' => $day], $by);

        return ['ok' => true, 'due' => $day];
    }

    /**
     * Items in the list's groups: overdue, today, the next seven days, later, without a day, and
     * apart from them, the system's waiting for nobody and those closed this week.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, list<array<string, mixed>>>
     */
    public static function groups(array $items, int $tenant, ?int $now = null): array
    {
        $now    = $now ?? time();
        $local  = (new \DateTimeImmutable('@' . $now))->setTimezone(\Pramnos\Metrics\Window::zoneOf($tenant));
        $today  = $local->setTime(23, 59, 59)->getTimestamp();
        $week   = $today + 7 * 86400;
        $groups = ['overdue' => [], 'today' => [], 'week' => [], 'later' => [], 'none' => [], 'system' => [], 'closed' => []];
        foreach ($items as $i) {
            $key = match (true) {
                in_array($i['status'], [self::DONE, self::DISMISSED], true) => 'closed',
                $i['kind'] !== 'own' && $i['assigned_to'] === null => 'system',
                $i['due_at'] === null => 'none',
                $i['due_at'] < $now => 'overdue',
                $i['due_at'] <= $today => 'today',
                $i['due_at'] <= $week => 'week',
                default => 'later',
            };
            $groups[$key][] = $i;
        }

        return $groups;
    }

    /** The assistant's tool's name: `todos.tool`, else `todos` (its writes are `<name>_change`). */
    public static function toolName(): string
    {
        return (string) ((self::config()['tool'] ?? null) ?: 'todos');
    }

    /** Whether an organisation has to-dos at all (`todos.allowed`). */
    public static function allowed(int $tenant): bool
    {
        $allowed = self::config()['allowed'] ?? null;

        return !is_callable($allowed) || (bool) $allowed($tenant);
    }

    /**
     * Tell the people an item concerns that it changed (`todo.changed`), where the conversations'
     * realtime is there.
     *
     * @param list<?int> $people
     */
    private static function changed(int $id, array $people, bool $deleted = false): void
    {
        $people = array_values(array_unique(array_filter($people, static fn (?int $p): bool => $p !== null && $p > 0)));
        if ($people !== []) {
            \Pramnos\Conversations\Realtime::todo($people, $id, $deleted);
        }
    }

    /** Settle an item's notices in everybody's bell: it was closed or deleted. */
    private static function settle(int $id): void
    {
        try {
            \Pramnos\Notification\Feed::settleAbout(['todo:' . $id], null, self::db());
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('todos: ' . $id . ' not settled: ' . $e->getMessage(), 'todos');
        }
    }

    /**
     * Tell somebody an item is theirs now: "A to-do for you · from X". Never for their own act.
     *
     * @param array<string, mixed> $item
     */
    private static function given(array $item, int $to, int $by = 0): void
    {
        if ($to === $by) {
            return;
        }
        $from = $by > 0 ? self::nameOf($by) : '';
        $said = $from !== '' ? sprintf(t('A to-do for you · from %s: %s'), $from, self::short((string) $item['title']))
            : sprintf(t('A to-do for you: %s'), self::short((string) $item['title']));
        self::tell($item, $to, $said);
    }

    /**
     * One notice to one person, in the bell and by push. A person not told is logged, not raised:
     * the act that told them has happened.
     *
     * @param array<string, mixed> $item
     */
    private static function tell(array $item, int $person, string $said): void
    {
        try {
            $user = new \Pramnos\User\User($person);
            if ((int) ($user->userid ?? 0) === $person) {
                $user->notify(new TodoNotification((int) $item['id'], $said, self::url($item)));
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('todos: ' . ($item['id'] ?? '?') . ': ' . $person . ' not told: ' . $e->getMessage(), 'todos');
        }
    }

    /** Where a notice of an item leads: `todos.url`, the list by default. @param array<string, mixed> $item */
    private static function url(array $item): string
    {
        $url = self::config()['url'] ?? '/admin/Todo';

        return is_callable($url) ? (string) $url($item) : (string) $url;
    }

    /** A person's name: first and last, or the username. */
    private static function nameOf(int $user): string
    {
        try {
            $u = new \Pramnos\User\User($user);
            $full = trim((string) ($u->firstname ?? '') . ' ' . (string) ($u->lastname ?? ''));

            return $full !== '' ? $full : (string) ($u->username ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** A title cut for a notice. */
    private static function short(string $title): string
    {
        $line = trim((string) preg_replace('~\s+~u', ' ', $title));

        return mb_strlen($line) > 120 ? mb_substr($line, 0, 119) . '…' : $line;
    }

    /** Change a column from what it was, if it still is: true when this runner did it. */
    private static function claim(int $id, string $column, ?int $was, ?int $to): bool
    {
        $query = self::db()->queryBuilder()->table(self::TABLE)->where('todoid', $id);
        $was === null ? $query->whereNull($column) : $query->where($column, $was);
        $result = $query->update([$column => $to]);

        return is_object($result) && method_exists($result, 'getAffectedRows') ? (int) $result->getAffectedRows() > 0 : (bool) $result;
    }

    /** Whether somebody may be given items, without raising. */
    private static function mayBeGiven(int $tenant, int $user): bool
    {
        try {
            self::assignable($tenant, $user);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
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
                    'assigned_to' => $to ?? 0, 'due' => $want['due'] ?? null, 'tell' => ($want['tell'] ?? true) !== false]);
                $out['opened']++;
                continue;
            }
            $row = [];
            if ($item['title'] !== $title) {
                $row['title'] = $title;
            }
            if ($item['due_at'] !== $due) {
                $row += ['due_at' => $due, 'due_told_at' => null];
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
                if (isset($row['assigned_to']) && ($want['tell'] ?? true) !== false && $item['status'] !== self::DISMISSED) {
                    self::given(['title' => $title] + $item, $row['assigned_to']);
                }
            }
        }
        foreach (array_diff_key($have, $wanted) as $item) {
            // A dismissed one whose reason is gone is closed quietly, as done: "not now" lasts while
            // its reason does, and a new reason opens it again.
            if ($item['status'] === self::DISMISSED) {
                $table()->where('todoid', $item['id'])->update(['status' => self::DONE, 'closed_at' => $item['closed_at'] ?? $now, 'updated_at' => $now]);
                continue;
            }
            if (in_array($item['status'], [self::OPEN, self::IN_PROGRESS], true)) {
                $table()->where('todoid', $item['id'])->update(['status' => self::DONE, 'closed_at' => $now, 'updated_at' => $now]);
                self::settle($item['id']);
                $out['closed']++;
            }
        }
    }

    /**
     * The next occurrence of a repeating time: the first after `$now`, at the same time of day in
     * the zone, so a change of clocks keeps 09:00 at 09:00. Done early, it is the one after, not
     * the same; missed ones are not each made, the first still to come is. A month and a year count
     * from `$from`'s day and keep it where the month has it (31 Jan → 28 Feb → 31 Mar; 29 Feb → 28
     * Feb); weekdays skip Saturday and Sunday.
     */
    public static function nextOccurrence(int $from, string $repeat, int $now, ?\DateTimeZone $zone = null): int
    {
        if (!in_array($repeat, self::REPEATS, true)) {
            throw new \InvalidArgumentException(t('A to-do repeats daily, on weekdays, weekly, monthly or yearly.'));
        }
        $start = (new \DateTimeImmutable('@' . $from))->setTimezone($zone ?? new \DateTimeZone('UTC'));
        [$y, $m, $d] = array_map('intval', explode('-', $start->format('Y-n-j')));
        $time  = [(int) $start->format('G'), (int) $start->format('i'), (int) $start->format('s')];
        $step  = match ($repeat) { 'weekly' => 7, 'daily', 'weekdays' => 1, default => 0 };
        // A day or a week: jump close to now, then step; a month or a year: count them from the start.
        $k = $step > 0 ? max(1, intdiv(max(0, $now - $from), $step * 86400)) : 1;
        for ($guard = 0; $guard < 100000; $guard++, $k++) {
            if ($step > 0) {
                $at = $start->modify('+' . ($k * $step) . ' days');
            } else {
                $months = $repeat === 'monthly' ? $k : 12 * $k;
                $first  = $start->setDate($y, $m + $months, 1);
                $at     = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($d, (int) $first->format('t')))->setTime(...$time);
            }
            if ($repeat === 'weekdays' && (int) $at->format('N') >= 6) {
                continue;
            }
            if ($at->getTimestamp() > $now) {
                return $at->getTimestamp();
            }
        }
        throw new \RuntimeException('No next occurrence found.');
    }

    /**
     * A repeat as kept: one of {@see REPEATS}, or null for '' and none.
     *
     * @throws \InvalidArgumentException For one not known, or a repeat with no time to count from
     */
    private static function repeat(mixed $repeat, ?int $when): ?string
    {
        $repeat = $repeat === null ? '' : trim((string) $repeat);
        if ($repeat === '') {
            return null;
        }
        if (!in_array($repeat, self::REPEATS, true)) {
            throw new \InvalidArgumentException(t('A to-do repeats daily, on weekdays, weekly, monthly or yearly.'));
        }
        if ($when === null) {
            throw new \InvalidArgumentException(t('A repeating to-do needs a day or a reminder.'));
        }

        return $repeat;
    }

    /**
     * A repeating item done: the next, at once — the same title, link, assignee, privacy and
     * repeat; its reminder at the next occurrence and its day moved as many days. Nobody is told
     * it is theirs: it is the same item again.
     *
     * @param array<string, mixed> $item
     */
    private static function again(array $item, int $by): void
    {
        $zone   = \Pramnos\Metrics\Window::zoneOf($item['tenant']);
        $remind = $item['remind_at'] ?? $item['reminded_at'] ?? null;
        $base   = $remind ?? $item['due_at'];
        // A source's item comes back by its source, not by a repeat.
        if ($base === null || $item['repeat'] === null || $item['source'] !== null) {
            return;
        }
        $next = self::nextOccurrence($item['repeat_from'] ?? $base, $item['repeat'], max(time(), $base), $zone);
        $day  = static fn (int $at): \DateTimeImmutable => (new \DateTimeImmutable('@' . $at))->setTimezone($zone)->setTime(0, 0);
        $due  = null;
        if ($item['due_at'] !== null) {
            $days = (int) $day($base)->diff($day($next))->format('%r%a');
            $due  = $day($item['due_at'])->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
        }
        try {
            self::add(['tenant' => $item['tenant'], 'kind' => $item['kind'], 'title' => $item['title'], 'link' => $item['link'] ?? '',
                'assigned_to' => $item['assigned_to'] ?? 0, 'private' => $item['private'], 'due' => $due, 'remind_at' => $remind !== null ? $next : null,
                'repeat' => $item['repeat'], 'repeat_from' => $item['repeat_from'] ?? $base, 'messageid' => $item['messageid'], 'conversationid' => $item['conversationid'],
                'tell' => false], (int) ($item['created_by'] ?? 0));
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('todos: the next of ' . $item['id'] . ' not opened: ' . $e->getMessage(), 'todos');
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
            'updated_at' => (int) $row['updated_at'], 'closed_at' => $int('closed_at')]
            // Where the columns are: an installation that has not migrated has no repeats.
            + (array_key_exists('repeat', $row) ? ['repeat' => ($row['repeat'] ?? '') === '' ? null : (string) $row['repeat'],
                'repeat_from' => $int('repeat_from'), 'reminded_at' => $int('reminded_at')] : ['repeat' => null]);
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
