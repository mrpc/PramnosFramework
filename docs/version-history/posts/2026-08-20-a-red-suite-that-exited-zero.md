---
date: 2026-08-20
categories: [Changelog]
---

# A red suite that exited zero

`./dockertest` ran PHPUnit and then fell off the end of the script, so its exit status was
whatever the last thing it happened to do returned — the `if` that opens the coverage report.
A run that printed **FAILURES!** exited `0`.

<!-- more -->

## Fixed

```
FAILURES!
Tests: 9878, Assertions: 24541, Failures: 1.
[exited with code 0]
```

Anything reading `$?` — CI, a pre-push hook, an agent deciding whether it is done — was told
the suite passed. The status is now captured from each of the three PHPUnit invocations and
re-raised at the end. Verified both ways: a deliberate failing test exits `1`, a passing run
exits `0`.

`Broadcasting\DatabaseDriverTest::testAQuietLoopStopsAtTheRuntimeCeiling` asserted that a
one-second runtime ceiling ended its loop **within four seconds of wall clock**. It passed in
isolation and failed inside a full run on a loaded machine. The invariant worth protecting is
that the loop ends itself, not how many seconds that takes on any particular machine, so the
bound is now generous. A test that fails for a reason unrelated to its subject teaches the
reader to re-run rather than to look — which is exactly what it did here, and only the exit
code above kept it from being missed entirely.
