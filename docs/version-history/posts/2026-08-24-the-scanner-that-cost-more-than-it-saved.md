---
date: 2026-08-24
categories: [Changelog]
---

# The scanner that cost more than it saved

Asked whether the last few days had undone the test-suite performance work.
Measured rather than reasoned, and the answer was two regressions — both mine,
both from today, and neither of them visible in the suite total. The measuring
then turned up something older and larger underneath.

<!-- more -->

## Fixed — the comment scanner, on every prepared statement

`maskInertSql()` shipped this morning so the placeholder scanner would stop
reading an apostrophe inside a comment as the start of a string literal. It walks
the statement one byte at a time in PHP, and it replaced two quote-only regexes.

| Statement | scanner | the regexes it replaced |
|---|---|---|
| 41 bytes | 21.9 µs | 0.26 µs |
| 103 bytes | 50.6 µs | 0.38 µs |
| 150 bytes | 74.1 µs | 0.31 µs |

**150 to 240 times, on a path that runs for every prepared statement the
framework issues.** A page with thirty queries paid about 1.5 ms of pure
character-looping for a feature the overwhelming majority of statements never
use.

The walk is necessary — where a comment ends is not something a regex can decide
while also respecting string literals — but only for statements that contain a
comment. With no comment opener present the only inert regions are single-quoted
literals, and one regex finds exactly those, same length and same filler.
**22–74 µs became 1.3–1.7 µs**, of which about 1 µs is the reflection call the
benchmark used.

The fast path also requires an even number of quotes: the walk treats everything
after an unterminated quote as inert and the regex would not match it at all.
Invalid SQL either way, but not the *same* answer, and a fast path that changes
an answer is not a fast path. A test compares the two branches directly rather
than trusting that reasoning.

## Fixed — the PostgreSQL introspection, on every model save

Yesterday's fix for the `ForeignKey` flag was correct and four times slower.
`information_schema.constraint_column_usage` gives the wrong column for a foreign
key; `key_column_usage` gives the right one and costs **9.6 ms → 37.9 ms** for a
single table. `Model::_save()` runs that query **uncached, on every save**.

The information_schema views are themselves joins over the catalog, and asking
one per column means paying per column. `pg_constraint` answers the same question
directly — `conkey` holds the attribute numbers of the columns *this* table
constrains, which is exactly the semantics both flags need — and each set is
gathered **once** as an array instead of being correlated per column, so the
planner evaluates it as an InitPlan rather than a subplan per row.

The three `ForeignTable` / `ForeignSchema` / `ForeignColumn` lookups moved with
them, off a three-way join across `referential_constraints` and
`key_column_usage` that ran once per column for each of the three fields.

| | |
|---|---|
| before yesterday's correctness fix | 9.6 ms |
| after it | 28.9 ms |
| **now** | **4.2 ms** |

So it is right *and* faster than it was before either change — verified against a
real two-table fixture, not a reasoned equivalence.

## Fixed — two classes the earlier performance work never reached

| Class | Before | After |
|---|---|---|
| `TwoFactorAuthServiceMySQLTest` | 24.7 s / 17 tests | **3.3 s** |
| `MessagingModelsPostgreSQLTest` | 22.2 s / 11 tests | **17.5 s** |

Both rebuilt their whole schema in every `setUp()` — the first re-ran three
migrations seventeen times, the second five migrations eleven times. Building a
schema is expensive; building the *same* schema seventeen times is seventeen
times as expensive. Emptying it is not.

Neither could use `DatabaseTestCase`, because the code under test reaches the
database through `Database::getInstance()` and the base class owns a handle of
its own. Both got the pattern by hand.

## Found, measured, and deliberately not fixed

The second class barely moved, and that is worth more than the seconds it saved.
Profiling instead of guessing:

| Per test | |
|---|---|
| `setUp()` in total | **3.3 ms** |
| every test, including ones that assert almost nothing | **1.0 – 1.9 s** |

The cost was attributed to the saves. **That attribution is withdrawn** — see the
correction immediately below.

> **Corrected 2026-08-24.** The 268 ms below does not reproduce. Re-measured, the
> suite's cache resolves to `FileAdapter` — the fixtures configure no cache method
> — and a category clear there is **0.05 ms**. So this number was not measured
> where it says it was, and the conclusion that `cacheflush()` explains the 1.0–1.9 s
> per test is **withdrawn**; that cost is currently unexplained. The mechanism
> described below is real and was fixed the same day — see
> *[Clearing one cache category cost the whole database](2026-08-24-clearing-one-category-cost-the-whole-database.md)*
> for the measurements that do reproduce (128.7 ms against a 500,000-key
> keyspace, flat under 1 ms after).

| | |
|---|---|
| `$cache->clear('mails')` | ~~**268 ms**~~ — see above |
| `$db->cacheflush('mails')` | ~~293 ms~~ |

Clearing a category deleted by pattern — the pattern is narrow, but **`SCAN` with
a `MATCH` still walks the entire keyspace**. `MATCH` filters what comes back, not
what is traversed. So clearing one category cost what clearing all of them cost:
that part was right, and it is what got fixed.

`Model` calls `cacheflush()` on every write: once per save (the category on
insert, the record's key on update) and **twice** per delete. So a save is one
full Redis traversal and a delete is two, in production as much as in the suite.

*(Corrected: this first said `_save()` called it twice. Counted against the code,
it does not — the two-call site is `_delete()`.)*

The performance page has met a number like this before: it measured `cacheflush()`
at 85 ms against the **file** cache and removed the calls from two test classes
that did not need them. That was a directory scan and is unrelated to the keyspace
traversal described here.

**Not fixed here, on purpose** — and fixed later the same day, once the
measurement had been redone properly. A Redis set per category holding its own
keys, so a flush is `SMEMBERS` plus `DEL` and costs the size of the category
rather than the size of the database. See
*[Clearing one cache category cost the whole database](2026-08-24-clearing-one-category-cost-the-whole-database.md)*.

Worth knowing before picking it up: this only began costing anything when the SQL
cache started working at all. `Cache::getInstance()`'s method default went from
`'memcached'` — a store nobody configured, so every call was a silent no-op — to
one that resolves to the configured store. A consuming project's suite met the
same change from the other side, as four tests that suddenly served stale rows.

## And the answer to the question that started this

**No, the last few days did not undo the performance work** — beyond the two
regressions above, which are fixed.

Of 290.6 s of measured test time, files added or touched in those days account for
**26.9 s (9.2%)** across 424 tests. The `≥ 1000 ms` band grew from 19 tests to 68,
but the classes in it were almost entirely older; the two largest were the two
fixed above.

The `--no-coverage` run is 5:14 for 10,570 tests against a documented 3:44 for
9,750. Most of that is the suite having grown, and the honest way to read it is
the per-test figures rather than the wall clock — which is why the page records
them, and why it says to compare ranges rather than single measurements.
