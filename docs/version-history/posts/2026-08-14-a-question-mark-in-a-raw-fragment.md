---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
  - Added
tags:
  - querybuilder
  - database
---

# A `?` in a raw fragment now binds where it was written

Reported from a consuming application, and the cost was in the failure mode rather
than the failure. A query mixing `where()` with a `whereRaw()` carrying `?`
placeholders returned **`false`** from `first()` — no exception, nothing in the log —
and the only symptom was `Attempt to read property "fields" on false` pointing at the
*consumer*, several lines from the cause. They rewrote the query as a prepared
statement rather than spend longer on it.

<!-- more -->

## What was wrong

This builder does not use `?`. It emits the framework's own typed placeholders —
`%s`, `%i`, `%d`, `%b` — which `Database::prepare()` substitutes positionally. A raw
fragment was emitted **verbatim**, so a `?` in one stayed a literal `?` in the
statement while its value was still appended to the binding list: one more value than
there were placeholders, and every binding after the fragment shifted by one.

```php
$qb->from('chat_messages')
   ->where('created_at', '>=', $start)
   ->where('created_at', '<=', $end)
   ->whereRaw('channel_id IN (SELECT id FROM channels WHERE station_id = ?)', [$station]);

// before: … created_at >= %s AND created_at <= %s AND channel_id IN (… station_id = ?)
//         three bindings, two placeholders, and a ? the server rejects
// after:  … station_id = %i, bound in this clause's own position
```

## What it does now

Each `?` becomes the placeholder its own binding's type needs, in the position it was
written — which is what makes the ordering come out right, since the fragment already
sits in the correct place in the clause. Two things are deliberately left alone:

- **A fragment with no bindings.** `whereRaw('enabled = TRUE')` is used across the
  framework itself, and a `?` with nothing to bind may be PostgreSQL's `jsonb ? key`
  operator or a literal. Rewriting it would be a guess.
- **A `?` inside a quoted string.** `label = 'why?'` means what it says, escaped
  quotes (`'it''s'`) included.

## And it fails loudly now

A placeholder count that does not match the bindings throws from the `whereRaw()`
call itself — in the caller's own file, where the mistake is:

```
InvalidArgumentException: whereRaw() was given 2 binding(s) for 1 placeholder(s) in: a = ?
```

Separately, a statement that **cannot be prepared** is now written to the application
error log with its SQL. Outside strict mode the caller still gets `false` — that is
long-standing behaviour and not something to change under existing applications — but
the false now leaves a trail, which turns the property-read-on-false report into a
two-minute fix.

## Added: `orWhereRaw()` and `orHavingRaw()`

`orWhereRaw()` simply did not exist, while `orWhere()`, `orWhereIn()` and
`orWhereNull()` all did. The only route was `whereRaw($sql, $bindings, 'or')`, which
reads as an internal detail because the third parameter is one. `orHavingRaw()` comes
with it, for the same reason.

`havingRaw()` shares the placeholder fix: its bindings are a separate bucket merged
after the WHERE's, so a `?` there was wrong in the same way, in a place that is harder
to notice — an aggregate that quietly returns nothing looks like data that is not
there.

## Tests

19 unit tests on the compiled SQL, and six integration tests that assert the
statements **execute and return the right rows** against a real database, across the
engines the suite covers. The unit tests would have passed a fix that produced valid
SQL binding the wrong values; the integration tests would not.

## Documentation

- [QueryBuilder Guide](../../Pramnos_QueryBuilder_Guide.md) — `whereRaw()`, the two
  placeholder styles, what is left alone, and what throws.
