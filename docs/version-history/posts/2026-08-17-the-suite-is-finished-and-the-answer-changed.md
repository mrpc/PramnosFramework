---
date: 2026-08-17
categories: [Changelog]
---

# The suite is finished, and the answer to the last question changed

Two items were standing open on the test-suite performance study: finish the remaining
`DatabaseTestCase` conversions, then re-ask whether `paratest` is the next step. Both are now
answered by measurement, and the first one is answered **no**.

<!-- more -->

## Where it ended up

Two consecutive `--nocoverage` runs on the same tree: **3:44 and 3:46 for 9750 tests**, 211.9 s
and 214.1 s of measured test time. The study opened at 14:58 for 9364 tests.

| Directory | Per test now | Per test then |
| --- | --- | --- |
| `tests/Unit` | **7.9 ms** | 60 ms |
| `tests/Integration` | 103.6 ms | 303 ms |
| `tests/Characterization` | 35.2 ms | 84 ms |

## The remaining conversions are declined

Fifteen classes still do DDL in `setUp()` rather than `setUpBeforeClass()` — the shape that took
81 s out of ten classes earlier in the study. Seven of the fifteen are convertible at all.
Together they are **10.26 s of 211.9 s: 4.8%**. At the reduction actually achieved before
(8.38 s → 1.38 s, ~83%), converting all seven perfectly saves about **8.5 s**.

The suite's run-to-run spread is ±15 s. So the entire remaining programme is at or under the
noise it would have to be measured in — there is no experiment that could show it worked. That
is a different thing from "small win": it is a win that cannot be observed.

Two further reasons, both already in the study: three of the seven are PostgreSQL, where this
exact conversion was measured making a class **slower** (5.71 s → 7.34 s, reverted); and four are
migration tests, where the DDL is the subject rather than the cost.

Two other candidates were examined and left. `InitSpinnerTest` spends 3 s in `sleep` — the shape
of the study's first item — but the spinner escalates on an integer-second threshold, so a
command must genuinely exceed a second for the path under test to exist. The compressible part is
1.4 s, 0.6% of the run, in exchange for tightening a timing-dependent test. A flake in CI costs
more than that the first time.

## `paratest`: the previous answer expired

It said *"not yet — finish the cheaper work first."* The cheaper work has now been measured, and
it is worth 8.5 s. So parallelism is the only remaining lever of any size, and the question is no
longer whether something cheaper exists but whether the prize justifies the cost.

At 8 workers, bin-packing by class gives a **26.5 s makespan** against 211.9 s of test time —
ideal scaling, ceiling 10.7×, set by `FrameworkMigrationsPostgreSQLTest`. With ~12 s of non-test
wall clock, **3:45 becomes roughly 50 s**: three minutes a run.

The cost is unchanged and permanent: a database per worker on three engines, and the 38 test
files that name `pramnos_test` themselves routed through a helper.

The recommendation is now **decide it on CI, not on a developer's patience**. Locally, 3:45 is
inside the span where you read the diff rather than leave the desk, and `--filter` already answers
fast feedback in under a second; buying three minutes with permanent cross-worker isolation is a
poor trade when that is not the binding constraint. On CI it is minutes × runs × people, it is
money rather than patience, and a worker-split flake is caught by a rerun instead of by somebody's
afternoon.

Either way, step 3 — routing every connection through one helper — is worth having on its own.

## What the shape says

When the study opened, **203 tests were 46% of the run** and the work was to find them. Now the
largest band is 525 tests averaging 190 ms, and the 19 tests over a second are 13.6% of the time —
mostly the migration classes that are meant to be slow.

There is no target left. That is the same conclusion as the declined conversions, arrived at from
the other direction, and it is why the study is closed rather than paused.
