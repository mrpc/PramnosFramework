---
date: 2026-08-21
categories: [Changelog]
---

# The env prefix that setsid tried to execute

`bc1fad14` exported the resolved lock path to spawned workers. It built
`nohup setsid VAR=value php …` — and a `VAR=value` prefix is a *shell assignment*, valid only at
the start of a command. `setsid` takes its first argument as the program, so it tried to execute
`PRAMNOS_JOB_LOCK_FILE=/…` as a binary.

**Every worker with a declared `lockFile` failed to start.**

<!-- more -->

## Fixed

The assignment goes through `env`, which takes it as an argument rather than needing to be at the
start of a command:

```
nohup setsid env PRAMNOS_JOB_LOCK_FILE='/app/logs/realtime.lock' php bin/pramnos realtime:serve …
```

`env` is added only when there is a path to export, so a worker with no declared lock is spawned
exactly as before.

**And it reported success.** `echo $!` yields a pid whatever happened, so the spawn looked fine and
`confirmProcessStartup()` was the only thing that noticed. Two projects reproduced it independently
within the hour, one of them across five workers on every supervisor tick.

## The part worth keeping

`buildSpawnShellCommand()` was extracted from `startDesiredProcess()` in that same commit for a
stated reason: *"this is the only place the exported environment can be asserted without starting a
process"*. Three tests then asserted the built string, and all three passed on the broken version —
**the variable was there, in the wrong place.**

A guard that reads text about a shell cannot tell you the shell accepts it. Both consuming projects
reached that conclusion independently and wrote the same sentence, and it is the general rule
already applied elsewhere in this codebase: assert the construction, not the name.

So there are now three tests that *run* the built command with a harmless child that reports what
it was given, covering the plain case, the no-lock case and a path with a space in it. Verified from
both directions: reverting the fix makes two of them fail.

One of those two only failed after being tightened, and the reason is the same trap one level
down. `setsid`'s error message quotes the path it could not execute — `setsid: failed to execute
PRAMNOS_JOB_LOCK_FILE=/…/my realtime.lock` — so a test asserting merely that the path appears in
the output passed on the broken form. It now asserts the absence of `failed to execute` first.

## Note

The read side of `bc1fad14` — `resolvedJobLockFilePath()` being final and env-preferring,
`getJobLockFilePath()` still overridable for a hand-run command, `workerLock()` going through the
resolver rather than around it — was correct and is unchanged. It is also inert without the export,
which is why rolling back to `63370d4c` lost nothing for the project that did.
