---
use_cases:
  - Declaring a TimescaleDB hypertable
  - Repairing a database whose hypertables were never created
  - Writing late-arriving data into a compressed chunk
  - Checking hypertable state from code
  - Setting up data retention on MySQL or plain PostgreSQL
  - Changing a chunk, compression or retention interval after the fact
  - Choosing segmentby and orderby so compression actually compresses
  - Deciding whether to cache an aggregate as a continuous aggregate or a materialised view
  - Working out why CREATE MATERIALIZED VIEW WITH (timescaledb.continuous) is refused
  - Scheduling the refresh of a rollup, or fixing one that is frozen
  - Deciding whether a table should become a hypertable at all
---

# Hypertables: declaring them, and repairing them later

The framework keeps seven tables as TimescaleDB hypertables where the extension
is available. This page covers where those parameters are declared, how to add
your own, and — the part most installations eventually need — how to repair a
database that gained TimescaleDB *after* the migrations had already run.

## The gap this exists to close

A migration creates its table and then converts it:

```php
$schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, function () use ($schema) {
    HypertableRegistry::apply($schema, 'authserver.user_activity_log');
});
```

`ifCapable()` is the right tool for *creating* a hypertable on a fresh install,
and the wrong one for its lifecycle. A database that ran that migration before
TimescaleDB was installed keeps a plain table **for ever**: the migration is
recorded as applied, so it never runs again.

Such a table is correct in every other respect — the composite primary key
`(id, <time column>)` is created unconditionally, outside the capability block,
which is exactly what makes a later repair possible. But it is never
partitioned, never compressed, and, most consequentially, **its retention policy
never applies, so it grows without bound**.

That is the normal path for any long-lived installation that adopts TimescaleDB
later, not an edge case.

## One declaration

The parameters live in `Pramnos\Database\HypertableRegistry`, and both the
migrations and the repair read them from there. Nothing holds a copy: a copy of
somebody else's policy values drifts the first time they change, and then the
two disagree silently.

| Table | Time column | Chunk | Compress after | Retention |
|---|---|---|---|---|
| `tokenactions` | `action_time` | 14 days | 60 days | 3 years |
| `authserver.twofactor_attempts` | `attempt_time` | 7 days | 7 days | 2 years |
| `authserver.user_activity_log` | `created_at` | 1 day | 30 days | 24 months |
| `authserver.user_consents` | `granted_at` | 1 month | 6 months | 7 years |
| `authserver.data_processing_records` | `processed_at` | 1 week | 90 days | 36 months |
| `authserver.gdpr_requests` | `requested_at` | 1 month | 1 year | 7 years |
| `applications.application_stats` | `time` | 14 days | 60 days | 3 years |

This table is documentation. The registry is the source.

### Choosing `segmentby`: a measurement worth repeating

TimescaleDB compresses in batches of up to 1000 rows **per segment**. Which columns
go in `segmentby` therefore decides whether compression compresses at all — and
getting it wrong is not a small loss.

Measured on 2 M changelog rows, 12 entities, 240 000 records over 30 days
(`tests/Benchmarks/changelog_compression.php`):

| `segmentby` | chunk | ratio | stored | compress | per-row | recent |
|---|---|---|---|---|---|---|
| `entity` | 7 days | **12.82** | **37.5 MB** | 5.8 s | 11.0 ms | 4.8 ms |
| `entity` | 1 day | 10.02 | 48.7 MB | 4.6 s | 12.7 ms | 2.6 ms |
| `entity, itemid` | 7 days | 0.89 | 543 MB | 74.6 s | 16.8 ms | 176 ms |
| `entity, itemid` | 1 day | **0.59** | 822 MB | 133.6 s | **2.2 ms** | 53 ms |

**A ratio below 1 means compression made the table larger.** A change log is sparse
per record — one row changes a handful of times a day — so `itemid` in `segmentby`
produces segments of a few rows each, far below the batch size, and the per-segment
overhead exceeds the saving. 822 MB against 37.5 MB for identical data, and 133
seconds of CPU against 6.

The rule that follows, and it generalises past this table:

- **`segmentby`**: columns you filter on that have *few* distinct values.
- **`orderby`**: the high-cardinality column first. Compressed batches carry min/max
  metadata for `orderby` columns, so a filter on one skips batches without
  decompressing them — which is how the layout above stays fast on a per-row lookup
  while keeping `itemid` out of `segmentby`.

The exception is real and visible in the table: `entity, itemid` at 1-day chunks
wins the per-row lookup, 2.2 ms against 11.0 ms, because the segment is located
directly. It costs 22× the disk and compression that does not compress — worth it
only for a log read constantly and kept briefly, and that is what the overrides
below are for.

#### The same question for `tokenactions`, with a different answer

`tokenactions` is one row per API request. Whether a high-cardinality segment key
works there depends entirely on **who calls the API**, and the two plausible answers
point opposite ways. Measured on 2 M rows over 60 endpoints and 90 days
(`tests/Benchmarks/tokenactions_compression.php`):

| callers | `segmentby` | ratio | stored | by-token | by-url |
|---|---|---|---|---|---|
| few, long-lived | `tokenid, urlid, method` | 6.95 | 36.8 MB | **0.41 ms** | 0.65 ms |
| few, long-lived | `urlid, method` | 7.72 | 33.0 MB | 6.83 ms | 0.44 ms |
| many, short-lived | `tokenid, urlid, method` | **0.50** | 515.5 MB | 5.44 ms | 38.5 ms |
| many, short-lived | `urlid, method` | 6.76 | 37.9 MB | 6.68 ms | 0.46 ms |

With `tokenid` in the segment key the layout is excellent for a server-to-server API
— 0.41 ms on "what did this token do" — and collapses for one serving browser
sessions, where it is **not even faster**: it loses on every axis at once.

The framework ships `urlid, method`, which is never bad. An installation that knows
its callers are few and long-lived takes the faster lookup deliberately:

```php
'hypertables' => [
    'tokenactions' => ['segmentby' => 'tokenid, urlid, method'],
],
```

The cost of the safe default is a token-history listing at 6.8 ms rather than 0.4 ms
— an admin screen rather than a hot path, and the analytical reads go through the
hourly continuous aggregate rather than this table.

!!! note "Existing installations keep what they have"
    `HypertableRegistry::apply()` sets compression only on a table that has none, so
    a changed `segmentby` reaches new databases only. To adopt it on an existing one,
    decompress and recompress the chunks deliberately — per chunk, since the disk
    headroom needed is the size of the largest one.

### Retuning one without editing the framework

None of those intervals fit every installation — a busy API's `tokenactions` and a
quiet one's are the same declaration and very different amounts of disk. Override
per table in `app/app.php`:

```php
'hypertables' => [
    'tokenactions'      => ['retention' => '10 years'],
    'pramnos.changelog' => ['compress_after' => '3 days'],
],
```

An override changes only the keys it names; the rest keep the framework's values.
Naming a table that is not declared registers it, so an application can use the same
block for its own tables. A key the spec does not recognise is ignored rather than
passed through — these values end up inside `create_hypertable()` and the policy
calls, and a typo must not become an unknown option in somebody else's migration.

Then apply it:

```bash
php pramnos timescale:ensure --dry-run   # what would change
php pramnos timescale:ensure --fix       # change it
```

### Changing a policy that already exists

`timescale:ensure` compares the **interval**, not merely whether a policy is there.
A declaration that no longer matches the database is reported as drift and repaired
by removing the policy and adding it back — `add_retention_policy()` raises on a
duplicate, so replacing is the only way to change one.

```
retention policy (1 year → 90 days)
```

Two spellings of the same duration are not drift. PostgreSQL hands intervals back as
`@ 90 days` while a declaration says `90 days`, and treating that as a change would
rewrite every policy on every run, for ever, over a leading `@`.

An interval the comparison cannot parse — a composite like `1 mon 15 days`, or whatever a
future server version prints — is treated as **equal**, so nothing is reconfigured. That is
the safe direction: reading an unfamiliar spelling as drift would rewrite the policy on every
run, which is the failure this whole comparison exists to avoid. Also not normalised across
units: `24 months` and `2 years` are reported as different, because they are different in
PostgreSQL and guessing otherwise would be the comparison inventing a policy nobody declared.

### On a backend without TimescaleDB

The command still runs, and it does something: **continuous aggregates are refreshed on every
backend**, because the defect there belongs to the backend *without* the extension — four
migrations registered the refresh only inside their TimescaleDB branch, so on plain PostgreSQL
the materialized view was created and never updated again.

Only then does the hypertable half bow out, and it says where retention actually comes from:

```
TimescaleDB is not available on this connection (mysql).
No hypertable was touched. On this backend both compression and retention are the
policy engine's job (service:policy-engine).
```

Exit 0, deliberately — including for "nothing to do" and "no extension". A repair command that
exited non-zero because a database is already correct would fail every deployment pipeline it
was added to.

An interval that cannot be parsed — `1 year 6 mons 3 days` — is **left alone**. The
bias is deliberate: a false positive is permanent churn against the scheduler, while
a false negative costs one changed number not taking effect, which is the situation
this replaced rather than one it introduces.

### One hypertable that is deliberately not in the registry

`authserver.audit_log` is created as a hypertable — 7-day chunks, compressed after
90 days — by its own migration, and is **not declared in the registry**.

| Table | Time column | Chunk | Compress after | Retention |
|---|---|---|---|---|
| `authserver.audit_log` | `event_timestamp` | 7 days | 90 days | **none** |

Two deliberate absences, and both are the point:

**No retention.** An audit trail is the one table where the framework deciding to
drop old rows would be wrong. An installation that wants a retention policy adds
one itself, knowing what it is buying.

**Not in the registry**, because the registry is what `timescale:ensure` reads —
and a declaration there would convert existing installations. Converting a live
audit table means dropping and rebuilding its primary key and rewriting every row
into chunks, under lock, on a table that other things hold foreign keys into. The
migration guards on `hasTable()` and leaves such a database exactly as it is.

The cost is that `timescale:ensure` will not report drift on this table. That was
judged the better half of the trade: the alternative is rewriting somebody's audit
log because they ran a maintenance command.

**On an existing installation** the table stays a plain table with a 32-bit
`auditid`. Widening it later is possible but is a maintenance-window job —
decompress, rebuild the primary key, recompress — so if you are running one and
expect volume, plan it rather than discover it.

## What belongs in a hypertable, and what does not

The question arrives from the other direction too: *a continuous aggregate needs
a hypertable, so should I make this table one so that it qualifies?* Usually no,
and the reason generalises.

**A hypertable is for append-mostly rows queried by time range. A table of live
mutable state looked up by identity is not one, however many rows it has.**

`public.usertokens` is the worked counter-example, and it is instructive because
it looks like a candidate: tens of millions of rows, a `created` timestamp, rows
that stop mattering after a while. It is still the wrong shape, for three
reasons, and the first two are hard refusals rather than trade-offs.

**Every unique index must contain the partitioning column.** Measured on 2.19.3:

```
ERROR:  cannot create a unique index without the column "created" (used in partitioning)
HINT:   If you're creating a hypertable on a table with a primary key, ensure the
        partitioning column is part of the primary or composite key.
```

`usertokens` has unique indexes that exist to guarantee one row per value —
`token_lookup` is there precisely to make a digest unique. Compositing it as
`(token_lookup, created)` is accepted and still serves lookups from the index
prefix, but the uniqueness guarantee is gone: the same digest can now be inserted
twice with different `created`. For an index whose entire purpose is that
guarantee, that is not a workaround.

**Then nothing can reference it by its surrogate key.** The primary key has to
become `(tokenid, created)` for the same reason, and then:

```
ERROR:  there is no unique constraint matching given keys for referenced table "usertokens"
```

for any `REFERENCES usertokens (tokenid)`. That is not hypothetical either — it
is `fk_tokenactions_tokenid`, which the framework's own
`add_missing_foreign_keys_to_existing_tables` creates. A composite foreign key is
accepted, but only if every referencing table also stores the token's `created`.

**And there is nothing to gain.** The access pattern is a point lookup on a unique
digest, not a time-ranged scan, so chunk exclusion buys nothing. The rows are
updated constantly — `lastused`, `status` — which is what compression is worst at.
And retention by chunk age would delete tokens by *creation* time, while a
long-lived token in an old chunk is still perfectly valid.

`HypertableRegistry` already reflects this. Every table it declares is a record of
something that happened, addressed by when: `tokenactions`,
`authserver.twofactor_attempts`, `authserver.user_activity_log`, the consent and
GDPR trails (`user_consents`, `data_processing_records`, `gdpr_requests`), the
three `pramnos.changelog*` tables, and `applications.application_stats`.
`usertokens` — live credential state, addressed by digest — is not among them, and
that is the distinction rather than an oversight.

## Caching an aggregate: continuous aggregate, or materialised view

The framework offers two ways to cache an aggregate and they are not
interchangeable. `SchemaBuilder::createContinuousAggregate()` is the better one
where it applies, and where it does not apply it does not *degrade* — it refuses.
So the first question is not which you prefer, it is which your query is allowed
to be.

### The three requirements

A continuous aggregate must:

1. **select from a hypertable.** Not from an ordinary table, and not only from a
   CTE over one.
2. **bucket on that hypertable's time dimension with `time_bucket()`.**
   `date_trunc()` does not count, even though it produces the same buckets.
3. **stay inside the SQL TimescaleDB can maintain incrementally.** No CTEs, no
   subqueries, no set-returning functions, no window functions.

Miss one and you get a refusal at `CREATE`, not a slow view. Measured on
TimescaleDB 2.19.3, which is what this project's stack runs:

| What is wrong | What PostgreSQL says |
|---|---|
| source is an ordinary table | `invalid continuous aggregate view` · *At least one hypertable should be used in the view definition.* |
| no `time_bucket()` | `continuous aggregate view must include a valid time bucket function` |
| `date_trunc()` instead of `time_bucket()` | the same error as above |
| a window function | `invalid continuous aggregate query` · *Window functions are not supported by continuous aggregates.* |
| a CTE, or a correlated subquery | `invalid continuous aggregate query` · *CTEs, subqueries and set-returning functions are not supported by continuous aggregates.* |

**Check the restriction against your own version before designing around it.**
The maintainable-SQL subset has grown across releases, and two things commonly
described as forbidden are accepted on 2.19.3: `COUNT(DISTINCT …)` and a
`LEFT JOIN` to an ordinary table both create successfully. Ask your database
rather than a list — the cost of asking is one `CREATE` that either succeeds or
tells you why not.

### A pair that makes the boundary concrete

Two rollups in the same schema, one of each kind, and the difference is not taste.

```sql
-- applications.tokenactions_hourly — qualifies.
-- public.tokenactions is a hypertable on action_time (see the registry above);
-- the bucket is on that same column; every aggregate is one TimescaleDB can
-- maintain incrementally — including FILTER and percentile_cont, which are fine.
SELECT time_bucket('1 hour', action_time) AS bucket,
       tokenid, urlid, method, return_status,
       COUNT(*)                                                               AS request_count,
       AVG(execution_time_ms)                                                 AS avg_execution_time,
       percentile_cont(0.95) WITHIN GROUP (ORDER BY execution_time_ms::float)  AS p95_execution_time,
       COUNT(*) FILTER (WHERE return_status BETWEEN 500 AND 599)              AS server_error_count
FROM public.tokenactions
WHERE action_time IS NOT NULL
GROUP BY time_bucket('1 hour', action_time), tokenid, urlid, method, return_status
```

```sql
-- applications.usage_statistics — cannot be a continuous aggregate.
-- Two independent reasons: public.usertokens is an ordinary table, and the query
-- is four CTEs. There is also no time bucket anywhere in it, because it is not a
-- time series — it is one current row per application.
WITH token_stats AS (...), historical_stats AS (...),
     oauth_config AS (...), webhook_stats AS (...)
SELECT a.appid, ... FROM public.applications a
LEFT JOIN token_stats ts ON ...
```

The sentence that separates them: **a continuous aggregate answers "this measure,
per time bucket, from this time series". Anything whose answer is "the current
state of this entity" is the other kind**, however much aggregation it does.

### The fallback, and what it costs

A materialised view plus a scheduled refresh. `createMaterializedView()` builds
it; the refresh has to be arranged, because PostgreSQL never refreshes one on its
own.

```sql
-- The refresh function's signature is not optional, and getting it wrong is
-- worse than an error at CREATE: see below.
CREATE FUNCTION applications.refresh_usage_statistics(job_id INT, config JSONB)
RETURNS VOID AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY applications.usage_statistics;
END
$$ LANGUAGE plpgsql;

SELECT add_job('applications.refresh_usage_statistics'::regproc, '4 hours');
```

`CONCURRENTLY` needs a unique index on the view, and buys readers no lock during
the refresh. Without it, readers block.

Three costs that a continuous aggregate does not have:

**Staleness you choose.** Every read between refreshes is as old as the last one.
That is the trade being made deliberately — for an aggregate over a whole table
read once per page view, a four-hour-old answer is usually the right call — but it
is a decision to record where the view is defined, not an implementation detail.

**`add_job` accepts the wrong signature and fails later.** A function taking no
arguments registers without complaint and then fails on every scheduled run with
`cache lookup failed for function 0` — which names nothing you can search for.
Measured on 2.19.3. If a refresh job is silently not working, check the signature
first.

**A job outliving its view fails forever.** Drop the view and the job stays
scheduled, erroring every interval with
`relation "…" does not exist` inside `REFRESH MATERIALIZED VIEW`, far from
whatever dropped it. So the job and the function belong to the view's lifecycle:
if a migration removes the view, it removes them in the same migration. This is
not hypothetical — it is why `create_applications_views` asks
`SchemaBuilder::getRelationKind()` before dropping anything and leaves a
materialisation it did not create alone.

Note also that `add_continuous_aggregate_policy()` is not available here:
pointing it at a plain materialised view answers `"…" is not a continuous
aggregate`. Refreshing one is `add_job` on TimescaleDB, and the framework's own
declaration for its rollups is [`ContinuousAggregateRegistry`](#keeping-a-rollup-refreshed).

## Keeping a rollup refreshed

Creating a rollup does not schedule its refresh, on either backend.
`SchemaBuilder::addContinuousAggregatePolicy()` does that, and it branches: a
native TimescaleDB job where the extension is present, a row in
`pramnos.framework_policies` executed by the policy engine everywhere else. A
materialised view that nothing refreshes is frozen at the moment it was created —
it exists, it answers, and every answer is the one it gave the day the migration
ran, which is worse than a missing view because a missing view fails.

`Pramnos\Database\ContinuousAggregateRegistry` is where the parameters live, once,
so the migration that creates a rollup and the repair that fixes an installation
whose migrations already ran cannot disagree about them.

```php
use Pramnos\Database\ContinuousAggregateRegistry;

ContinuousAggregateRegistry::register('reports.daily_totals', [
    'start_offset'      => '3 days',   // how far back a refresh reaches
    'end_offset'        => '1 day',    // how close to now it stops
    'schedule_interval' => '1 day',    // how often it runs
]);
```

Then, from the migration that created the view:

```php
ContinuousAggregateRegistry::apply($schema, 'reports.daily_totals');
```

`apply()` is guarded on every side, so it is safe from a migration that has just
created the view and from a repair run against a database that already has the
policy: it does nothing when the view is absent (the feature may not be enabled
here), nothing when a policy already exists, and nothing when there is nowhere to
record one — on a backend without TimescaleDB whose core migrations have not yet
created `pramnos.framework_policies`, the insert would fail and take the
surrounding migration with it. It returns what it did, so a migration can log it.

`php pramnos timescale:ensure` reads the same registry and adds what is missing,
which is the repair path for a database migrated before its rollup had a policy.

## Repairing a database

```bash
php pramnos timescale:ensure --dry-run    # what would change, and how big
php pramnos timescale:ensure              # do it
php pramnos timescale:ensure --table=authserver.user_activity_log
```

`--dry-run` reports each declared table's state, the row count of anything
pending conversion, and the total. Read it first — see the warning below.

The command is **idempotent**. Every step is guarded by its own existence check,
so a second run is a no-op and a run against a correct database changes nothing.
That is not a nicety: `add_compression_policy()` and `add_retention_policy()`
raise on a duplicate rather than no-opping, so an unguarded repair would work
exactly once and fail ever after.

On a database **without** the extension the command changes nothing and says so.
Retention there is handled by the software policy engine
(`service:policy-engine`), which is a different mechanism, not a broken one.

!!! warning "Conversion takes an exclusive lock"

    Converting an existing table runs `create_hypertable(…, migrate_data => true)`,
    which rewrites the table into chunks under an exclusive lock. On a years-old
    `user_activity_log` that is not instant, and writes block for the duration.
    Run `--dry-run` first, read the row counts, and pick your window.

### What it checks, in what order

1. **Is the table there at all?** A declared table that this installation never
   created is reported as absent — not every installation enables every feature.
2. **Is the primary key usable?** TimescaleDB requires the partitioning column
   in every unique constraint. The framework creates these keys unconditionally,
   so this should always hold — which is why it is verified rather than assumed.
   A table whose key omits the time column is reported as blocked, with its
   actual key, instead of failing with a driver error.
3. **Convert**, then **enable compression**, then **add the two policies**. The
   order is not cosmetic: a compression policy on a non-hypertable raises, and
   so does compression where the setting was never enabled.

## Declaring your own

An application registers its tables the same way, and gets the same repair:

```php
use Pramnos\Database\HypertableRegistry;

HypertableRegistry::register('readings', [
    'time_column'    => 'measured_at',
    'chunk_interval' => '1 day',
    'compress_after' => '7 days',
    'retention'      => '2 years',
    'segmentby'      => 'device_id',
    'orderby'        => 'measured_at DESC',
]);
```

Register during bootstrap — a service provider is the natural place — so that
both your migration and `timescale:ensure` see it.

Then in the migration:

```php
$schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, function () use ($schema) {
    HypertableRegistry::apply($schema, 'readings');
});
```

Omitting `retention` means "keep for ever", and omitting `compress_after` means
"never compress automatically" — neither is given a default, because inventing a
retention policy for a table that did not ask for one deletes data.

Your table must have the partitioning column in its primary key, and in every
unique constraint. `PRIMARY KEY (id, measured_at)`, not `PRIMARY KEY (id)`.

## Retention without TimescaleDB

`SchemaBuilder::addRetentionPolicy()` is not Timescale-only. Without the extension
it registers a row in `pramnos.framework_policies`, and
`Pramnos\Policy\PolicyEngine` — run by the `service:policy-engine` daemon —
executes it as an ordinary `DELETE`. So a declared retention works on MySQL and
plain PostgreSQL too; only the mechanism differs.

| | TimescaleDB | MySQL / plain PostgreSQL |
|---|---|---|
| Mechanism | `add_retention_policy()` drops whole chunks | `PolicyEngine` deletes rows |
| Granularity | chunk boundaries | exact, by the time column |
| Runs from | the extension's own scheduler | `service:policy-engine` |

### It deletes in batches, and you can size them

A retention `DELETE` on a table with real backlog is the kind of statement that
holds locks for as long as it takes — in a daemon, against a table the application
is still writing to. So the engine issues bounded statements and repeats them:

```php
$engine->register('retention', 'changelog', [
    'interval'    => '90 days',
    'time_column' => 'created_at',
    'batch'       => 5000,   // rows per statement; the default
    'max_batches' => 200,    // passes per run; the default
]);
```

`batch × max_batches` is the most one run will remove. A table with more backlog
than that is **not** cleared in one pass, deliberately: the engine runs on a
schedule and the rest goes next time. Holding the daemon for an hour on its first
execution looks exactly like a hang, and gets it killed.

Both values are clamped to something sensible. A `batch` of `0` from a config file
would otherwise be a loop that deletes nothing for two hundred passes while
reporting success — the quietest failure available.

!!! note "Two different statements underneath"
    PostgreSQL has no `LIMIT` on `DELETE`, so the bounded form selects physical row
    ids first — `DELETE FROM t WHERE ctid IN (SELECT ctid FROM t WHERE … LIMIT n)`.
    MySQL uses `DELETE … LIMIT n`. Same behaviour, different SQL, and both are
    covered by the integration suite for that reason.

## Writing late data into a compressed table

A hypertable with a compression policy stops accepting writes into the ranges it
has already compressed. Every application that writes late data meets this: a
delayed reading, a backfill, a correction, a webhook that arrives months after
the event it describes. Without somewhere to put those rows, the choice is
"compression **or** the ability to correct data" — which is why tables that get
updated after the fact usually end up uncompressed for ever.

`Pramnos\Database\DeferredWriteQueue` is that somewhere.

### Declaring the table

Add `deferred_writes` to the declaration, and — if a late row should *correct* an
existing one rather than duplicate it — the columns that identify it:

```php
HypertableRegistry::register('readings', [
    'time_column'     => 'measured_at',
    'chunk_interval'  => '1 day',
    'compress_after'  => '7 days',
    'deferred_writes' => true,
    'conflict'        => ['device_id', 'measured_at'],
    'conflict_update' => ['value'],          // optional
]);
```

`conflict_update` defaults to every column that is not part of `conflict`. Name
it explicitly when some columns must survive an overwrite — an audit stamp, a
flag an operator set by hand.

### Writing

Replace the direct insert with `write()`:

```php
use Pramnos\Database\DeferredWriteQueue;

$queue = new DeferredWriteQueue($database);

$queue->write('readings', [
    'device_id'   => 42,
    'measured_at' => $timestamp,
    'value'       => 19.4,
]);
```

It returns `true` when the row went into the table and `false` when it was
queued. The row's time comes from the declared `time_column`; pass it as a third
argument when it lives somewhere else.

The cutoff is read from the **live compression policy**, not from a constant, and
cached for the life of the process — a bulk import pays one query, not one per
row. On a database with no policy, on MySQL, and on any development or CI box
without TimescaleDB, there is no cutoff, nothing is ever deferred, and this is a
plain insert. That is what lets the same code run on every backend.

A write that is cleared and then fails anyway — the policy compressed the chunk
in the second between the two — is queued rather than lost.

### Draining

```
php pramnos timescale:drain                  # write everything waiting
php pramnos timescale:drain --status         # what is waiting, per table
php pramnos timescale:drain --table=readings # one table
php pramnos timescale:drain --retry-failed   # re-queue rows that failed
```

Run it from cron, as often as your tolerance for late data requires. Hourly is a
reasonable default.

**What makes it worth having:** the drain groups the backlog **by chunk**. A
compressed chunk has to be decompressed before it accepts a write and compressed
again afterwards, and that pair costs the same for one row as for ten thousand.
Paying it once per row is the obvious implementation and an unusable one. The
grouping is the pattern; everything else is bookkeeping.

It asks TimescaleDB only for the chunks that actually have rows waiting, so a
drain is proportional to the backlog rather than to the table's age.

### When something cannot be written

A batch runs in one transaction. If it raises, the batch is replayed row by row,
so one bad row is marked failed and its five hundred blameless neighbours are
still written — the difference between a queue that drains and one that jams
behind a single row.

Failed rows are **kept**, with the error message, and never retried on their own:
a row that fails once usually fails the same way for ever, and a queue that
retries it hourly hides the problem instead of showing it. Fix the cause, then
`--retry-failed`.

The chunk is compressed again even when every row in it failed. A chunk left
decompressed never recompresses on its own — the policy only looks at chunks it
has not already handled — so this would otherwise be a silent storage
regression.

### From code

```php
$queue->pending('readings');              // rows waiting
$queue->failed('readings');               // rows that could not be written
$queue->tablesWithPendingRows();          // which tables have work
$queue->writeCutoff('readings');          // the live cutoff, or null
$queue->retryFailed('readings');          // put failures back in the queue
$queue->process('readings');              // drain, returns per-table stats
```

The queue lives in **`pramnos.deferredwrites`**, created by a framework core migration on every
backend. In the `pramnos` schema because it is the framework's own bookkeeping — nobody writing
an application queries it, `DeferredWriteQueue` does, and `public` is the application's. On
MySQL, which has no schemas, the name flattens to a `pramnos_` prefix.

> **Moved on 2026-08-30.** It was created in `public` on 12 August, so unlike the tables that
> moved the same week this one had been deployed — the move is its own migration
> (`move_deferredwrites_to_pramnos`) rather than an edit to the one that created it. `ALTER
> TABLE … SET SCHEMA` is a catalogue update: no row is copied, nothing is locked beyond the
> statement, and the migration is a no-op where the table is already in place. Address the table
> through `DeferredWriteQueue::TABLE` and none of this matters to a caller.

## Checking state from code

```php
$schema = $database->schema();

$schema->hasHypertable('authserver.user_consents');       // partitioned?
$schema->isCompressionEnabled('authserver.user_consents'); // setting enabled?
$schema->hasCompressionPolicy('authserver.user_consents'); // job scheduled?
$schema->hasRetentionPolicy('authserver.user_consents');   // job scheduled?
$schema->primaryKeyColumns('authserver.user_consents');    // ['id', 'granted_at']
```

All four return `false` on a backend without TimescaleDB rather than raising, so
they are safe to call from portable code.
