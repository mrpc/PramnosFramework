<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Database\QueryBuilder;
use Pramnos\Framework\Factory;
use Pramnos\Mcp\McpToolInterface;

/**
 * What the analyst's own tools share: a date window, figures per period, counts by a column,
 * and the promise that nothing here writes.
 *
 * Every tool answers aggregates. A tool that has no table to read — the feature is not
 * installed — says so instead of failing.
 */
abstract class AdminTool implements McpToolInterface
{
    /** The longest window a question may ask about, in days. */
    public const MAX_DAYS = 366;

    /** How many rows a breakdown lists before it stops. */
    public const TOP = 15;

    /** @return array<string, array<string, mixed>> The tool's own arguments, beside the window */
    protected function arguments(): array
    {
        return [];
    }

    /** @param array<string, mixed> $input */
    abstract protected function answer(array $input, Window $window): array;

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'days'  => ['type' => 'integer', 'description' => 'The last N days, 30 by default; at most ' . self::MAX_DAYS . '.'],
            'from'  => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD, instead of days.'],
            'to'    => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD; today by default.'],
            'group' => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'description' => 'Period for series; chosen from the window by default.'],
        ] + $this->arguments()];
    }

    public function execute(array $input): mixed
    {
        try {
            return $this->answer($input, Window::from($input));
        } catch (\InvalidArgumentException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    protected function table(string $name): QueryBuilder
    {
        return Factory::getDatabase()->queryBuilder()->table($name);
    }

    protected function has(string $name): bool
    {
        return Factory::getDatabase()->schema()->hasTable($name);
    }

    /**
     * Rows counted by one column, largest first.
     *
     * @return array<string, int>
     */
    protected function countBy(QueryBuilder $query, string $column, int $limit = self::TOP): array
    {
        $result = $query->select([$column, $query->raw('COUNT(*) AS n')])
            ->groupBy($column)->orderBy('n', 'desc')->limit($limit)->get();
        $counts = [];

        while (($row = $result->fetch()) !== null) {
            $counts[(string) ($row[$column] ?? '')] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * How many of a time column's values fall in each period of the window.
     *
     * ponytail: the column's values in the window are read and bucketed here, which works the
     * same for Unix integers and timestamps on every database; a table with millions of rows in
     * one window wants the bucketing in SQL instead.
     */
    protected function series(QueryBuilder $query, string $column, Window $window, bool $unix): array
    {
        $result  = $window->apply($query, $column, $unix)->select([$column])->get();
        $buckets = $window->buckets();

        while (($row = $result->fetch()) !== null) {
            $at = $unix ? (int) $row[$column] : strtotime((string) $row[$column]);
            if ($at !== false && ($label = $window->label((int) $at)) !== null && isset($buckets[$label])) {
                $buckets[$label]++;
            }
        }

        return $buckets;
    }

    /** The query's first row, or null. @return array<string, mixed>|null */
    protected static function one(QueryBuilder $query): ?array
    {
        $result = $query->first();

        return $result && (int) $result->numRows > 0 ? (array) $result->fields : null;
    }

    /** No table to read: the feature is not installed here. */
    protected static function absent(string $what): array
    {
        return ['available' => false, 'says' => 'This installation keeps no ' . $what . '.'];
    }
}
