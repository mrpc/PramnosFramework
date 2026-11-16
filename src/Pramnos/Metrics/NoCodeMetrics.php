<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

use Pramnos\Database\Database;
use Pramnos\Database\Instant;
use Pramnos\Framework\Factory;

/**
 * A metric defined on the screen, not in code (BI roadmap phase 7i, `docs/Metrics-BI-Design.md`
 * §15.1.10): an administrator picks a table, a time column and what to count, each from the
 * tables and columns the application already allowed —
 *
 * ```php
 * 'metrics' => ['no_code' => ['tables' => [
 *     'invoices' => ['time' => ['date'], 'unix' => false, 'columns' => ['total', 'status', 'market']],
 * ]]],
 * ```
 *
 * — never a table, column or condition of their own typing. `create()`/`update()` refuse anything
 * outside that allowlist; `cost()` runs the declared query's `EXPLAIN` before it is saved, so the
 * screen can show what it would cost. A declaration becomes an ordinary {@see Metric} the same
 * way a PHP-declared one is — the framework makes the one query, not two engines — loaded by
 * {@see Metrics::load()} like {@see \Pramnos\Audience\AudienceMetrics}'s own, unless
 * `'metrics' => ['no_code' => false]`.
 *
 * Deliberately not supported in this pass: every aggregation {@see Metric} itself can do
 * (`last()`, `counter()`, cohorts), segment/attribute breakdowns, a tenant column, live metrics,
 * and conditions beyond equals/one-of on an allowed column — a free-text filter would undo the
 * whole point of the allowlist. Widen it later if an application genuinely needs to, over the
 * same stored shape.
 */
final class NoCodeMetrics implements Catalogue
{
    /** The safe aggregations a declaration may ask for. */
    public const FUNCTIONS = ['sum', 'count', 'countDistinct', 'avg', 'min', 'max'];

    /** @return iterable<Metric> */
    public function metrics(): iterable
    {
        foreach (self::all() as $row) {
            if (!$row['active']) {
                continue;
            }
            try {
                yield self::toMetric($row);
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('No-code metric "' . $row['name'] . '" was left out: ' . $e->getMessage(), 'metrics');
            }
        }
    }

    /**
     * The tables and columns an application allows a no-code declaration to use.
     *
     * @return array<string, array{time: list<string>, unix: bool, columns: list<string>}>
     */
    public static function allowedTables(): array
    {
        $noCode = (array) (Metrics::config()['no_code'] ?? []);
        $tables = (array) ($noCode['tables'] ?? []);
        $out    = [];
        foreach ((array) $tables as $table => $given) {
            if (!is_string($table) || !is_array($given)) {
                continue;
            }
            $time = array_values(array_filter(array_map('strval', (array) ($given['time'] ?? []))));
            if ($time === []) {
                continue;
            }
            $out[$table] = ['time' => $time, 'unix' => !empty($given['unix']), 'columns' => array_values(array_unique(array_map('strval', (array) ($given['columns'] ?? []))))];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> Every declaration, active or not, newest first */
    public static function all(): array
    {
        return self::rows($db = Factory::getDatabase())->orderBy('declaration_id', 'desc')->getAllOrFail();
    }

    /** One declaration, or null. @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $row = self::rows(Factory::getDatabase())->where('declaration_id', $id)->getAllOrFail();

        return $row[0] ?? null;
    }

    /**
     * Define a metric, or refuse it: the field that is wrong and why.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|string
     */
    public static function create(array $data, object $user): array|string
    {
        $checked = self::validate($data);
        if (is_string($checked)) {
            return $checked;
        }
        $db  = Factory::getDatabase();
        $now = Instant::utc(time(), $db);
        $db->queryBuilder()->table('pramnos.metric_declarations')->insert($checked + ['active' => true, 'created_by' => (int) $user->userid, 'created_at' => $now, 'updated_at' => $now]);
        $row = self::find((int) $db->getInsertId());

        return $row ?? $checked;
    }

    /**
     * Change a declaration, or refuse it the same way as `create()`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|string
     */
    public static function update(int $id, array $data): array|string
    {
        $checked = self::validate($data, $id);
        if (is_string($checked)) {
            return $checked;
        }
        $db = Factory::getDatabase();
        $db->queryBuilder()->table('pramnos.metric_declarations')->where('declaration_id', $id)->update($checked + ['updated_at' => Instant::utc(time(), $db)]);

        return self::find($id) ?? $checked;
    }

    /** Turns a declaration on or off: off, it is kept but no longer loaded as a metric. */
    public static function setActive(int $id, bool $active): bool
    {
        $db = Factory::getDatabase();

        return (bool) $db->queryBuilder()->table('pramnos.metric_declarations')->where('declaration_id', $id)->update(['active' => $active, 'updated_at' => Instant::utc(time(), $db)]);
    }

    /** Removes a declaration for good. */
    public static function delete(int $id): bool
    {
        return (bool) Factory::getDatabase()->queryBuilder()->table('pramnos.metric_declarations')->where('declaration_id', $id)->delete();
    }

    /**
     * The declared query's planner estimate — rows it expects to read, and whether it uses an
     * index — over the last day, before the declaration is saved.
     *
     * @param array<string, mixed> $data Checked data, as `validate()` returns it
     * @return array{rows: int, indexed: ?bool}
     */
    public static function cost(array $data): array
    {
        $db      = Factory::getDatabase();
        $query   = $db->queryBuilder()->table($data['source_table']);
        $counted = match ($data['fn']) {
            'count'         => 'COUNT(*)',
            'countDistinct' => 'COUNT(DISTINCT ' . $data['value_column'] . ')',
            default         => strtoupper($data['fn']) . '(' . $data['value_column'] . ')',
        };
        $query->select([$query->raw($counted . ' AS value')]);
        $from = $data['time_unix'] ? time() - 86400 : Instant::utc(time() - 86400, $db);
        $query->where($data['time_column'], '>=', $from);
        foreach (json_decode((string) ($data['where_json'] ?? '[]'), true) ?: [] as $condition) {
            is_array($condition['value'] ?? null) ? $query->whereIn((string) $condition['column'], $condition['value']) : $query->where((string) $condition['column'], $condition['value']);
        }
        $sql    = 'EXPLAIN ' . $query->toSql();
        $result = $db->execute($sql, ...$query->getBindings());
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = (array) $result->fields;
        }
        if ($db->type === 'postgresql') {
            $plan = implode(' ', array_map(static fn (array $r): string => (string) ($r['QUERY PLAN'] ?? ''), $rows));
            preg_match_all('/rows=(\d+)/', $plan, $matches);
            return ['rows' => $matches[1] === [] ? 0 : (int) max($matches[1]), 'indexed' => str_contains($plan, 'Index') ? true : (str_contains($plan, 'Seq Scan') ? false : null)];
        }

        return ['rows' => (int) ($rows[0]['rows'] ?? 0), 'indexed' => isset($rows[0]) ? ($rows[0]['key'] ?? null) !== null : null];
    }

    /** The runtime {@see Metric} a declaration builds — the same declared-query DSL a PHP catalogue uses. */
    public static function toMetric(array $row): Metric
    {
        $metric = Metric::flow((string) $row['name'], (string) $row['label'])->from((string) $row['source_table'])->at((string) $row['time_column'], (bool) $row['time_unix']);
        $metric = match ($row['fn']) {
            'sum'           => $metric->sum((string) $row['value_column']),
            'count'         => $metric->count(),
            'countDistinct' => $metric->countDistinct((string) $row['value_column']),
            'avg'           => $metric->avg((string) $row['value_column']),
            'min'           => $metric->min((string) $row['value_column']),
            'max'           => $metric->max((string) $row['value_column']),
            default         => throw new \LogicException('No-code metric "' . $row['name'] . '": "' . $row['fn'] . '" is not a function it can declare.'),
        };
        foreach (json_decode((string) ($row['where_json'] ?? '[]'), true) ?: [] as $condition) {
            $metric->where((string) $condition['column'], $condition['value']);
        }
        $dimensions = [];
        foreach (json_decode((string) ($row['dimensions_json'] ?? '[]'), true) ?: [] as $dimension) {
            $metric->dimension((string) $dimension['name'], (string) $dimension['column']);
            $dimensions[] = (string) $dimension['name'];
        }
        if ($dimensions !== []) {
            $metric->dimensions($dimensions);
        }
        if (is_string($row['currency'] ?? null) && $row['currency'] !== '') {
            $metric->currency((string) $row['currency']);
        }

        return $metric;
    }

    /**
     * Checked, normalised data ready for `create()`/`update()`, or the field that is wrong: every
     * table, time column, value column, dimension column and condition column is one the
     * application allowed — nothing an administrator typed reaches a query unchecked.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|string
     */
    public static function validate(array $data, ?int $exceptId = null): array|string
    {
        $name = trim((string) ($data['name'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/', $name) || strlen($name) > 100) {
            return 'name: lower-case words joined by . and _, e.g. "store.paid_orders".';
        }
        $existing = Factory::getDatabase()->queryBuilder()->table('pramnos.metric_declarations')->where('name', $name)->getAllOrFail();
        if ($existing !== [] && (int) $existing[0]['declaration_id'] !== $exceptId) {
            return 'name: already defined.';
        }
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return 'label: required.';
        }
        $tables = self::allowedTables();
        $table  = (string) ($data['source_table'] ?? '');
        if (!isset($tables[$table])) {
            return 'source_table: not one of the tables allowed here.';
        }
        $allowed = $tables[$table];
        $time    = (string) ($data['time_column'] ?? '');
        if (!in_array($time, $allowed['time'], true)) {
            return 'time_column: not one of this table\'s allowed time columns.';
        }
        $fn = (string) ($data['fn'] ?? '');
        if (!in_array($fn, self::FUNCTIONS, true)) {
            return 'fn: one of ' . implode(', ', self::FUNCTIONS) . '.';
        }
        $value = $fn === 'count' ? null : (string) ($data['value_column'] ?? '');
        if ($fn !== 'count' && !in_array($value, $allowed['columns'], true)) {
            return 'value_column: not one of this table\'s allowed columns.';
        }
        $where = [];
        foreach ((array) ($data['where'] ?? []) as $condition) {
            $column = (string) ($condition['column'] ?? '');
            if (!in_array($column, $allowed['columns'], true)) {
                return 'where: "' . $column . '" is not one of this table\'s allowed columns.';
            }
            $where[] = ['column' => $column, 'value' => $condition['value'] ?? null];
        }
        $dimensions = [];
        foreach ((array) ($data['dimensions'] ?? []) as $dimension) {
            $dimName   = (string) ($dimension['name'] ?? '');
            $dimColumn = (string) ($dimension['column'] ?? '');
            if ($dimName === '' || !in_array($dimColumn, $allowed['columns'], true)) {
                return 'dimensions: "' . $dimColumn . '" is not one of this table\'s allowed columns.';
            }
            $dimensions[] = ['name' => $dimName, 'column' => $dimColumn];
        }
        $currency = trim((string) ($data['currency'] ?? ''));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            return 'currency: a 3-letter code, or empty.';
        }

        return [
            'name' => $name, 'label' => $label, 'source_table' => $table, 'time_column' => $time, 'time_unix' => $allowed['unix'],
            'fn' => $fn, 'value_column' => $value, 'where_json' => json_encode($where, JSON_UNESCAPED_UNICODE), 'dimensions_json' => json_encode($dimensions, JSON_UNESCAPED_UNICODE),
            'currency' => $currency === '' ? null : $currency,
        ];
    }

    /** `pramnos.metric_declarations`'s query builder, every column it reads. */
    private static function rows(Database $db): \Pramnos\Database\QueryBuilder
    {
        return $db->queryBuilder()->table('pramnos.metric_declarations');
    }
}
