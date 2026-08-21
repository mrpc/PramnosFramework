---
date: 2026-08-21
categories: [Changelog]
---

# The error path that did not wait

Separating `false` from `0` made a failing `stream_select()` visible. It did not stop it spinning:
**a failing `select(2)` past `FD_SETSIZE` returns immediately — the timeout is not applied to the
error path.** Measured at 1,377,387 iterations per second, against roughly 10 when idle.

<!-- more -->

## Fixed

The failure branch pauses. Without it, a node past the ceiling ran `drainRedisIngest()`, a
`pollLogFile()` stat, `sendKeepalives()`, `gossipState()` and `flushWebhooks()` 1.4 million times a
second while serving nobody.

That is also the real argument for throttling the log rather than writing a line per failure — the
throttle was the right call for the wrong reason. At that rate **the log was never the expensive
part**, and without the pause the noise simply moves to Redis and the filesystem.

### Two failure shapes, two timings

Measured while writing the test, and it refines the report this came from:

| | | |
|---|---|---|
| past `FD_SETSIZE` | returns `false` | **immediately** |
| invalid resource in the set | throws `TypeError` | after the **full** timeout, 100.4 ms |

So the hot loop is specific to the ceiling case — and that is the one that matters, because it is
permanent, while a bad resource in the set is a bug that announces itself at once.

## Fixed — a guard on the door that cannot open

The wrapper caught `\ValueError`. That needs *every* entry in the set to be invalid, and `$read`
always holds the listening socket, so it could never fire. Measured:

| set | throwable |
|---|---|
| every entry invalid, or empty | `ValueError`, "No stream arrays were passed" |
| one live entry plus an invalid one | `TypeError`, "supplied resource is not a valid stream resource" |

It now catches `Throwable`, which is the point rather than a widening: the reason to guard a
single-process event loop is the edit that has not happened yet, and a guard should not depend on
having predicted which door that edit opens.

## Fixed — a number in a comment, out by five orders of magnitude

The comment said a node past the ceiling "was spinning every 100 ms serving nobody". A failed select
does not wait, so it was spinning about 1.4 million times a second. Corrected because that line is
what somebody reads to judge how much the crossing costs, and 100 ms reads as tolerable.

## And a test that could not fail

The first version of the pacing test asserted elapsed time — and **passed with the pause removed**,
verified by removing it. The reason is the table above: a test can produce the invalid-resource
shape cheaply, and that shape already waits the full timeout, so wall-clock cannot tell a paced
branch from a spinning one. The shape that actually spins needs a thousand open descriptors.

Replaced with a seam — `pauseAfterSelectFailure()` — and an assertion that it is taken once per
failing iteration, checked from both directions. Not as good as measuring the real shape, and better
than an assertion that cannot fail. That is the third such test this batch, and the pattern is now
explicit: check the guard fails before believing it passes.

## Provenance

All three findings came from the project that measured the boundary, including the correction to its
own report: its test returned `false` in every `FD_SETSIZE` shape and found no throwable at all. The
two throwables came from a second probe written afterwards, and the comment they landed in described
the wrong one — which is what the table above is for.
