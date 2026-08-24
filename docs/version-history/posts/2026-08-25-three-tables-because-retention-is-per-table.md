---
date: 2026-08-25
categories: [Changelog]
---

# Three tables, because retention is per table

The `changelog` feature: enable it and every emitted model change is written down,
at 0.003 ms a row.

<!-- more -->

## Added

```php
// app.php
'features' => ['changelog'],
```

- `pramnos.changelog` — machine diffs, one row per save, kept 30 days
- `pramnos.changelog_events` — things a person did, kept 2 years
- `pramnos.changelog_trace` — stack trace and request context, kept 3 days
- `pramnos.changelog_history` — a read-only view over the first two
- `Model::logEvent()`, `ChangelogReader`, `ChangelogRenderer`

## Why three tables and not one

A TimescaleDB retention policy drops **whole chunks by time** and takes no row
predicate. One table can only ever have one retention.

The reference application reached the same conclusion the expensive way: its
`itemlogs` was split in a repair migration on a live hypertable, after the automatic
save log drowned everything else. Its numbers — machine noise for a month, semantic
events kept, JSON payload far shorter — are where these start.

On an empty table the split costs nothing. On a live one it cost that project two
migrations, one of which decompressed every chunk to widen a primary key.

**The test that matters asserts they are different:**

```php
$this->assertCount(3, array_unique($intervals),
    'three tables sharing one retention is the design failing silently');
```

Three tables sharing an interval would pass every other assertion in the file while
defeating the entire reason for the split — and the way that happens is somebody
tidying three declarations into one.

## No INSTEAD OF triggers

The reference application routes writes through a view with `INSTEAD OF` triggers,
because it had hundreds of call sites naming one table and a migration that had to
leave every one of them alone.

That view is a compatibility shim, not architecture. New code has no call sites to
preserve: the writer targets the right table directly, and the view is **read-only**
— which is also what makes it portable, since MySQL has no updatable views of that
kind.

## A round trip that nearly got in

The first version of the trace table keyed on the feed row's `logid`, and the writer
generated one so the two rows could be linked.

That id has to exist *before* the row does, because the spool does not insert until
the drain — so generating it meant a database round trip per change, inside the
request, undoing the 0.003 ms append the whole design is built on. Caught while
writing it, not by a test, because no test would have failed: it would simply have
been slow.

The trace now carries `(entity, itemid, created_at)`, which the feed already indexes.

## Nothing stores prose

The feed stores a diff, an event stores a machine code, and `ChangelogRenderer`
turns either into a sentence at read time — so wording changes without a migration
and without reinterpreting rows written years ago, and so it can be translated.

The reference application renders from a `switch` returning hardcoded English keyed
on two magic numbers. Same idea, frozen into PHP.

A `description` column exists for events no code describes. It should stay the
exception.

## Documentation

- [Model Change Feed Guide](../../Pramnos_Change_Feed_Guide.md) — a new "Writing
  changes down" section: the three tables, `logEvent()`, reading it back, and why
  traces are opt-in.
