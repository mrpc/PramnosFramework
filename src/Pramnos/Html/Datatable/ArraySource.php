<?php

declare(strict_types=1);

namespace Pramnos\Html\Datatable;

/**
 * A server-side datatable's answer from rows held in memory — for a list that is not one table's
 * rows, such as segments beside groups, where {@see Datasource} has no query to page.
 *
 * ```php
 * return Response::json(ArraySource::answer(
 *     $rows,                                         // every row, with what sorts it
 *     [0 => 'name', 2 => 'members'],                 // column index => the key it sorts by
 *     static fn (array $row): string => $row['name'] . ' ' . $row['description'],
 *     fn (array $page): array => array_map($this->cells(...), $page), // only the rows shown
 * ));
 * ```
 *
 * It reads DataTables' own request — `draw`, `start`, `length` (-1 for all), `search[value]`,
 * `order[N][column|dir]` — and answers `draw`, `recordsTotal`, `recordsFiltered` and `data`. The
 * search matches anywhere in a row's text, ignoring case; a column not named in `$sortable` is not
 * sorted by. What is costly per row belongs in `$cells`, which sees only the page.
 *
 * ponytail: every row is in memory, so it suits lists of hundreds or a few thousand; a longer
 * list wants its own query and {@see Datasource}.
 */
final class ArraySource
{
    /**
     * The answer to one request.
     *
     * @param list<array<string, mixed>>               $rows     Every row
     * @param array<int, string>                       $sortable Column index => the row's key it sorts by
     * @param callable(array<string, mixed>): string   $text     What the search is matched against
     * @param callable(list<array<string, mixed>>): list<list<string>> $cells The page's rows drawn
     * @param array<string, mixed>|null                $request  DataTables' parameters; $_POST when null
     * @return array{draw: int, recordsTotal: int, recordsFiltered: int, data: list<list<string>>}
     */
    public static function answer(array $rows, array $sortable, callable $text, callable $cells, ?array $request = null): array
    {
        $request = $request ?? $_POST;
        $total   = count($rows);
        $search  = trim((string) (is_array($request['search'] ?? null) ? ($request['search']['value'] ?? '') : ($request['search'] ?? '')));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => mb_stripos($text($row), $search) !== false));
        }

        $order = [];
        foreach ((array) ($request['order'] ?? []) as $o) {
            $key = $sortable[(int) ($o['column'] ?? -1)] ?? null;
            if ($key !== null) {
                $order[] = [$key, ($o['dir'] ?? 'asc') === 'desc' ? -1 : 1];
            }
        }
        if ($order !== []) {
            usort($rows, static function (array $a, array $b) use ($order): int {
                foreach ($order as [$key, $dir]) {
                    $x = $a[$key] ?? null;
                    $y = $b[$key] ?? null;
                    $c = is_string($x) || is_string($y) ? strcmp(mb_strtolower((string) $x), mb_strtolower((string) $y)) : ($x <=> $y);
                    if ($c !== 0) {
                        return $c * $dir;
                    }
                }

                return 0;
            });
        }

        $length = (int) ($request['length'] ?? -1);
        $page   = $length === -1 ? $rows : array_slice($rows, max(0, (int) ($request['start'] ?? 0)), max(1, $length));

        return ['draw' => (int) ($request['draw'] ?? 1), 'recordsTotal' => $total, 'recordsFiltered' => count($rows), 'data' => $page === [] ? [] : array_values($cells(array_values($page)))];
    }
}
