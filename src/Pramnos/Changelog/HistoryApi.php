<?php

declare(strict_types=1);

namespace Pramnos\Changelog;

use Pramnos\Routing\Router;

/**
 * A record's history as JSON, for a front end that draws its own — a SPA's `HistoryPanel.svelte`.
 *
 * ```php
 * \Pramnos\Changelog\HistoryApi::routes($r);
 * ```
 *
 *   - `GET /history?entity=post&itemid=42&page=1&per_page=20`   one page of {@see History::page()}
 *
 * The other filters of {@see ChangelogReader::listing()} — `origins` (comma-separated), `event`,
 * `userid`, `source`, `from`, `to`, `scope` — as query parameters, and `order=asc` for oldest first.
 * Read only by whom {@see History::allows()}: 401 for nobody signed in, 403 for somebody else.
 */
class HistoryApi
{
    /** Query parameters that are filters, and how each is read. */
    private const FILTERS = ['entity', 'itemid', 'origins', 'event', 'logtype', 'userid', 'source', 'from', 'to', 'scope'];

    /** Mount the route, under `$path`. */
    public static function routes(Router $r, string $path = '/history'): void
    {
        $r->get('/' . trim($path, '/'), static fn () => (new static())->list());
    }

    /** One page of history. */
    public function list(): array
    {
        $user = \Pramnos\User\User::current();
        if (!is_object($user) || (int) ($user->userid ?? 0) <= 1) {
            return ['status' => 401, 'error' => 'Sign in to read a history.'];
        }
        $filters = [];
        foreach (self::FILTERS as $name) {
            $value = $_GET[$name] ?? '';
            if ($value === '' || $value === []) {
                continue;
            }
            $filters[$name] = match ($name) {
                'origins', 'event', 'logtype' => is_array($value) ? $value : explode(',', (string) $value),
                'userid', 'from', 'to'        => (int) $value,
                default                       => (string) $value,
            };
        }
        if (!History::allows($user, $filters)) {
            return ['status' => 403, 'error' => 'This history is not yours to read.'];
        }

        return ['status' => 200, 'data' => History::page(
            $filters,
            (int) ($_GET['page'] ?? 1),
            (int) ($_GET['per_page'] ?? 20),
            ($_GET['order'] ?? '') === 'asc' ? 'asc' : 'desc'
        )];
    }
}
