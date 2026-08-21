---
date: 2026-08-21
categories: [Changelog]
---

# A check that had never once been taken

`hasContinuousAggregatePolicy()` exists to make policy creation idempotent. On TimescaleDB 2.26 it
returned false for every continuous aggregate, always — so the repair re-added a policy that
already existed on every schedule cycle: three stack traces per cycle, and an errors counter in
every worker's lock file that could never read zero.

<!-- more -->

## Fixed

The check joined `timescaledb_information.jobs` to `continuous_aggregates` on the materialization
hypertable, and its docblock stated the premise outright: the job *"cannot be found by the view's
name"*.

Measured on two versions, and the premise expired somewhere between them:

| TimescaleDB | `jobs.hypertable_schema` / `hypertable_name` |
|---|---|
| 2.19.3 | `_timescaledb_internal` / `_materialized_hypertable_N` |
| 2.26.4 | the aggregate's own `view_schema` / `view_name` |

So the join was right when it was written and matched nothing afterwards. The lookup now accepts
**either** pairing.

**Accepting both rather than swapping**, and that was the reporter's instinct before it was
measured — they asked whether `jobs` had ever reported the materialization hypertable, noting that
if it had, the fix wants both. It had: verified here on 2.19.3, where the original join returns 1
and the view-name join returns 0. Swapping would have broken every 2.19-era installation in the
same silent way, including this project's own dev stack.

**Nothing was broken, which is exactly why it would never have been fixed.** The cost is that a
real fault has to compete with it for attention — and the deployment that found it had just spent
an afternoon on a restart loop that turned out to be something else.

### The test asks the database, not the code

`ContinuousAggregatePolicyDetectionTest` creates an aggregate, has TimescaleDB add a policy, and
then asks the framework. It also asserts that exactly one of the two pairings identifies the job,
so the day a future version changes the columns again the test says which.

A guard on the join's spelling would only have asserted that the code says what it says — it would
have passed on every version and caught nothing. Verified from the other direction too: narrowing
the query to the 2.26-only pairing makes two of the three fail on this 2.19 database.

## Fixed — three things about `descriptorCeiling()`, all of them mine

**It is a constant, not a measurement, and the changelog claimed otherwise.**
`defined('FD_SETSIZE')` is false on every build checked, so the accessor returns a hard-coded 1024.
The recommendation to prefer it over a local literal — because a local one *"would stop agreeing
the day PHP is rebuilt"* — is the one thing it cannot deliver: **it is the literal, centralised.**
Caught by the consumer who adopted it on that sentence, which is the right way for such a claim to
be found and the wrong way for it to have been made. The single-definition value is real and stays;
`useDescriptorCeiling()` now exists for a build that differs.

**`select(2)` bounds descriptor numbers, not how many you watch.** PHP's own diagnostic says so —
*"you have descriptors numbered at least as high as"* — and `isNearDescriptorCeiling($watched)` was
called with a socket count. A long-lived daemon holds database, Redis and log handles it never
passes to `stream_select()`, so its fd numbers run above that count and the warning fired late by
exactly that margin. The reporter's own figure is the evidence: 58 feeds, 69 descriptors, eleven
invisible to the count. It now takes the greater of the count and `/proc/self/fd`, falling back to
the count where `/proc` is absent. A proxy, and honestly labelled as one — open descriptors equal
the highest number only when there are no gaps — but better than the count and never worse.

**The failure shape was described wrongly.** The docblock said `stream_select()` "returns false, so
the loop stops serving every connected client simultaneously". PHP emits
`_php_emit_fd_setsize_warning` per descriptor and leaves the offending stream out of the set, so
*that* client is never reported readable. Not a smaller failure — a differently-shaped one, and the
two call for opposite responses at 90%: refuse new connections, rather than shed load. Read from
PHP's strings and source shape rather than measured at the boundary, and marked as such in both the
docblock and the guide; the project that raised it has offered the measurement and that note should
be replaced by their number.

## Not a bug

The same project's earlier report of a schedule worker restarting 29 times was withdrawn with
measurements: the lock's pid agrees with the running process, `heartbeat_at` advances, `etime` shows
42 minutes without a restart. The restarts were the window in which the `setsid` env-prefix
regression meant nothing could start — so nothing heartbeated and everything read stale. One fault
wearing another's clothes, and the other fault was ours.
