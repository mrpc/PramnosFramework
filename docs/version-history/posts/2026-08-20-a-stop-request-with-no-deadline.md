---
date: 2026-08-20
categories: [Changelog]
---

# A stop request with no deadline

`DaemonOrchestrator` escalates a stop to `SIGTERM` after 30 seconds — on the teardown path,
for a process that has left the desired set. The two paths that stop *desired* processes
recorded nothing, so the deadline ten lines away never started.

<!-- more -->

## Fixed

A redeploy calls `requestStopAll()` and then re-execs the orchestrator. The new image knows
only what is in the state file, and the stop was not in it. The disabled path — the hook a
deploy-pause sentinel hangs on — polls and nothing more. Either way a daemon that does not
poll its own sentinel was never stopped, never signalled, and never reported as anything but
healthy.

Reported from a project where `realtime:serve` ran **1h32m across three deploys**, with
`realtime-html-<id>.lock.stop` on disk the whole time and three `[stop-all] stop requested`
lines in the log — the one worker bridging Redis to every WebSocket client, serving the old
code, while the supervisor called it `[ok]`.

`requestStopAll()` now records `stoppingAt` in the state file, where it survives the
re-exec, and reconcile starts a deadline for any sentinel it finds without one — a state
file from an older release, or a sentinel somebody touched by hand. Past the grace period
the worker is signalled and reported as its own event:

```
[waiting]      realtime pid=30 — gracefully stopping, will restart when done (24s before SIGTERM)
[stop-timeout] realtime pid=30 — ignored the stop sentinel for 31s, sent SIGTERM
```

`[stop-timeout]` rather than a line about the deploy, because "this worker had to be
signalled" is a fact about the worker.

**A `.stop` sentinel now counts whatever `requireLockFile` says.** It was read as part of the
lock check, which `requireLockFile => false` forces true — so for exactly the daemons that
keep no lock, the orchestrator's own instruction to stop was invisible to it. That is why the
reported worker was healthy for an hour and a half with a stop pending: not one bug but two,
each hiding the other.

**`[ok] … (lock active)` now says `(pid alive)` when no lock was read.** For 1h32m that line
named a lock file that did not exist, in the log an operator reads to find out what is
running. The state it describes is "the pid is alive" — a wedged daemon satisfies it, and so
does one that never wrote a lock. Two words, and the difference between a log that reports
evidence and one that reports an assumption.

## Documentation

`Pramnos_Workers_And_Daemons_Guide.md` §3 — what a stop deadline is, what `[stop-timeout]`
means, and what `requireLockFile => false` costs (the stale-heartbeat restart, which is the
one restart a pid check cannot make).
