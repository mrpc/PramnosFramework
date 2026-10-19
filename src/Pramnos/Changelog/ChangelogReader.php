<?php

declare(strict_types=1);

namespace Pramnos\Changelog;

/**
 * Reads a record's history back out of the change log.
 *
 * ```php
 * foreach (ChangelogReader::history('wcm-device', 42) as $row) {
 *     echo ChangelogRenderer::describe($row);
 * }
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ChangelogReader
{
    /** The view over the feed and the application events. */
    public const VIEW = 'pramnos.changelog_history';

    /**
     * What happened to one record, newest first.
     *
     * **Defaults to application events only.** The automatic feed is one row per save and
     * would bury them; a user-facing timeline wants the semantic rows. The reference
     * application writes `AND logtype != 90` by hand in every listing that shows a person
     * what happened, and forgetting it once is what this default prevents.
     *
     * Pass `['events', 'feed']` for a diagnostic view.
     *
     * @param  list<string> $origins `events`, `feed`, or both
     * @return list<array<string, mixed>>
     */
    public static function history(
        string $entity,
        string|int $itemid,
        array $origins = ['events'],
        int $limit = 50
    ): array {
        $origins = array_values(array_intersect($origins, ['feed', 'events']));
        if ($origins === []) {
            return [];
        }

        $database = \Pramnos\Database\Database::getInstance();
        $view     = $database->schema()->resolveTableName(self::VIEW);

        $result = $database->queryBuilder()
            ->table($view)
            ->where('entity', $entity)
            ->where('itemid', (string) $itemid)
            ->whereIn('origin', $origins)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        if (!$result) {
            return [];
        }

        $rows = [];
        while ($result->fetch()) {
            $rows[] = static::decode($result->fields);
        }

        return $rows;
    }

    /**
     * A page of the log, for a screen that lists it: filtered, ordered, and with the total the
     * pager needs.
     *
     * ```php
     * $page = ChangelogReader::listing(['entity' => 'user', 'itemid' => 42], limit: 25, offset: 50);
     * $page['rows'];    // these 25, newest first
     * $page['total'];   // how many match, for the pager
     *
     * ChangelogReader::listing(['userid' => 7, 'from' => strtotime('-7 days')]);          // what 7 did
     * ChangelogReader::listing(['event' => ['user.login', 'user.logout'], 'source' => 'api']);
     * ChangelogReader::listing(['scope' => 'org:12', 'entity' => 'post'], order: 'asc');
     * ```
     *
     * Filters, each optional and all of them together:
     *
     * - `entity`, `itemid` (one or a list) — which records;
     * - `origins` — `events` (the default, as {@see history()}), `feed`, or both;
     * - `event`, `logtype` (one or a list), `userid` (who did it), `source` (`web`, `api`, `cli`);
     * - `from`, `to` — Unix times, from inclusive, to exclusive;
     * - `scope` — one tenant's events, as {@see eventsInScope()}.
     *
     * An unknown filter is refused rather than ignored: a typo would otherwise list everything.
     *
     * @param  array<string, mixed> $filters
     * @param  string               $order   `desc` (newest first) or `asc`
     * @return array{rows: list<array<string, mixed>>, total: int}
     * @throws \InvalidArgumentException for a filter this does not know
     */
    public static function listing(array $filters = [], int $limit = 50, int $offset = 0, string $order = 'desc'): array
    {
        $unknown = array_diff(array_keys($filters), ['entity', 'itemid', 'origins', 'event', 'logtype', 'userid', 'source', 'from', 'to', 'scope']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('ChangelogReader::listing() does not filter by ' . implode(', ', $unknown) . '.');
        }
        $database = \Pramnos\Database\Database::getInstance();
        $origins  = array_values(array_intersect((array) ($filters['origins'] ?? ['events']), ['feed', 'events']));
        $scoped   = array_key_exists('scope', $filters);
        if ($origins === [] || ($scoped && !$database->schema()->hasColumn(ChangelogWriter::EVENTS_TABLE, 'scope'))) {
            return ['rows' => [], 'total' => 0];
        }

        // A tenant's events are in their own table: the view has no scope column, and the feed
        // carries none.
        $query = $database->queryBuilder()->table($scoped
            ? ChangelogWriter::EVENTS_TABLE
            : $database->schema()->resolveTableName(self::VIEW));
        if ($scoped) {
            $query->where('scope', (string) $filters['scope']);
        } else {
            $query->whereIn('origin', $origins);
        }
        foreach (['entity' => 'entity', 'itemid' => 'itemid', 'event' => 'event', 'logtype' => 'logtype', 'source' => 'source'] as $key => $column) {
            if (array_key_exists($key, $filters)) {
                $values = array_map(static fn ($v): string|int => $key === 'logtype' ? (int) $v : (string) $v, (array) $filters[$key]);
                $query->whereIn($column, $values);
            }
        }
        if (array_key_exists('userid', $filters)) {
            $query->where('userid', (int) $filters['userid']);
        }
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', \Pramnos\Database\Instant::at($database, (int) $filters['from']));
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<', \Pramnos\Database\Instant::at($database, (int) $filters['to']));
        }

        $total     = (clone $query)->count();
        $direction = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $result    = $query->orderBy('created_at', $direction)
            ->orderBy($scoped ? 'eventid' : 'id', $direction)
            ->limit(max(1, $limit))->offset(max(0, $offset))->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = static::decode($result->fields);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * What happened in one tenant, newest first — an organisation's activity log.
     *
     * ```php
     * ChangelogReader::eventsInScope('org:12');               // everything
     * ChangelogReader::eventsInScope('org:12', 'post', 20);   // only its posts
     * ```
     *
     * Reads the application events only, from their table rather than the history view: the
     * automatic feed carries no scope, and the view has no scope column to filter on. The
     * scope is whatever the models' {@see \Pramnos\Application\Model::changeScope()}
     * returns; events written before a model had one are not in any scope.
     *
     * Empty, not an error, before the `changelog_events.scope` migration has run.
     *
     * @return list<array<string, mixed>>
     */
    public static function eventsInScope(string $scope, ?string $entity = null, int $limit = 50): array
    {
        $database = \Pramnos\Database\Database::getInstance();
        if (!$database->schema()->hasColumn(ChangelogWriter::EVENTS_TABLE, 'scope')) {
            return [];
        }

        $query = $database->queryBuilder()
            ->table(ChangelogWriter::EVENTS_TABLE)
            ->where('scope', $scope);
        if ($entity !== null) {
            $query->where('entity', $entity);
        }

        $result = $query->orderBy('created_at', 'DESC')->limit($limit)->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = static::decode($result->fields);
        }

        return $rows;
    }

    /**
     * The request context behind one feed row, when it was captured.
     *
     * Separate and explicit, for the one row somebody is investigating. It is deliberately
     * not joined into {@see history()}: a listing would pay for a column no listing shows,
     * which is what the reference application's compatibility view does.
     *
     * Returns null when no trace was captured, or when it has aged out — traces are kept
     * days and the rows they describe are kept weeks, so most rows have none.
     *
     * @return array<string, mixed>|null
     */
    public static function trace(string $entity, string|int $itemid, string $createdAt): ?array
    {
        $database = \Pramnos\Database\Database::getInstance();
        $table    = $database->schema()->resolveTableName(ChangelogWriter::TRACE_TABLE);

        $result = $database->queryBuilder()
            ->table($table)
            ->where('entity', $entity)
            ->where('itemid', (string) $itemid)
            ->where('created_at', $createdAt)
            ->limit(1)
            ->get();

        if (!$result || !$result->fetch()) {
            return null;
        }

        return static::decode($result->fields);
    }

    /**
     * Turn the JSON columns back into arrays.
     *
     * MySQL hands them back as strings and PostgreSQL may hand `jsonb` back either way
     * depending on the driver, so a caller that trusted one would break on the other
     * backend — and only there.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function decode(array $row): array
    {
        foreach (['changes', 'details', 'context'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $decoded = json_decode($row[$column], true);
                if (is_array($decoded)) {
                    $row[$column] = $decoded;
                }
            }
        }

        return $row;
    }
}
