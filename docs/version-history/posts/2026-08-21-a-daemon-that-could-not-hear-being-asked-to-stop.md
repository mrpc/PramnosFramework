---
date: 2026-08-21
categories: [Changelog]
---

# A daemon that could not hear being asked to stop

`DaemonOrchestrator` stops a worker cooperatively: it drops a `.stop` file beside the lock and
expects the worker to notice. `LocalBroadcastServer` blocked in `stream_select()` and observed only
signals — so the framework's own WebSocket daemon was **structurally guaranteed** to be reported
`[stop-timeout]` on every deploy.

<!-- more -->

## Fixed

`LocalBroadcastServer::shouldStopUsing(callable $check)`, asked once per loop iteration before any
work, and wired in `broadcast:serve` as `fn (): bool => $this->shouldStop()`.

The error line the orchestrator prints past the grace period —
`ignored the stop sentinel for 30s, sent SIGTERM` — was written on the reasoning that *"this worker
had to be signalled" is a fact about the worker*. It was, and the worker had been given no way to
comply.

**What it cost, reported from a deployment.** That installation declares its daemons
`requireLockFile => false`, which before `6ba785f1` short-circuited the orchestrator's own sentinel
check, so the escalation never started either. Three of their four workers survived that anyway,
because they extend a loop that polls `shouldStop()` itself. The fourth was the realtime server,
whose loop *is* this class. **It was never stopped, never signalled and never reported as anything
but healthy — it served pre-deploy code across deploys, indefinitely.**

Two decisions worth stating, both taken from the filing:

**A seam of its own, not a check folded into `onTick()`.** `onTick` is documented as the place for
the application's per-iteration work. Making the stop depend on an application remembering to
return the right thing from it is how the protocol became optional in the first place — every
consumer writes the three lines, or does not, and nothing tells them which. The symptom of getting
it wrong is a worker that looks healthy and serves code from before the deploy.

**A setter, not a `run()` parameter**, for the reason `1530bcfe` already gives for `useTls()`: a
trailing optional parameter is source-compatible for callers and fatal for a subclass that
overrides the method. The test file for this feature subclasses the server, so the hazard is not
hypothetical.

The check is consulted before the work rather than after, so a stop retires the process one
select-timeout later instead of after another full round of accepts, reads and fan-out. Only an
explicit `true` stops it: a truthy string from a misspelled call must not retire a healthy daemon.

An existing consumer that wires nothing keeps exactly the behaviour it has — including the bad half
— until it wires the seam. **If your application drives `LocalBroadcastServer` from its own
command, that is you.**

## Documentation

`Pramnos_Realtime_Guide.md`, under Supervising the daemon, now states the seam and what happens
without it.
