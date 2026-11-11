---
use_cases:
  - Turning the numbers of a fixed report into metrics that any report or dashboard can show
  - Counting unique customers per day and per month without adding the days together
  - Keeping how many members were active at the end of each month, when the tables only know today
  - Importing a report's history from an old statistics table
  - Reading a metric over time, broken down by a dimension, against last year
  - Building a report of metrics by period or market, shared and mailed every month
  - Letting a regional manager see only their region's figures in every report, card and export
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
    'grains'    => ['day', 'month', 'year'],   // what metrics:compute keeps; day to year by default
    'retention' => ['hour' => '90 days'],      // how long a grain is kept; for ever when not said
    'replica'   => true,                   // sources read the replica: the database settings' `replica` (or `read`) block
    // 'replica' => ['hostname' => getenv('DB_REPLICA_HOST'), 'port' => 5432, 'timeout' => 3],   // or one of the metrics' own
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
  configured: one of the metrics' own (`hostname`, `port`, `timeout`), or the database settings'
  `replica` block. That block routes nothing else, so the rest of the application keeps reading
  the primary. Without a replica, or when the replica does not answer within its timeout, the
  database itself is read and the log says so.

The source answers rows that carry the `$by` dimensions and a `value`. When `$by` is empty it may
answer a single number instead.

**A declared source.** Instead of a closure, a metric can declare its query, and the framework
writes it:

```php
Metric::flow('payments.amount', 'Revenue')
    ->from('invoices')
    ->at('date', unix: true)              // the time column; unix: true for unix seconds, read as they are
    ->sum('total')                        // or ->count(), ->countDistinct('userid') (a distinct metric),
                                          // ->avg(), ->min(), ->max(), ->last('level'), ->counter('reading', per: 'deviceid')
    ->where('status', 1)                  // a value, or a list of values
    ->dimension('market', 'countryid')    // a dimension and its column, or an SQL expression
    ->tenant('organizationid');
```

- **The window.** For each period, the framework selects the window's rows with the half-open
  bounds above.
- **Grouping and counting.** It groups by the dimensions asked, each aliased to its name, and
  counts with the declared function.
- **Time columns.** A date or time column is compared with UTC instants, as the framework writes
  them. A unix column is compared with unix seconds, so the application's schema stays as it is.
- **What is checked.** Column names are checked, and a dimension's expression is the
  application's own SQL. A declaration with no time column, or with nothing to count, fails when
  it is computed.
- **A meter and a latest reading.** `last($column)` is the latest reading in the period.
  `counter($column, per: $entity)` is the increase of a cumulative reading such as a meter. Each
  entity's readings are taken in time and each one's rise over the one before is added up. A
  reading lower than the one before is a reset, counted from nought. The entities are then added
  together. The increase counts from each entity's first reading in the period, not from the
  previous period's last.
- **Cohorts.** `Metric::cohort()` counts, of those who began in a period, how many reached an event
  within each number of days. It is declared on a table:

  ```php
  Metric::cohort('parents.paid_within', 'Paid within N days')->from('users')
      ->of('regdate', unix: true)->event('firstpayment', unix: true)->within([7, 14, 30]);
  ```

  - **Storage.** It is kept per cohort period with the dimension `within` (`7`, `14`, `30`), and
    its total is the cohort's size.
  - **Reading.** Each `within` is read on its own; they do not add up.
  - **The event column** may hold unix seconds or a time.
- **Live.** `->live()` reads the metric from its source when it is asked for and never computes
  it into the store. It is meant for a metric over tens of thousands of entities by the hour,
  such as a device's consumption, whose source already holds the data.
  - **Any breakdown.** Any combination of its dimensions can be read, filtered, for any periods.
  - **Empty periods.** A period with nothing reads as nought.
  - **Computing.** `metrics:compute` passes it by.
  - **Speed.** On TimescaleDB, `metrics:compute` gives it an hourly continuous aggregate once,
    `stats.live_<name>`, and reads come from it (`Store::liveAggregate()`). This needs a declared
    source that sums, counts, or takes a least or a most, over a table that is a hypertable on its
    time column. An integer column needs the hypertable's `integer_now`. The aggregate holds every
    dimension and the organisation, and is refreshed by its own policy. Hours not refreshed yet are
    read too (`materialized_only = false`). Where no aggregate can be made, the source is read.
- **Many periods at once.** Computing many periods of a declared source (`--from`/`--to`, or the
  catch-up after a pause) reads them in one query per breakdown, grouped by each row's period in
  the metrics' zone (`Metric::prefetch()`). This holds on PostgreSQL with a unix-time column and
  one zone for every organisation. Measured on a year of days of real invoices, it took 28 ms
  where one query per day took 152 ms. Elsewhere each period is queried on its own, with the same
  figures.

**Gaps.** A period the source cannot answer shows why, not a nought:

```php
Metric::flow('visits.count')->since('2026-10-16')                                   // no data before it
    ->missing(fn (Window $w): ?string => Outages::during($w) ? 'The tracker was down.' : null)
    ->expect('1 hour');                                                              // how often data should arrive
```

- **Not computed.** Such a period is never computed, so nothing is written for it and it reads as
  no figure.
- **The reason.** `Metrics::query()->gaps()` gives the reasons by period, and a report's cell shows
  the reason in place of a number.
- **`expect()`** says how often new data should arrive, for a card's staleness and the "data
  stopped" alert.

`rows()` is optional: it lists the records behind a number, for a drill-down. `drill(fn (Window $w,
array $filters): string)` sends the number to the application's own list instead, with the period
and the filters: a report's cell links there.

A declared query (`from()`…) lists its own records from the columns it is given, by heading where
the key is a string:

```php
Metric::flow('payments.amount', 'Revenue')->from('invoices')->at('date', unix: true)->sum('total')
    ->where('status', 1)->dimension('market', 'countryid')
    ->rows(['userid' => 'userid', 'Amount' => 'total', 'When' => 'date']);
```

They are read from its table in the period, with its conditions and the filters asked, the newest
first, at most `Metric::RECORDS` (500). A filter is applied through its dimension's column or
expression. A filter on a dimension the metric has no column for is refused rather than ignored,
so a viewer held to one region never lists another's. The columns are named, never `*`, so a
table's other columns stay out of the list.

The framework's own metrics list theirs:

| Metrics | Each record |
|---|---|
| `visits.views`, `visits.visits`, `visits.visitors`, `visits.bots`, `visits.no_js` | a hit: `at`, `userid`, `visitor`, `visit`, `kind`, `source`, `country`, `device` |
| `visits.signed_in`, `visits.single_page`, `visits.duration` | a visit: `started`, `userid`, `visit`, `pages`, `seconds` and its landing page's breakdown |
| `event.*`, `event.*.people` | an event: `userid`, `at` |
| `audience.segment.members`, `.entered`, `.left` | a stay: `userid`, `segment`, `entered`, `left` |

A record's value opens where the application says, by its column:

```php
'metrics' => ['links' => [
    'userid' => fn ($value, array $row): ?string => '/admin/Users/edit/' . $value,
]],
```

The records page links each such value. `Metrics::link($column, $value, $row)` gives the address
to any other screen too: null for a column without a link, or an empty value.

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
- **`Metric::AVG`**, **`Metric::MIN`**, **`Metric::MAX`**: a mean, the least and the most of the
  period. Like a distinct metric, each breakdown is kept on its own. A mean is kept with how many
  it is of (`extra.count`), so the means of several organisations combine weighted. Two of 10 and
  one of 40 make 20, not the 25 of two means. The least and the most combine as the least and the
  most. A declared source says so with `->avg($column)`, `->min($column)` and `->max($column)`. A
  closure answers each row's `value`, and for a mean its `count`.

**A corrected definition.** `->version(2)` on a metric whose definition was corrected. Version 2 is
computed and read. The figures of version 1 stay beside it and are never touched by a
recomputation. They are figures from before the correction, or imported with `Store::adopt()`'s
`version`. `Metrics::query($m)->version(1)` reads them, "as before" against "corrected".

**Presentation.** `->direction(Metric::HIGHER_IS_BETTER | Metric::LOWER_IS_BETTER)` says which way
is good news, for colouring a change and ranking. `->format('number' | 'currency' | 'percent' |
'duration')` says how the value reads.

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

**Every breakdown in one read.** A distinct metric, or a mean, keeps its total and each
dimension apart, and each is a read of the period. On PostgreSQL a declared source reads them all
at once, with `GROUPING SETS`. A closure source does the same where it declares
`->sourceSets(fn (Window $w, array $sets, Database $db): ?array => …)`: every breakdown, keyed by
its dimensions joined with commas (`''` for the total), or null for one at a time. Elsewhere each
breakdown is read on its own, with the same figures.

**A distinct metric from daily sketches.** Where TimescaleDB's toolkit is in the database
(`CREATE EXTENSION timescaledb_toolkit`), a distinct metric declared with `countDistinct()` over a
hypertable on a time column is read from HyperLogLog sketches. It must be kept for the whole
installation, with no organisations:
- `metrics:compute` makes, the first time, a continuous aggregate for each breakdown the metric
  keeps (`stats.hll_<metric>_<hash>`): a sketch of the counted column per day, in the metrics' zone.
  The first refresh reads the table's whole history once.
- A week, a month or a year is then its days' sketches rolled up, not a count over every raw row
  of it. It is approximate, to about 0.6% (2^15 buckets).
- An hour, and anything not so declared, is counted exactly, as ever.

`Store::sketched($db, $metric)` says whether a metric is read this way.

**A costly open period, computed less often.** By default every run computes the open period of
every grain from the source: the open day, but also the open month and year. For a source that
reads raw rows, a count of distinct people over a year say, that is most of the run.
`->refresh(['month' => '1 hour', 'year' => '1 day'])` computes an open month at most hourly and
an open year daily, while the day is computed at every run. A period that ends is closed as ever.
`'metrics' => ['refresh' => ['month' => '1 hour']]` in app.php sets it for every metric, and a
metric's own wins.

If one metric's source throws, only that metric is skipped. The command still keeps every other
metric, names the failed one, and exits `1`.

**Data that arrive late.** `->lateness('7 days')` keeps a period open for that long after it ends.
It is computed again at every run, so a late record still counts. Only after that does it close
and become final, left alone until `--recompute` or a new `version()`. By default a period closes
at its end. A level always closes at its end, because it is how things stood then.

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

### One query for every reader

`Metrics::query()` is the read every reader goes through: a card, a report, an export, the JSON
API or the assistant. The calls above are short forms of it.

```php
Metrics::query('orders.count')
    ->grain('month')->between('2026-01-01', '2026-10-31')   // or ->at('2026-09-01'): one period
    ->by('plan')->where(['region' => 'north'])
    ->compare(Metrics::YEAR_EARLIER)                         // for ->compared()
    ->for($user)                                             // what this viewer may read
    ->series();          // or ->value(), ->compared(), ->table() (rows: period, key, value), ->top(10)
```

- **`for($user)`** refuses a metric the viewer may not read (`allowed()`), with a
  `DomainException`. It also narrows the values to the organisations the viewer may read (below).
  Without it, every organisation is read, as a scheduled job reads them.
- **`tenant($id)`** narrows the read to one organisation. If `for()` came first, it must be one of
  the viewer's.
- **`top($n)`** gives the breakdown's largest values in the last period, largest first.

The framework's own readers all read this way, as their viewer: a report's cells and KPI cards as
the person the report is built for, a metric card as the person the board is drawn for, and the
assistant's metrics tool as the administrator asking. A report mailed by the scheduler is built
for its owner.

### Organisations

In an application whose figures belong to organisations, a metric names the source's column that
says which organisation each row is of. Every value is then kept per organisation, and each
organisation's total is its own breakdown added up.

```php
Metric::flow('water.consumed', 'Water consumed')->dimensions(['zone'])->tenant('organizationid')->source(…);

// app/app.php: which organisations a viewer may read — a list of ids, or null for all of them
'metrics' => ['tenant' => fn (?object $user): ?array => $user?->organizationIds()],
```

The source is asked by the organisation's column too, beside the dimensions (`$by`), so its
`GROUP BY` covers it. The organisation is not a dimension: a reader never chooses it as a
breakdown, and never sees an organisation that is not theirs. Sums and levels add up across a
viewer's organisations. A distinct does not: somebody counted in two organisations is one person.
Reading a distinct metric over more than one organisation therefore throws a `LogicException`, and
the reader picks one with `tenant()`.

**Each organisation's own day.** When organisations are in different time zones, `metrics.timezone`
can be a function of the organisation. `metrics.tenants` then lists the organisations:

```php
'metrics' => [
    'timezone'         => fn (int $tenant): string => Organizations::timezone($tenant),
    'tenants'          => fn (): array => Organizations::ids(),
    'default_timezone' => 'Europe/Athens',   // the installation's, for everything else
],
```

- **Its own periods.** A metric with `tenant()` is computed per zone: the source is asked once for
  each zone's window, and its rows are kept for that zone's organisations alone. An organisation's
  "9 November" is then its own local day, and two organisations' 9 November start at different
  instants.
- **A total for every organisation.** Each organisation of a zone has a total for every computed
  period, nought where it had nothing.
- **Reading.** Reads find each organisation's period by its own zone (`Window::zoneOf()`), and
  reading several organisations adds up each one's own day.
- **An unknown zone.** A zone the function names that does not exist falls back to the
  installation's.

### A viewer's scope: their part of each dimension

Some viewers may read only part of the figures: a regional manager reads one region, a store
manager one store. `metrics.scope` says which part:

```php
// app/app.php: dimension => the values this viewer may read, or null for everything
'metrics' => ['scope' => fn (?object $user): ?array => $user?->region ? ['region' => [$user->region]] : null],

Metric::flow('site.visits', 'Visits')->unscoped()->source(…);           // nobody's region: whole for everybody
Metrics::dimension(Dimension::of('customer', 'Customer')->ability('reports.customers'));
```

Every reader applies it, because it is part of the one query (`Query::for()`): cards, reports,
the JSON API, exports and the assistant.

- **Totals are theirs.** A dimension the viewer did not filter on is filtered to their values, so
  a total is the total of their region, never the whole.
- **A breakdown** by the scoped dimension has only their values.
- **A filter outside the scope** is refused with a `DomainException`. The JSON API answers 404,
  and a card says the figures are not theirs.
- **A metric without the dimension** is not theirs, because its whole would be more than their
  part. It is shown to them only when it is declared `unscoped()`.
- **Records** behind a number are listed one value at a time: a viewer scoped to two regions
  chooses one.
- **Goals.** A goal set for more than the viewer's part (`Metrics::withinScope()`) is not shown
  to them.
- **The report's pickers** offer only the viewer's values, and nothing at all for a single value.
- **A distinct metric** cannot add up several values. A viewer scoped to two regions reads it one
  region at a time.

`Dimension::ability()` guards a dimension the way an audience attribute's `ability()` does, through
the same `Gate`. Somebody without the ability does not break down or filter by that dimension, but
the metric's totals stay theirs. `Metrics::scoped($metric, $filters, $user)` gives the filters a
viewer reads with, for code that reads a metric some other way.

### Money: one currency at a time

```php
Metric::flow('payments.amount', 'Payments')->sum('amount')->currency('EUR')…;
Metric::flow('fees', 'Fees')->tenant('org')->currency(fn (int $org): string => Organizations::currency($org))…;

// app/app.php: the currency of a currency metric that names none (euros by default)
'metrics' => ['currency' => 'GBP'],
```

- **Kept with each value.** The currency is written beside each value as it is computed, in the
  row's `extra`. An organisation that changes currency later does not relabel what it earned
  before.
- **Never added.** A read whose values are in two currencies throws a `LogicException`, as a
  distinct metric over two organisations does. Read one organisation, or one currency, at a time.
  A report section's total over rows in different currencies, or over money beside counts, is not
  drawn.
- **Told to the reader.** `Query::currency()` gives the currency the last read's amounts were in.
  The JSON API answers with `currency`, the assistant's tool says it, and a CSV writes it beside
  the row's label (`Paid (EUR)`), leaving the cells plain numbers.
- **Written in the reader's language.** Cards, report cells, chart axes and their images use
  `Metric::display()`: `1.234,50 €` on a Greek page and `€1,234.50` on an English one (see
  `NumberFormat` in the Theme guide).

There is no conversion between currencies: a figure in pounds is never turned into euros.

### Across a dimension: all channels

A metric kept per channel, or per store, is often wanted for all of them together. Three
declarations make that total right:

```php
Metrics::dimension(Dimension::of('channel', 'Channel')->base(fn () => Channels::ownActiveIds()));
Metric::level('followers', 'Followers')->dimensions(['channel'])->acrossBy('channel')->source(…);
Metric::coalesce('audience', 'Audience', ['followers', 'subscribers', 'members'], 'channel');
```

- **`Dimension::base()`.** The values a total counts when a read does not name the dimension's
  values, for instance active channels that are not a competitor's, where competitors are rows of
  the same table. A read that names a value reads it.
- **`Metric::acrossBy($dimension, carry: true)`.** A series across the dimension carries each
  value's last reading over the days it was not read: its first reading for the days before it,
  its last for the days after. A channel not measured one day does not drop the total, and one
  first connected does not look like growth. Goals on such a metric read this series.
- **`Metric::coalesce()`.** A level made of others: for each value of the dimension, the first of
  the metrics that has a reading, added up across it. It is computed from the parts' kept values,
  so it is registered after them.

### The audience's own metrics

Where the audience's tables are, its segments and person events are metrics with nothing
declared (`Pramnos\Audience\AudienceMetrics`):

| Metric | What it counts |
|---|---|
| `audience.segment.members` | The people in each segment at the end of the period: a level, by `segment` |
| `audience.segment.entered` | Who entered each segment in the period. Its first working out puts everybody in at once, and is its start, not people entering |
| `audience.segment.left` | Who left each segment in the period |
| `event.<key>` | A person event's occurrences, from the change log |
| `event.<key>.people` | The people who did it |

A segment's size is then a chart, a goal or an alert. A segment's past goes back as far as its
stays are kept (`audience.segment_history_days`), and an event's as far as the change log keeps
it. `'metrics' => ['audience' => false]` leaves them out, and a metric the application registers
under the same name wins.

### The site's traffic

Where the site's own traffic is counted, its page views, visits, visitors and the rest are metrics
too (`visits.*`, `Pramnos\Visits\VisitsMetrics`). See the [Site traffic guide](Pramnos_Visits_Guide.md#as-metrics).

**A level that knows its past.** A level is normally kept only as each period closes, because how
things stood in an older period is not known. A level whose source can say it, from dated stays
or a history it keeps, declares `->knowsThePast()`, and its past periods are computed as a flow's
are. The segments' size is such a level.

## On the dashboard and for the assistant

- **Dashboard:** each metric is a card, `metric.<name>`, in the dashboard's catalogue. It is
  offered to those its `allowed()` lets see it, and a new dashboard does not have it. One period
  draws a tile with the comparison chosen under it; several draw a chart, broken down and
  filtered as the person sets it. A read the metric cannot answer, such as a breakdown a
  distinct metric does not keep, is said on the card. See [Dashboard](Pramnos_Dashboard_Guide.md#metric-cards).
- **Assistant:** the analyst has a `metrics` tool wherever the application declares metrics. It
  offers the catalogue, `series`, `compare` and `rows`, and only the metrics the administrator
  asking may see. Its description tells the model that a distinct metric is never added across
  periods or values. To offer it on the public MCP endpoint, add
  `new \Pramnos\Llm\Analyst\Tools\MetricsTool($userId)` the way the application offers its
  other tools.

## Reports

A report is a board of cards built from metrics, drawn for one period. The application can ship
ready reports in app.php, and people build their own at **`/admin/MetricReports`** (the *Reports*
menu item, shown wherever metrics are declared). They can share a report the way dashboard
presets are shared, export it to CSV, print it or save it as PDF from the browser, and have it
mailed every day, week or month.

### Cards

A report's cards are arranged with the dashboard's own editor, on the report's page: its owner
chooses **Customise** in the report's bar, then adds, moves, resizes, groups into tabs and sets each card, and every
change is drawn at once with the real numbers. There is one editor for dashboards and reports,
so a card type or a setting added to one is in the other.

**New report** asks for a name, a period, a comparison, who sees it and whether it is mailed,
then opens the empty report already in Customise. Any report of one's own with no cards
opens the same way. The values of a table, and their headings, are aligned right inline, so
every theme lines them up the same.

| Card | id | What it shows |
|---|---|---|
| Section | `report.section` | A thin heading across the width, a line rather than a card. On the page it folds away the cards under it, up to the next section. |
| Table of metrics | `report.table` | Rows across columns, as below. Its columns are the report's, or its own: the last N periods, a dimension's values, or columns of its own. |
| Chart | `report.chart` | Series of metrics over the last N periods, as lines or bars, with the same periods a year earlier beside them on request. |
| Figure | `report.kpi` | One metric's value in the period, with its change against the period before, a year earlier, or as the report compares. |
| Top values | `report.top` | The largest values of a breakdown in the period, 5, 10 or 20, each with a bar to the largest. |
| Goal | `report.goal` | Progress towards a target in the period, a day or a month: the actual so far, what was expected by now along the reference's curve (dashed), and the forecast (see *Goals*). Nothing where the reference has a hole. |
| Analysis | `report.analysis` | The assistant's reading of the report's numbers, written when the report is drawn for sending from the tables its CSV holds, with what to look at in the card's own words. It is kept for the period (`reports` cache), so opening the report does not ask again. The page shows what was written; before the first send, a note says when it will be. |
| Map | `report.map` | A metric by place: a circle at each value of a dimension that has places (`Dimension::geo()`), sized by its figure, over OpenStreetMap. It is drawn with Leaflet where the page has it, and the figures are a table under it, or alone without Leaflet. |
| Cohorts | `report.cohorts` | A cohort metric as a grid: each of the last periods a row, its cohort's size and the share that reached the event within each span. |
| Text | `report.text` | Words in markdown, escaped and then formatted as the assistant's answers are. |
| Records | `report.rows` | The records behind a metric that declares `rows()`, the first ones as a table, with a link to all of them. |

**Ask about this.** Where Ask is on, a card that reads a metric links to Ask with a question that
carries its context, ready to send (`?q=`, `ReportWidget::askAbout()`). The context is the metric,
its period, the board's variables and organisation. A table or a chart asks about its first row.
The assistant then reads further through the query API, under the same scope as the person.

**A report from words.** The New report form takes a sentence: "orders by region against last
year". `ReportProposal::fromWords($words, $user)` gives the assistant the catalogue the person may
read and asks for the report as the editor's text. Rows that name any other metric, or a dimension
the metric does not keep, are left out. The form opens filled in, with the numbers below it, and
nothing is saved until the person saves. When nothing usable comes back, the form says so.

**A scheduled task as a report.** `Routines::asReport($scope, $id)`, or
`POST /chat/routines/{id}/report`, turns one of the assistant's daily, weekly or monthly tasks into a
report. The report gets the cards the task's words describe and an analysis card that reads them as
those words ask. It is mailed on the task's cadence to its owner and to whoever read the task by
mail. The task is paused, so the report, with its charts, is what goes out.

**The board's variables.** A report's bar has a picker for each dimension the report reads —
what its rows, columns and cards filter on or break down by (`Report::dimensions()`) — or that the
address sets, when the dimension has listed values. A dimension of more than
`MetricReports::PICKER_LIST` (50) values is a search field over them, the browser's own
(`datalist`), rather than a list. A dimension's values given as a closure are read once and kept
for a minute (`Dimension::KEEP`), however many cards ask. A choice narrows every card on the board (`?v[region]=north`, and `?v[tenant]=7` for one
organisation), and is kept in the address as the reader moves between periods or downloads the
CSV or XLSX. A card's own filter on the same dimension keeps its own. What is not a declared
dimension is ignored (`ReportBoard::variables()`). The pinned pickers and the period send their
form themselves through `data-pf-autosubmit`, which pf-utils handles, so there is no inline script
for a Content-Security-Policy to refuse.

**The report page**, so it opens on its numbers:

- **The head** is the title and the period with ‹ ›. The actions (Customise, Settings or Copy,
  Export CSV and XLSX, Print, All reports) sit behind a ⋯ menu.
- **Pinned filters.** Only the period and the application's pinned dimensions are in sight, each
  labelled: `'metrics' => ['pinned_filters' => ['market']]`.
- **The Filters panel** holds every other picker with its label. Its button counts the filters
  chosen in it, and its *Apply* sends them.
- **Chips.** Each filter chosen is a chip above the cards; its × takes that filter off. The
  address stays `?v[…]`.
- **Columns narrowed to the filter.** A table whose columns are a dimension's values shows only
  the values the page is narrowed to: the board's variable on that dimension, and the viewer's
  scope. The total column goes when one column is left. The report keeps its definition; only the
  drawing narrows.

A card is headed by the heading set in its form, or by nothing: the name of its kind is shown only
in the catalogue. The report's page is denser than a dashboard: less room inside a card, and
tighter table rows, for reading numbers.

A card's form offers choices, not syntax. A metric is picked from the catalogue, and a breakdown
from the chosen metric's dimensions. Filters are a dimension and one of its values. Rows, series
and columns are lists whose items are added, removed and dragged into order. A report has as
many cards of each kind as it wants (`Widget::repeatable()`, see the Dashboard guide).

- **Who arranges it:** the owner of a saved report. Everybody else who may open it sees the same
  cards, without Customise, and copies it to make one of their own. A report from app.php is
  copied the same way.
- **Where the cards are kept:** in the report's definition, as `layout`, in the shape the
  dashboard keeps a layout. A report's board is `report:<id>` (`Pramnos\Metrics\ReportBoard`), served by
  the dashboard controller like any other board.
  `Reports::save()` and `saveLayout()` throw a `JsonException` when a definition does not encode
  (invalid UTF-8 in a name or a label), rather than storing an empty one.
- **The period:** every card reads the report's grain, period, comparison and columns
  (`ReportBoard::context()`), so moving to the previous month redraws them all for it.
- **Cards of an application's own:** a widget whose `boards()` names `report` is offered on
  reports too, and can read `ReportBoard::context()`.

### Sections as text

A definition can still give `sections` instead of cards, as below. It is read as cards: each
section a heading, its chart where it asks for one, and its table. Once its owner arranges the
cards, the cards are kept and the sections go.

```php
// app/app.php
'metrics' => ['reports' => [
    'monthly' => [
        'name'    => 'Monthly',
        'grain'   => 'month',
        'compare' => 'year',            // or 'previous', or null
        'columns' => [                  // a list of specs …
            ['label' => 'Total'],
            ['label' => 'North, new',       'filters' => ['region' => 'north', 'customer' => 'new']],
            ['label' => 'North, returning', 'filters' => ['region' => 'north', 'customer' => 'returning']],
            ['label' => 'Revenue',          'metric' => 'orders.revenue'],
            ['label' => 'Month before',     'offset' => -1],
        ],
        // … or a shorthand: ['periods' => 12]
        //                or ['dimension' => 'region', 'values' => ['north', 'south'], 'total' => true]
        'sections' => [
            ['title' => 'Orders', 'chart' => 'line', 'total' => true, 'rows' => [
                ['label' => 'Orders', 'metric' => 'orders.count'],
                ['metric' => 'customers.ordering', 'breakdown' => 'plan', 'total' => true],
            ]],
        ],
    ],
]],
```

### Cells

A cell is the row's metric with the row's filters and the column's filters together, in the
column's period. A column filter wins over a row filter on the same dimension. A column can name
a metric of its own, and an `offset` counts periods back from the report's period. The `periods`
and `dimension` shorthands expand into specs of this kind when the report is built.

### Rows by breakdown

A row with a `breakdown` stands for one row per value of that dimension: the declared values,
plus any value found in the report's periods. With `total`, a last row follows that holds the
metric without the breakdown. For a distinct metric that total is counted, not added up.
A section's `total` adds up its rows but leaves breakdown totals out.

### What each reader sees

- **Rows:** a row reads only a metric its reader may see. The others are left out, and the
  report says how many.
- **Cells that cannot be answered:** for example, a column filter a distinct metric does not
  keep. Such a cell shows a dash with the reason, and the rest of the row stands.
- **Records:** a cell whose metric declares `rows()` links to the records behind the number.
- **Comparison:** each row, without the columns' filters, in the period before or a year
  earlier, with the change.

### On the screen

The report's settings form holds its name, grain, comparison, sharing and schedule, and the
default columns of its tables, folded under **For the advanced** with the list of metrics.
There, the whole report can also be written as text, in place of its cards. The fold is open when
the report already is text:

```text
# Columns
periods=12
dimension=region north,south total
Total
North, new | region=north, customer=new
Revenue | | orders.revenue
Month before | | | -1

# Sections and rows
# Orders [chart=line] [total]
Orders | orders.count
North basic or pro | orders.count | region=north, plan=basic;pro
By plan | customers.ordering | | by=plan total
```

In the columns text, write either one of the two shorthands (`periods=…` or `dimension=…`) or
one column per line. Separate several values of one filter with `;`, because `|` separates the
parts.

People can change and delete their own reports, and copy any report they can open. An
administrator can delete anybody's. The views are `.tpl.php` templates in the framework's
template engine, the same in the three themes.

### Mailing

A saved report with a schedule (`day`, `week` or `month`) and recipients is mailed once for each
period that closes:

- **What is sent:** the report of that period, read as its owner would see it, in the body of
  the mail: each card in turn, under its heading (`ReportBoard::html()`).
- **Charts:** as PNG images carried inside the mail, because no mail client runs a script or
  shows an SVG. The server draws the chart as SVG (`ChartImage::svg()`) and converts it with the
  `imagick` extension (ImageMagick with its SVG delegate, `apt install php-imagick librsvg2-2`) or
  the `rsvg-convert` program (`apt install librsvg2-bin`). Neither keeps anything running. Without
  either, or when one fails, the chart is sent as a table of its numbers and the mail still goes.
  `ChartImage::convertWith()` sets a converter of the application's own.
- **How:** `metrics:reports-send` sends it through the outbox. The framework's schedule runs it
  daily at 07:30.

The mail carries no CSV attachment; the CSV is one click away on the report's page. The CSV holds
the report's tables one under another, each line its table's heading (or its section's) and row.
**XLSX**, next to it, is the same rows as a spreadsheet (`MetricReports/xlsx`). Numbers are
written as numbers, so they add up.

### A spreadsheet of anything

`Pramnos\Export\Xlsx` writes a workbook with `ext-zip` and a little XML, without a spreadsheet
library:

```php
$bytes = Xlsx::write(['Sales' => [['Region', 'Orders'], ['North', 12]], 'Notes' => [['…']]]);
$bytes = Xlsx::fromCsv($csv, 'Report');   // what a CSV export already writes
```

- **Cells.** A number, or a string in the plain form `-12.5`, is written as a number, and
  everything else as text. The first row is bold.
- **Sheet names.** A sheet's name is cut to what Excel allows.

### Closed periods

A closed period's values are final (see above), so a report of a closed period shows the same
numbers every time it is opened. Nothing has to be frozen separately.

## As JSON, outside the administration

`MetricsApi` gives the metrics to a SPA, a mobile application or an organisation's own
dashboard. It uses the API's credentials and the one query every reader uses, as the person
asking: only the metrics they may read, of the organisations they may. Mount it with one line in
the application's API routes:

```php
\Pramnos\Metrics\MetricsApi::routes($r);   // under /metrics; a second argument names another path
```

| Route | Answers |
|---|---|
| `GET /metrics` | the catalogue: name, label, unit, kind, aggregate, dimensions, format, direction, grains, live |
| `GET /metrics/series?metric=&grain=&from=&to=&by=&f[dim]=&tenant=` | `periods` (keys), `labels`, `values` by breakdown value, `gaps` (why a period has no figure) |
| `GET /metrics/value?metric=&grain=&at=&f[dim]=&compare=&tenant=` | `{value}`, or with `compare` the period against the one before or a year earlier |
| `GET /metrics/goal?id=` | the goal and its `progress` |

Answers are in the API's shape, `['status' => 200, 'data' => …]` or `['status' => …, 'error' => …]`:

- 401 when nobody is signed in;
- 404 for a metric or a goal that is not theirs;
- 422 for something that makes no sense, such as an unknown grain.

## Goals

A target on a metric, for a day or a month, and how near it is now:

```php
$id = Goals::set('payments.revenue', 'day', 2000.0);                       // every day
Goals::set('registrations', 'month', 900.0, '2026-11-01');                  // one month
Goals::set('payments.revenue', 'day', 2000.0, reference: Goals::WEEKDAYS);  // the last four same weekdays
Goals::progress($id);
// ['target' => 2000.0, 'actual' => 620.0, 'expected' => 580.0, 'forecast' => 2140.0,
//  'share' => 0.29, 'through' => '2026-11-09 11:00', 'period' => '2026-11-09', 'reference' => 'lastyear']
```

- **Its curve.** The expected curve is not a straight line. The share of the target due by now is
  the share of a reference period done by the same point, scaled to the target.
  - **The reference:** the same weekday a year earlier (52 weeks back) by default, or the mean of
    the same weekday over the last four weeks. A month's goal follows the days of the same month a
    year earlier.
  - **The forecast** carries the actual along the same curve.
- **What it reads.** A day's goal reads the metric by the hour, the hours completed and computed
  so far, so the metric must be kept by the hour (`->grains(['hour', 'day'])`). A month's goal
  reads it by the day.
- **No curve, no answer.** Where a single hour, or day, of the reference was never computed, the
  progress is null. A curve with a hole in it would say something false.
- **The goal that applies.** `Goals::for($metric, $grain, $at)` finds the goal set for that period
  first, and otherwise the goal for every period.
- **Where they are kept.** Goals are kept in `stats.goals`. They are state; a missed goal is an
  alert.

**Every kind of goal.** `Goals::create()` makes a goal of any kind, and `Goals::judge($id)` says
how it stands:

```php
Goals::create(['metric' => 'payments.revenue', 'target' => 2000, 'repeats' => 'month']);                 // count, every month
Goals::create(['metric' => 'registrations', 'kind' => 'growth', 'target' => 10, 'repeats' => 'week']);   // +10% on the week before
Goals::create(['metric' => 'followers', 'kind' => 'reach', 'target' => 10000, 'due' => '2026-12-31']);  // a level, by a day
Goals::judge($id);   // ['kind' => 'reach', 'state' => 'on_track', 'says' => 'On this trend, 2026-11-19.', 'current' => …, 'projected' => …]
```

- **On a total (a flow):**
  - `count`: the period's total reaches the target, expected along the elapsed time, or along last
    year's curve where the metric is kept by the hour;
  - `growth`: the total reaches its reference × (1 + the target %). The reference is `previous`
    (the same length before), `lastyear` or `weekdays`.
- **On a level:**
  - `reach`: a reading crosses the target, in the metric's direction. Otherwise the least-squares
    trend gives the day it would, compared with the due date (`stalled` when it does not move
    towards it);
  - `sustain`: no reading worse than the target, its worst said;
  - `pace`: the change since the start reaches the target;
  - `growth`: that change in %.
- **When:**
  - a one-off goal runs from `starts` (now when left out; it may be in the past) to `due`;
  - a recurring one (`repeats`: day, week, month, quarter) is judged on its current period, with
    `history` (each past period as it stood at its end) and `streak` (the met ones in a row).
  - Days are the organisation's.
- **States:** `met`, `on_track`, `behind`, `stalled` or `unknown`, with a sentence that says why.
  The sentence writes a day in the reader's language ("30 November", with the year when it is
  not this one), while the fields (`projected`, `met_at`, `worst_at`) keep `Y-m-d`. For a
  `number` metric whose readings are whole — followers, orders — the figures worked out from it
  (a target grown by a percentage, what is expected by today) are written whole.
  - A one-off `reach` is `unknown` until it has 12 readings over 7 days. A recurring one needs half
    its period's days.
  - After its due date a goal is still judged: a missed reach is `behind`, and a `sustain` that held
    is `met`.
- **Changes:** `Goals::update($id, [...])` keeps what it does not name, and forgets the goal's state.

**Told when a goal turns.** `metrics:compute` judges every goal after computing
(`Goals::check()`) and keeps its state. It tells once, when a goal turns from good (met, on track)
to bad (behind, stalled). A first state, a move to or from unknown, and an improvement tell
nobody. A goal's `notify` says whom:
- `owner`, the default where it has one;
- `members`, the organisation's, from `'metrics' => ['goals' => ['members' => fn (int $tenant): array => …]]`;
- `conversation:ID`, a line there.

The bell and a push (one per goal, replacing its last) link where `metrics.goals.link` says.
There is no mail. The turn is kept in the changelog and fired as `goal.turned` (the goal, its
state before, its state now), for a rule or a to-do source to act on.

**Achievements.** What a business reached, kept for good in `stats.achievements` and listed by
`Achievements::list($tenant)`, newest first, each with a sentence:
- `Metric::level(…)->milestones()` earns a milestone at each rung of the ladder (100, 250, 500,
  1k … 10M) its readings pass above their first. It is dated the day it was passed, and a crossing
  not seen is never invented. For each value of the dimension it adds up across, the first reading
  is that value's own: a channel connected later starts from its first day, not from nought.
- `Metric::flow(…)->bestWeeks()` earns its best week so far, Monday to Sunday, the current week
  left out. A new record is a new row.
- A one-off `reach` goal that is met is kept with its goal's id, and stays when the goal is
  deleted.

Each is kept for the total and for each value of the dimension the metric adds up across (its
`base`: no competitor's channel), per organisation, and never twice. `metrics:achievements` scans
nightly at 03:40.

**As JSON, and to an assistant.** `MetricsApi::routes()` mounts the goals under
`/metrics/goals`, or `MetricsApi::goalRoutes($r, '/goals')` on a path of the application's own:

| Route | Does |
|---|---|
| `GET {path}` | the goals the person may read, each judged, with the `kinds`, `repeats` and `metrics` a form needs |
| `POST {path}` | add one, with `Goals::create()`'s keys; 201 |
| `POST {path}/preview` | the same values judged without keeping them: the sentence, the last period's figure, a target to try |
| `PUT {path}/{id}` | change one: what is not sent stays; the metric, organisation and dimension values cannot change |
| `DELETE {path}/{id}` | take one away; what it reached stays |
| `GET {path}/achievements?limit=` | what was reached, newest first |

A goal on a metric, an organisation or a dimension value the person may not read is a 404.

The analyst has two tools when the application has metrics:
- `goals` reads the goals, the metrics a goal can be set on, and the achievements. It is read only,
  and its name comes from `'metrics' => ['goals' => ['tool' => '…']]`, so an assistant that knew
  an application's own tool by its name keeps it.
- `goals_change` adds or changes one. Its owner is `me`, a user id or a name. It is a write, so
  the assistant asks before it.

**The screen.** `/admin/Goals`, under Reports in the administration menu, lists the goals the
person may read. Each one shows its state, its sentence, a dot for each past period (met, missed,
not known), its streak, and the dimension values it is for. The form has a select per dimension
of the chosen metric (those the person may read, with listed values), so a goal can be for one
market or one package, chosen when it is added and changed after; the preview reads the same values. Its form adds a goal or changes one (`?goal=<id>`). As the form is
filled in, it shows the sentence the goal would have and the last whole period's figure. For a
count, it also offers 10% above that figure as a target to try. `Goals::preview($goal)` gives the
same without keeping anything. Below the form is the latest of what was reached.

**On the dashboard.** The `metric.goal` card's `goal` setting picks any goal kept, of any kind.
The card then shows its state, its sentence, its past periods and its streak. Without the
setting, it shows a metric and a target as before.

**A target relative to last year.** Instead of a fixed number, a goal can grow over its
reference: the reference period's total times 1 + growth, worked out each period. One goal set
once then serves every day, with no daily script:

```php
$id = Goals::set('payments.revenue', 'day', 0, growth: 0.10);   // the same day last year, plus 10%
Goals::update($id, ['growth' => 0.0]);                          // from an application's own settings screen
```

Where the reference has a hole there is no target, and no progress. The `growth` column is added
by itself to a table made before it existed.

**The way there.** `Goals::trace($id)` is `progress()` step by step, every hour of the day (or day
of the month). Each step has a label, the actual so far (null for the steps to come), the expected
along the curve, and the forecast from the last step counted to the end.

**The goal card.** `report.goal` on a report, and `metric.goal` on the dashboard, shows progress
as a bar or, with `show: chart`, as the way there. The chart draws the actual so far, the curve
expected, the forecast and the target line, and a mailed report draws it as an image. Its target
is the card's own `target`, or its own `growth` (a percentage), or else the goal kept for the
metric (`Goals::for()`). Administrators change the growth in the card's settings. On the
dashboard the card shows today, or this month with `grain: month`. It is offered to whoever may
read a metric, and shows only metrics they may read.

## Alerts

What is watched on a metric, and who is told:

```php
// Payments at nought for two hours, between nine and eleven at night: a gateway in trouble.
Alerts::create(['metric' => 'payments.count', 'kind' => Alerts::STOPPED, 'value' => 7200, 'hours' => '9-23',
    'notify' => ['users' => [1], 'conversation' => $opsChannel]]);
// Registrations down 30 % against a year earlier.
Alerts::create(['metric' => 'registrations', 'grain' => 'day', 'kind' => Alerts::CHANGE, 'op' => 'below',
    'value' => -0.3, 'against' => Metrics::YEAR_EARLIER, 'notify' => ['users' => [1]]]);
```

- **What can be watched.**
  - **threshold**: the last completed period's value is above (or below) `value`.
  - **change**: its change against the period before, or a year earlier, is above (or below)
    `value`, a fraction.
  - **stopped**: the metric is at nought, with nothing coming, for the last `value` seconds of
    completed hours. It reads the metric by the hour.
- **When.** `hours` keeps a rule to those hours of the day, in the metrics' zone (`9-23`).
- **Who is told.** A rule that fires tells its users in the bell, and as a push where they have
  one (`AlertNotification`). It also posts the line in its conversation, the team's channel. It
  then waits `every` seconds (an hour by default) before it may fire again.
- **When it is checked.** `metrics:compute` checks every rule after it computes
  (`Alerts::evaluate()`).
- **Where it is kept.** The rules are kept in `stats.alert_rules`, which is state. Each firing is
  kept in the changelog (`alert.fired`), not in a table of its own.

## Notes on the charts

Something that explains a figure — a deploy, a price change, a campaign — a moment or a span:

```php
Annotations::add(time(), 'Spring campaign', 'campaign', until: strtotime('+14 days'), link: '/admin/Campaigns/3');
Annotations::add(time(), 'New prices', 'price', tenant: $organisation);
Annotations::between($from, $to, $tenant);   // the notes that touch a range: an organisation's and everybody's
```

- **On the charts.** Report charts and metric cards draw the notes of their periods
  (`Annotations::forPeriods()`, and `Html\Chart::render()`'s `annotations` option). Each note is a
  dashed line at the period it begins in, named at the top, and also listed under the chart for a
  screen reader and without JavaScript.
- **Deploys.** The daemon orchestrator notes each deploy it notices: `deploy`, "Deployed" and the
  commit.
- **Where they are kept.** Notes are kept in `stats.annotations`. Why a table and not the
  changelog: they are the business's own notes, written and edited by people, read by time range
  across every metric, and kept as long as the figures are.

## Where the values are kept

Each grain has a table of its own: `stats.metric_values_day`, `_week`, `_month`, `_quarter` and
`_year` (on MySQL, `stats_metric_values_day` and so on). Each holds one row per metric, tenant,
breakdown and period. A view over them all, `stats.metric_values` (`Metrics::TABLE`), adds a
`grain` column, for SQL, exports and the assistant. Read through the view; write through
`Metrics::compute()` and `Metrics::import()`, never into the view.

| Column | |
|---|---|
| `metric`, `tenant` | the metric's name; the organisation the value is of (0 for the installation) |
| `period_start` | the period's first instant in the metrics' time zone, `timestamptz` |
| `dimset`, `dims`, `dims_hash` | the breakdown's dimensions, its values as JSON (`{}` for the total), md5 of them as a uuid |
| `version` | the metric's definition the value was computed by |
| `value`, `extra` | a `double precision`; what a value carries beside it, as JSON |
| `source`, `final`, `computed_at` | computed, snapshot or imported; 1 once the period closed; when |

- **Why `stats`:** these are the business's figures, so they get a schema of their own, apart
  from the engine's state (`pramnos.*`) and sign-in (`authserver.*`).
- **Why a table per grain:** each grain needs its own chunk size, compression and retention, and
  a read of one grain never pays for another's rows. Measured on 100 M rows, a 24-month chart
  reads in 0.5 ms this way, and in 3.1 ms from one table of every grain (10 ms once compressed).
  The split is the same on MySQL and plain PostgreSQL, where it costs nothing, so there is one
  layout and one query path everywhere.
- **`Pramnos\Metrics\Store`** makes all of it (`Store::ensure($db)`, which the migration calls and
  which is safe to run again), names a grain's table (`Store::table('month')`), and holds the
  lock below.
- **Made on first use.** The first computation or read in a process checks once that every grain's
  table is there (`Store::ready()`). When one is missing, it calls `ensure()`. A deploy whose migration has not run yet therefore
  computes and reads normally, and does not fail on a missing table.

### TimescaleDB

Where the extension is installed, each grain's table is a hypertable on `period_start`, with
no declaration from the application:

| Grain | Chunk interval | Compressed after |
|---|---|---|
| day | 90 days | 90 days |
| week | 1 year | 1 year |
| month | 2 years | 2 years |
| quarter | 5 years | 5 years |
| year | 10 years | never: too few rows |

Compression is by `(metric, tenant, dims_hash)`, ordered by `period_start DESC`, and makes the
values 7–9 times smaller. `--recompute` and `Metrics::import()` rewrite closed periods inside
compressed chunks directly (TimescaleDB 2.11+). A rewrite lifts
`timescaledb.max_tuples_decompressed_per_dml_transaction` for its own transaction, because a
closed month of a large metric decompresses more than the default 100 000 tuples.

### The store's lock

A computation and a change of the store — a conversion, an import — never run together, on any
host. `Store::lock($db, $wait)` takes an advisory lock (`pg_advisory_lock`, MySQL `GET_LOCK`), and
`Store::unlock($db)` releases it.

- `Store::ensure()` and `Store::adopt()` wait for the lock.
- `Metrics::compute()` only tries it. If the lock is taken, the computation does nothing and
  reports `busy`; `metrics:compute` says so and exits 0. The next run computes what is due.
- A script of the application's own that changes the store takes the same lock.

### Bringing figures in

`Store::adopt($db, $from, $table, $map)` imports another table's figures once. The table can be
an application's own store or its statistics tables. `$map` names the columns:

- `metric`, `period` and `value`;
- the grain, either as a column (`grain`) or as one grain for the whole table (`grainIs`);
- optionally `dims` (dimension columns; an empty value is the total), `extra` (columns kept beside
  the number) and `prefix` (put before each metric's name);
- optionally `version`, for the figures of another definition of the same metrics, such as
  corrected ones. They are kept beside the version the metric is computed and read at (its
  `->version()`, 1 by default), and a recomputation never touches them.

Every value comes in final, as `imported`. A second run replaces what the first wrote. Rows whose
grain is none of the metrics' are counted as left out.


**Why there are no continuous aggregates over the values.** A continuous aggregate rolls a fine
grain up into a coarser one, for example days into months, and that roll-up gives the right
number for only one of the three kinds of metric:

- **A distinct metric does not roll up.** The month's unique customers are not its days' unique
  customers added together. That is exactly the error this design exists to prevent.
- **A level does not roll up.** The month's level is how things stood when the month closed,
  not the sum or the average of its days.
- **A sum would roll up, but gains nothing.** Every grain is already computed from its source,
  over its own half-open window in the metrics' time zone, so the month is exact even when some
  of its days were never computed or were imported. An aggregate over a few thousand rows saves
  no time worth having.

Continuous aggregates belong on the high-volume tables underneath: an application hypertable of
events (logins, readings, page views) with an aggregate per day, which a metric's source then
reads instead of the raw rows. That is where TimescaleDB saves work, and the source decides it.
The business's own tables (customers, orders, invoices) change state, so they stay ordinary
tables, and their metrics read them as they are.

## Dimensions: parts and places

```php
Metrics::dimension(Dimension::of('area', 'Area')->values(…)->parent('region')
    ->geo(fn (string $area): ?array => Areas::centre($area)));   // [lat, lng], or null
```

- **`parent()`** says what a dimension is a part of. A reader drills from a region to its areas by
  filtering on the region and breaking down by the area (`->where(['region' => 'north'])->by('area')`).
  The JSON API's catalogue gives each metric's `hierarchy`: each dimension's label, parent, and
  whether it maps.
- **`geo()`** gives a value its place, for the map card.
- **The zone at the time, or the zone today**, is a source's choice, made with two dimensions
  (`zone_then`, `zone_now`), not a feature of the store.

## Periods: `Window`

```php
$w = Window::of(Window::MONTH, '2026-09-14');   // hour, day, week (Monday first), month, quarter, year
$w->label();          // '2026-09'
$w->previous();       // August
$w->yearEarlier();    // September 2025; for a week, the same ISO week
Window::range(Window::MONTH, '2026-01-01', '2026-10-31');   // every month in between
```

**By the hour.** `Window::HOUR` is the zone's hour, keyed and labelled `2026-09-17 14:00`. Hours
are counted in time, not on the clock, so the night the clocks go back has 25 of them. A metric is
kept by the hour only when it asks for it:

```php
Metric::flow('readings.count', 'Readings')->grains(['hour', 'day', 'month'])->source(…);
```

`Window::DEFAULT_GRAINS` (day to year) is what every other metric keeps. An application that wants
every metric by the hour names `hour` in `metrics.grains`. `metrics.retention` keeps the hours
short: after each computation, older ones are dropped, as whole chunks on TimescaleDB
(`Store::prune()`). Computing a day of hours with `--from`/`--to` days covers every hour of the
last day.

## Related

- [Dashboard](Pramnos_Dashboard_Guide.md): the cards that can show a metric
- [Workers and daemons](Pramnos_Workers_And_Daemons_Guide.md): the framework's schedule
- [Query Builder](Pramnos_QueryBuilder_Guide.md): writing sources
