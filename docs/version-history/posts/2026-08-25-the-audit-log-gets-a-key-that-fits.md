---
date: 2026-08-25
categories: [Changelog]
---

# The audit log gets a key that fits

`authserver.audit_log` is created with a 64-bit key and, on TimescaleDB, as a
compressed hypertable. Existing installations are not touched — deliberately, and
that is most of what this change is about.

<!-- more -->

## Changed

- `auditid` is now `bigIncrements` rather than `increments`.
- The primary key is `(auditid, event_timestamp)`.
- On TimescaleDB: a hypertable with 7-day chunks, compressed after 90 days,
  segmented by `event_type` and ordered by `event_timestamp DESC`.
- **No retention policy**, and that is not an oversight.
- `log_audit_event()` returns `BIGINT`.

## Why existing databases keep what they have

The migration has always opened with `hasTable()` and returned. That guard now
carries weight it did not before: everything above only happens on a database that
does not already have the table.

Converting a live audit table means dropping and rebuilding its primary key and
rewriting every row into chunks, under lock, on a table other things hold foreign
keys into. That is not something a framework upgrade gets to do to somebody in the
middle of a deploy.

For the same reason the table is **not** declared in `HypertableRegistry`. That is
what `timescale:ensure` reads, and a declaration there would convert exactly the
installations the guard exists to protect. The cost is real — `timescale:ensure`
will not report drift on this table — and it was the better half of the trade.

## No retention, and a test that says so

An audit trail is the one table where the framework deciding on its own to delete
old rows would be wrong. The absence is asserted:

```php
$this->assertSame(0, (int) $retention->fields['cnt'],
    'audit_log must have no retention policy: dropping audit rows is never the '
    . "framework's decision to make");
```

A retention policy is exactly what somebody adds later "for consistency with the
other tables", and the damage shows up months afterwards, when the rows that
mattered are gone.

## Two things that would have broken it

**PostgreSQL refuses to let `CREATE OR REPLACE FUNCTION` change a return type.**
Changing `log_audit_event()` to `RETURNS BIGINT` fails with *"cannot change return
type of existing function"* on any database that already has the older one. A
`DROP FUNCTION IF EXISTS … CASCADE` now precedes it — a migration that only works
against a database which has never seen it is not a migration.

**`timescaledb_information.compression_settings` has no `segmentby` column.** It is
a different view from the one the name suggests: one row per column, with index
positions rather than expressions. The settings as declared live in
`hypertable_compression_settings`. Cost one test run, recorded in the test so it
costs nobody else one.

## Scope

Checked before touching anything: no reader or writer of this table anywhere in
`src/`, nothing in `scaffolding/`, nothing in `app/` or `bin/`. The only writer is
the SQL function above.

## Documentation

- [Hypertable Guide](../../Pramnos_Hypertable_Guide.md) — a new section on the one
  hypertable that is deliberately outside the registry, and what an existing
  installation should expect.
