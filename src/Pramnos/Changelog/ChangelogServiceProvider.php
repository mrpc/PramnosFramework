<?php

declare(strict_types=1);

namespace Pramnos\Changelog;

use Pramnos\Application\ServiceProvider;
use Pramnos\Database\WriteSpool;

/**
 * Bootstraps the change log.
 *
 * Activated by listing `changelog` in app.php features, which also decides whether the
 * tables exist at all — the migrations live under a directory named for the feature, and
 * an application that does not enable it gets none of them.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ChangelogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to bind: the writer is a listener, not a service.
    }

    public function boot(): void
    {
        // The spool round-trips each row through JSON, so a nested array has to be
        // re-encoded before it reaches queryBuilder()->insert(). Same mechanism the
        // framework already uses for tokenactions, and the reason it exists: without it
        // the drain writes the string "Array" into a jsonb column, once per row, with no
        // error anywhere.
        WriteSpool::transform(
            ChangelogWriter::TABLE,
            static fn(array $row): array => static::encodeJson($row, ['changes'])
        );
        WriteSpool::transform(
            ChangelogWriter::EVENTS_TABLE,
            static fn(array $row): array => static::encodeJson($row, ['details'])
        );
        WriteSpool::transform(
            ChangelogWriter::TRACE_TABLE,
            static fn(array $row): array => static::encodeJson($row, ['context'])
        );

        ChangelogWriter::listen();
    }

    /**
     * JSON-encode the named columns, leaving a null null.
     *
     * `null` matters: these columns are nullable, and `json_encode(null)` is the string
     * `"null"` — which a database stores as a JSON null rather than as SQL NULL, so
     * `WHERE details IS NULL` stops matching rows that have no details.
     *
     * @param  array<string, mixed> $row
     * @param  list<string>         $columns
     * @return array<string, mixed>
     */
    protected static function encodeJson(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (isset($row[$column]) && is_array($row[$column])) {
                $row[$column] = json_encode(
                    $row[$column],
                    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                );
            }
        }

        return $row;
    }
}
