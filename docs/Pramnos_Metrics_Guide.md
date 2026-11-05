---
use_cases:
  - Turning the numbers of a fixed report into metrics that any report or dashboard can show
  - Counting unique customers per day and per month without adding the days together
  - Keeping how many members were active at the end of each month, when the tables only know today
  - Importing a report's history from an old statistics table
  - Reading a metric over time, broken down by a dimension, against last year
---

# Metrics Guide

A **metric** is one number a business reports, such as orders, revenue or active customers. You
declare it once with what it counts, how its periods combine and what it can be broken down by.
The framework computes it for every period and keeps the values. Reports, dashboards and the
assistant then read the kept values instead of working the numbers out again on every page.

## Declaring metrics

Metrics are declared in a **catalogue**, a class that `app/app.php` lists:

```php
// app/app.php
'metrics' => [
    'catalogue' => [\App\Metrics\Sales::class],
    'timezone'  => 'Europe/Athens',        // periods are counted in it; PHP's zone otherwise
    'grains'    => ['day', 'month', 'year'],   // what metrics:compute keeps; all five by default
    'replica'   => true,                   // sources read the replica ("read" database settings)
],
```

```php
namespace App\Metrics;

use Pramnos\Database\Database;
use Pramnos\Metrics\{Catalogue, Dimension, Metric, Window};

final class Sales implements Catalogue
{
    public function metrics(): iterable
    {
        yield Dimension::of('region', 'Region')->values(['north' => 'North', 'south' => 'South']);
        yield Dimension::of('plan', 'Plan')->values(fn (): array => Plan::labels());

        yield Metric::flow('orders.count', 'Orders')
            ->dimensions(['region', 'plan'])
            ->source(function (Window $w, array $by, Database $db): iterable {
                $q = $db->queryBuilder()->table('orders');

                return $q->select([...$by, $q->raw('COUNT(*) AS value')])
                    ->where('placed_at', '>=', $w->startText())->where('placed_at', '<', $w->endText())
                    ->groupBy($by)->getAllOrFail();
            })
            ->rows(fn (Window $w, array $filters, Database $db): iterable => /* the orders behind the number */ []);

        yield Metric::flow('customers.ordering', 'Customers who ordered')
            ->aggregate(Metric::DISTINCT)
            ->dimensions(['region'])
            ->source(/* COUNT(DISTINCT customer_id) grouped by $by */);

        yield Metric::level('customers.active', 'Active customers')
            ->source(fn (Window $w, array $by, Database $db): int => Customers::activeCount($db));

        yield Metric::derived('orders.per_customer', 'orders.count / customers.ordering', 'Orders per customer');
    }
}
```

Code can also register metrics directly with `Metrics::register(Metric ...)` and
`Metrics::dimension(Dimension)`. A metric registered in code replaces the catalogue's metric of
the same name.

### The source

The framework calls `source($window, $by, $db)` once for each period and each breakdown:

- **`$window`** is the period. It is half-open, `[start, next start)`: compare with `>=` against
  `startText()` / `startsAt()` and with `<` against `endText()` / `endsAt()`. Both ends are in
  the metrics' time zone. A `<= 23:59:59` loses the last second, and two sources that disagree
  on where a day ends disagree on every total built from them.
- **`$by`** is the list of dimensions to group by, possibly empty. Pass it to `groupBy($by)` as
  an array: `groupBy(...$by)` fails when the list is empty.
- **`$db`** is the connection to read. It is the replica when `replica` is on and a replica is
  configured. Without one the database itself is read, and the log says so.

The source answers rows that carry the `$by` dimensions and a `value`. When `$by` is empty it may
answer a single number instead.

`rows()` is optional: it lists the records behind a number, for a drill-down.

`allowed(fn (?object $user): bool)` decides who may see the metric. By default that is the
dashboard's role, `manager`.

## Flow, level, derived

| Kind | Counts | Past periods |
|---|---|---|
| `Metric::flow()` | What happened inside the period: orders, sign-ups, payments | Can be counted again from the tables for any period |
| `Metric::level()` | How things stood: active customers, open tickets | Computed while the period is open, then kept as it stood when the period closed. The tables only know how things stand now, so history comes from `Metrics::import()` |
| `Metric::derived()` | Arithmetic over other metrics: `+ - * /`, numbers and parentheses | Worked out when read and never stored, so it cannot go stale. A division by zero, or an operand with no value, gives no value |

Write a percentage out in the expression: `'100 * orders.count / visits.unique'`. The unit
(`'%'`, `'€'`) only says how the number reads; it does not change the value.

## Sum or distinct

- **`Metric::SUM`** (the default): breakdowns add up. Orders by region add up to all orders, so
  the metric is stored broken down by all of its dimensions together, and any other combination
  is added up from that when read.
- **`Metric::DISTINCT`**: breakdowns do not add up. The customers who ordered in September are
  not the customers of each September day added together: one customer ordering on two days is
  two across the days and one in the month. The same goes for regions. So each grain is counted
  on its own, and so is each breakdown the metric keeps.

  By default a distinct metric keeps its total and each dimension on its own. Declare more with
  `->keeps([['region', 'plan']])`. Reading a breakdown the metric does not keep is refused, and
  so is a filter with several values of one dimension, because adding those together would
  count a customer twice.

## Computing and closing periods

```bash
php bin/pramnos metrics:compute                       # what is due
php bin/pramnos metrics:compute --metric=orders.count --grain=month --from=2026-01-01 --to=2026-10-31
php bin/pramnos metrics:compute --from=2026-01-01 --to=2026-10-31 --recompute
```

Without dates, each metric gets two kinds of work at each grain:

- **The open period** is computed and replaces what was kept for it.
- **Periods that ended** since the last one closed are **closed**: computed once more and
  marked final. The first time, that is the period just ended.

A closed period is never computed again unless `--recompute` asks for it. So a closed report
keeps its numbers, and an order recorded late does not reopen the month.

A level has its own rules:

- It is closed only by the run that comes after its period ends, and the value kept is how
  things stood then.
- It is never computed for an older period, not even with `--recompute`.

No rows are ever written for a period that has not begun. Writing is idempotent: a period's rows
are replaced, never added to.

The framework schedules `metrics:compute` at minute 7 of every hour. Where no metric is declared,
the run does nothing. An application that wants the close to happen in a pass of its own disables
the framework's entry and schedules the command itself:

```php
// app/schedule.php
FrameworkSchedule::disable('metrics:compute');
Scheduler::command('metrics:compute')->cron('2 1,5,9,17,19,22 * * *');
```

If one metric's source throws, only that metric is skipped. The command still keeps every other
metric, names the failed one, and exits `1`.

## Importing history

```php
Metrics::import('customers.active', 'month', '2025-03-01', [], 4210);              // the total
Metrics::import('orders.count', 'month', '2025-03-01', ['region' => 'north'], 312);  // a breakdown
```

An imported value is final, so computing never replaces it, and it is read like any other value.
Import a period's total as its own row (empty dimensions) beside any breakdown, because a
distinct metric's total cannot be added up from its parts.

## Reading

```php
use Pramnos\Metrics\Metrics;

// Monthly, by plan, for one region: ['periods' => [Window…], 'values' => ['basic' => [12.0, 15.0, …], …]]
Metrics::series('orders.count', 'month', '2026-01-01', '2026-10-31', ['region' => 'north'], 'plan');

Metrics::value('orders.count', 'month', '2026-09-01');                       // 412.0, or null
Metrics::value('orders.count', 'month', '2026-09-01', ['region' => ['north', 'south']]);
Metrics::compare('orders.count', 'month', '2026-09-01', [], Metrics::YEAR_EARLIER);
// ['period' => '2026-09', 'value' => 412.0, 'against' => '2025-09', 'other' => 380.0, 'difference' => 32.0, 'change' => 0.084…]
Metrics::records('orders.count', 'month', '2026-09-01', ['region' => 'north']);   // the rows() behind it
```

The value of a period that was never computed is `null`, which is different from `0`. Without a
breakdown, `series()` keeps the values under the key `''`.

## Where the values are kept

The values are kept in `stats.metric_values`, one row per metric, grain, period and breakdown.
On MySQL the table is `stats_metric_values`.

- **Why `stats`:** these are the business's figures, so they get a schema of their own, apart
  from the engine's state (`pramnos.*`) and sign-in (`authserver.*`).
- **Why not a hypertable on TimescaleDB:** the table holds a few thousand rows per metric and
  year, and `--recompute` rewrites closed periods, which a compressed chunk refuses.

## Periods: `Window`

```php
$w = Window::of(Window::MONTH, '2026-09-14');   // day, week (Monday first), month, quarter, year
$w->label();          // '2026-09'
$w->previous();       // August
$w->yearEarlier();    // September 2025; for a week, the same ISO week
Window::range(Window::MONTH, '2026-01-01', '2026-10-31');   // every month in between
```

## Related

- [Dashboard](Pramnos_Dashboard_Guide.md): the cards that can show a metric
- [Workers and daemons](Pramnos_Workers_And_Daemons_Guide.md): the framework's schedule
- [Query Builder](Pramnos_QueryBuilder_Guide.md): writing sources
