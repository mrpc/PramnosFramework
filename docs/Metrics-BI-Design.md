---
use_cases:
  - Deciding how an application's figures are stored, computed and shown by the framework instead of its own statistics code
  - Planning the move of an application's own report builder, statistics tables or metric store onto the framework's metrics
  - Choosing between one values table and a hypertable per period on TimescaleDB
  - Adding hourly figures, per-organisation figures, alerts or goals to an application
---

# Metrics and BI — design

**Status:** approved (§13); phase 0 done — layout B, measured in §5.4.
**Last updated:** 9 November 2026

This page designs the framework's statistics and BI mechanism as one engine for every application:
an application **declares its metrics, and nothing else**. Storage, periods, history, closing,
replica, scaling, the editor, charts, drill-down, goals, alerts, exports, the API and the
assistant are the framework's, the same everywhere.

It starts from what exists today ([Metrics guide](Pramnos_Metrics_Guide.md)), and from the needs
of six applications built on the framework. Section 2 describes those applications by kind:

- a marketplace;
- a sensor platform with half a terabyte of readings;
- a multi-tenant analytics service;
- a prepaid-card service on PostgreSQL 10;
- a hosting and billing application;
- a real-time chat service.

Small applications on MySQL or without TimescaleDB count as a seventh case.

See also [Roadmap & Open Items](Roadmap.md).

---

## 1. Principles

1. **An application declares; the framework does the rest.** A declaration says what is counted,
   from where, broken down by what, who may see it, and what lies behind each number. Nothing in
   an application stores values, schedules computations, draws charts or decides periods.
2. **Values are stored, not pages.** A page, card, report, mail or API call reads stored values. No
   screen computes when it is opened. A rendered artefact is cached only where measured to help:
   a chart's PNG for a mail.
3. **One value per period, tenant and combination of dimensions.** While the period is open, the
   value is replaced in place. The number of rows follows the dimensions, not how often
   computation runs.
4. **One definition per number.** Pages, cards, reports, the API and the assistant read the same
   metric, so the same question gives the same answer everywhere.
5. **Computation needs no request.** It runs from the scheduler or a worker, with no session or
   controller. A failure leaves the previous values in place, never an empty one.
6. **The replica is for computation only.** It has a timeout and falls back to the primary. There
   is no general read/write split.
7. **Built on TimescaleDB and its Toolkit; simpler elsewhere.** The advanced features use
   TimescaleDB and the TimescaleDB Toolkit to the full, and exist only where they are installed.
   On MySQL or plain PostgreSQL the basic mechanism runs on plain tables, and a feature that needs
   more is not offered there. Nothing is rebuilt slower to look the same everywhere (§1.1).
8. **One store.** An application keeps no store of its own beside the framework's. Section 10
   moves existing ones over without losing history.
9. **Measured, not estimated.** Each phase ends with timings on real and synthetic data, and the
   storage decision in §5 is taken on measurements.

### 1.1 What each database has

Every report feature exists everywhere. Its more advanced forms have stated requirements, and an
installation without them gets a simpler version.

| | MySQL, plain PostgreSQL | TimescaleDB | TimescaleDB + Toolkit |
|---|---|---|---|
| Declaring metrics: sums, levels, derived | ✔ | ✔ | ✔ |
| Periods of a day and longer | ✔ | ✔ | ✔ |
| The editor for reports and dashboards: cards, tables, charts, the comparison with last year | ✔ | ✔ | ✔ |
| Drill-down to the records | ✔ | ✔ | ✔ |
| CSV, XLSX, print/PDF, mail with charts | ✔ | ✔ | ✔ |
| Distinct people counted exactly, each grain from the source | ✔ | ✔ | ✔ |
| Hour periods, and finer per metric | | ✔ | ✔ |
| Hypertables, compression, retention per grain | | ✔ | ✔ |
| Continuous aggregates of sources, hierarchical where they add up | | ✔ | ✔ |
| Live metrics over large event tables | | ✔ | ✔ |
| Distinct people and percentiles that roll up across periods (`hyperloglog`, `uddsketch`) | | | ✔ |
| Cumulative meters with resets (`counter_agg`), time-weighted averages (`time_weight`) | | | ✔ |
| "Data stopped" (`heartbeat_agg`) | | | ✔ |

**Each feature declares what it needs**: a card, a metric, an aggregation or a field option says
`requires('timescaledb')` or `requires('toolkit')`. The framework checks once per installation
which extensions are present, and their versions, and keeps the answer. What is missing is not
offered: not in the card catalogue, not in a form's choices, not in the assistant's tool. It is
neither an error nor a slower substitute. The same report on MySQL shows fewer choices.

`timescaledb_toolkit` is a trusted extension, so the application's own database user (with
`CREATE` on the database, not a superuser) can install it. A framework migration runs
`CREATE EXTENSION IF NOT EXISTS timescaledb_toolkit` where the package is on the server. Where it
is not, the migration says so and the Toolkit's features stay off.

---

## 2. What the applications need

| Need | Marketplace | Sensor platform | Analytics service | Prepaid-card service | Billing | Chat | Small / MySQL |
|---|---|---|---|---|---|---|---|
| Hour (and finer) periods | later | ✔ | ✔ | | | ✔ | |
| A tenant (organisation, site) on every row | | ✔ | ✔ | ✔ | | | |
| A time zone per tenant | | ✔ | ✔ | | | | |
| Many series per entity (device, account) | | ✔ (tens of thousands) | ✔ | ✔ | | | |
| avg / min / max / last / peak; counter, gauge, rate | ✔ | ✔ | ✔ | | | ✔ | |
| Formulas; the difference of a cumulative reading | ✔ | ✔ | | | | | |
| Late data, a correction window before closing | | 7 days | 3 days | | | | |
| Cohorts, "within N days" | ✔ | | | | | | |
| Drill-down into the application's own lists | ✔ | ✔ | | | | | |
| Dimension hierarchy, map | ✔ | ✔ | ✔ | | | | |
| How a dimension stood then vs now (an entity's zone) | | ✔ | | | | | |
| Alerts: threshold, change vs last year, data stopped | ✔ | ✔ | ✔ | ✔ | | | |
| Goals, intraday progress, forecast | ✔ | | ✔ | | | | |
| Annotations | ✔ | | ✔ | | | | |
| JSON API and SPA outside the administration, by tenant | | ✔ | ✔ | ✔ | ✔ | | |
| Mail, CSV, XLSX, PDF | ✔ | ✔ (XLSX) | ✔ | | | ✔ | |
| Versions of a corrected definition, side by side | ✔ | | | | | | |
| The assistant's reports as reports; the assistant in the editor | ✔ | | | | | | |
| Import of history from older reports | ✔ (12 years) | ✔ | ✔ | | | ✔ | |
| No cron, old PostgreSQL, MySQL | | | | PG 10 | | no cron | ✔ |

Volumes that set the upper bound:

- **The sensor platform** keeps 457 GiB of readings at five-minute resolution, 18 GiB once
  compressed, in a 418 GB database. It logs about 3.1 million rows a day, and already runs
  continuous aggregates per day, month and year by area and zone.
- **The analytics service** keeps eight real-time continuous aggregates, hourly and daily.

None of these applications uses the framework's metrics today. Four of them have their own
mechanism, and the framework's has to replace each one:

- a report builder with a formula language over continuous aggregates;
- a metric catalogue with goals and annotations;
- `stats_hourly` … `stats_yearly` tables rolled up in PL/pgSQL;
- revenue reports summed in PHP.

**A name to live beside.** In one application, `Metric` is a sensor channel: a model and a
`metricvalues` table. The framework's metrics stay in `Pramnos\Metrics` and the `stats` schema,
and its configuration stays under `'metrics'`, so the two never meet in a namespace or a table.
Its docs call them *business metrics* wherever both could be meant.

---

## 3. What exists, and what changes

**Exists** (Metrics guide):

- flow, level and derived metrics;
- sum and distinct aggregation, with the breakdowns each keeps;
- dimensions with values;
- `rows()` behind a number;
- day to year periods (`Window`) in one time zone;
- closing a period, recomputing and backfilling;
- `Metrics::import()` for history;
- a replica for computation;
- `stats.metric_values`;
- the dashboard's metric cards;
- reports as boards of cards, mailed with charts as PNG, and CSV export;
- the assistant's `metrics` tool.

The TimescaleDB machinery is there as well: `HypertableRegistry`, `ContinuousAggregateRegistry`,
`DeferredWriteQueue` and `timescale:drain`.

**Changes, in short:**

| Area | Today | Designed |
|---|---|---|
| Time | `period_start date`, `computed_at integer` | `timestamptz` (MySQL `DATETIME`, UTC) |
| Dimensions | `dims text` + md5 | `jsonb` (MySQL `JSON`) + a 16-byte hash |
| Tenant | none | a `tenant` column on every row, and a scope on every read |
| Grains | day … year | `hour` added; sub-hour as an option (§4.2) |
| Aggregation | sum, distinct, level, derived | + avg, min, max, last, peak; counter, gauge, rate (§4.3) |
| Closing | once, at the period's end | after a correction window per metric (§6.3) |
| Storage on TimescaleDB | a plain table | hypertables with compression and retention (§5) |
| Sources | a closure | a closure, or a declared query the framework can turn into `time_bucket` SQL and continuous aggregates (§4.4) |
| High-cardinality series | stored like any other | read live from a continuous aggregate, not copied (§4.5) |
| Reads | `Metrics::series/value/compare` | one query API used by cards, reports, the JSON API and the assistant (§7) |
| Signals | none | alerts, goals, annotations (§9) |
| The assistant | reads metrics; its routines are mailed apart, without charts | its routines are reports; it writes an analysis card, proposes cards in the editor, and explains a number (§8.1, §8.2) |

---

## 4. Declaring a metric

Everything here is **additive**: a metric declared today keeps working unchanged.

### 4.1 The tenant

```php
// app/app.php
'metrics' => [
    // Which tenants a viewer may read: a list of ids, or null for all of them.
    'tenant'   => fn (?object $user): ?array => $user?->organizationIds(),
    // A tenant's time zone, when periods follow it.
    'timezone' => fn (int $tenant): string => Organizations::timezone($tenant),
],

Metric::flow('water.consumed', 'Water consumed')
    ->tenant('organizationid')      // the source's column that names the tenant
    ->unit('m³');
```

The tenant is not a dimension. Every stored row carries one (`0` for an application without
tenants), every read is narrowed to the viewer's tenants, and the narrowing happens in the query
API: a card, a report, the JSON API and the assistant cannot leave it out. `allowed()` stays as
it is, deciding per metric whether a viewer may read it at all.

**A time zone per tenant.** When the application names one, a tenant's day is its own local day.
`period_start` is that local midnight as a `timestamptz`, so two tenants' "9 November" are
different instants. Day and night split by a tenant's local hour.

### 4.2 Grains

`Window::GRAINS` gains `hour`. A grain finer than an hour (`15min`, `5min`) is declared per
metric (`->grains(['5min', 'hour', 'day'])`). Only a metric that asks for it is stored that finely,
and retention keeps it short (§5.3). A metric declares the grains it is kept at; the default stays
day to year.

### 4.3 Aggregation and kind

| Declared | How a period's value is made | How a coarser period is made |
|---|---|---|
| `SUM` (today) | the source's sum | sum of the finer, or from the source |
| `DISTINCT` (today) | counted in the period | always from the source |
| `level()` (today) | as it stood when computed | the last of the finer |
| `AVG` | sum and count kept together | sum of sums ÷ sum of counts |
| `MIN`, `MAX` | the source's | min / max of the finer |
| `LAST` | the latest reading | the last of the finer |
| `PEAK` | the highest of a finer grain within the period (the busiest hour of a day) | the max of the finer's peaks |
| `counter()` | a cumulative reading (a meter): the increase over the period, a reset detected and added back | sum of the increases |
| `gauge()` | a reading of how things stand: `LAST` unless told `AVG` / `MAX` | as its aggregation |
| `rate('per hour')` | derived: the value ÷ the period's length | — |

Also:

- **Presentation:** `->direction(Metric::HIGHER_IS_BETTER)` colours a change and orders a top-N.
  `->format('currency' | 'percent' | 'duration' | 'number')` reads it.
- **Derived metrics** keep their `+ - * /` and gain `delta()`, the difference of a cumulative
  reading, and `ifnull()`.
- **A wider formula language** is not designed: one application's own formula language is moved
  as derived metrics and declared sources (§10).

### 4.4 The source

There are two ways to declare a source.

- **A closure, as today.** It receives the window, the dimensions to group by and the connection,
  and returns rows. It is the only way for a level, a cohort or anything that needs code.
- **A declared query** that the framework turns into SQL:

```php
Metric::flow('payments.amount', 'Revenue')
    ->from('invoices')
    ->at('date', unix: true)                 // a unix-time column, read as time without a schema change
    ->sum('amount')                          // or ->count(), ->countDistinct('userid'), ->avg(), ->max()…
    ->where('status', 1)
    ->dimension('market', 'countryid')       // dimension => column, or => an SQL expression
    ->tenant('organizationid');
```

A declared query lets the framework do what a closure cannot:

- compute every grain with one `time_bucket` query instead of one query per period;
- offer a continuous aggregate of the source, and build it, register its refresh policy and read
  from it;
- read unix-time columns as `to_timestamp(col)`. Where the source table is itself a hypertable on
  an integer column, it uses `integer_now` for the policies. Either way the application's schema
  stays as it is.

### 4.5 Stored or live

A metric is **stored** by default: its values are computed and kept (§5).

A metric over tens of thousands of entities at the hour can be declared **live**. The sensor
platform is the case: a device's consumption by hour is 50,000 devices × 24 × 365, about 440
million rows a year. Copying that into a second table doubles a store that already has it, so a
live metric is read at query time from the continuous aggregate its declared source defines,
narrowed to the entities asked for. Its totals (by zone, by tenant) are stored as usual. The
query API hides the difference (§7).

### 4.6 Records, cohorts, versions, gaps

**Records.** `rows()` stays. `->drill(fn (Window $w, array $filters): string => adminUrl('Users?…'))`
sends a number to the application's own list, with filters, instead of the framework's.

**Cohorts.** `Metric::cohort('parents.paid_within', 'Paid within N days')->of('registered_at')->event('first_payment_at')->within([7, 14, 30])`.
It is stored per cohort period and offset, as a dimension.

**Versions.** `->version(2)` on a definition that was corrected. The new version is computed beside
the old one for the same periods, and a switch per report or card shows "as before" or
"corrected". The old version stops being computed once nothing reads it.

**Gaps.** `->since('2026-10-16')`, or `->missing(fn (Window $w): ?string => …)`, says which
periods the source cannot answer. Such a period shows why ("no data before 16 October 2026"),
not a zero.

### 4.7 The time a source runs on

`->expect('1 hour')` declares how often new data should arrive. It feeds the alert "data stopped"
(§9.1) and the staleness shown on a card.

---

## 5. Storage

### 5.1 The row

```sql
-- stats.metric_values_<grain>, one table per grain (§5.2); PostgreSQL (MySQL: DATETIME UTC, JSON, CHAR(36))
metric       varchar(100) NOT NULL,
tenant       bigint      NOT NULL DEFAULT 0,
period_start timestamptz NOT NULL,          -- the period's first instant, in its zone
dimset       varchar(200) NOT NULL DEFAULT '', -- the breakdown's dimensions, sorted: picks a breakdown by index
dims         jsonb       NOT NULL,          -- {} for the total
dims_hash    uuid        NOT NULL,          -- md5 of the canonical dims, as a uuid: 16 bytes
version      smallint    NOT NULL DEFAULT 1,
value        double precision,
extra        jsonb,                         -- AVG: {sum, count}; counter: {first, last, resets}; a kept text
source       varchar(10) NOT NULL,          -- computed, snapshot, imported
final        smallint    NOT NULL DEFAULT 0,
computed_at  timestamptz,
PRIMARY KEY (metric, tenant, dims_hash, version, period_start)
```

Indexes:

- the primary key serves a series, where everything but time is fixed;
- `(metric, period_start)` serves a report across dimensions;
- a GIN index on `dims` is added only where a measurement shows filtering inside `dims` without
  the hash.

`double precision` rather than `numeric`: TimescaleDB compresses it several times better. Money
stored as euros with cents stays exact up to 2⁵³ cents. A metric that needs exact decimals beyond
that declares `->exact()`, and is kept in a second column.

The table already exists with `date`, `integer` and `text` columns, in at least one production. By
rule 15 and the rule on changing a migration that has run, **the create-table migration is
changed in place**. Installations that ran it get a one-time statement in the Upgrade Guide row,
which converts the columns. `Store::adopt()` (§10) brings in an application's own store; it is a
method, not a command, since each application's store is moved once, by a script of its own.

### 5.2 One table, or a hypertable per grain

| | **A. One hypertable** | **B. A hypertable per grain**, one API over them | **C. Hour (and finer) apart, the rest together** |
|---|---|---|---|
| Chunk size | one size for all: always wrong for some grain | each grain its own (hour: 7 days; day: 3 months; month and up: 5 years) | right for the fine grains, one size for the coarse |
| Compression and retention | one policy for all grains | per grain: hours deleted after weeks, days compressed after months, months kept | per fine grain; one for the rest |
| Hierarchical continuous aggregates (day from hour) | not possible on the values | possible for SUM/MIN/MAX/LAST/counter | possible from the hour table |
| One place to query (SQL, the assistant, exports) | yes | through a view, `stats.metric_values`, `UNION ALL` of the grain tables, and the API | through a view |
| Migrations | one table | one per grain (generated from the grain list) | two |
| MySQL / no TimescaleDB | one table | the same split, harmless; or one table, as the API hides it | either |

**Decided: B, on every database** — measured in §5.4. On TimescaleDB it is the fastest layout
at every size and the only one whose reads stay flat as the hours grow: at 100 M rows a 24-month
chart is 0.5 ms against 3.1 ms in A (10 ms once compressed), a 30×12 report 1.4 ms against 13 ms.
It writes an open day fastest, recomputes a compressed closed month fastest, and is the smallest
on disk. On plain PostgreSQL the three are within a millisecond of each other, so the same split
is kept there too: one layout, one migration generator and one query path for every installation.
The application never sees it: it declares metrics, the query API reads them, and the view
`stats.metric_values` keeps one place for SQL.

The sizes measured (`benchmarks/metrics-storage/`):

| Size | Metrics | Tenants | Dimension combinations per metric | Grains | Years | Rows (all grains) |
|---|---|---|---|---|---|---|
| Small | 20 | 1 | 3 | day … year | 3 | 78 k |
| Medium (the marketplace) | 150 | 1 | 20 | day … year | 12 | 15.6 M |
| Large (the sensor platform's stored totals) | 12 | 30 | 10 | hour … year | 3 | 99 M |

The large size was planned at 2 B rows and built at 100 M, the most this machine holds in its
time budget with three layouts side by side. Its hourly table is 95 % of the rows; the reads that
matter go to one series or one period, through the primary key, and stayed flat from 15 M to
100 M rows in B and C, so the order between the layouts is not expected to change at 2 B. A's
does not stay flat: its day and month reads pay for the hours in the same chunks.

### 5.3 TimescaleDB, all of it

Where the extension is present, and with no declaration from the application:

- **Hypertables** on `period_start`. Chunk intervals follow §5.2.
- **Compression** of chunks older than the longest correction window plus a margin. Segment by
  `(metric, tenant, dims_hash)`, order by `period_start DESC`. Recomputing a closed period writes
  into compressed chunks directly on 2.11+ (DML on compressed data). On older versions it goes
  through `DeferredWriteQueue`, grouped per chunk. It is rare and heavy, never blocked.
- **Retention** per grain, from `'metrics' => ['retention' => ['5min' => '14 days', 'hour' => '90 days']]`.
  Day and coarser are kept for ever by default.
- **Continuous aggregates** of declared sources (§4.4), with refresh policies registered in
  `ContinuousAggregateRegistry`. They become the source the framework computes from, or the live
  series (§4.5). On the values themselves there is no aggregate across grains for DISTINCT and
  level, because those do not roll up. Under option B, hierarchical aggregates roll up SUM, MIN,
  MAX, LAST and counter where a measurement shows them to be cheaper than computing from the
  source.
- **`time_bucket` and `time_bucket_gapfill`** for the declared queries and for charts with empty
  periods. Elsewhere the framework fills the gaps in PHP.
- **A hypertable for the application's own event tables** where it asks for one (`HypertableRegistry`),
  including unix-time tables through `integer_now`.

- **The Toolkit**, where it is installed (§1.1):
  - `hyperloglog` sketches let a DISTINCT metric roll up from days to months without the raw
    events, within a stated error. A metric that must be exact declares `->exact()` and keeps
    being counted from its source at every grain. **A figure read as an accounting number is
    exact**: paying customers, new customers, anything set beside invoices or another ledger.
    Sketches are for counts over large event tables (visits, readings, actions), where ±3 % is
    the answer;
  - `uddsketch` / `percentile_agg` give percentiles that roll up;
  - `counter_agg` is the increase of a cumulative meter with resets (`counter()`, `delta()`);
  - `time_weight` gives time-weighted averages of a gauge;
  - `heartbeat_agg` finds the stretches with no data, for "data stopped";
  - `stats_agg` gives averages and deviations that roll up.

Without TimescaleDB the declarations that need nothing more run on plain tables. Day and coarser
grains, sums, levels and exact distinct counts are each computed from their source, with
retention by a scheduled delete. The features in §1.1 that need TimescaleDB or the Toolkit are
not offered there; they are not rebuilt in PHP or plain SQL.

### 5.4 Measurements

Medians of 15 runs after two warm-ups, in milliseconds, on one WSL machine (TimescaleDB 2.31 and
Toolkit 1.26 on PostgreSQL 18, 6 GB for the container), with the scripts and raw results in
`benchmarks/metrics-storage/`. Chunks before 1 September 2026 are compressed for the second half of
each table; "recompute a closed month" rewrites one metric's October 2025 in those compressed
chunks, by direct DML.

#### Large — 99 M rows, hour … year

| ms | A | B | C | A, compressed | B, compressed | C, compressed |
|---|---:|---:|---:|---:|---:|---:|
| Card (1 value) | 0.4 | 0.4 | 0.4 | 0.4 | 0.4 | 0.4 |
| Chart, 24 months | 3.1 | **0.5** | 0.5 | 10.0 | **0.6** | 0.6 |
| Report 30×12 with last year | 13.3 | **1.4** | 1.9 | 18.8 | **1.5** | 1.8 |
| Chart, 168 hours | 0.6 | 0.6 | 0.6 | 0.8 | 0.6 | 0.6 |
| A day by tenant, from the hours | 1.0 | 0.9 | 0.9 | 0.9 | 0.7 | 0.8 |
| Write an open day, every series | 48.5 | **40.8** | 49.9 | 56.2 | **40.5** | 48.8 |
| Write an open day's 24 hours | 1 047 | 1 067 | 1 048 | 847 | **760** | 839 |
| Recompute a closed month: its days | | | | 129 | **112** | 436 |
| Recompute a closed month: its hours | | | | 3 022 | **2 108** | 2 136 |
| Size on disk | 26.8 GB | **25.5 GB** | 25.6 GB | 3.70 GB | **3.02 GB** | 3.11 GB |

Compressing the 3 years took 8.8 minutes for all three layouts together.

#### Medium — 15.6 M rows, day … year

| ms | A | B | C | A, compressed | B, compressed | C, compressed | Plain PG 18: A | B | C |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Card | 0.4 | 0.4 | 0.4 | 0.4 | 0.4 | 0.5 | 0.4 | 0.3 | 0.3 |
| Chart, 24 months | 0.6 | 0.5 | 0.6 | 0.7 | 0.6 | 0.8 | 0.4 | 0.4 | 0.4 |
| Report 30×12 with last year | 2.0 | 1.8 | 2.0 | 2.5 | 2.1 | 2.9 | 1.8 | 1.7 | 1.7 |
| Write an open day | 45.9 | **28.0** | 42.3 | 39.3 | **32.4** | 38.3 | 36.6 | 32.5 | 34.7 |
| Recompute a closed month: its days | | | | 34.0 | **14.9** | 32.7 | 15.4 | 18.0 | 9.9 |
| Size on disk | 4.68 GB | **3.93 GB** | 4.68 GB | 539 MB | **451 MB** | 539 MB | 4.71 GB | 4.29 GB | 4.71 GB |

Small (78 k rows): every read under 2 ms and every write under 7 ms in every layout, on
TimescaleDB, on PostgreSQL 18 and on **PostgreSQL 10.21**, which runs the plain layout — `jsonb`,
`ON CONFLICT`, the md5 hash as a `uuid` — unchanged.

What follows from them:

- **Compression is 7–9× on the values**, and does not slow the reads B makes. It is on by default
  where TimescaleDB is present.
- **Rewriting a compressed month is cheap enough to do inline**: 112 ms for a month of days, 2.1 s
  for a month of hours of one metric (300 series), on 2.31. No queue is needed on 2.11+.
- **A closed month of hours exceeds TimescaleDB's default limit** of 100 000 decompressed tuples
  per DML transaction (302 400 here): the recomputation sets
  `timescaledb.max_tuples_decompressed_per_dml_transaction = 0` for its own transaction
  (`SET LOCAL`), and nothing else does.
- **The cost that grows is writing hours**, about 1 s for a day of 24 hours across 3 600 series —
  a background job's work, not a request's.

#### Distinct counts — exact, against the Toolkit's `hyperloglog`

People counted once per period, from events; daily sketches in a continuous aggregate, and a
monthly one rolled up from it.

| | 20 M events, 400 k people: exact | `hyperloglog(4096)` | 100 M events, 2 M people: exact | `hyperloglog(4096)` |
|---|---:|---:|---:|---:|
| A month | 244 ms | 3.3 ms from days, 0.5 from months | 1 643 ms | 3.3 ms, 0.5 |
| A month by region | 1 357 ms | 4.0 ms | 7 824 ms | 3.8 ms |
| A year | 2 974 ms | 1.6 ms | 9 340 ms | 1.7 ms |
| Error, month / year | | −1.2 % / −1.4 % | | −1.8 % / −2.7 % |
| Sketch | | 3 kB | | 3 kB |
| Refreshing one day | | 10 ms | | 16 ms |

**4096 buckets is the default.** 32 768 buckets cuts the error below 1.5 % but its rollup is
erratic: 1.4 ms for a month at 2 M people, 1.1–1.4 s at 400 k, reproduced twice — the sketch's
sparse form merges slowly. A metric that needs better than ±3 %, and every figure compared with a
ledger (§5.3), declares `->exact()`.

#### Real data — a marketplace's paid invoices and registrations

232 k paid invoices and 500 k registrations, copied read-only from an anonymised copy, their times
kept as unix integers as the application keeps them.

| A year of days, revenue by gateway and renewal, paying people | ms |
|---|---:|
| One query per day, as a closure source runs today | 152 |
| One `time_bucket` query over the unix column | 28 |
| The same, the table made a hypertable on its unix column (`integer_now`) | 29 |
| Read from a continuous aggregate of it | 1.6 |
| Twelve years of months, one query | 137 |

A declared source (§4.4) is **5× faster than the same figures computed day by day**, with no
change to the application's schema. A continuous aggregate makes it two orders of magnitude
faster again. Making a table of this size a hypertable buys nothing by itself; it is what allows
the aggregate.

#### MySQL — through the framework's own code (phase 1)

MySQL 8.0, layout B as `Store::ensure()` makes it, read with `Metrics::value()` and
`Metrics::series()` (`benchmarks/metrics-storage/mysql.php`). The data is 150 metrics × 20 series:
a year of days (1.1 M rows) and twelve years of months (432 k rows), 454 MB with indexes.

| ms (median of 15) | first | dimset in the query |
|---|---:|---:|
| Card (1 value) | 1.1 | 0.5 |
| Chart, 24 months | 10.4 | 2.4 |
| Chart, 12 months by its 19 values | 6.0 | 6.9 |
| Report, 30 metrics × 12 months | 168 | 39 |
| Chart, 365 days | 138 | 13 |
| Write an open day, all 3 000 series | 264 | 275 |

The first column also measured the read path itself. It fetched every breakdown of a metric
(20 rows a period) and kept the one asked for in PHP. Phase 1 asks the database for that
breakdown and the total, which marks the periods that were computed. That made a year of days
10× faster, on PostgreSQL as well.

What is left is PHP's own cost per series, about 1.3 ms for a report row. The query API of
phase 2 reads a report's rows in one query.

---

## 6. Computation

### 6.1 Who runs it

`metrics:compute` runs from the framework's schedule. It computes the open periods of every grain
and those still inside their correction window, closes the ones past it, and backfills on request
(`--from`, `--metric`, `--recompute`).

An application without cron gets the same command through the queue worker. Where there is
neither, a request starts the command in the background once its interval has passed, behind a
lock. It never runs inside the page that started it, and the page shows the stored values as
they are.

### 6.2 How

- **One query per metric and grain, for every open period at once**, where the source is declared
  (`GROUP BY time_bucket(…)`). A closure is called per period, as today.
- **The replica**, with its timeout and fallback (`'metrics' => ['replica' => …]`).
- **A failure leaves the previous value.** A period is replaced only by a complete computation of
  that period. It is written in one transaction per metric and grain.
- **Each run is recorded** in `changelog_events`: what was computed, how long it took, and what
  failed (rule 14). No runs table.

### 6.3 Late data and closing

`->lateness('7 days')` keeps a period open, and recomputed, for that long after it ends. Only then
is it `final` and left alone, until `--recompute` or a new `version`. The default is the current
behaviour, closing at the period's end. Compression waits for the longest lateness declared.

### 6.4 History

`Metrics::import()` stays the way in for old reports, and gains a bulk form for a whole table.
`Store::adopt()` maps an application's existing store or statistics tables onto metrics, with the
dimensions and grains each column means, and imports them once (§10), from the application's own
one-off script.

---

## 7. Reading: one query API

```php
Metrics::query('payments.amount')
    ->grain('month')->between('2026-01-01', '2026-11-01')
    ->by('market')->where(['package' => ['premium', 'plus']])
    ->compare(Metrics::YEAR_EARLIER)
    ->fill()                                   // a value for every period, null where none
    ->for($user)                               // allowed() and the tenant scope
    ->series();                                // or ->value(), ->table(), ->top(10)
```

Every reader goes through it: cards, reports, the JSON API, exports and the assistant's tool. So
`allowed()`, the tenant scope, versions, gaps and live metrics are applied once. It reads the store
(§5), or the continuous aggregate of a live metric, or works out a derived metric from its
operands. `Metrics::series/value/compare` become thin calls into it and keep their signatures.

---

## 8. Presentation and delivery

- **The editor:** one for dashboards and reports, already built (reports are boards of cards).
  New cards come with the needs:
  - a time series with zoom and range, the same period a year earlier on the same chart, and
    stacking by a dimension;
  - progress towards a goal;
  - a map;
  - a top-N;
  - a cohort grid.
- **Dashboard variables:** pickers at the top that set a dimension, tenant or period for every
  card on the board, kept in the address.
- **Dimension hierarchy:** `Dimension::of('area')->parent('region')`. A drill goes from region to
  area, and a map card reads `->geo(fn ($value): ?array => [lat, lng] | geojson)`.
  **Then-or-now** (an entity's zone at the time, or its zone today) is a source's choice, made
  with two dimensions (`zone_then`, `zone_now`), not a feature of the store.
- **A JSON API outside the administration:** `/api/{version}/metrics/...` on the framework's API
  stack, with the API key or user token, the same query API and the tenant scope. The SPA stack
  gets the card and chart components.
- **Exports:**
  - CSV, as today;
  - XLSX, a minimal writer over `ext-zip` (no spreadsheet library);
  - PDF from the browser's print;
  - mail with PNG charts, as today.

### 8.1 One report system, for people and for the assistant

The assistant's scheduled reports (its routines, sent by mail, push or the bell without charts)
and the reports people build become **one system**. A routine becomes a report:

- the same board of cards;
- the same sharing and schedule;
- the same mail, with charts as PNG.

The assistant's part is a card: **an analysis card**, whose words the assistant writes from the
report's own numbers each time the report is drawn for sending. It is cached per period, so
opening the report does not ask again. A routine that today is only a prompt is moved as a report
with one analysis card and the metrics its prompt read. Its schedule and recipients move with it,
and `llm:routines` hands such reports to `metrics:reports-send`.

### 8.2 The assistant inside the editor

- **Building from words.** "Sales by pillar compared with last year" in the editor asks the
  assistant, through a tool of its own (`report_propose`), for cards: their kinds, metrics,
  dimensions, filters, comparison and layout. It answers only from the catalogue the person may
  read. The proposal is drawn as a preview on the board, with the real numbers. The person keeps,
  changes or drops each card. Nothing is saved until they save, as with any edit.
- **Explaining a number.** Each card offers *Ask about this*. The assistant opens with the card's
  context: metric, filters, tenant, period, value, comparison, the annotations near it, and the
  breakdown that moved most. It reads further through the query API, under the same scope.

Both go through the query API (§7). The assistant cannot read a metric, tenant or record the
person could not.

---

## 9. Signals

### 9.1 Alerts

The rule is state. A row in `stats.alert_rules` holds:

- the metric, filters, tenant, grain and condition. The condition is a threshold, a change against
  the period before or a year earlier, or **data stopped**, which needs no new data for longer
  than `expect()`;
- who is told (users, roles, a conversation channel);
- how often it repeats.

It is evaluated after each `metrics:compute`, and the "data stopped" check on its own schedule.
A firing is history: it goes to `changelog_events` and is sent through the notifier (bell, push,
the conversation), with a link to the chart.

### 9.2 Goals

`stats.goals` holds a target per metric, period, tenant and filters. Progress within a day reads
the hour grain. **The expected curve** is the same weekday a year earlier, by hour, scaled to the
target, and the **forecast** extrapolates from it. Goals that a person sets are state; a missed
goal is an alert (§9.1).

### 9.3 Annotations

`stats.annotations` holds a moment or a span, a tenant, words, a kind and an optional link. They
are written by people, and by the application's own sources: deploys from the changelog, price
changes, campaigns. Charts draw them, and the assistant reads them as context.

These three tables are state, not logs (rule 14). Firings and changes go to the logs.

---

## 10. Moving the applications over

| Application | What goes | How, without losing history |
|---|---|---|
| Marketplace | its own metric store (`timestamptz`) and its monthly report classes | its metrics are declared; its own data script imports its store into `stats` with `Store::adopt()`; its two ready reports are rebuilt as boards; its table is dropped once compared for a month |
| Sensor platform | its report builder, formula language and aggregate tables | declared sources over its readings hypertable; its day/month/year continuous aggregates become the metrics' sources (live per device, stored per zone and tenant); its formulas become derived metrics with `delta()`; XLSX from §8 |
| Analytics service | its catalogue, goals, annotations, SVG charts | catalogue → metrics (counter/gauge/rate, direction, format); its hourly and daily continuous aggregates → sources; goals and annotations imported into §9's tables |
| Chat service | `stats_hourly` … `stats_yearly` and the PL/pgSQL roll-up | metrics at hour to year; the tables imported; no-cron mode (§6.1) |
| Billing | reports summed in PHP | declared sum metrics over invoices |
| Prepaid-card service | `DATE_TRUNC` SQL in its API | declared metrics, read through the JSON API with the tenant scope; PostgreSQL 10 compatibility verified in phase 0 |

---

## 11. What stays the application's

The metrics and their sources, the dimensions' words and values, who may see what (`allowed()`,
the tenant scope), the drill-down lists, which annotations its own events make, and the retention
it wants.

---

## 12. Phases

Each phase ends with measured timings, not estimates.

| Phase | What | Done when |
|---|---|---|
| 0 | The storage benchmark (§5.2) in three sizes; PostgreSQL 10 and MySQL checks | **Done:** B, on the numbers in §5.4. MySQL is measured in phase 1, on the real code |
| 1 | The row (§5.1): `timestamptz`, `jsonb`, tenant, version; the chosen layout; compression and retention; the migration changed in place and the one-time statement; `Store::adopt()` | **Built:** the tables per grain and their view, hypertables and compression, the store's lock, `Store::ensure()` converting the single table, `Store::adopt()`. The marketplace's import is its own data script |
| 2 | Hour grain; tenant scope and time zone per tenant; the query API (§7), with today's calls on top | every reader on the query API; a per-tenant card on a multi-tenant application. **Built so far:** `Metrics::query()` with `for()`/`tenant()`, `Metric::tenant()`; today's calls on top of it, every framework reader through it as its viewer; the hour grain (`Metric::grains()`, `stats.metric_values_hour`, `metrics.retention`); a time zone per organisation (`metrics.timezone` as a function, `metrics.tenants`). **Done** |
| 3 | Aggregations and kinds (§4.3); declared sources (§4.4) with `time_bucket` and unix time; live metrics (§4.5); lateness (§6.3); versions and gaps (§4.6) | the sensor platform's daily report rebuilt from declarations. **Built:** declared sources (sum, count, countDistinct, avg, min, max, last, counter, cohort), many periods in one query on PostgreSQL, live metrics with an hourly continuous aggregate on TimescaleDB, lateness, versions, gaps, drill, direction and format. The sensor platform's rebuild is its own step (phase 6) |
| 4 | Alerts, goals, annotations (§9) | "data stopped" and a goal's intraday progress on a real board. **Built:** `Goals` (curve of the same weekday a year earlier or the last four, null on a hole), `Alerts` (threshold, change, stopped, hours; bell, push, channel; fired in the changelog), `Annotations` (drawn on charts; deploys by the orchestrator) |
| 5 | JSON API and SPA components; XLSX; dimension hierarchy, maps, cohorts (§8) | a tenant's dashboard outside the administration. **Built:** `MetricsApi`, `Export\Xlsx`, `Dimension::parent()`/`geo()`, the top, goal, cohort and map cards, board variables. The SPA's own card components are the application's, over the JSON API |
| 5a | One report system with the assistant's routines; analysis card; `report_propose` and *Ask about this* (§8.1, §8.2) | a routine sent as a report with charts; a report built from one sentence. **Built:** the analysis card, *Ask about this*, `ReportProposal` (the editor's "build it from words", answered as the editor's text rather than through a tool), `Routines::asReport()` |
| 6 | Moving each application over (§10) | each one's own mechanism removed |
| 7 | One framework for many applications (§15): the gaps that show once a third application arrives, and the metrics joined to the audience — segments, events, rules, tests | each item of §15.3 in its order, with the done-when written there |

---

## 13. Decisions

Taken by the owner on 9 November 2026:

1. **Storage layout:** A, B or C is decided on the phase-0 measurements. These run on synthetic
   data in three sizes, on TimescaleDB 2.31 with the Toolkit, and on real data: an anonymised
   copy of the marketplace for the medium size, and the sensor platform's data, arranged with the
   owner. Plain PostgreSQL and MySQL are measured for the basic mechanism only.
2. **`double precision` for values**, with `->exact()` for the rare metric that needs `numeric`.
3. **Live metrics** (§4.5) for per-entity series: approved.
4. **Sub-hour grains** only per metric, and with retention: approved.
5. **XLSX** through a small writer of the framework's own over `ZipArchive` and XML, the approach of
   the assistant's document import (`Llm/DocumentText.php`). The assistant exports with it too,
   not only CSV.
6. **No-cron mode**, in the background and never inside the page: approved.
7. **The order of the phases:** as in §12.
8. **Charts:** Chart.js on the page, which keeps tooltips, zoom and a click on a point to drill
   down. For mail and PDF the server draws SVG (`ChartImage`) and `rsvg-convert` makes the PNG.
   Both are drawn from the same description of a chart (its series, colours, type, and the year
   before), so a mail looks like its page.
9. **The mechanism is built on TimescaleDB and its Toolkit.** What needs them does not exist on
   plain PostgreSQL or MySQL, and is not offered there (§1.1).

## 14. Ideas taken from other systems

| From | Idea | Where it lands |
|---|---|---|
| Semantic layers (dbt, Cube, Looker) | Kinds **cumulative** (running total) and **conversion** (A then B within N days) beside simple, ratio and derived | §4.3, §4.6 (beside cohorts) |
| Looker | **Drill fields** declared per metric: the columns a number's records show | §4.6, `rows()` / `drill()` |
| Cube | Pre-aggregations with a refresh key, and **"lambda"** reads: closed periods from the store, the open one live from the source | §6.3 for late data; the query API (§7) joins the two |
| Cube | **The tenant as a security context**: a filter on every query that no caller can leave out | §4.1, §7 |
| Laravel Pulse | **Aggregation at write time** into buckets of several periods, for progress within the day | §9.2: the hour (and finer) of a goal's metric updated as events arrive, on TimescaleDB |
| Grafana | **Variables in the address**, annotations from a query, alerts on **no data** | §8 dashboard variables, §9.3, §9.1 |
| Metabase | **X-rays**: cards proposed for a metric automatically | §8.2, with `report_propose` |
| PostHog | Ready **analysis types**: trends, funnels, retention, stickiness, lifecycle | new cards, phase 5: each a card over declared events; funnels and retention need the Toolkit's sketches or the source |
| PostHog, Metabase | Cohorts as filters; **anomaly detection** | §4.6 cohorts as a dimension; anomaly as an alert condition (§9.1): outside a band learned from the same weekday and hour |

---

## 15. Phase 7: one framework, many applications

Phases 0 to 5a were built against two applications. This section is what a third one would find
missing, and how the metrics meet the audience module (attributes, person events, segments,
rules, A/B tests), which today knows nothing of them, and they nothing of it.

### 15.1 Gaps

1. **Scope by a dimension's values.** `allowed()` says whether a person sees a metric at all, and
   the tenant scope cuts by organisation. Nothing says "this manager sees region north only". It
   has to be one rule that the query applies (`Metrics::scope(fn (object $user): ?array)`, a
   dimension => values), so the assistant, the JSON API, reports and exports cannot go round it.
   A cell outside the scope is not shown. A total is the total of what the person may see, never
   the whole with parts hidden.
2. **Currency and locale.** `format('currency')` does not say which currency. A metric, or a
   tenant, gets one (`currency('EUR')` or a closure per tenant). Values in different currencies
   are never added: across tenants with different currencies a total is refused, not summed.
   Numbers are written in the reader's locale.
3. **Shared links.** A report or a board is opened by somebody without an account through a
   signed, expiring, revocable link. It reads with the scope of whoever made it. Opening it is in
   the audit log.
4. **Where a report goes.** Today it goes by mail only. Adding a webhook, a conversation channel,
   and a CSV/XLSX file in the application's storage (`Storage`) gives one sender with four
   destinations. The CSV attachment to the mail is part of this.
5. **Health.** `metrics:status`, and a card on the operators' board: for each metric, when it was
   last computed, how long it took, what failed, which periods are missing, and how late it is.
   A source that breaks today is found only when a number looks wrong.
6. **Funnels.** Steps A → B → C over person events, the same people at each step, with the share
   and the time between steps, broken down by a dimension or a segment. This is a card and a
   query over `pramnos.changelog_events`, not a stored metric.
7. **The same answer on MySQL.** Live metrics, the many-periods prefetch and compression are
   PostgreSQL/TimescaleDB only. The guide gets a table of what each database does, and a fallback
   where one is possible (prefetch with `DATE_FORMAT`, live as a plain read).
8. **Testing an application's metrics.** `Metrics::fake()` keeps values in memory, and
   `assertMetric('orders', '2026-09', 12, ['region' => 'north'])` checks one. Without them every
   application builds tables and runs compute to check a single number.
9. **"Unusual" as an alert.** A value outside the band of the same weekday and hour, using the
   curve that goals already read (§9.2). This needs no model.
10. **Metrics without code.** A declared source stored as data, chosen from tables and columns an
    application allows, so an administrator defines "paid orders per store" without a developer.
    It is last because it carries the most risk: which tables, and what each query costs.

### 15.2 The metrics and the audience

The two modules answer the same questions from opposite ends. The audience asks "who": attributes,
events, segments. The metrics ask "how many, over time". Joining them removes several things each
application would otherwise write twice.

1. **A segment is a metric.** Every segment gets three metrics without a declaration: members (a
   level, at the end of each period, from `pramnos.segment_members`' stays), entered and left
   (flows). A segment's size over a year becomes a chart, a goal or an alert ("the at-risk segment
   grew by 20% this week"). This removes `Segments::movement()`'s fixed seven days as the only
   view.
2. **A person event is a metric.** A declared `PersonEvents::define()` event is offered as a
   metric: occurrences (count) and people (count distinct), from the change log, with its declared
   properties as dimensions. An application stops declaring the same thing twice.
3. **Segments and attributes as dimensions.** A metric that says whose rows it counts
   (`->person('userid')`) can be filtered and broken down by a segment ("revenue from the dormant
   segment") or by any audience attribute (`usertype`, `language`, an application's own). The
   query uses the audience's own `Filter`, so an attribute means the same thing in both places and
   keeps its `ability()`. A segment is read as of each period, from its stays, not as of today.
4. **From a number to people.** A cell whose metric is per person, and whose `rows()` carry the
   person, offers "these people as a segment" (a static segment, or the filter that gives them)
   and "a rule for them". This is the drill of §4.6, with somewhere to go.
5. **One automation engine.** An alert that fires is an event (`metric.alert`, with the rule, the
   value and the period) that a rule can be triggered on. Alerts' own "notify" becomes a rule with
   `bell`, `conversation_post`, `webhook`, `ai` and the rest: the hours, caps and audit of rules
   are not written a second time. A plain alert keeps its simple notify, which is a default rule
   made with it.
6. **Rules and tests measured.** The framework declares, for every rule and version: entered,
   messages sent per channel, goal reached, exited. For every A/B test and variant: assigned and
   goal reached. A report then answers "how is the win-back series doing this month against last"
   without the rule's own screen. `Experiments::results()` becomes a report card with its
   confidence.
7. **Annotations from the audience.** A rule switched on or changed, a test started or decided,
   and a segment's filter changed are written as annotations, as deploys are. A step in a chart
   then carries its reason.
8. **Organisations' figures as attributes.** For applications whose people belong to
   organisations: `Attribute::metric('org_revenue', 'payments.revenue', 'month')` reads the
   person's organisation's value from the store, so a segment can be "members of organisations
   whose revenue fell". This reads one row from the store and is not computed per person.
9. **One scope.** The row-level scope (15.1.1) and an attribute's `ability()` are one mechanism:
   who sees which values of what. The assistant, which reads both modules, cannot then answer
   with numbers about people whose details it could not list.

### 15.3 Order

The order is correctness first, then what the joins open, then convenience:

| Step | What | Done when |
|---|---|---|
| 7a | Scope by dimension values (15.1.1), shared with the audience's abilities (15.2.9) | a manager scoped to one region sees only that region in a report, the API, an export and the assistant, and the totals are theirs **Built:** `metrics.scope`, `Metric::unscoped()`, `Dimension::ability()` through the audience's `Gate`, `Metrics::scoped()`/`withinScope()`. The audience's own people lists are narrowed when segments and attributes become dimensions (7d) |
| 7b | Currency and locale (15.1.2) | a two-currency installation refuses a mixed total and writes each value in its currency and the reader's locale |
| 7c | Segments and person events as metrics (15.2.1, 15.2.2) | a segment's size over a year on a chart; a declared event as a metric with no declaration of its own |
| 7d | Segments and attributes as dimensions; from a number to people (15.2.3, 15.2.4) | "revenue by segment" in a report; a cell made into a segment |
| 7e | One automation engine; rules and tests measured; annotations from the audience (15.2.5–15.2.7) | an alert starts a rule; a rule's goals per version in a report; a rule's change marked on a chart |
| 7f | Health; destinations; shared links (15.1.5, 15.1.4, 15.1.3) | `metrics:status`; a report into a channel and to a webhook; a link opened without an account |
| 7g | Funnels; "unusual"; testing helpers (15.1.6, 15.1.9, 15.1.8) | a three-step funnel by segment; an alert on the weekday band; `assertMetric()` in an application's test |
| 7h | MySQL parity table and fallbacks; organisations' figures as attributes (15.1.7, 15.2.8) | the guide's table; a segment over an organisation's metric |
| 7i | Metrics without code (15.1.10) | a metric defined on the screen, on allowed tables only, with its query's cost shown |
