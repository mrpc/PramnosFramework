---
date: 2026-08-21
categories: [Changelog]
---

# A row reported written, and gone

`WriteSpool::writeNow()` discarded the return value of its insert. `Database::execute()` has one
documented path that fails **without throwing** — a prepare failure, outside strict mode — so a
spooled row whose write failed that way was counted as written, and the spool file was deleted.

Data loss, not a failed write.

<!-- more -->

## Fixed

`writeNow()` checks the return value and throws when the write returned `false`, which hands the
row to the retry and parking machinery that already existed. The database's own `error_text` is
carried into the message, because in the reported incident it was the only record of the cause.

Measured on a live stack, twice, with the previous commit installed:

```
file #PREFIX#tokenactions: 1 row(s)
#PREFIX#tokenactions: 1 written
1 row(s) written in 6ms.   exit=0
```

Not in the table. Not in the spool — the file was deleted. Not in `*.spool.failed`. The only trace
was a line from the same process: `Statement could not be prepared: ERROR: current transaction is
aborted, commands ignored until end of transaction block`.

**The mechanism was documented two files away, in a comment about this exact hazard.**
`Database.php` says of that return: *"this return-false is the one silent path (prepare failure —
e.g. a missing table) … In strict mode, surface it too so a caller (like the query builder) cannot
swallow it."* `WriteSpool` was such a caller, and it swallowed it.

**Checked locally rather than by enabling strict mode**, which is the reporter's preference and the
right one: strict mode changes behaviour for everything else sharing that connection, while a
return-value check is confined to the write it guards.

## It also explains a symptom that had looked unrelated

A parked-row count that stopped growing while the underlying condition continued. The rows had
stopped being *seen* as failures, not stopped failing — so the count was accurate about what the
spool believed and silent about what was happening.

`current transaction is aborted` is classified as **retryable** rather than permanent, which is
correct for this shape: a fresh process per drain means the next attempt gets a clean transaction.
There is a test pinning that, so the constraint classifier added earlier today cannot quietly start
parking these.

## And a test that would not have caught it

The first version of this test used a double that reimplemented `writeNow()`'s contract — so it
would have passed with the check removed. `writeNow()` now takes its database through a
`database()` seam and the tests drive the **real** method against a builder whose `insert()` returns
`false`, verified from both directions: removing the check fails two of them.

That is the fourth test this batch that had to be rewritten because it could not fail, and by now
the pattern is not a coincidence — each one was shaped to match a conclusion already reached. The
rule and its sibling, both earned the hard way this week: **check the guard fails before believing
it passes**, and **a contradiction is not a finding until the probe that produced it has been read
as carefully as the code it contradicts.**

## Not fixed, and not ours to guess at

What aborted the transaction earlier in that CLI process is unknown. It is a fresh process per
drain, the only log line is the aborted insert, and whatever failed first logged nothing. The
reporter notes their entry point auto-migrates and is not attributing it upstream. Recorded here
because the two compound: theirs exercises the silent path, ours turned a failed write into a lost
one. Only the second half was ours, and only the second half is fixed.
