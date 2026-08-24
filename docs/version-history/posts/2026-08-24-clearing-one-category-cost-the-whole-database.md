---
date: 2026-08-24
categories: [Changelog]
---

# Clearing one cache category cost the whole database

`RedisAdapter::clear($category)` deleted by pattern. That reads as a narrow
operation and is not one, and it was on the path of every model write.

Fixing it meant first retracting the number that motivated it.

<!-- more -->

## The retraction

Yesterday's post and the performance page both said a category clear cost
**268 ms**, measured in the suite. Asked to implement the repair, the first step
was to reproduce the cost, and it does not reproduce.

The suite's cache resolves to **`FileAdapter`** — the test fixtures configure no
cache method — and a category clear there is **0.05 ms**. So the 268 ms was not
measured where it was said to be measured, and the claim that `cacheflush()`
explains the 1.0–1.9 s per test in `MessagingModelsPostgreSQLTest` is withdrawn.
That cost is currently unexplained.

Worth saying at this length because the number had already been repeated into two
documents and was about to justify a third change. A measurement that cannot be
re-run is not a measurement.

## What is real, and measurable

The mechanism. `SCAN` with a `MATCH` **traverses the whole keyspace** — `MATCH`
filters what comes back, not what is walked. So the cost of clearing one category
was a function of everything else in the Redis database: other categories,
sessions, rate limiters, another application entirely.

Measured directly, category held at 40 keys:

| keyspace | `SCAN` + `MATCH` | `SMEMBERS` + `DEL` | |
| --- | --- | --- | --- |
| 1,000 | 0.6 ms | 0.29 ms | 2× |
| 10,000 | 1.2 ms | 0.70 ms | 2× |
| 100,000 | 15.8 ms | 0.27 ms | 58× |
| 500,000 | **128.7 ms** | **0.85 ms** | **151×** |

One column is linear in the size of the database. The other is flat.

At a thousand keys this was not worth fixing. That is the honest reading of the
top row, and it is why the number mattered: the change is justified by the slope,
not by any single measurement.

`Model` clears on every write — once per save, twice per delete, counted against
the code — so the left column was the price of a write in production.

## The fix

A Redis set per category, holding that category's keys. A clear is `SMEMBERS`
plus `DEL`.

Three details carry the correctness, and each has a test that fails without it.

**The set outlives its newest member.** Every save pushes its expiry to an hour
past that entry's own TTL. That bounds its growth in a category written
constantly and never cleared, and it guarantees the set is never the first thing
to expire — a set that went while its members lived would leave them
unclearable, which is the same stale-for-ever failure the change exists to
prevent. An entry saved with no expiry makes the set permanent, because there is
then a member that will never leave on its own.

**An installation that predates the index still gets its old keys removed.** Keys
written before the set existed are in no set, and among them are entries saved
with no expiry that would otherwise sit there for ever. A per-category **marker**
decides: no marker means scan once, the old way, then write the marker. So the
crossover happens exactly once per category, and never again.

Deciding that by "is the set empty?" would have been wrong twice over — an empty
set is also what an idle category looks like, so every clear of one would pay the
scan again, and that is the common case.

**A key written into a category's namespace by something other than the adapter
is no longer swept.** That is the real cost of not searching. It is pinned by a
test rather than left to be discovered, and the crossover scan is what makes it
safe on upgrade.

## Tests

18 integration tests against live Redis on database 9, plus one unit test for a
`sMembers()` that returns `false` instead of an array — which some phpredis
versions do, and a connection that has just dropped does. Passing that to
`array_chunk()` would throw a TypeError out of a cache invalidation, turning a
degraded cache into a failed request.

The tests pin the mechanism structurally rather than by timing — a stopwatch
assertion in CI is a flake waiting to happen. The proof that the clear reads the
index is that a key matching the old pattern, written behind the adapter's back,
**survives**: under the pattern scan it would have gone.

Coverage on the new and changed code: **100%**. 754 cache and Redis tests pass.

## Not changed

The Memcached, Memcache, File and Array adapters. Memcached cannot enumerate keys
at all and the others do not scan, so none of them had this cost; giving them an
index they cannot use atomically would add a failure mode to buy nothing.
