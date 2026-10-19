<?php

declare(strict_types=1);

namespace Pramnos\Changelog;

/**
 * A record's history as people read it: who did what, and when — a page at a time.
 *
 * ```php
 * $page = History::page(['entity' => 'post', 'itemid' => 42], perPage: 20);
 * $page['lines'];   // [['at' => …, 'who' => 'anna', 'what' => 'Published', 'changes' => [], …], …]
 * $page['total'];   // for the pager
 * ```
 *
 * The same filters as {@see ChangelogReader::listing()}, each line with its sentence
 * ({@see ChangelogRenderer::describe()}), the name of whoever did it — or nobody, for the system —
 * and the fields a save changed. What the bell's feed is to notifications, this is to the change
 * log: {@see \Pramnos\Html\HistoryPanel} draws it in a server-rendered page, `HistoryPanel.svelte`
 * in a SPA through {@see HistoryApi}.
 *
 * ## Who may read it
 *
 * A history names people and what they did, so it is not anybody's to read. By default: whoever
 * may open the administration, and anybody the history of their own account (`user`, their id).
 * An application with other rules says so once:
 *
 * ```php
 * History::allowUsing(fn (?object $user, array $filters): bool => $user && Projects::canSee($user, $filters));
 * ```
 */
final class History
{
    /** @var (callable(?object, array<string, mixed>): bool)|null The application's rule */
    private static $policy = null;

    /**
     * One page of history, newest first.
     *
     * @param  array<string, mixed> $filters As {@see ChangelogReader::listing()}
     * @return array{lines: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function page(array $filters, int $page = 1, int $perPage = 20, string $order = 'desc'): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $found   = ChangelogReader::listing($filters, $perPage, ($page - 1) * $perPage, $order);
        $names   = self::names($found['rows']);

        return [
            'lines' => array_map(static fn (array $row): array => self::line($row, $names, array_key_exists('scope', $filters)), $found['rows']),
            'total' => $found['total'],
            'page'  => $page,
            'pages' => max(1, (int) ceil($found['total'] / $perPage)),
        ];
    }

    /**
     * Whether this person may read the history these filters select.
     *
     * @param array<string, mixed> $filters
     */
    public static function allows(?object $user, array $filters): bool
    {
        if (self::$policy !== null) {
            return (bool) (self::$policy)($user, $filters);
        }
        $id = is_object($user) ? (int) ($user->userid ?? 0) : 0;
        if ($id <= 1) {
            return false;
        }
        // Their own account's history, and nothing wider.
        if (($filters['entity'] ?? null) === 'user' && (string) ($filters['itemid'] ?? '') === (string) $id
            && !isset($filters['userid']) && !isset($filters['scope'])) {
            return true;
        }

        return \Pramnos\Auth\AdminAccess::allows($user, 'admin.history', \Pramnos\Auth\AdminAccess::defaultUsertype());
    }

    /**
     * The application's rule for who may read which history, instead of the default; null puts
     * the default back.
     *
     * @param (callable(?object, array<string, mixed>): bool)|null $policy
     */
    public static function allowUsing(?callable $policy): void
    {
        self::$policy = $policy;
    }

    /**
     * One row as a line: when, who, the sentence, and a save's changed fields.
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $names
     * @return array<string, mixed>
     */
    private static function line(array $row, array $names, bool $scoped): array
    {
        // A tenant's events come from their own table, which has no origin column.
        $row['origin'] ??= $scoped ? 'events' : 'feed';
        $userid  = (int) ($row['userid'] ?? 0);
        $changes = [];
        foreach (is_array($row['changes'] ?? null) ? $row['changes'] : [] as $field => $change) {
            $changes[] = [
                'field' => (string) $field,
                'old'   => is_array($change) ? ($change['old'] ?? null) : null,
                'new'   => is_array($change) ? ($change['new'] ?? null) : null,
            ];
        }

        return [
            'at'      => (string) ($row['created_at'] ?? ''),
            'who'     => $userid > 0 ? ($names[$userid] ?? '#' . $userid) : null,
            'userid'  => $userid > 0 ? $userid : null,
            'source'  => (string) ($row['source'] ?? ''),
            'origin'  => (string) $row['origin'],
            'entity'  => (string) ($row['entity'] ?? ''),
            'itemid'  => (string) ($row['itemid'] ?? ''),
            'event'   => $row['event'] ?? $row['op'] ?? null,
            'what'    => ChangelogRenderer::describe($row),
            'changes' => $changes,
            'details' => is_array($row['details'] ?? null) ? $row['details'] : [],
        ];
    }

    /**
     * The usernames of whoever the rows name, in one query.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    private static function names(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['userid'] ?? 0), $rows))));
        if ($ids === []) {
            return [];
        }
        $names = [];
        try {
            $result = \Pramnos\Database\Database::getInstance()->queryBuilder()->table('#PREFIX#users')
                ->select(['userid', 'username'])->whereIn('userid', $ids)->get();
            while ($result && $result->fetch()) {
                $names[(int) $result->fields['userid']] = (string) $result->fields['username'];
            }
        } catch (\Throwable) {
            // No users table to ask: the line says #id.
        }

        return $names;
    }
}
