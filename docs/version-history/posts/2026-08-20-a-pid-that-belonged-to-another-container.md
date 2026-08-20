---
date: 2026-08-20
categories: [Changelog]
---

# A pid that belonged to another container

`withoutOverlapping()` wrote its own pid to a file and, next time round, asked
`posix_kill($pid, 0)` whether that number was alive. Locally.

<!-- more -->

## Fixed

A pid is a fact about the process table of whoever is asking, and the ordinary shape here is
two containers sharing one `var/` — an application container and a daemon container. Each
reads the other's pid, finds some unrelated local process holding that number, and concludes
the task is still running. The task is then skipped for as long as that number stays in use,
which for a low pid on a busy container is indefinitely.

Nothing is logged, because "skipped: previous run still active" is a normal thing for a
scheduler to say. This is the third variation of the same mistake found in this repository —
after `wsWorkerHealthy()` and the console's process-table check — and the pattern is worth
stating once more: **a pid is only evidence to the kernel that owns it.**

The lock is now a `WorkerLock`, which the framework already had and which records the host
beside the pid: the pid is trusted when the host matches, and the holder is judged by
heartbeat age when it does not. That also closes a race the old code had — `isLocked()` then
`acquireLock()` is a check followed by a write, and two schedulers a millisecond apart both
passed the check. `acquire()` is one atomic create.

`withoutOverlapping()` takes an optional second argument for the staleness threshold, for a
task that legitimately runs longer than the default two minutes.

**Upgrading:** a lock left in the old format is a bare pid rather than JSON.
`WorkerLock::readState()` reports it as an unknown holder with the file's own age, so it is
honoured while fresh and taken over once stale — an upgrade neither runs a task that is
already running nor inherits a lock that outlives the process that wrote it.

Reported by a downstream project running two containers over one volume.

## Documentation

`Pramnos_Workers_And_Daemons_Guide.md` — a section on overlap protection across containers,
and what the threshold argument is for.
