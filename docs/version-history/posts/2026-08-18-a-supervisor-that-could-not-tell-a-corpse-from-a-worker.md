---
date: 2026-08-18
categories: [Changelog]
---

# A supervisor that could not tell a corpse from a worker

Reported from a development stack where three of four background daemons had been dead for
fourteen hours. `daemons:start` was running, its log had no errors in it, and the dashboard
listed every worker as present:

```
PID 1    php myliveradio.php daemons:start
PID 14   [php] <defunct>
PID 16   [php] <defunct>
PID 18   [php] <defunct>
PID 20   php myliveradio.php realtime:serve
```

The features behind those three workers — now-playing, airplay statistics, feed-health
tiering — were simply empty, and nothing anywhere said why.

<!-- more -->

## What was wrong

`isProcessRunning()` asked `posix_kill($pid, 0)`. That call answers *"may I signal this
process"*, and a process which has exited but has not been reaped still says yes: its PID stays
in the table until somebody waits on it. So the supervisor asked whether its own dead child was
alive and was told it was fine.

The state that produces those zombies is not an edge case, it is what a container does. Workers
are started detached:

```php
$shell = 'nohup setsid ' . $command . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
```

The intermediate shell exits immediately, so the worker is orphaned, and an orphan is
reparented to **PID 1** — which inside a container is the orchestrator itself. It therefore
becomes the parent of every daemon it starts, reaps none of them (there is no wait loop, and
`pcntl` is frequently not built into a PHP image), and every graceful stop leaves behind a
`<defunct>` entry it reads as a healthy worker.

A redeploy is what triggered it. The git-hash check asks all daemons to stop; each one exits;
each becomes a zombie; and from then on the reconcile loop sees four PIDs that "exist" and
starts nothing.

## The fix

`/proc/<pid>/stat` carries the process state, and it is now read before anything else:

```php
$state = $this->processState($pid);

if ($state !== null) {
    return $state !== 'Z' && $state !== 'X';
}

if (function_exists('posix_kill')) {
    return @posix_kill($pid, 0);
}
```

`null` means *this platform cannot answer* and is deliberately distinct from `'Z'`: on a system
with no `/proc` the previous `posix_kill()` behaviour stands, unchanged.

The parsing is half of it. `/proc/<pid>/stat` is `pid (comm) state …` with `comm` unescaped —
and a zombie's `comm` is `(php) <defunct>`. Splitting the line on whitespace puts `<defunct>`
where the state belongs, so the state is taken from after the **last** `)`.

`reapExitedChildren()` now runs at the top of each supervisor cycle, before reconciling, so a
worker that has just exited is out of the table by the time the loop asks about it. Where
`pcntl_waitpid` is unavailable it does nothing: the zombies remain visible in `ps` but no longer
convince the supervisor, which is the part that mattered.

## Why this is worth a release note

A supervisor that reports success while its workers are dead is worse than no supervisor. The
application had been running its whole background stack in development *specifically* so that a
bug in one of those queries could not stay invisible until production — and the mechanism meant
to guarantee that had been quietly off for the whole day.

If you run `daemons:start` in a container, build `pcntl` into the image. Without it the
orchestrator can neither re-exec itself on a redeploy (so a newly-declared worker never appears)
nor reap what it inherits.

## Tests

`isProcessRunning()` is asserted against a **real** zombie rather than a stubbed `/proc`: a
child is started with `proc_open`, allowed to exit, and deliberately not reaped. The test also
asserts the premise it depends on — that `posix_kill($pid, 0)` still accepts that PID — so if
the kernel ever stopped answering that way, the test would say so rather than passing for a new
reason.
