---
date: 2026-08-25
categories: [Changelog]
---

# A delete that holds the table

`PolicyEngine` executes retention policies on every backend that is not TimescaleDB.
It did it with one unbounded `DELETE`. Now it does it in batches, and the batch size
is yours to set.

<!-- more -->

## Fixed

- **Retention deletes in bounded statements**, repeated until the table is clean or
  the run's budget is spent.

## Added

```php
$engine->register('retention', 'changelog', [
    'interval'    => '90 days',
    'time_column' => 'created_at',
    'batch'       => 5000,   // rows per statement; the default
    'max_batches' => 200,    // passes per run; the default
]);
```

## Why an unbounded delete is worse than it looks

It is correct. That is the problem. An unbounded `DELETE` empties the table exactly
as a batched one does, so nothing about the outcome distinguishes them and no
correctness test can tell them apart.

What differs is everything around it: one statement holding locks for as long as it
takes, inside a daemon, against a table the application is still writing to. The
policy then reports `ok`, however long that was.

This matters now because a declared retention on MySQL runs through here and
nowhere else — `SchemaBuilder::addRetentionPolicy()` registers a `framework_policies`
row rather than a chunk-drop job when the extension is absent.

## The test that had to be invented

Since the outcome is identical either way, the test needs a single pass to be
*observable*. Making the batch size configurable — which is useful anyway — does it
in twenty-five rows:

```php
$this->seed(25, 60);
$this->registerRetention(['batch' => 10, 'max_batches' => 1]);
$this->engine->run();

$this->assertSame(15, $this->rowCount(),
    'one pass with a batch of 10 must delete 10 rows, not all 25');
```

Against the old implementation that assertion reads zero. It is the only one in the
class that does; every other test passes on both versions.

## Two backends, two statements

PostgreSQL has no `LIMIT` on `DELETE`, so the bounded form selects physical row ids
first:

```sql
DELETE FROM t WHERE ctid IN (SELECT ctid FROM t WHERE … LIMIT n)
```

MySQL uses `DELETE … LIMIT n`. Different code, so both are under the same sixteen
tests — a batching bug on one branch is invisible from the other.

## The cap is deliberate

`batch × max_batches` is the most one run removes, and a table with more backlog is
not cleared in one pass. The engine runs on a schedule and the rest goes next time.
Holding the daemon for an hour on its first execution looks exactly like a hang, and
gets it killed.

Both numbers are clamped. A `batch` of `0` from a config file would otherwise be a
loop deleting nothing for two hundred passes while reporting success — the quietest
failure available, and config files are exactly where a `0` arrives.

## Documentation

- [Hypertable Guide](../../Pramnos_Hypertable_Guide.md) — a new "Retention without
  TimescaleDB" section: what runs where, how to size the batches, and why the two
  backends emit different SQL.
