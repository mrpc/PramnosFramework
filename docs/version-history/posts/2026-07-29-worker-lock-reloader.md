---
date: 2026-07-29
categories:
  - Changelog
  - Features
tags:
  - console
  - daemons
  - workers
  - locking
---

# Worker liveness: WorkerLock + WorkerReloader

Long-running CLI workers get two standalone primitives — a robust single-instance
lock with a built-in heartbeat, and a reloader that keeps a daemon current across
deploys. `CommandBase` delegates its lock lifecycle to `WorkerLock`, so every
console worker gains the same guarantees without changing a line; a bespoke worker
script uses either class directly.

<!-- more -->

See the **[Console Guide](../../Pramnos_Console_Guide.md)** for the full API tables
and examples.

## Added

**`Pramnos\Console\WorkerLock`** — a single-instance lock + heartbeat that works
where advisory `flock()` silently doesn't (notably Docker bind mounts on macOS,
where two processes both "acquire" the same lock with no error). The lock is a JSON
file whose atomic *creation* (`fopen($path, 'x')` → `O_CREAT|O_EXCL`) is the mutex,
and which doubles as the worker's heartbeat.

```php
$lock = new WorkerLock('chat-worker', WorkerLock::defaultPath('chat-worker'));
if (!$lock->acquire($takenOverFrom)) {
    exit("another worker holds the lock\n");
}
while ($working) {
    /* ... one job ... */
    if (!$lock->heartbeat(['jobs_processed' => ++$n])) break; // taken over → stop
    if ($lock->stopRequested()) break;                        // <path>.stop sentinel
}
$lock->release();
```

A holder is respected only when it is **both alive and progressing** — its pid is
alive (checked on the same host) *and* its heartbeat is fresh within the stale
window. A crashed holder (dead pid) or a **wedged** one (alive but no longer
heartbeating — the case a plain pid check misses) is taken over, with
`$takenOverFrom` describing whom for logging. `WorkerLock::pidFromFile()` reads the
JSON `pid` and falls back to a legacy plain-text `"<pid>\n..."` lock.

**`Pramnos\Console\WorkerReloader`** — keeps a daemon from running forever on the
code and configuration it started with. Both inputs are constructor parameters (no
application coupling): the **watched paths** and a **settings-version resolver**
callback.

```php
$reloader = new WorkerReloader(ROOT, ['src', 'worker.php', 'composer.lock'],
    fn () => MySettings::versionStamp());
$reloader->baseline();
// between jobs:
if ($reloader->settingsChanged()) { /* rebuild snapshot objects in place */ }
if ($reloader->codeChanged()) {
    $lock->release();
    WorkerReloader::isSupervised() ? exit(0) : /* respawn self */ ;
}
```

`codeChanged()` fingerprints watched files' size+mtime; `settingsChanged()` fires
once per stamp move; `isSupervised()` detects systemd/supervisord/`WORKER_SUPERVISED`
so the worker knows whether exiting reloads or just stops.

## Notes (BC)

`CommandBase::startJob()/heartbeat()/endJob()` now acquire/refresh/release a
`WorkerLock` JSON lock, and both `CommandBase::readPidFromLockFile()` and
`DaemonOrchestrator::readWorkerPidFromLockFile()` delegate to
`WorkerLock::pidFromFile()`. `checkIfRunning()` keeps its pid+mtime guard, now
JSON-aware through the shared parser, and `endJob()` still removes the file — so the
single-instance guard and the orchestrator's dead-pid / live-pid / stale-lock
recovery all behave exactly as before, including for a lock left behind by an older
build across an upgrade.

## Tests

`tests/Unit/Console/WorkerLockTest.php` and `WorkerReloaderTest.php` (20 new cases),
plus the existing `CommandBaseTest` / `DaemonOrchestratorTest` / `ProcessQueueCommandTest`
lock suites unchanged and green.
