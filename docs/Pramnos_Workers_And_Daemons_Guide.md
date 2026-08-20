---
use_cases:
  - Writing a long-running worker or daemon
  - Scheduling recurring work
  - Supervising background processes in production
  - Choosing between CommandBase, ProcessQueue and a standalone script
---

# Workers & Daemons Guide

Everything for **console commands, long-running workers, and the daemon supervisor** —
one place, with worked examples for every case.

> **The short version (nothing you knew got harder).** The three classes you already
> use are unchanged in spirit:
>
> - **`CommandBase`** — the base for *every* console command (adds a single-instance lock).
> - **`ProcessQueue`** — a ready-made queue worker (extends `CommandBase`).
> - **`DaemonOrchestrator`** — supervises long-running daemons.
>
> What's new is that the daemon *plumbing* (locking, heartbeats, stop-signals, systemd
> notifications) was pulled out of those classes into four small **standalone primitives**.
> You almost never construct them yourself — `CommandBase` composes them for you. They exist
> so the classes above share one implementation, and so a **plain script** that is *not* a
> command can reuse the same building blocks. If you only ever extend `CommandBase` /
> `ProcessQueue` / `DaemonOrchestrator`, you can skip straight to those sections.

---

## The layers

```
 Primitives  (plain PHP · no base class · optional · compose freely)
 ┌───────────────┬────────────────┬─────────────┬──────────────────┐
 │  WorkerLock   │ WorkerReloader │ SignalStop  │ SystemdNotifier  │
 │ lock+heartbeat│ code/settings  │ graceful    │ sd_notify        │
 │ +.stop+wedged │ reload         │ SIGTERM/INT │ (Type=notify)    │
 └───────▲───────┴───────▲────────┴──────▲──────┴────────▲─────────┘
         └───────────────┴── composed by ┴───────────────┘
                              │
                    ┌─────────┴──────────┐
                    │    CommandBase     │   base for all commands (lock-guarded)
                    └─────────┬──────────┘
                              │ extends
                    ┌─────────┴──────────┐
                    │    ProcessQueue    │   ready-made queue worker
                    └────────────────────┘

 DaemonOrchestrator (extends CommandBase)  ── spawns & supervises ──►  worker processes
                                              (a command, or a bespoke script)
```

## Decision guide — "I want to…"

| Goal | Use |
|---|---|
| A one-shot command that must not run twice at once | **`CommandBase`** → `beginJob()`/`endJob()` |
| Continuously process the framework queue | **`ProcessQueue`** subclass |
| A bespoke long-running loop **as a console command** | **`CommandBase`** → `installStopSignals()` + `while (!shouldStop())` + `heartbeat()` |
| A bespoke long-running loop **as a standalone script** (no Symfony Console) | the **primitives** directly (`WorkerLock` [+ `SignalStop`/`SystemdNotifier`/`WorkerReloader`]) |
| Supervise one or more of the above as background daemons | **`DaemonOrchestrator`** subclass |
| Report worker/daemon health to a dashboard | `DaemonOrchestrator::status()` + `WorkerLock::readState()` |

---

## 1. `CommandBase` — the base for commands

Extend it for any console command. Two usage modes:

### 1a. Simple lock-guarded command (one-shot)

The classic pattern — guarantees a single instance, cleans up on Ctrl+C:

```php
use Pramnos\Console\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildSearchIndex extends CommandBase
{
    protected function configure(): void
    {
        $this->setName('search:reindex')->setDescription('Rebuild the search index');
    }

    protected function getJobName(): string { return 'SEARCH_REINDEX'; }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->beginJob($output)) {
            return self::FAILURE;           // already running → prints a message
        }
        try {
            // ... do the work ...
            return self::SUCCESS;
        } finally {
            $this->endJob();                // release + remove the lock
        }
    }
}
```

`beginJob()` also installs a SIGINT handler that cleans up and exits — right for a
one-shot command.

### 1b. Long-running worker command (a loop)

For a daemon-style loop, use the **cooperative** stop instead, so a SIGTERM/deploy never
cuts a job in half:

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    if ($this->checkIfRunning()) {
        $output->writeln('<error>Already running.</error>');
        return self::FAILURE;
    }
    $this->startJob();                 // acquire the lock
    $this->installStopSignals();       // trap SIGTERM + SIGINT → cooperative flag
    $this->systemd()->ready();         // no-op unless under systemd Type=notify

    try {
        while (!$this->shouldStop()) {                 // signal ∪ .stop sentinel
            $did = $this->processOneBatch();

            // Records state + pings the systemd watchdog; false ⇒ our lock was
            // taken over by a replacement worker, so stop.
            if (!$this->heartbeat(['jobs_processed' => $did])) {
                break;
            }
            if ($did === 0) {
                sleep(1);              // idle back-off
            }
        }
    } finally {
        $this->endJob();               // release + remove the lock, notify STOPPING
    }
    return self::SUCCESS;
}
```

### The two stop models

| | `beginJob()` (simple) | `installStopSignals()` (worker) |
|---|---|---|
| Traps | SIGINT | SIGTERM **and** SIGINT |
| On signal | clean up + **exit now** | raise a flag; **finish the job**, then exit |
| Loop guard | — | `while (!$this->shouldStop())` |
| Best for | one-shot commands | long-running loops |

Pick one per command; don't mix them.

### Method reference

| Method | Description |
|---|---|
| `getJobName(): string` | Lock name (override; used for the lock file + `.stop` sentinel) |
| `getJobLockFilePath(): string` | Lock path (default `ROOT/var/<jobName>`) |
| `getLockStaleSeconds(): int` | Age past which a lock is treated as stale (default 2 h) |
| `beginJob(OutputInterface, bool $registerShutdown=true): bool` | Guard + acquire; `false` if already running |
| `endJob(): void` | Release + remove the lock; notify systemd `STOPPING` |
| `checkIfRunning(): bool` | Is another live instance holding the lock? |
| `startJob(): void` | Acquire the lock (no guard/handler; for the manual worker pattern) |
| `heartbeat(array $extra=[]): bool` | Refresh heartbeat + record `$extra` + watchdog; `false` if the lock was taken over |
| `installStopSignals(?callable $onStop=null): void` | Cooperative SIGTERM/SIGINT graceful stop |
| `shouldStop(): bool` | Loop guard: trapped signal **or** the `.stop` sentinel |
| `signalStop(): SignalStop` / `systemd(): SystemdNotifier` / `workerLock(): WorkerLock` | The composed primitives, if you need them directly |

---

## 1c. The schedule, and the two ways to run it

The framework has periodic work of its own — buffered writes to flush, queued
rows to write into compressed chunks, finished queue items to clear. It declares
that itself, in `Pramnos\Scheduling\FrameworkSchedule`, so an application does
not have to know it exists. **Either** of these runs it:

**With cron** — one line, and it keeps working when a later framework version
adds a task:

```
* * * * * cd /path/to/app && php pramnos schedule:run >> /dev/null 2>&1
```

**Without cron** — one long-running process, for containers, under systemd, or
as an image's command:

```
php pramnos work                    # until told to stop
php pramnos work --once             # one pass, then exit
php pramnos work --interval=30      # check every 30 seconds
php pramnos work --max-runtime=3600 # exit hourly for a supervisor to restart
```

`work` holds a single-instance lock and stops cooperatively, so a SIGTERM
during a task lets that task finish.

**With a daemon orchestrator** — nothing to do. `DaemonOrchestrator` supervises
`work` alongside the application's own daemons, so a project that has an
orchestrator already runs the schedule. See
[§3](#3-daemonorchestrator--the-supervisor).

### This is not the queue worker

`queue:process` runs background **jobs** — things an application dispatches and
expects within seconds. `work` runs the **schedule** — things that happen on the
clock. They want opposite things: a queue worker polls constantly to keep
latency low, a scheduler sleeps a minute at a time. Run both:

```
php pramnos work &
php pramnos queue:process --daemon &
```

### How a `command` task is run

`Scheduler::command('spool:drain')` shells out, and the console it shells out to is the
one **this** process is running:

```
/usr/local/bin/php /var/www/html/myapp.php spool:drain
```

The running entry point, not a fixed name — a scaffolded application's console is
`<cliName>.php` in the project root, not the framework's `pramnos`. Define `PRAMNOS_BIN`
to point somewhere else:

```php
define('PRAMNOS_BIN', '/usr/local/bin/php /srv/app/console');
```

**A non-zero exit fails the task.** `schedule:run` prints `✗ Failed` and returns non-zero;
`work` counts it and carries on with the rest of the pass. Both write it to `schedule.log`.

> **Corrected 2026-08-20.** The default used to be the literal `php pramnos`, and the exit
> status was discarded. In a scaffolded application every `command` task therefore answered
> `Could not open input file: pramnos` while reporting `✓ Done` — including `spool:drain`,
> which meant a write spool that was never drained. Found on an installation with 478 rows
> in `var/spool/` and a `tokenactions` table that had never had a row in it.

### Replacing a framework task

```php
// app/schedule.php
use Pramnos\Scheduling\FrameworkSchedule;
use Pramnos\Scheduling\Scheduler;

FrameworkSchedule::disable('spool:drain');       // or ::disableAll()
Scheduler::command('spool:drain')->everyFiveMinutes();
```

The application's file is loaded **before** the framework registers, which is
what makes `disable()` usable at all. An application that disables these takes
on running them itself.

---

## 2. `ProcessQueue` — the ready-made queue worker

Extend it and supply your queue model; you get the daemon loop, dashboard, heartbeat,
graceful stop (SIGTERM/SIGINT/`.stop`), DB-reconnect and git-deploy handling for free.

```php
use Pramnos\Console\Commands\ProcessQueue;
use Pramnos\Queue\Worker;

class MyQueue extends ProcessQueue
{
    protected function getJobName(): string        { return 'QUEUE_PROCESSOR'; }
    protected function getDashboardTitle(): string { return ' MY APP QUEUE '; }
    protected function getControllerName(): string { return 'Queueitems'; }

    protected function createWorker($controller, ?string $workerId): Worker
    {
        $w = new Worker($controller, $workerId);
        $w->registerTaskHandler('send_email', SendEmailTask::class);
        return $w;
    }
}
```

Run it:

```
php console my:queue --daemon            # loop forever
php console my:queue --daemon --runtime=55   # cron-friendly: run 55s then exit
php console my:queue --limit=100         # process up to 100 then exit
php console my:queue --force             # clear a stale lock and start
```

Common options: `--daemon`, `--runtime=N`, `--sleep=N`, `--batch=N`, `--limit=N`,
`--type=a,b`, `--worker-id=N`, `--force`, `--start-from=…`, `--reverse-order`.
It uses the primitives internally — you wire nothing.

---

## 3. `DaemonOrchestrator` — the supervisor

Extend it to declare *which* long-running processes should exist; it spawns them, restarts
crashed ones, restarts on a stale heartbeat, and requests a graceful restart of all daemons
when a new git commit is deployed.

```php
use Pramnos\Console\DaemonOrchestrator;

final class MyDaemons extends DaemonOrchestrator
{
    protected function getJobName(): string        { return 'daemon_orchestrator'; }
    protected function getDashboardTitle(): string { return ' MY DAEMONS '; }
    protected function getEntryPoint(): string     { return ROOT . '/console'; }

    protected function buildDesiredProcesses(): array
    {
        return [
            [
                'id'              => 'queue_1',
                'daemon'          => 'queue',
                'workerId'        => 'worker-1',
                'lockFile'        => ROOT . '/var/QUEUE_PROCESSOR_worker-1',
                'shellCommand'    => escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg(ROOT . '/console') . ' my:queue --daemon --worker-id=worker-1',
                'requireLockFile' => true,   // health = a fresh heartbeat in the lock file
                'profile'         => 'email + notification queue',
            ],
        ];
    }
}
```

### The schedule comes with it

The list above is the application's daemons. The orchestrator supervises one more
without being asked — `work`, the framework's schedule worker (§1c) — under the id
`schedule`, with the standard lock file at `var/pramnos-work.lock`:

```
[started] stats pid=118
[started] realtime pid=30
[started] schedule pid=141      ← not in buildDesiredProcesses()
```

The framework declares periodic work of its own and a schedule only happens when
something runs it. A stack with an orchestrator and no crontab used to run none of
it: `spool:drain` never fired, rows written through the `WriteSpool` stayed in a
file, and every report reading the drained table showed "no data" for ever — a
symptom several layers away from its cause. An application answers for its own
daemons; the framework's are the framework's business.

Two overrides:

```php
// Already have a crontab line for schedule:run? Running both is safe (every
// framework task takes an overlap lock) but pointless.
protected function includeScheduler(): bool { return false; }

// Or supervise it differently — a shorter interval, a different lock file.
protected function schedulerProcess(): array
{
    return [
        'id' => 'schedule', 'daemon' => 'schedule', 'workerId' => 'schedule-1',
        'lockFile' => ROOT . '/var/pramnos-work.lock',
        'tokens'   => ['work', '--interval=30'],
    ];
}
```

An application that *already* declares `work` in `buildDesiredProcesses()` keeps its
own entry — recognised by what it runs, not by the id it was given. An application
whose console does not register `work` at all gets nothing added, rather than a
supervised entry that could only ever fail to start.

- `requireLockFile => true` — health is a fresh heartbeat (mtime) in the worker's lock file
  (a `CommandBase`/`ProcessQueue` worker keeps this automatically via `heartbeat()`).
- `requireLockFile => false` — health is process liveness only (use for a worker whose lock
  is JSON-heartbeat rather than the orchestrator's mtime convention — see the standalone
  example below).
- Stopping a daemon = the orchestrator drops a `<lockFile>.stop` sentinel; the worker's
  `shouldStop()` (or a standalone script's `WorkerLock::stopRequested()`) sees it and exits
  after the current job. Constants: `HEARTBEAT_STALE_SECONDS` (300), `GIT_CHECK_SECONDS` (60).

### Health snapshot for a dashboard

```php
$status = (new MyDaemons())->status();   // does NOT run a reconcile cycle
// [ 'running' => bool, 'pid' => int, 'heartbeat_age_seconds' => int, 'daemons' => [...] ]
```

---

## 4. Standalone script worker (no `CommandBase`)

When a worker is a plain script (e.g. `worker.php`, not a Symfony Console command), reach
for the primitives directly — the exact same ones `CommandBase` composes:

```php
use Pramnos\Console\WorkerLock;
use Pramnos\Console\SignalStop;
use Pramnos\Console\SystemdNotifier;
use Pramnos\Console\WorkerReloader;

$lock    = new WorkerLock('worker', WorkerLock::defaultPath('worker'));
$systemd = new SystemdNotifier();
$reload  = new WorkerReloader(ROOT, ['src', 'worker.php', 'composer.lock'],
    fn () => MySettings::versionStamp());

if (!$lock->acquire($takenOverFrom)) {
    exit("another worker holds the lock\n");   // still running / wedged
}
$reload->baseline();

$running = true;
$signals = new SignalStop([], function () use (&$running): void { $running = false; });
$signals->install();
$systemd->ready();

try {
    while ($running) {
        $processed = process_one_batch();

        if (!$lock->heartbeat(['jobs_processed' => $processed])) {
            break;                          // lock taken over → stop
        }
        $systemd->watchdog();
        if ($lock->stopRequested()) break;  // supervisor's <path>.stop sentinel

        if ($reload->settingsChanged()) { rebuild_from_settings(); }
        if ($reload->codeChanged()) {
            // Under a supervisor, exit and let it restart on the new code; otherwise
            // respawn yourself (see WorkerReloader::isSupervised()).
            break;
        }
        if ($processed === 0) sleep(1);
    }
} finally {
    $systemd->stopping();
    $lock->release();
}
```

This is why the primitives are standalone: a script gets the same locking, heartbeat,
graceful stop, systemd watchdog and reload guarantees as a `CommandBase` worker, without a
base class.

---

## 5. The four primitives (reference)

### `WorkerLock` — single-instance lock + heartbeat
A JSON file whose atomic *creation* is the mutex (works where advisory `flock()` doesn't,
e.g. Docker bind mounts on macOS), doubling as the heartbeat.

| Method | Description |
|---|---|
| `acquire(?string &$takenOverFrom=null): bool` | Win the lock; take over a dead/wedged holder; refuse a live+progressing one |
| `heartbeat(array $extra=[]): bool` | Refresh (+ record state); `false` once taken over |
| `release(string $status='stopped'): void` | Mark stopped, keep the file for status reads |
| `stopRequested(): bool` | Is there a `<path>.stop` sentinel? |
| `isHeldByAnother(): bool` / `holderIsWedged(): bool` | Foreign-holder liveness / alive-but-stuck |
| `readState(): ?array` / `heartbeatAge(?array): ?int` | State inspection (for `status`) |
| `static pidFromFile(string): int` / `static defaultPath(string $name, ?string $dir=null): string` | Legacy-aware pid read / `<dir>/<name>.lock` |

### `SignalStop` — cooperative graceful stop
```php
$stop = new SignalStop();                          // SIGTERM + SIGINT
$stop->install();
while (!$stop->requested()) { /* one job */ }
// or break a blocking loop: new SignalStop([], fn () => $server->stop())
```

### `SystemdNotifier` — sd_notify
```php
$sd = new SystemdNotifier();   // reads NOTIFY_SOCKET; no-op off Type=notify
$sd->ready(); $sd->watchdog(); $sd->stopping(); $sd->status('processing');
```

### `WorkerReloader` — code / settings reload
```php
$r = new WorkerReloader(ROOT, ['src', 'worker.php', 'composer.lock'], fn () => $stamp);
$r->baseline();
if ($r->settingsChanged()) { /* rebuild snapshot objects in place */ }
if ($r->codeChanged())     { /* exit for a restart on the new code */ }
WorkerReloader::isSupervised();   // is something going to restart me if I exit?
```

> **git-HEAD vs fingerprint.** The orchestrator's git-HEAD detection (§3) and
> `WorkerReloader::codeChanged()` both mean "restart on new code", but at different layers:
> the orchestrator (supervisor, git-deploy-granular, production) vs the worker itself
> (file mtime, git-less / dev `--watch-files` / self-respawn). They are complementary — the
> worker's own reload is normally **off** when a git+orchestrator setup owns restarts.

---

## 6. Deployment recipes

**systemd, watchdog (recommended for a command or script worker):**
```ini
[Service]
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php /srv/app/console my:queue --daemon    # or /srv/app/worker.php run
Restart=always
RestartSec=5
Type=notify          # the worker's ready()/watchdog() drive this
WatchdogSec=120      # a missed watchdog ping → systemd restarts it
```

**systemd, simplest:** drop `Type=notify`/`WatchdogSec`; `Restart=always` still restarts a
crash, and the single-instance lock prevents overlap.

**cron (no supervisor):** `run --runtime=55` (ProcessQueue) or `--max-runtime=55` (the RCB
script) polls for 55 s of each minute:
```
* * * * * cd /srv/app && php console my:queue --daemon --runtime=55 >> var/logs/queue.log 2>&1
```

**Under `DaemonOrchestrator`:** run the orchestrator itself as the single systemd service; it
spawns and supervises the workers from `buildDesiredProcesses()`.

---

## See also

- [Console Guide](Pramnos_Console_Guide.md) — command scaffolding, dashboards, terminal helpers.
- [Queue Guide](Pramnos_Queue_Guide.md) — the delayed-queue capability (`DelayedQueue`).
- [Realtime Guide](Pramnos_Realtime_Guide.md) — `broadcast:serve` (a `CommandBase` worker).
