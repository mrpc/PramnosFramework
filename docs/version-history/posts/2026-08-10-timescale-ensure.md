---
date: 2026-08-10
categories:
  - Changelog
  - Added
tags:
  - timescaledb
  - migrations
  - console
  - retention
---

# `timescale:ensure` — repairing hypertables on a database that got TimescaleDB late

Seven framework tables are meant to be hypertables. On a database that ran the
migrations before the extension was installed, they are plain tables — and stay
plain tables for ever, growing without bound because their retention policies
never apply.

<!-- more -->

## The gap

Those migrations create their table and convert it inside
`ifCapable(TIMESCALEDB, …)`. That is the right tool for creating a hypertable on
a fresh install and the wrong one for its lifecycle: the migration is recorded
as applied, so it never runs again. Install TimescaleDB a year later and nothing
goes back to finish the job.

The tables are correct in every other respect — the composite primary keys
`(id, <time column>)` are created unconditionally, outside the capability block,
which is exactly what makes a later repair possible. But they are never
partitioned, never compressed, and their retention policies never apply. That is
the normal path for any long-lived installation that adopts TimescaleDB later,
not an edge case.

## Added

**`Pramnos\Database\HypertableRegistry`** — one declaration of which tables are
hypertables and with what parameters. The seven migrations now read it instead
of writing the values out inline, so the table of chunk intervals, compression
windows and retention periods exists exactly once. Applications register their
own the same way and get the same repair.

**`php pramnos timescale:ensure`** — walks the registry and brings each declared
table in line: convert with `migrate_data => true`, enable compression, add the
compression policy, add the retention policy. Every step is guarded by its own
existence check, so a second run is a no-op and a run against a correct database
changes nothing. That is not a nicety — `add_compression_policy()` and
`add_retention_policy()` raise on a duplicate rather than no-opping, so an
unguarded repair would work exactly once and fail ever after, which is worse
than not having one.

`--dry-run` reports each table's state, the row count of anything pending
conversion, and the total, before anything is locked. Conversion rewrites the
table under an exclusive lock; on a years-old audit table that is not instant,
and the command says so rather than letting an operator find out.

`--table=` limits the run to one declared table.

A database **without** the extension comes out unchanged and is told why:
retention there is handled by the software policy engine, a different mechanism
rather than a broken one.

Before converting, the command verifies that the primary key contains the
partitioning column — TimescaleDB requires it in every unique constraint. It
should always hold, which is why it is checked rather than assumed: a table
whose key omits the time column is reported as blocked, with its actual key,
instead of surfacing a driver error.

**Schema introspection**: `hasHypertable()`, `isCompressionEnabled()`,
`hasCompressionPolicy()`, `hasRetentionPolicy()` and `primaryKeyColumns()` on
`SchemaBuilder`. All return `false` (or `[]`) on backends without TimescaleDB
rather than raising.

## Fixed

**`isHypertable()` always answered false for an unqualified table.** It defaulted
its schema argument to `resolveSchema()`, which is an empty string unless a
`withSchema()` override is in force — and `''` matches no row in
`timescaledb_information.hypertables`. Fine for building SQL, where an
unqualified name resolves through the search path; useless for querying a
catalogue view that reports the real schema. Unqualified tables now resolve to
`public`, which is where the framework creates them.

## Documentation

[Hypertables (TimescaleDB)](../../Pramnos_Hypertable_Guide.md) — the declared
parameters, how to repair an installation, how to declare your own, and why the
conversion locks.
