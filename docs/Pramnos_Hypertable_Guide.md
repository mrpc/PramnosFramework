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
