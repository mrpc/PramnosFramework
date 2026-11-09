---
use_cases:
  - Turning the numbers of a fixed report into metrics that any report or dashboard can show
  - Counting unique customers per day and per month without adding the days together
  - Keeping how many members were active at the end of each month, when the tables only know today
  - Importing a report's history from an old statistics table
  - Reading a metric over time, broken down by a dimension, against last year
  - Building a report of metrics by period or market, shared and mailed every month
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
    ->sum('total')                        // or ->count(), ->countDistinct('userid') (a distinct metric)
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
| Text | `report.text` | Words in markdown, escaped and then formatted as the assistant's answers are. |
| Records | `report.rows` | The records behind a metric that declares `rows()`, the first ones as a table, with a link to all of them. |

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

### Closed periods

A closed period's values are final (see above), so a report of a closed period shows the same
numbers every time it is opened. Nothing has to be frozen separately.

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
  corrected ones. They are kept beside version 1, which is the version computed and read
  (`Store::VERSION`). A recomputation never touches them.

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
