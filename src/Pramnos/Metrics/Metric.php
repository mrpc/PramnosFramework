<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * One number a business reports, declared once: what it counts, how its periods combine, what it
 * is broken down by, and who may see it.
 *
 * ```php
 * Metric::flow('orders.count', 'Orders')
 *     ->aggregate(Metric::SUM)
 *     ->dimensions(['region', 'plan'])
 *     ->source(fn (Window $w, array $by, Database $db): iterable => $db->queryBuilder()->table('orders')
 *         ->select([...$by, $db->queryBuilder()->raw('COUNT(*) AS value')])
 *         ->where('paid_at', '>=', $w->startText())->where('paid_at', '<', $w->endText())
 *         ->groupBy($by)->getAllOrFail())
 *     ->rows(fn (Window $w, array $filters, Database $db): iterable => /* the orders behind it *\/ [])
 *     ->allowed(fn (?object $user): bool => AdminAccess::allows($user, 'admin.reports'));
 *
 * Metric::level('customers.active', 'Active customers')->source(fn (Window $w): int => Customers::activeCount());
 * Metric::derived('orders.conversion', '100 * orders.count / visits.unique', 'Conversion', '%');
 * ```
 *
 * **Flow or level.** A flow counts what happened inside a period — orders, sign-ups — and any
 * period can be counted again from the tables. A level is how things stood — active members — and
 * the tables only know how they stand now, so a level is computed while its period is open and
 * kept as it stood when the period closed; its past comes from {@see Metrics::import()}.
 *
 * **Sum or distinct.** A {@see SUM} metric's breakdowns add up: orders by region add up to all
 * orders, so it is stored by every dimension it has and any combination is added together when
 * read. A {@see DISTINCT} one does not — the customers who ordered in September are not the
 * customers of each of its days added up, nor of each region — so each grain is counted on its own,
 * and so is each breakdown it {@see keeps()}; a breakdown it does not keep cannot be read.
 *
 * **The source** is called once for each period and breakdown with the window, the dimensions to
 * group by (`$by`, possibly empty) and the connection to read — the replica's when the
 * application set `'metrics' => ['replica' => true]`. It answers rows carrying those dimensions
 * and a `value`, or a single number when `$by` is empty.
 *
 * A **derived** metric is arithmetic over others — `+ - * /`, numbers and parentheses — worked
 * out when read, never stored, so it cannot go stale. A division by nothing is no value.
 */
final class Metric
{
    public const FLOW    = 'flow';
    public const LEVEL   = 'level';
    public const DERIVED = 'derived';

    /** Breakdowns add up, periods add up. */
    public const SUM = 'sum';

    /** Counted on its own at every grain and every breakdown it keeps. */
    public const DISTINCT = 'distinct';

    /** A mean, kept with how many it is of, so that means combine weighted: a delivery time. */
    public const AVG = 'avg';

    /** The least of the period: a lowest temperature. */
    public const MIN = 'min';

    /** The most of the period: a highest load. */
    public const MAX = 'max';

    /** A rise is good news, coloured so and ranked first. */
    public const HIGHER_IS_BETTER = 'higher';

    /** A fall is good news: a cost, a waiting time. */
    public const LOWER_IS_BETTER = 'lower';

    /** How a value reads. */
    public const FORMATS = ['number', 'currency', 'percent', 'duration'];

    private string $unit        = '';
    private string $aggregate   = self::SUM;
    private string $description = '';

    /** @var list<string> */
    private array $dimensions = [];

    /** @var list<list<string>>|null */
    private ?array $keeps = null;

    private ?\Closure $source  = null;

    /** @var list<string>|null The grains it is kept at; null: the application's ({@see Metrics::grains()}) */
    private ?array $grains = null;

    /** The definition's version: computed and read; another is kept beside it. */
    private int $version = 1;

    /** Which way is good news; null: neither. */
    private ?string $direction = null;

    /** How its value reads. */
    private string $format = 'number';

    /** The first day the source can answer; null: every day. */
    private ?string $since = null;

    /** Why a period cannot be answered, or null when it can: the application's own rule. */
    private ?\Closure $missing = null;

    /** How often new data should arrive (`1 hour`); null: no expectation. */
    private ?string $expect = null;

    /** The source's column that names the organisation a row is of; null for none. */
    private ?string $tenantColumn = null;
    private ?\Closure $rows    = null;
    private ?\Closure $allowed = null;

    /** @var list<string|float> The expression, in reverse Polish order */
    private array $program = [];

    private function __construct(public readonly string $kind, public readonly string $name, private string $label)
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/', $name) || strlen($name) > 100) {
            throw new \InvalidArgumentException('A metric\'s name is lower-case words joined by . and _: "' . $name . '" is not.');
        }
    }

    /** Something that happens in a period, counted from the tables for any period. */
    public static function flow(string $name, string $label = ''): self
    {
        return new self(self::FLOW, $name, $label);
    }

    /** How things stand, kept as they stood when each period closed. */
    public static function level(string $name, string $label = ''): self
    {
        return new self(self::LEVEL, $name, $label);
    }

    /** Arithmetic over other metrics, worked out when read. */
    public static function derived(string $name, string $expression, string $label = '', string $unit = ''): self
    {
        $metric          = new self(self::DERIVED, $name, $label);
        $metric->program = self::compile($expression);
        $metric->unit    = $unit;

        return $metric;
    }

    /** How it reads: `''`, `'€'`, `'%'`. */
    public function unit(string $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    /** {@see SUM} or {@see DISTINCT}. */
    public function aggregate(string $aggregate): self
    {
        if (!in_array($aggregate, [self::SUM, self::DISTINCT, self::AVG, self::MIN, self::MAX], true)) {
            throw new \InvalidArgumentException('A metric aggregates by ' . implode(', ', [self::SUM, self::DISTINCT, self::AVG, self::MIN, self::MAX]) . ', not "' . $aggregate . '".');
        }
        $this->aggregate = $aggregate;

        return $this;
    }

    /** @param list<string> $dimensions What it is broken down by, by {@see Dimension} name */
    public function dimensions(array $dimensions): self
    {
        $this->dimensions = array_values(array_unique(array_map('strval', $dimensions)));

        return $this;
    }

    /**
     * The breakdowns a {@see DISTINCT} metric is counted by, each a list of dimensions — the
     * total and each dimension alone unless it says.
     *
     * @param list<list<string>> $sets
     */
    public function keeps(array $sets): self
    {
        $this->keeps = array_map(static fn (array $set): array => self::sorted($set), $sets);

        return $this;
    }

    /**
     * A declared query instead of a closure (`from()` … `sum()`): the table, its time column and
     * whether it holds unix time, what is added up, the conditions, and each dimension's column.
     *
     * @var array{table: string, time: string, unix: bool, fn: string, column: string, where: list<array{0: string, 1: mixed}>, columns: array<string, string>}|null
     */
    private ?array $query = null;

    /**
     * Read its numbers from a table, declared rather than written: the framework makes the query.
     *
     * ```php
     * Metric::flow('payments.amount', 'Revenue')->from('invoices')->at('date', unix: true)
     *     ->sum('total')->where('status', 1)->dimension('market', 'countryid')->tenant('organizationid');
     * ```
     */
    public function from(string $table): self
    {
        $this->query = ($this->query ?? ['time' => '', 'unix' => false, 'fn' => '', 'column' => '', 'where' => [], 'columns' => []]) + ['table' => $table];
        $this->query['table'] = $table;

        return $this;
    }

    /**
     * The table's time column; `unix: true` for one that holds unix seconds, read as they are. A
     * date or time column is compared with UTC instants, as the framework writes them.
     */
    public function at(string $column, bool $unix = false): self
    {
        $this->needsTable('at');
        $this->query['time'] = self::identifier($column);
        $this->query['unix'] = $unix;

        return $this;
    }

    /** Add up a column. */
    public function sum(string $column): self
    {
        return $this->counting('SUM', $column);
    }

    /** Count the rows. */
    public function count(): self
    {
        return $this->counting('COUNT', '*');
    }

    /** The mean of a column, kept with how many it is of: an average metric. */
    public function avg(string $column): self
    {
        $this->aggregate = self::AVG;

        return $this->counting('AVG', $column);
    }

    /** The least of a column in the period: a minimum metric. */
    public function min(string $column): self
    {
        $this->aggregate = self::MIN;

        return $this->counting('MIN', $column);
    }

    /** The most of a column in the period: a maximum metric. */
    public function max(string $column): self
    {
        $this->aggregate = self::MAX;

        return $this->counting('MAX', $column);
    }

    /**
     * The version of its definition, for one that was corrected: the new version is computed and
     * read, and the figures of the old one stay beside it, read with `Query::version()` — "as
     * before" against "corrected".
     */
    public function version(int $version): self
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('A version is 1 or more.');
        }
        $this->version = $version;

        return $this;
    }

    /** The version computed and read. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** Which way is good news: {@see HIGHER_IS_BETTER} or {@see LOWER_IS_BETTER}, for colouring and ranking. */
    public function direction(string $direction): self
    {
        if (!in_array($direction, [self::HIGHER_IS_BETTER, self::LOWER_IS_BETTER], true)) {
            throw new \InvalidArgumentException('A direction is Metric::HIGHER_IS_BETTER or Metric::LOWER_IS_BETTER.');
        }
        $this->direction = $direction;

        return $this;
    }

    /** Which way is good news, or null where neither is. */
    public function getDirection(): ?string
    {
        return $this->direction;
    }

    /** How its value reads: one of {@see FORMATS}. */
    public function format(string $format): self
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('A format is one of: ' . implode(', ', self::FORMATS) . '.');
        }
        $this->format = $format;

        return $this;
    }

    /** How its value reads. */
    public function getFormat(): string
    {
        return $this->format;
    }

    /** Count the distinct values of a column — people, say: a distinct metric, which does not add up. */
    public function countDistinct(string $column): self
    {
        $this->aggregate = self::DISTINCT;

        return $this->counting('COUNT DISTINCT', $column);
    }

    /** A condition of the declared query: a column equal to a value, or one of a list. */
    public function where(string $column, mixed $value): self
    {
        $this->needsTable('where');
        $this->query['where'][] = [self::identifier($column), $value];

        return $this;
    }

    /**
     * A dimension of the declared query, and the column — or SQL expression — that gives it:
     * `dimension('market', 'countryid')`, `dimension('year', 'EXTRACT(YEAR FROM created)')`.
     */
    public function dimension(string $name, string $column): self
    {
        $this->needsTable('dimension');
        $this->query['columns'][$name] = $column;
        if (!in_array($name, $this->dimensions, true)) {
            $this->dimensions[] = $name;
        }

        return $this;
    }

    /** What is counted, for the declared query. */
    private function counting(string $fn, string $column): self
    {
        $this->needsTable('a count');
        $this->query['fn']     = $fn;
        $this->query['column'] = $column === '*' ? '*' : self::identifier($column);

        return $this;
    }

    /** A declared query's part, refused before `from()`. */
    private function needsTable(string $what): void
    {
        if ($this->query === null) {
            throw new \LogicException('Metric ' . $this->name . ': declare the table with from() before ' . $what . '.');
        }
    }

    /** A column's name as a declared query takes it: letters, digits, `_` and a `table.` before it. */
    private static function identifier(string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw new \InvalidArgumentException('"' . $column . '" is not a column\'s name.');
        }

        return $column;
    }

    /**
     * The declared query as a source: the window's rows of the table, counted, grouped by the
     * dimensions asked — each its column or expression — and the organisation's column.
     */
    private function declaredSource(): \Closure
    {
        $q = $this->query;
        if ($q['time'] === '' || $q['fn'] === '') {
            throw new \LogicException('Metric ' . $this->name . ': a declared query needs at() and what it counts (sum(), count(), countDistinct()).');
        }

        return static function (Window $w, array $by, \Pramnos\Database\Database $db) use ($q): array {
            $query  = $db->queryBuilder()->table($q['table']);
            $select = [];
            $group  = [];
            foreach ($by as $name) {
                $expression = $q['columns'][$name] ?? $name;
                // Raw: a dimension may be an expression the application declared, aliased to its name.
                $select[] = $query->raw($expression . ' AS ' . self::identifier($name));
                $group[]  = $query->raw($expression);
            }
            $counted  = match ($q['fn']) {
                'COUNT'          => 'COUNT(*)',
                'COUNT DISTINCT' => 'COUNT(DISTINCT ' . $q['column'] . ')',
                default          => $q['fn'] . '(' . $q['column'] . ')',
            };
            $select[] = $query->raw($counted . ' AS value');
            if ($q['fn'] === 'AVG') {
                // How many the mean is of, so that means combine weighted.
                $select[] = $query->raw('COUNT(' . $q['column'] . ') AS count');
            }
            $query->select($select);
            [$from, $to] = $q['unix'] ? [$w->startsAt(), $w->endsAt()] : [\Pramnos\Database\Instant::utc($w->startsAt(), $db), \Pramnos\Database\Instant::utc($w->endsAt(), $db)];
            $query->where($q['time'], '>=', $from)->where($q['time'], '<', $to);
            foreach ($q['where'] as [$column, $value]) {
                is_array($value) ? $query->whereIn($column, array_values($value)) : $query->where($column, $value);
            }
            if ($group !== []) {
                $query->groupBy($group);
            }

            return $query->getAllOrFail();
        };
    }

    /** @var array<string, list<array<string, mixed>>> Rows read ahead, by period and breakdown ({@see prefetch()}) */
    private array $ahead = [];

    /**
     * Read many periods of a declared source at once — one query per breakdown, grouped by the
     * period in the metrics' zone — instead of one query per period, where it can be done exactly:
     * PostgreSQL, a unix-time column. {@see measure()} then answers from what was read. Elsewhere
     * nothing is read ahead and each period is queried on its own.
     *
     * @param list<Window> $windows
     * @return bool Whether it was read ahead
     */
    public function prefetch(array $windows, \Pramnos\Database\Database $db): bool
    {
        $this->ahead = [];
        $q = $this->query;
        if ($q === null || $windows === [] || $db->type !== 'postgresql' || !$q['unix'] || $q['time'] === '' || $q['fn'] === '') {
            return false;
        }
        $grain = $windows[0]->grain;
        $zone  = $windows[0]->start->getTimezone()->getName();
        if (preg_match('~^[A-Za-z0-9_/+-]+$~', $zone) !== 1) {
            return false;
        }
        $first = $windows[0]->startsAt();
        $last  = end($windows)->endsAt();
        foreach ($this->storedSets() as $set) {
            $by     = $this->tenantColumn !== null ? [...$set, $this->tenantColumn] : $set;
            $query  = $db->queryBuilder()->table($q['table']);
            // Raw: the period of each row in the metrics' zone is PostgreSQL's date_trunc.
            $bucket = "date_trunc('" . $grain . "', to_timestamp(" . $q['time'] . ") AT TIME ZONE '" . $zone . "')";
            $select = [$query->raw($bucket . ' AS pf_period')];
            $group  = [$query->raw($bucket)];
            foreach ($by as $name) {
                $expression = $q['columns'][$name] ?? $name;
                $select[] = $query->raw($expression . ' AS ' . self::identifier($name));
                $group[]  = $query->raw($expression);
            }
            $select[] = $query->raw(match ($q['fn']) {
                'COUNT'          => 'COUNT(*)',
                'COUNT DISTINCT' => 'COUNT(DISTINCT ' . $q['column'] . ')',
                default          => $q['fn'] . '(' . $q['column'] . ')',
            } . ' AS value');
            if ($q['fn'] === 'AVG') {
                $select[] = $query->raw('COUNT(' . $q['column'] . ') AS count');
            }
            $query->select($select)->where($q['time'], '>=', $first)->where($q['time'], '<', $last);
            foreach ($q['where'] as [$column, $value]) {
                is_array($value) ? $query->whereIn($column, array_values($value)) : $query->where($column, $value);
            }
            foreach ($query->groupBy($group)->getAllOrFail() as $row) {
                $key = Window::of($grain, substr((string) $row['pf_period'], 0, 19), new \DateTimeZone($zone))->key();
                unset($row['pf_period']);
                $this->ahead[$key . '|' . implode(',', $by)][] = $row;
            }
            foreach ($windows as $window) {
                $this->ahead[$window->key() . '|' . implode(',', $by)] ??= [];
            }
        }

        return true;
    }

    /** Let go of what {@see prefetch()} read. */
    public function forgetAhead(): void
    {
        $this->ahead = [];
    }

    /** @param \Closure(Window, list<string>, \Pramnos\Database\Database): (iterable<array<string, mixed>>|int|float|null) $source */
    public function source(\Closure $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * The source's column that names the organisation each row is of: the source is asked by it
     * too, beside the dimensions, and each value is kept as that organisation's. Not a dimension —
     * a reader sees only the organisations they may ({@see Query::for()}).
     */
    public function tenant(string $column): self
    {
        $this->tenantColumn = $column;

        return $this;
    }

    /**
     * The grains it is kept at — `['hour', 'day', 'month']` — when not the application's. An hour
     * is kept only for a metric that asks, and `metrics.retention` keeps it short.
     *
     * @param list<string> $grains
     */
    public function grains(array $grains): self
    {
        foreach ($grains as $grain) {
            if (!in_array($grain, Window::GRAINS, true)) {
                throw new \InvalidArgumentException('Grain "' . $grain . '" is not one of: ' . implode(', ', Window::GRAINS) . '.');
            }
        }
        $this->grains = array_values(array_unique($grains));

        return $this;
    }

    /** @return list<string>|null The grains it asked for, or null for the application's */
    public function getGrains(): ?array
    {
        return $this->grains;
    }

    /** The first day the source has data for: a period before it shows why, not a nought. */
    public function since(string $day): self
    {
        if (\DateTime::createFromFormat('!Y-m-d', $day) === false) {
            throw new \InvalidArgumentException('since() takes a day, YYYY-MM-DD.');
        }
        $this->since = $day;

        return $this;
    }

    /**
     * Which periods the source cannot answer, and why: `fn (Window $w): ?string` — the reason, or
     * null when it can.
     */
    public function missing(\Closure $why): self
    {
        $this->missing = $why;

        return $this;
    }

    /**
     * Why a period has no figure from the source — before {@see since()}, or as {@see missing()}
     * says — or null when it has one.
     */
    public function gapOf(Window $window): ?string
    {
        if ($this->since !== null && $window->end <= Window::of(Window::DAY, $this->since)->start) {
            return sprintf(t('No data before %s.'), $this->since);
        }
        $why = $this->missing !== null ? ($this->missing)($window) : null;

        return is_string($why) && $why !== '' ? $why : null;
    }

    /** How often new data should arrive — `1 hour`, `1 day` — for a card's staleness and "data stopped". */
    public function expect(string $every): self
    {
        if (strtotime('+' . $every, 0) === false || strtotime('+' . $every, 0) <= 0) {
            throw new \InvalidArgumentException('expect() takes a length of time: "1 hour", "1 day".');
        }
        $this->expect = $every;

        return $this;
    }

    /** How often new data should arrive, or null. */
    public function getExpect(): ?string
    {
        return $this->expect;
    }

    /** The column that names the organisation, or null. */
    public function getTenant(): ?string
    {
        return $this->tenantColumn;
    }

    /** @param \Closure(Window, array<string, string>, \Pramnos\Database\Database): iterable<array<string, mixed>> $rows The records behind a number */
    public function rows(\Closure $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    /** @param \Closure(?object): bool $allowed Who may see it; the dashboard's role, `manager`, by default */
    public function allowed(\Closure $allowed): self
    {
        $this->allowed = $allowed;

        return $this;
    }

    /** A line on what it counts, for the catalogue and the assistant. */
    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : $this->name;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function getAggregate(): string
    {
        return $this->aggregate;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * The breakdowns stored: every dimension together for a sum (any other is added up from it),
     * the declared ones for a distinct; the total always.
     *
     * @return list<list<string>>
     */
    public function storedSets(): array
    {
        if ($this->aggregate === self::SUM) {
            return $this->dimensions === [] ? [[]] : [[], self::sorted($this->dimensions)];
        }
        $sets = $this->keeps ?? array_map(static fn (string $d): array => [$d], $this->dimensions);
        array_unshift($sets, []);

        return array_values(array_unique($sets, SORT_REGULAR));
    }

    public function hasSource(): bool
    {
        return $this->source !== null || $this->query !== null;
    }

    public function hasRows(): bool
    {
        return $this->rows !== null;
    }

    /**
     * Ask the source for one period and breakdown, as rows of `dims` and `value`.
     *
     * @param list<string> $by
     * @return list<array{dims: array<string, string>, value: float}>
     */
    public function measure(Window $window, array $by, \Pramnos\Database\Database $db): array
    {
        $this->source ??= $this->query !== null ? $this->declaredSource() : null;
        if ($this->source === null) {
            throw new \LogicException('Metric ' . $this->name . ' has no source.');
        }
        $asked  = $this->tenantColumn !== null ? [...$by, $this->tenantColumn] : $by;
        $answer = $this->ahead[$window->key() . '|' . implode(',', $asked)] ?? ($this->source)($window, $asked, $db);
        if ($answer === null || is_int($answer) || is_float($answer) || is_numeric($answer)) {
            if ($asked !== []) {
                throw new \UnexpectedValueException('Metric ' . $this->name . ' answered one number when asked by ' . implode(', ', $by) . '.');
            }

            return [['dims' => [], 'value' => (float) ($answer ?? 0), 'tenant' => 0]];
        }
        $out = [];
        foreach ($answer as $row) {
            $row  = (array) $row;
            $dims = [];
            foreach ($by as $name) {
                $dims[$name] = (string) ($row[$name] ?? '');
            }
            $tenant = $this->tenantColumn !== null ? (int) ($row[$this->tenantColumn] ?? 0) : 0;
            $key    = $tenant . json_encode($dims);
            if (in_array($this->aggregate, [self::AVG, self::MIN, self::MAX], true)) {
                // One row per breakdown: a mean, a least or a most is not added to another.
                $out[$key] = ['dims' => $dims, 'value' => (float) ($row['value'] ?? 0), 'tenant' => $tenant]
                    + ($this->aggregate === self::AVG ? ['count' => (int) ($row['count'] ?? 1)] : []);
                continue;
            }
            $out[$key] ??= ['dims' => $dims, 'value' => 0.0, 'tenant' => $tenant];
            $out[$key]['value'] += (float) ($row['value'] ?? 0);
        }

        return array_values($out);
    }

    /**
     * The records behind a number, or an empty list when it declares none.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function records(Window $window, array $filters, \Pramnos\Database\Database $db): array
    {
        if ($this->rows === null) {
            return [];
        }
        $out = [];
        foreach (($this->rows)($window, $filters, $db) as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    /** Whether this person may see it. */
    public function allows(?object $user): bool
    {
        return $this->allowed !== null
            ? (bool) ($this->allowed)($user)
            : \Pramnos\User\Roles::atLeast($user, \Pramnos\User\Roles::MANAGER);
    }

    /** @return list<string> The metrics a derived one is worked out from */
    public function operands(): array
    {
        return array_values(array_unique(array_filter($this->program, static fn ($t): bool => is_string($t) && !in_array($t, ['+', '-', '*', '/', 'neg'], true))));
    }

    /**
     * Work a derived metric out from its operands' values; null when one is missing or a
     * division is by nothing.
     *
     * @param array<string, float|null> $values By operand name
     */
    public function evaluate(array $values): ?float
    {
        $stack = [];
        foreach ($this->program as $token) {
            if (is_float($token)) {
                $stack[] = $token;
                continue;
            }
            if ($token === 'neg') {
                $a = array_pop($stack);
                $stack[] = $a === null ? null : -$a;
                continue;
            }
            if (!in_array($token, ['+', '-', '*', '/'], true)) {
                $stack[] = isset($values[$token]) ? (float) $values[$token] : null;
                continue;
            }
            $b = array_pop($stack);
            $a = array_pop($stack);
            $stack[] = $a === null || $b === null || ($token === '/' && $b == 0.0) ? null : match ($token) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => $a / $b,
            };
        }

        return $stack[0] ?? null;
    }

    /**
     * An expression in reverse Polish order: names, numbers, `+ - * /`, unary minus, parentheses.
     *
     * @return list<string|float>
     */
    private static function compile(string $expression): array
    {
        if (!preg_match_all('/\s*(?:(\d+(?:\.\d+)?)|([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)|([-+*\/()]))\s*/A', $expression, $m, PREG_SET_ORDER)
            || implode('', array_column($m, 0)) !== $expression) {
            throw new \InvalidArgumentException('Cannot read the metric expression "' . $expression . '".');
        }
        $out  = [];
        $ops  = [];
        $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2, 'neg' => 3];
        $prev = null;
        foreach ($m as $t) {
            if (($t[1] ?? '') !== '') {
                $out[] = (float) $t[1];
                $prev  = 'operand';
            } elseif (($t[2] ?? '') !== '') {
                $out[] = $t[2];
                $prev  = 'operand';
            } elseif ($t[3] === '(') {
                $ops[] = '(';
                $prev  = '(';
            } elseif ($t[3] === ')') {
                while ($ops !== [] && end($ops) !== '(') {
                    $out[] = array_pop($ops);
                }
                if (array_pop($ops) !== '(') {
                    throw new \InvalidArgumentException('Unbalanced parentheses in "' . $expression . '".');
                }
                $prev = 'operand';
            } else {
                $op = $t[3] === '-' && $prev !== 'operand' ? 'neg' : $t[3];
                if ($op !== 'neg' && $prev !== 'operand') {
                    throw new \InvalidArgumentException('An operator without an operand in "' . $expression . '".');
                }
                while ($op !== 'neg' && $ops !== [] && end($ops) !== '(' && $prec[end($ops)] >= $prec[$op]) {
                    $out[] = array_pop($ops);
                }
                $ops[] = $op;
                $prev  = 'op';
            }
        }
        if ($prev !== 'operand') {
            throw new \InvalidArgumentException('The expression "' . $expression . '" ends without an operand.');
        }
        while ($ops !== []) {
            $op = array_pop($ops);
            if ($op === '(') {
                throw new \InvalidArgumentException('Unbalanced parentheses in "' . $expression . '".');
            }
            $out[] = $op;
        }

        return $out;
    }

    /** @param list<string> $set @return list<string> */
    private static function sorted(array $set): array
    {
        $set = array_values(array_unique(array_map('strval', $set)));
        sort($set);

        return $set;
    }
}
