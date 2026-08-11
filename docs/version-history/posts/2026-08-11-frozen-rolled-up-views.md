---
date: 2026-08-11
categories:
  - Changelog
  - Fixed
tags:
  - timescaledb
  - migrations
  - views
---

# Five rolled-up views were frozen on every database without TimescaleDB

They existed. They answered every query. And every answer was the one they gave
on the day the migration created them.

<!-- more -->

## Fixed

Four migrations create the same thing twice: a TimescaleDB continuous aggregate
where the extension is present, and a plain materialized view where it is not.
Only the first branch registered a refresh policy.

```php
$schema->ifCapable(TIMESCALEDB,
    function () use ($schema) {
        $schema->createContinuousAggregate('authserver.daily_activity_summary', …);
        $schema->addContinuousAggregatePolicy(…);   // ← the refresh
    },
    function () use ($schema) {
        $schema->createMaterializedView('authserver.daily_activity_summary', $sql);
    }                                                // ← no refresh, ever
);
```

PostgreSQL never refreshes a materialized view on its own. So on plain
PostgreSQL and MySQL, `daily_activity_summary`, `daily_2fa_stats`,
`tokenactions_hourly`, `application_stats_daily` and `application_stats_hourly`
were stale from the moment they were created — which is worse than missing. A
view that is not there fails and gets noticed; one that quietly returns
month-old numbers gets believed.

The mechanism was never missing. `addContinuousAggregatePolicy()` already
branches by backend: a native job on TimescaleDB, a row in
`pramnos.framework_policies` executed by the policy engine everywhere else. It
simply was not called on the second path.

The parameters now live in `Pramnos\Database\ContinuousAggregateRegistry`, and
the call sits **outside** the capability check, so both branches get a refresh.
A test reads the migration files and fails if anyone puts it back inside one —
nothing in code shows the absence of a call, which is why this survived.

## Added

`timescale:ensure` now repairs these too, and — the part that matters — it does
that **before** deciding whether TimescaleDB is present. The command used to
report "no extension here" and stop, which meant it refused to run on exactly
the backend whose views were frozen. `--dry-run` lists the views with no refresh
and says what stale means for them.

`2026_08_11_000001` does the same automatically, because the four migrations are
recorded as applied on every installation that already ran them and will never
run again — the same gap the repair exists to close. It adds nothing to a view
that already refreshes, and creates no view that is not there.

Two supporting methods on `SchemaBuilder`:

- `hasView()` — a continuous aggregate is not a table and neither is a
  materialized view, so `hasTable()` finds none of them, and asking it about an
  aggregate quietly answers no.
- `hasContinuousAggregatePolicy()` — on TimescaleDB the refresh job cannot be
  found by the view's name. `timescaledb_information.jobs` records the
  *materialization* hypertable (`_timescaledb_internal._materialized_hypertable_N`),
  so the lookup goes through `continuous_aggregates`. Written the obvious way,
  the check answers "no policy" for every aggregate that has one — and a repair
  built on it would add a second policy on every run.

Verified against both backends, including a PostgreSQL database with the
TimescaleDB extension dropped — what an installation without it actually looks
like, rather than a simulation of one.
