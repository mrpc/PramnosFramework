---
use_cases:
  - Declaring a TimescaleDB hypertable
  - Repairing a database whose hypertables were never created
  - Writing late-arriving data into a compressed chunk
  - Checking hypertable state from code
  - Setting up data retention on MySQL or plain PostgreSQL
  - Changing a chunk, compression or retention interval after the fact
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

The queue lives in `deferredwrites`, created by a framework core migration on
every backend.

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
