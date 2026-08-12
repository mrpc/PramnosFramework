---
date: 2026-08-12
categories:
  - Changelog
  - Fixed
tags:
  - settings
  - postgresql
  - querybuilder
---

# Settings read every key one at a time on PostgreSQL, and said nothing

`Settings::loadAllSettings()` reads every setting in one cached query, so the
rest of the request can answer lookups from memory instead of going back to the
database for each one. On PostgreSQL it had never worked.

<!-- more -->

The statement was hand-built with MySQL backticks and passed straight to
`query()`, without going through `prepareQuery()` — so nothing translated it:

```sql
select `setting`, `value` from `settings`
```

PostgreSQL answers that with `syntax error at or near ","`. The `catch
(\Throwable)` around the call — there so a fresh install without a settings
table can still boot and run its migrations — turned the error into silence.

So nothing appeared to break. The bulk read did nothing, every lookup fell back
to a query of its own (the N round-trips the bulk read exists to replace), and
each request wrote another line into the error log. It ran that way in a real
application until the log was read for an unrelated reason.

## Fixed

`loadAllSettings()`, the per-key read in `getSetting()`, and all three
statements in `setSetting()` are now query-builder calls. The builder is the
only layer that knows the dialect: it quotes identifiers per driver, resolves
the table prefix and binds values instead of interpolating them.

```php
$result = self::$database->queryBuilder()
    ->table('#PREFIX#settings')
    ->select(['setting', 'value'])
    ->get(true, self::CACHE_TTL, 'settings');
```

## Tests

`tests/Integration/Application/SettingsPostgreSQLTest.php` — against a live
PostgreSQL, because the bug was in the dialect and only a dialect can report it.
The unit tests exercise the in-memory store, where no SQL is generated at all,
and could never have caught this. Besides the round trips, one test asserts that
**no statement the settings path issues contains a backtick** — the same shape of
mistake, next time, fails a test instead of a request.

## Documentation

The Query Builder guide now says that `#PREFIX#` is written by the caller: the
builder substitutes the token but never adds a prefix on its own, so omitting it
produces a query against a name that exists only where the prefix is empty —
working on a developer's machine and finding nothing on an installation that has
one.
