---
title: The test database that would not copy
date: 2026-08-26
categories:
  - Testing
  - Database
---

# The test database that would not copy

A TimescaleDB project's suite failed before a single test ran, and only sometimes:

```
Database setup failed: SQLSTATE[55006]: Object in use: 7 ERROR:  source database
"template1" is being accessed by other users
DETAIL:  There is 1 other session using the database.
```

<!-- more -->

## What was happening

`TestEnvironment::setupPostgres()` recreates the test database as a copy of
`template1`. That is deliberate: the TimescaleDB image installs the `timescaledb`
extension into `template1`, so a database copied from it has the extension and a
database copied from `template0` does not.

PostgreSQL will not copy a template database while any session is attached to it.
The setup code terminated the sessions on the *target* database — the app, a stray
`psql` — which is the obvious hazard and was handled. Nothing terminated the
sessions on the template, because in plain PostgreSQL there are none.

TimescaleDB is not plain PostgreSQL. The extension runs one background-worker
scheduler per database, and it enumerates every database including `template1`.
That worker connects, idles, disconnects and reconnects on a schedule of its own.
Whether the copy succeeded came down to where in that cycle the suite happened to
start — which is why the failure looked random and why it never reproduced when
you ran the failing test on its own.

## The fix

`setupPostgres()` now terminates `template1`'s sessions as well as the target's,
and — because the scheduler can be back before the next statement runs — retries
the terminate and the copy **together**:

```php
self::retryWhileTemplateBusy(function () use ($pdo, $dbName) {
    $pdo->exec(
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
        . "WHERE datname = 'template1' AND pid <> pg_backend_pid()"
    );
    $pdo->exec("CREATE DATABASE \"$dbName\" WITH TEMPLATE template1");
});
```

Ten attempts, 200 ms apart. Only SQLSTATE 55006 is retried: a wrong password or a
missing role still fails on the first attempt, rather than two seconds and ten
identical failures later.

The two halves matter equally. Terminating without retrying is a race the
scheduler wins often enough to keep the flake. Retrying without terminating waits
for a worker that has no reason to leave.

## Notes

- Nothing to change in a project. The retry is inside `TestEnvironment`, which
  every scaffolded `tests/bootstrap.php` already calls.
- MySQL is unaffected — `CREATE DATABASE` there copies no template.
- Terminating a background worker is safe: TimescaleDB restarts its schedulers,
  and `template1` holds no state anyone is using.

## Documentation

- `Pramnos_Testing_Guide.md` — new section under the `./dockertest` troubleshooting
  material, covering the symptom, why `template1` and not `template0`, and why the
  terminate and the copy are retried as a pair.
