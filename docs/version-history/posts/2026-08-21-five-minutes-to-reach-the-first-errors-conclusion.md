---
date: 2026-08-21
categories: [Changelog]
---

# Five minutes to reach the first error's conclusion

`WriteSpool` has always carried the reasoning: *"a row whose foreign key was deleted while it
waited cannot become writable by being tried again."* It then applied that only at the fifth
attempt.

<!-- more -->

## Fixed

A write failure that cannot resolve itself is now parked on a budget of **two** attempts rather
than five.

Reported with the whole trail: 40 rows in a spool file, `Key (tokenid)=(844) is not present in
table "usertokens"`, `spool:drain` exiting 1 once a minute from 21:56:59 to 22:01:10 and not since.
Five minutes of a failing scheduled task to establish what the first error had already said.

`isPermanentWriteFailure()` matches integrity-constraint violations only — SQLSTATE class 23 where
the driver surfaces it, and otherwise the wording both engines use. Deliberately narrow and
deliberately biased: a false negative costs the extra attempts this exists to avoid, while **a
false positive parks a row that would have been written**. The tests weight the transient list
accordingly — dropped connections, deadlocks, serialization failures, lock timeouts, a full disk.

**Two attempts rather than one**, and the reason is the case that is not permanent: the spool groups
rows by table and has no dependency ordering, so a child row can legitimately fail because its
parent is still sitting in the spool. One retry covers a parent landing in the next drain. A budget
of one would park rows that were about to become writable, which is the failure this must not trade
for the one it fixes.

The parked record now carries the attempts actually spent rather than the configured maximum, which
would misreport how hard a constraint violation had been tried.

## Fixed — a task that failed while working correctly

`spool:drain` returned `FAILURE` whenever any row was kept for the next run. A row inside its retry
budget is the spool working as designed, so the scheduler recorded a failure every minute until the
budget ran out.

It now fails only when rows were **parked** — data set aside, no further attempt, somebody has to
look. Rows still inside their budget are reported as a comment. The signal is preserved rather than
dropped: a row that never becomes writable ends up parked, and that still fails.

**The ambiguity this removes is the part worth naming.** The reporter read *"3 errors in 200
seconds"* and had to work out whether it was three tasks failing once or one task failing three
times. It was the second, and the counter could not say. Their words for it — that the count and a
plausible mechanism "lend each other credibility" — describe the failure mode exactly, and it is the
same one this changelog has been recording from the other side all batch.

## Also — a comment that would have narrowed the wrong thing

The pause on the WebSocket loop's select-failure branch said the invalid-resource path was "already
paced". It is not a property of the path: **what decides whether the call waited is whether anything
in the set was ready.** Measured across all four shapes:

| set | result | timing |
|---|---|---|
| live descriptor past `FD_SETSIZE` | `false` | 0 ms |
| live **quiet** descriptor + invalid entry | `TypeError` | 101 ms |
| live **readable** descriptor + invalid entry | `TypeError` | 0 ms |
| every entry invalid | `ValueError` | 0 ms |

So under load, with a readable client on every pass, the invalid-resource path throws at 0 ms and
spins exactly like the ceiling case. `catch (\Throwable)` already funnels both into the paused
branch, so there was no gap in the code — but "already paced" is precisely the sentence somebody
would later cite to narrow the pause to the ceiling case alone. It now says *paced while the loop is
quiet*.

Reported as a correction to the reporter's own earlier contradiction: their first probe had written
a byte into the live stream in its set, so `select()` returned at once and the throw arrived
immediately. Their note on it is the rule worth keeping: **a contradiction is not a finding either,
until the probe that produced it has been read as carefully as the code it contradicts.**
