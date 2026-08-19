---
date: 2026-08-20
categories: [Changelog]
---

# An error message from a different statement

Two properties on `Database` outlive the statement that set them, and both are read by
the error path. Neither was tied to the statement being attempted.

<!-- more -->

## Fixed

`error_text` is captured when `prepare()` fails, under an `empty($this->error_text)`
guard. The guard is deliberate — the PostgreSQL retry path runs `DEALLOCATE`, which
overwrites `pg_last_error()` with the retry's message, so the first error of a *single*
prepare attempt is the one worth keeping. But nothing reset the property between
statements, so "the first error of one attempt" quietly became "the first error of the
request": every failure after it was reported with its message.

`currentQuery` is appended to the exception `setError()` throws, and only `query()` ever
set it. Anything raised from a prepared statement therefore quoted whichever *unprepared*
query had run last.

The two together produce a message that names a real error and a real query belonging to
different statements, minutes apart. That is worse than no message, because it sends the
reader to the wrong file — and it did:

```
DevPanel could not load login lockouts: 0:ERROR: bind message supplies 1 parameters,
but prepared statement "plan_6f6cd…" requires 0 ::: SQL QUERY:
INSERT INTO public."sessions" ("visitorid", "uname", "time", …)
```

The failing statement was a `SELECT` against `authserver.loginlockouts`. The `INSERT` is
the session write from application boot, which had failed minutes earlier and for an
unrelated reason. The same plan name appeared under three different queries in one log,
which is what eventually gave it away.

`prepare()` now clears `error_text` and `error_number` as a statement begins, and records
the statement it is preparing in `currentQuery`; `execute()` records it again when it runs
a statement prepared earlier. A statement that succeeds now leaves no error behind it, so
an empty `error_text` means "nothing went wrong here" rather than "nothing has gone wrong
yet".

MySQL execution errors are untouched: since PHP 8.1 mysqli reports in strict mode, so a
duplicate key throws `mysqli_sql_exception` out of `$statement->execute()` before the
framework builds a message of its own, and existing callers depend on that throw. The
integration tests assert it, so the difference is recorded rather than assumed.

## Documentation

`Pramnos_Database_API_Guide.md` — a section on reading `error_text`, and what it used to
report instead.
