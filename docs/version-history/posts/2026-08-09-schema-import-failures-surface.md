---
date: 2026-08-09
categories:
  - Changelog
  - Fixed
tags:
  - testing
  - database
---

# A failed schema import is no longer silent

`TestEnvironment` now checks the exit status of the `psql` / `mysql` import and
raises a `RuntimeException` carrying the client's own output, instead of discarding
every error and handing back an empty test database.

<!-- more -->

## Before

Both import branches sent everything to `/dev/null` and ignored the exit status:

```php
'PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -f %s > /dev/null 2>&1'
```

So a missing client binary, a syntax error in the dump, wrong credentials — all
looked identical to success. The database was created but never populated, and the
suite then failed much later, somewhere unrelated. Worse, `psql` exits 0 even when
individual statements fail, so the status alone would not have been enough anyway.

## After

- Both commands drop the redirect and run through a new
  `TestEnvironment::runImport()`, which captures stdout+stderr and the exit status.
- Non-zero exit → `RuntimeException` including the client's diagnostics.
- Status **127** gets its own message — the client binary is not installed, and the
  bare *"psql: not found"* would not explain the consequence.
- The PostgreSQL import gained `-v ON_ERROR_STOP=1`, so a dump whose statements fail
  actually reports failure rather than exiting 0.

```
RuntimeException: Schema import failed: psql exited with status 3:
ERROR:  relation "a_table_that_does_not_exist" does not exist
```

## Compatibility

`runCommand()` is untouched, and a successful import behaves exactly as before. The
change is only visible where an import was already broken: what used to be a silent
empty database is now an exception at the point of failure. If a project relied on
the import quietly doing nothing (for example a dump that no longer applies), remove
the schema path from the `TestEnvironment::setup()` call instead.

## Tests

`TestEnvironmentTest` — a dump that fails mid-file raises with psql's diagnostics in
the message, a missing binary is reported as such, any other non-zero status carries
status and output, and a zero status stays silent.
