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
