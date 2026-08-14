---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Documentation
tags:
  - database
  - querybuilder
---

# An empty ban list is still a ban list

`QueryBuilder::getAll()` answers `[]` for a failed query as well as an empty table, which its
own docblock presents as the feature it is. A consumer renamed their `settings` table away, got
`array()` with nothing thrown, and **cached that as the installation's configuration** — every
feature toggle at its compiled-in default for the whole TTL, nothing in the logs.

<!-- more -->

## What the filing got right, and the part it corrected itself

The report arrived as *"a caller cannot tell a failed query from an empty table"*, and the author
then corrected it — in the direction worth correcting: **`get()` keeps the distinction.**

```php
$r = $db->queryBuilder()->from('url_blacklist')->select(['pattern'])->get();
// false when the query failed; a Result when it succeeded, including when it matched nothing
```

Everything a caller needs is already there. What `getAll()` and `pluck()` do is collapse the two,
and that is documented rather than defective.

**The sharp edge is where those helpers get reached for.** `getAll()` is the obvious way to read a
list, and the lists whose empty answer is most plausible are the ones where it is most
consequential — settings, permissions, bans, allowlists. A ban list that failed to read is an
empty ban list, and one cache call later it is a *cached* empty ban list, outliving the failure
that caused it.

## And a refinement neither of us had

Verifying it turned up something the filing could not have seen from one engine: **the ambiguity
is PostgreSQL-only.**

With `throwOnError` off — the default — a failed prepare **returns `false` on PostgreSQL** and
**throws `mysqli_sql_exception` on MySQL**. That per-driver split is documented framework
behaviour, and it means:

| | A missing table, via `getAll()` |
| --- | --- |
| PostgreSQL | `[]` — indistinguishable from an empty table |
| MySQL | throws |

So `getAll()` was never ambiguous on MySQL, the reported outage was on PostgreSQL 17, and an
application developed against one engine and deployed against the other gets a different failure
mode for free. Both halves are now asserted against real databases — a MySQL class and a
PostgreSQL one — including a test that reproduces the ambiguity itself, so the day somebody
changes it, something says so.

## `getAllOrFail()`

```php
$patterns = $db->queryBuilder()->from('url_blacklist')->getAllOrFail();
```

The same as `getAll()` with the one distinction it discards put back: an empty table still returns
`[]`, and a failed query throws `QueryException` carrying the SQL.

It **wraps whatever the driver did** into that one type, rather than only checking for `false` —
which is the whole value, given the split above. One `catch (QueryException)` works on both
engines without turning on strict mode for the entire connection.

Per-call rather than a mode, because a single dangerous read should not have to make everything
strict. `$db->throwOnError = true` remains the right answer when a whole process should be loud,
and it already existed.

## The line at the point of reading

The filing's primary ask was documentation, and it was right to be: the capability existed, the
sentence did not — and it did not exist *where somebody reads about `getAll()`*.
`throwOnError` was thoroughly documented in the Database API guide's error-handling section,
which is not where anybody is standing when they reach for a convenience read.

The [Query Builder guide](../../Pramnos_QueryBuilder_Guide.md) now says it beside `pluck()`: what
`[]` can mean, which engine it can mean it on, why the danger tracks the *kind of list* rather
than the method, and the three ways to keep the distinction.

## One framework-side instance of the same shape

Auditing the framework's own `getAll()` calls, most are admin list views — an empty list there is
a visible symptom rather than a silent one. Two were not:

`DeferredWriteQueue::tablesWithPendingRows()` and `pendingBatch()`. A failed read there answers
"nothing pending", `process()` loops over nothing, and the run **reports success**. A queue that
cannot read its own table must not say it drained. Both use `getAllOrFail()` now.

## Added

- `QueryBuilder::getAllOrFail()` — `getAll()` for callers that would rather throw than branch,
  with one exception type on both drivers.

## Fixed

- `DeferredWriteQueue` fails loudly instead of reporting an empty drain when it cannot read its
  own table.

## Documentation

- `getAll()`'s and `pluck()`'s docblocks say what `[]` can mean and which engine it can mean it
  on.
- The [Query Builder guide](../../Pramnos_QueryBuilder_Guide.md) carries the same at the point
  the helpers are introduced.
