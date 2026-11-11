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

    /** Read from its source when asked, never stored. */
    private bool $live = false;

    /** @var string|(\Closure(int): string)|null Its currency, or each organisation's; null: `metrics.currency` */
    private string|\Closure|null $currency = null;

    /** @var array{0: string, 1: bool}|null The dimension it adds up across, and whether a value's last reading carries */
    private ?array $across = null;

    /** @var array{milestones: bool, best: bool} What achievements it earns ({@see Achievements}) */
    private array $earns = ['milestones' => false, 'best' => false];

    /** A level whose source answers how things stood at the end of any period, past ones too. */
    private bool $knowsThePast = false;

    /** Read whole by everybody who may read it, whatever their scope ({@see Metrics::scopeOf()}). */
    private bool $unscoped = false;

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

    /** How long after its end a period is still computed again, in seconds; 0: closed at its end. */
    private int $lateness = 0;

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
    /**
     * A cohort: of those who began in a period — registered, say — how many reached an event —
     * their first payment — within each number of days. Declared on a table:
     *
     * ```php
     * Metric::cohort('parents.paid_within', 'Paid within N days')->from('#PREFIX#users')
     *     ->of('regdate', unix: true)->event('firstpayment', unix: true)->within([7, 14, 30]);
     * ```
     *
     * Kept per cohort period with the dimension `within` (`7`, `14`, `30`), and its total the
     * cohort's size. The values of `within` do not add up: each is read on its own.
     */
    public static function cohort(string $name, string $label = ''): self
    {
        $metric            = new self(self::FLOW, $name, $label);
        $metric->aggregate = self::DISTINCT;
        $metric->keeps     = [['within']];
        $metric->dimensions = ['within'];

        return $metric;
    }

    /** A cohort's start: the column each one began at — the period it is counted in. */
    public function of(string $column, bool $unix = false): self
    {
        return $this->at($column, $unix);
    }

    /** A cohort's event: the column the event happened at, empty where it has not. */
    public function event(string $column, bool $unix = false): self
    {
        $this->needsTable('event');
        $this->query['event'] = self::identifier($column);
        $this->query['eventUnix'] = $unix;
        $this->query['fn']   = 'COHORT';

        return $this;
    }

    /** A cohort's offsets: within how many days of its start the event counts. @param list<int> $days */
    public function within(array $days): self
    {
        $this->needsTable('within');
        $days = array_values(array_unique(array_map('intval', $days)));
        if ($days === [] || min($days) < 0) {
            throw new \InvalidArgumentException('within() takes days: [7, 14, 30].');
        }
        sort($days);
        $this->query['within'] = $days;

        return $this;
    }

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

    /** The latest reading of a column in the period: where a series stands at its end. */
    public function last(string $column): self
    {
        return $this->counting('LAST', $column);
    }

    /**
     * The increase of a cumulative reading — a meter — over the period, worked out per entity
     * (`per: 'deviceid'`) and added up: each reading's rise over the one before it, and a reading
     * lower than the one before it a reset, counted from nought. Breakdowns add up.
     */
    public function counter(string $column, string $per): self
    {
        $this->counting('COUNTER', $column);
        $this->query['per'] = self::identifier($per);

        return $this;
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
     * Read from its source when asked, never computed into the store: for a metric over tens of
     * thousands of entities — a device's consumption by the hour — whose source already holds it.
     * Any breakdown of its dimensions can be read; a distinct is counted per period as asked.
     */
    public function live(bool $live = true): self
    {
        $this->live = $live;

        return $this;
    }

    /**
     * Its declared query — table, time column, what is counted, conditions, each dimension's
     * column — or null for a closure source.
     *
     * @return array<string, mixed>|null
     */
    public function declared(): ?array
    {
        return $this->query;
    }

    /** It earns a milestone at each rung of the ladder its readings pass (a level, higher being better). */
    public function milestones(): self
    {
        $this->earns['milestones'] = true;

        return $this;
    }

    /** It earns a record at each best week (a flow): weeks from Monday, the current one excluded. */
    public function bestWeeks(): self
    {
        $this->earns['best'] = true;

        return $this;
    }

    /** @return array{milestones: bool, best: bool} What achievements it earns */
    public function earns(): array
    {
        return $this->earns;
    }

    /**
     * Adds up across a dimension — "all channels" — where a value read on a day it was not, a
     * channel not measured that day, carries its last reading (`carry`): its first reading for the
     * days before it, its last for the days after. A total across the dimension then does not drop
     * on a day one value is missing, nor rise when one is first read. A metric that does not say so
     * takes no goal across the dimension ({@see Goals}).
     */
    public function acrossBy(string $dimension, bool $carry = true): self
    {
        $this->across = [$dimension, $carry];

        return $this;
    }

    /** @return array{0: string, 1: bool}|null The dimension it adds up across, and whether readings carry */
    public function getAcross(): ?array
    {
        return $this->across;
    }

    /**
     * A level made of others: for each value of a dimension, the first of the metrics that has a
     * reading — `coalesce('audience', 'Audience', ['followers', 'subscribers', 'members'], 'channel')`,
     * each channel's own measure of its audience — then added up across the dimension. Computed
     * from the parts' kept values, so it is registered after them.
     *
     * @param list<string> $parts
     */
    public static function coalesce(string $name, string $label, array $parts, string $dimension): self
    {
        $metric = self::level($name, $label)->dimensions([$dimension])->knowsThePast()->acrossBy($dimension);
        $metric->source(static function (Window $window, array $by) use ($parts, $dimension): array|float {
            $chosen = [];
            foreach ($parts as $part) {
                foreach (Metrics::read($part, [$window], [], $dimension) as $key => $values) {
                    if (!isset($chosen[$key]) && ($values[0] ?? null) !== null) {
                        $chosen[$key] = (float) $values[0];
                    }
                }
            }
            if ($by === []) {
                return (float) array_sum($chosen);
            }

            return array_map(static fn ($key, $value): array => [$dimension => (string) $key, 'value' => $value], array_keys($chosen), $chosen);
        });

        return $metric;
    }

    /**
     * A level whose source can say how things stood at the end of any period — from dated stays,
     * a history it keeps — and not only now: its past periods are computed as a flow's are, closed
     * once computed. Without it a level is kept only as each period closes.
     */
    public function knowsThePast(): self
    {
        $this->knowsThePast = true;

        return $this;
    }

    /** Whether its past periods can be computed ({@see knowsThePast()}). */
    public function hasThePast(): bool
    {
        return $this->kind !== self::LEVEL || $this->knowsThePast;
    }

    /**
     * Read whole by every viewer who may read it, though it lacks a dimension their scope
     * narrows — a figure that is nobody's region, the site's visits. Without it such a metric is
     * not shown to a scoped viewer: its whole would be more than theirs.
     */
    public function unscoped(): self
    {
        $this->unscoped = true;

        return $this;
    }

    /** Whether it is read whole by any viewer ({@see unscoped()}). */
    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /** Whether it is read from its source when asked. */
    public function isLive(): bool
    {
        return $this->live;
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

    /**
     * The currency its amounts are in — `'EUR'`, or each organisation's, `fn (int $tenant): string`
     * — which makes it a currency metric. Without it a currency metric is in `metrics.currency`,
     * else euros. Amounts in different currencies are never added up ({@see Metrics::read()}).
     */
    public function currency(string|\Closure $currency): self
    {
        $this->currency = is_string($currency) ? strtoupper(trim($currency)) : $currency;
        $this->format   = 'currency';

        return $this;
    }

    /** The currency of its amounts — an organisation's, where they differ — or null for a metric that is not money. */
    public function getCurrency(int $tenant = 0): ?string
    {
        if ($this->format !== 'currency') {
            return null;
        }
        $currency = $this->currency instanceof \Closure ? ($this->currency)($tenant) : $this->currency;

        return strtoupper(trim((string) ($currency ?? (Metrics::config()['currency'] ?? 'EUR'))));
    }

    /**
     * A value as people read it, in the reader's language: an amount with its currency's symbol,
     * a percentage, or a number with its unit.
     */
    public function display(float $value, ?string $currency = null): string
    {
        if ($this->format === 'currency') {
            return \Pramnos\General\NumberFormat::money($value, $currency ?? (string) $this->getCurrency());
        }

        return \Pramnos\Dashboard\Widgets\MetricCard::format($value, $this->format === 'percent' && $this->unit === '' ? '%' : $this->unit);
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

    /**
     * A cohort's rows in a window: its size, and for `within` each number of days how many reached
     * the event within it, grouped by any other dimension asked.
     *
     * @param array<string, mixed> $q
     * @param list<string>         $by
     * @return list<array<string, mixed>>
     */
    private static function cohortRows(array $q, Window $w, array $by, \Pramnos\Database\Database $db): array
    {
        $others = array_values(array_diff($by, ['within']));
        $offsets = in_array('within', $by, true) ? ($q['within'] ?? []) : [null];
        $rows   = [];
        foreach ($offsets as $days) {
            $query  = $db->queryBuilder()->table($q['table']);
            $select = [];
            $group  = [];
            foreach ($others as $name) {
                $expression = $q['columns'][$name] ?? $name;
                $select[]   = $query->raw($expression . ' AS ' . self::identifier($name));
                $group[]    = $query->raw($expression);
            }
            $select[] = $query->raw('COUNT(*) AS value');
            $query->select($select);
            [$from, $to] = $q['unix'] ? [$w->startsAt(), $w->endsAt()] : [\Pramnos\Database\Instant::utc($w->startsAt(), $db), \Pramnos\Database\Instant::utc($w->endsAt(), $db)];
            $query->where($q['time'], '>=', $from)->where($q['time'], '<', $to);
            foreach ($q['where'] as [$column, $value]) {
                is_array($value) ? $query->whereIn($column, array_values($value)) : $query->where($column, $value);
            }
            if ($days !== null) {
                // Raw: the event within N days of the start — seconds apart for unix times, an
                // interval for dates — compared column to column.
                $query->whereNotNull($q['event']);
                $start = $q['unix'] ? $q['time'] : self::epoch($q['time'], $db);
                $event = $q['eventUnix'] ? $q['event'] : self::epoch($q['event'], $db);
                $query->whereRaw($event . ' >= ' . $start . ' AND ' . $event . ' - ' . $start . ' <= ' . ((int) $days * 86400));
            }
            if ($group !== []) {
                $query->groupBy($group);
            }
            foreach ($query->getAllOrFail() as $row) {
                $rows[] = $days === null ? $row : $row + ['within' => (string) $days];
            }
        }

        return $rows;
    }

    /** A date or time column as unix seconds, in each database's own words. */
    private static function epoch(string $column, \Pramnos\Database\Database $db): string
    {
        return $db->type === 'postgresql' ? 'EXTRACT(EPOCH FROM ' . $column . ')' : 'UNIX_TIMESTAMP(' . $column . ')';
    }

    /**
     * A counter's increase in a window, per breakdown: each entity's readings in time, each one's
     * rise over the one before, a fall a reset counted from nought, added up.
     *
     * @param array<string, mixed> $q
     * @param list<string>         $by
     * @return list<array<string, mixed>>
     */
    private static function counterRows(array $q, Window $w, array $by, \Pramnos\Database\Database $db): array
    {
        // Raw: a window function (LAG … OVER) the query builder does not express.
        // ponytail: counts from each entity's first reading in the window — the rise from the previous
        // period's last reading to this one's first is not counted; read the reading before the window too if that matters.
        $inner = [];
        $outer = [];
        foreach ($by as $name) {
            $inner[] = ($q['columns'][$name] ?? $name) . ' AS ' . self::identifier($name);
            $outer[] = self::identifier($name);
        }
        $conditions = [$q['time'] . ' >= ' . ($q['unix'] ? '%i' : '%s'), $q['time'] . ' < ' . ($q['unix'] ? '%i' : '%s')];
        $arguments  = $q['unix'] ? [$w->startsAt(), $w->endsAt()] : [\Pramnos\Database\Instant::utc($w->startsAt(), $db), \Pramnos\Database\Instant::utc($w->endsAt(), $db)];
        foreach ($q['where'] as [$column, $value]) {
            $values       = array_values((array) $value);
            $conditions[] = $column . ' IN (' . implode(', ', array_fill(0, count($values), '%s')) . ')';
            array_push($arguments, ...array_map('strval', $values));
        }
        $sql = 'SELECT ' . implode(', ', [...$outer, 'SUM(CASE WHEN pf_rise < 0 THEN pf_reading ELSE pf_rise END) AS value'])
            . ' FROM (SELECT ' . implode(', ', [...$inner, $q['column'] . ' AS pf_reading',
                $q['column'] . ' - LAG(' . $q['column'] . ') OVER (PARTITION BY ' . $q['per'] . ' ORDER BY ' . $q['time'] . ') AS pf_rise'])
            . ' FROM ' . $db->schema()->quoteTable($q['table']) . ' WHERE ' . implode(' AND ', $conditions) . ') pf_readings'
            . ' WHERE pf_rise IS NOT NULL' . ($outer !== [] ? ' GROUP BY ' . implode(', ', $outer) : '');
        $result = $db->execute($sql, ...$arguments);
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = (array) $result->fields;
        }

        return $rows;
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

        if ($q['fn'] === 'COHORT') {
            return static fn (Window $w, array $by, \Pramnos\Database\Database $db): array => self::cohortRows($q, $w, $by, $db);
        }
        if ($q['fn'] === 'COUNTER') {
            return static fn (Window $w, array $by, \Pramnos\Database\Database $db): array => self::counterRows($q, $w, $by, $db);
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
                // The latest reading of the group: each database's own way to take it.
                'LAST'           => $db->type === 'postgresql'
                    ? '(array_agg(' . $q['column'] . ' ORDER BY ' . $q['time'] . ' DESC))[1]'
                    : 'CAST(SUBSTRING_INDEX(GROUP_CONCAT(' . $q['column'] . ' ORDER BY ' . $q['time'] . ' DESC SEPARATOR \',\'), \',\', 1) AS DECIMAL(30,6))',
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

    /** @var \Closure|null Every breakdown of a period at once: fn (Window, list<list<string>> $sets, Database): ?array<string, list<row>> */
    private ?\Closure $sourceSets = null;

    /** Rows a breakdown of a period answers, read elsewhere — a sketch — for {@see measure()}. @param list<array<string, mixed>> $rows */
    public function readAhead(Window $window, array $set, array $rows): void
    {
        $this->ahead[$window->key() . '|' . implode(',', $this->tenantColumn !== null ? [...$set, $this->tenantColumn] : $set)] = $rows;
    }

    /**
     * A source that answers every breakdown of a period at once — keyed by the breakdown's
     * dimensions joined with commas (`''` for the total) — or null where it cannot, beside the
     * one-at-a-time {@see source()}. For a distinct or averaged metric, which keeps its total and
     * each dimension apart: one read of the period instead of one per breakdown.
     */
    public function sourceSets(\Closure $sets): self
    {
        $this->sourceSets = $sets;

        return $this;
    }

    /**
     * Read every breakdown of one period at once, for {@see measure()} to answer from: the
     * declared query with `GROUPING SETS` on PostgreSQL, or the metric's own {@see sourceSets()}.
     * A distinct metric that keeps its total and nine dimensions was ten scans of the period, and
     * five periods open at a time — a day to a year — made fifty; it is five. Elsewhere, and for a
     * single breakdown, nothing is read ahead.
     *
     * @param list<list<string>> $sets The breakdowns to be measured
     */
    public function prefetchSets(Window $window, array $sets, \Pramnos\Database\Database $db): bool
    {
        if (count($sets) < 2) {
            return false;
        }
        $keyOf = fn (array $set): string => $window->key() . '|' . implode(',', $this->tenantColumn !== null ? [...$set, $this->tenantColumn] : $set);
        foreach ($sets as $set) {
            if (isset($this->ahead[$keyOf($set)])) {
                return false;   // read ahead already, by period
            }
        }
        if ($this->sourceSets !== null && $this->tenantColumn === null) {
            $answer = ($this->sourceSets)($window, $sets, $db);
            if (!is_array($answer)) {
                return false;
            }
            foreach ($sets as $set) {
                $this->ahead[$keyOf($set)] = array_values((array) ($answer[implode(',', $set)] ?? []));
            }

            return true;
        }
        $q = $this->query;
        if ($q === null || $db->type !== 'postgresql' || $q['time'] === '' || in_array($q['fn'], ['', 'LAST', 'COUNTER', 'COHORT'], true)) {
            return false;
        }
        $names  = array_values(array_unique(array_merge(...$sets)));
        $query  = $db->queryBuilder()->table($q['table']);
        $select = [];
        foreach ($names as $name) {
            $expression = $q['columns'][$name] ?? $name;
            $select[]   = $query->raw($expression . ' AS ' . self::identifier($name));
            // Raw: which dimensions a row is grouped by, PostgreSQL's GROUPING().
            $select[]   = $query->raw('GROUPING(' . $expression . ') AS pf_g_' . self::identifier($name));
        }
        if ($this->tenantColumn !== null) {
            $select[] = $query->raw($this->tenantColumn . ' AS ' . self::identifier($this->tenantColumn));
        }
        $select[] = $query->raw(match ($q['fn']) {
            'COUNT'          => 'COUNT(*)',
            'COUNT DISTINCT' => 'COUNT(DISTINCT ' . $q['column'] . ')',
            default          => $q['fn'] . '(' . $q['column'] . ')',
        } . ' AS value');
        if ($q['fn'] === 'AVG') {
            $select[] = $query->raw('COUNT(' . $q['column'] . ') AS count');
        }
        $query->select($select);
        [$from, $to] = $q['unix'] ? [$window->startsAt(), $window->endsAt()] : [\Pramnos\Database\Instant::utc($window->startsAt(), $db), \Pramnos\Database\Instant::utc($window->endsAt(), $db)];
        $query->where($q['time'], '>=', $from)->where($q['time'], '<', $to);
        foreach ($q['where'] as [$column, $value]) {
            is_array($value) ? $query->whereIn($column, array_values($value)) : $query->where($column, $value);
        }
        // Raw: GROUPING SETS, one parenthesised list of expressions a breakdown.
        $groups = array_map(fn (array $set): string => '(' . implode(', ', array_merge(array_map(static fn (string $n): string => (string) ($q['columns'][$n] ?? $n), $set),
            $this->tenantColumn !== null ? [$this->tenantColumn] : [])) . ')', $sets);
        $query->groupByRaw('GROUPING SETS (' . implode(', ', $groups) . ')');
        $bySignature = [];
        foreach ($sets as $set) {
            $sorted = $set;
            sort($sorted);
            $bySignature[implode(',', $sorted)] = $set;
            $this->ahead[$keyOf($set)] = [];
        }
        foreach ($query->getAllOrFail() as $row) {
            $grouped = [];
            foreach ($names as $name) {
                if ((int) $row['pf_g_' . self::identifier($name)] === 0) {
                    $grouped[] = $name;
                }
                unset($row['pf_g_' . self::identifier($name)]);
            }
            sort($grouped);
            $set = $bySignature[implode(',', $grouped)] ?? null;
            if ($set !== null) {
                $this->ahead[$keyOf($set)][] = array_intersect_key($row, array_flip([...$set, 'value', 'count', ...($this->tenantColumn !== null ? [$this->tenantColumn] : [])]));
            }
        }

        return true;
    }

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
        if ($q === null || $windows === [] || $db->type !== 'postgresql' || !$q['unix'] || $q['time'] === '' || $q['fn'] === '' || in_array($q['fn'], ['LAST', 'COUNTER', 'COHORT'], true)) {
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

    /** @var array<string, int> Seconds between computings of an open period, by grain */
    private array $refresh = [];

    /**
     * How often an open period of a grain is computed again: `->refresh(['month' => '1 hour',
     * 'year' => '1 day'])`. Between, `metrics:compute` passes it by — its days still computed every
     * run — and a period that ends is closed as ever. For a source that is costly over a long
     * period: a count of distinct people over a month of raw rows, say. `metrics.refresh` in
     * app.php sets it for every metric; the metric's own wins.
     *
     * @param array<string, string|int> $every By grain: an interval, or seconds
     */
    public function refresh(array $every): self
    {
        foreach ($every as $grain => $length) {
            if (!in_array($grain, Window::GRAINS, true)) {
                throw new \InvalidArgumentException('Grain "' . $grain . '" is not one of: ' . implode(', ', Window::GRAINS) . '.');
            }
            $this->refresh[$grain] = is_int($length) ? $length : self::seconds((string) $length);
        }

        return $this;
    }

    /** Seconds between computings of an open period of a grain: the metric's own, else `metrics.refresh`'s, else 0 (every run). */
    public function refreshOf(string $grain): int
    {
        if (isset($this->refresh[$grain])) {
            return $this->refresh[$grain];
        }
        $config = Metrics::config()['refresh'][$grain] ?? null;

        return $config === null ? 0 : (is_int($config) ? $config : self::seconds((string) $config));
    }

    /** `1 hour`, `30 minutes`, `1 day` as seconds. */
    private static function seconds(string $length): int
    {
        $at = strtotime('+' . ltrim(trim($length), '+'), 0);
        if ($at === false || $at <= 0) {
            throw new \InvalidArgumentException('"' . $length . '" is not a length of time: write it as "1 hour".');
        }

        return $at;
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

    /**
     * How long data arrive late: a period stays open, and is computed again at each run, for this
     * long after it ends — `7 days` — and only then closes. Closed at its end by default.
     */
    public function lateness(string $length): self
    {
        $seconds = strtotime('+' . $length, 0);
        if ($seconds === false || $seconds <= 0) {
            throw new \InvalidArgumentException('lateness() takes a length of time: "2 days", "7 days".');
        }
        $this->lateness = $seconds;

        return $this;
    }

    /** How long after its end a period stays open, in seconds. */
    public function getLateness(): int
    {
        return $this->lateness;
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

    /** The most records listed behind a number of a declared query. */
    public const RECORDS = 500;

    /** The columns a declared query's records are listed with, by their heading. @var array<string, string> */
    private array $rowColumns = [];

    /**
     * The records behind a number: a closure that lists them, or — for a declared query
     * (`from()`…) — the columns to list them with, by heading where the key is a string:
     * `->rows(['Person' => 'userid', 'Amount' => 'total', 'When' => 'date'])`. Those are read from
     * its table, in the period and with its conditions and the filters asked, the newest first, at
     * most {@see RECORDS}.
     *
     * @param \Closure(Window, array<string, string>, \Pramnos\Database\Database): iterable<array<string, mixed>>|array<int|string, string> $rows
     */
    public function rows(\Closure|array $rows): self
    {
        if (is_array($rows)) {
            $this->needsTable('rows');
            if ($rows === []) {
                throw new \InvalidArgumentException('rows() lists at least one column.');
            }
            $this->rowColumns = [];
            foreach ($rows as $heading => $column) {
                $this->rowColumns[is_string($heading) ? $heading : (string) $column] = (string) $column;
            }
            $this->rows = fn (Window $w, array $filters, \Pramnos\Database\Database $db): array => $this->declaredRecords($w, $filters, $db);

            return $this;
        }
        $this->rows = $rows;

        return $this;
    }

    /**
     * A declared query's records in a period: its columns, by heading, with its conditions and the
     * filters asked — each by its dimension's column; one it has no column for is refused, so a
     * viewer held to a region never lists another's.
     *
     * @param array<string, string|list<string>> $filters
     * @return list<array<string, mixed>>
     */
    private function declaredRecords(Window $window, array $filters, \Pramnos\Database\Database $db): array
    {
        $q       = (array) $this->query;
        $query   = $db->queryBuilder()->table($q['table']);
        $select  = [];
        $aliases = [];
        foreach (array_values($this->rowColumns) as $i => $column) {
            $select[]  = $query->raw($column . ' AS c' . $i);
            $aliases[] = 'c' . $i;
        }
        $query->select($select);
        [$from, $to] = $q['unix'] ? [$window->startsAt(), $window->endsAt()]
            : [\Pramnos\Database\Instant::utc($window->startsAt(), $db), \Pramnos\Database\Instant::utc($window->endsAt(), $db)];
        $query->where($q['time'], '>=', $from)->where($q['time'], '<', $to);
        foreach ($q['where'] as [$column, $value]) {
            is_array($value) ? $query->whereIn($column, array_values($value)) : $query->where($column, $value);
        }
        foreach ($filters as $name => $value) {
            $column = $q['columns'][$name] ?? null;
            if ($column === null) {
                throw new \DomainException(sprintf(t('%s cannot be listed by %s.'), $this->label(), $name));
            }
            $values = array_values(array_map('strval', (array) $value));
            if ($values !== []) {
                $query->whereRaw('(' . $column . ') IN (' . implode(', ', array_fill(0, count($values), '?')) . ')', $values);
            }
        }
        $out = [];
        foreach ($query->orderBy($q['time'], 'desc')->limit(self::RECORDS)->getAllOrFail() as $row) {
            $out[] = array_combine(array_keys($this->rowColumns), array_map(static fn (string $a): mixed => $row[$a] ?? null, $aliases));
        }

        return $out;
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
        return $this->rows !== null || $this->drill !== null;
    }

    /** Where a number's records are listed by the application itself, or null. */
    private ?\Closure $drill = null;

    /**
     * Send a number to the application's own list of what is behind it, filtered, instead of the
     * framework's: `fn (Window $w, array $filters): string => adminUrl('Users?…')`.
     */
    public function drill(\Closure $url): self
    {
        $this->drill = $url;

        return $this;
    }

    /**
     * The application's own list behind a number, or null where it has none.
     *
     * @param array<string, string|list<string>> $filters
     */
    public function drillUrl(Window $window, array $filters): ?string
    {
        $url = $this->drill !== null ? ($this->drill)($window, $filters) : null;

        return is_string($url) && $url !== '' ? $url : null;
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
