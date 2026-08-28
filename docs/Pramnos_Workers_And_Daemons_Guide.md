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

### Overlap protection across containers

```php
Scheduler::command('slow:job')->hourly()->withoutOverlapping();
Scheduler::command('very:slow')->daily()->withoutOverlapping('', 7200);  // wait up to 2h
```

The lock is a [`WorkerLock`](#workerlock--single-instance-lock--heartbeat): it records the
**host** beside the pid, and trusts the pid only when the host matches. Where it does not —
an application container and a daemon container sharing one `var/` — the holder is judged by
how recently it reported instead. The second argument is that threshold, for a task that
legitimately runs longer than the default two minutes.

> **Corrected 2026-08-20.** The lock used to be a bare pid file checked with
> `posix_kill($pid, 0)`. A pid is a fact about the process table of whoever is asking, so in
> a two-container stack each side read the other's pid, found *some* local process holding
> that number, and concluded the task was still running — for ever, silently, because "still
> running" is a normal answer. The check-then-write was also a race two schedulers a
> millisecond apart both passed; `acquire()` is a single atomic create.

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

## 1d. The write spool — rows written out of the request path

Some rows are worth keeping and worth nothing individually: an access log, an audit
trail, a hit counter. `WriteSpool::append()` puts one somewhere cheap and the scheduled
`spool:drain` writes what has accumulated, batched, out of the request path.

```php
\Pramnos\Database\WriteSpool::append('#PREFIX#tokenactions', [
    'tokenid' => 7, 'urlid' => 3, 'method' => 'POST', 'servertime' => time(),
]);
```

```
php pramnos spool:drain             # write everything buffered
php pramnos spool:drain --status    # how much is waiting, and where
```

### Rows that can never be written

A spool is a promise to write a row **later**, and later can arrive after the row stopped
being writable. The common case is a foreign key: `tokenactions` references `usertokens`,
and a token cleaned up while its rows waited takes the key with it.

Such a row is retried **five times** and then *parked* — appended to
`<table>.spool.failed` with the error that stopped it and a timestamp, removed from the
spool, and never read back:

```json
{"parked_at":"2026-08-20T04:11:07+03:00","attempts":5,
 "error":"insert or update … violates foreign key constraint","row":{"tokenid":3907,…}}
```

`spool:drain --status` reports the count; `WriteSpool::parked()` returns it. Parked rows
are **not** pending — nothing will try them again — so read that file, decide, and delete
it.

Tuning:

```
php pramnos spool:drain --max-attempts=10   # more patient
php pramnos spool:drain --max-attempts=0    # never park; retry for ever
```

```php
WriteSpool::setMaxAttempts(10);                    // in code
// or the `spool_max_attempts` application setting
```

> **Added 2026-08-20.** There was no limit: a row that could not be written was requeued
> unconditionally. One installation whose drain had never run accumulated a backlog whose
> tokens were cleaned up in the meantime; when the schedule started working, the same 209
> rows failed every minute, for ever, printing a line each. Identical failures are now
> reported once with a count, too — two hundred lines a minute for one reason is how the
> reason stops being read.

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
- `requireLockFile => false` — health is **process liveness only**, and the status line says
  `(pid alive)` rather than `(lock active)` because that is all that was checked. It also
  switches off the stale-heartbeat restart, which is the one restart a pid check cannot
  make: a wedged daemon satisfies "pid alive" indefinitely. Prefer `true` — a JSON lock is
  read fine, `readWorkerPidFromLockFile()` delegates to `WorkerLock::pidFromFile()`.
- Stopping a daemon = the orchestrator drops a `<lockFile>.stop` sentinel; the worker's
  `shouldStop()` (or a standalone script's `WorkerLock::stopRequested()`) sees it and exits
  after the current job. Constants: `HEARTBEAT_STALE_SECONDS` (300), `GIT_CHECK_SECONDS` (60).

### A stop request has a deadline

Every stop — a process leaving the desired set, a redeploy, the orchestrator being
disabled — records when it was asked for. A worker still running `STOP_GRACE_SECONDS`
(30) later is sent `SIGTERM` and reported as its own event:

```
[waiting]      realtime pid=30 — gracefully stopping, will restart when done (24s before SIGTERM)
[stop-timeout] realtime pid=30 — ignored the stop sentinel for 31s, sent SIGTERM
```

A daemon that polls its sentinel exits long before the deadline and never sees it. One that
cannot is the case the deadline exists for — and `[stop-timeout]` is a fact about that
worker, worth finding in a log.

The sentinel applies **whatever `requireLockFile` says**: it is the orchestrator's
instruction to that process, not a claim about its lock.

> **Corrected 2026-08-20.** Only the teardown path had a deadline. A redeploy called
> `requestStopAll()` and re-exec'd the orchestrator immediately; the new image knew only the
> state file, which recorded nothing about the stop, so the grace period never started —
> and a `requireLockFile => false` daemon was reported `[ok]` throughout, because the
> sentinel was read as part of a lock check it had switched off. Reported from a project
> where one worker ran for 1h32m across three deploys with its `.stop` sentinel on disk the
> whole time, serving every WebSocket client from the old code.

### Health snapshot for a dashboard

```php
$status = (new MyDaemons())->status();   // does NOT run a reconcile cycle
// [ 'running' => bool, 'pid' => int, 'heartbeat_age_seconds' => int, 'daemons' => [...] ]
```

`heartbeat_age_seconds` is the age of the state file, which the reconcile loop rewrites every
cycle — so a fresh mtime means the supervisor is not merely alive but actively cycling. **A
live pid with a stale heartbeat is the third state**, and it looks identical to healthy if you
only read the pid: the process is there and stuck.

For code with no instance to call — a web request cannot construct the application's
orchestrator subclass — the two paths are static:

```php
DaemonOrchestrator::stateFilePath();          // ROOT/var/daemon_orchestrator_state.json
DaemonOrchestrator::orchestratorLockPath();   // ROOT/var/DAEMON_ORCHESTRATOR.lock
```

### The services screen needs the supervisor to be running

`/admin/Services` lists what the orchestrator manages, and its Stop, Start and Restart
buttons **do not spawn or kill anything**: they write and remove a sentinel file, and the
orchestrator acts on it on its next cycle.

So with no orchestrator running:

- **Stop** still works — a daemon polls its own stop file.
- **Start** and **Restart** do nothing whatsoever. No error, no message: the operator clicks,
  the page reloads, the service stays down.

The screen therefore reports the supervisor's own state above the list — running with its pid
and last cycle, stale if it has not cycled for two minutes, and a warning naming the
consequence if it is not running at all. `GET /admin/Services/status` carries the same reading
as `orchestrator`, which is what a monitor should look at first: with the supervisor gone,
"0 running" is the expected number rather than an incident.

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

### <a id="creating-the-orchestrator-service"></a>Creating the orchestrator service — Ubuntu / Debian

This is the whole thing, on a stock Ubuntu or Debian box. `/admin/Services` links here when it
finds no supervisor running.

**1. Check the command exists.** An application declares its own orchestrator — a
`DaemonOrchestrator` subclass, conventionally named `daemons:start`:

```bash
cd /srv/app && php console list | grep daemons
```

If nothing comes back, there is no supervisor to run yet: write the subclass first
([§3](#3-daemonorchestrator--the-supervisor)). A scaffolded application gets one in
`src/ConsoleCommands/Daemons.php`.

**2. The unit file** — `/etc/systemd/system/app-daemons.service`:

```ini
[Unit]
Description=Application daemon orchestrator
# Ordering only. `After` does not wait for the database to be *ready*, which is the
# failure this file is written around — see the restart policy below.
After=network-online.target postgresql.service mysql.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php /srv/app/console daemons:start
# `always`, not `on-failure`. This process's worst failure is a *clean* exit: a machine
# that comes back before the database accepts connections boots the framework into its
# maintenance page and returns 0. `on-failure` looks at that and correctly does nothing,
# leaving the supervisor gone with every other service up and healthy beside it.
Restart=always
RestartSec=5
# It supervises children; let systemd clean up the whole tree.
KillMode=mixed
TimeoutStopSec=60
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

**3. Enable it:**

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now app-daemons
systemctl status app-daemons
sudo journalctl -u app-daemons -f      # what it is doing, live
```

**4. Confirm from the application**, not from the pid — `/admin/Services` reads the
orchestrator's own state file, so it can tell a live process from one that has stopped
cycling. `DaemonOrchestrator::status()` is the same reading for a monitor.

**Two things that go wrong on a fresh box:**

- **`var/` is not writable.** The locks, heartbeats and state file live in `ROOT/var`, and
  under `User=www-data` a directory owned by the deploying user is a supervisor that starts and
  immediately cannot record anything: `sudo chown -R www-data:www-data /srv/app/var`.
- **A crontab is already running the schedule.** The orchestrator supervises the schedule
  worker itself, so both together run every scheduled job twice. Remove the `schedule:run`
  crontab line, or override `includeScheduler()` to `false`.

**In Docker**, the same process is a service beside the app, sharing its image and volume so it
runs the code the site is running:

```yaml
  daemons:
    build: { context: . }
    restart: unless-stopped          # for the reason given above
    command: php /var/www/html/console.php daemons:start
    volumes:
      - .:/var/www/html
    depends_on:
      - db
```

`pramnos init` writes exactly that for an application whose features have background work —
the queue, messaging, broadcasting, or the periodic jobs `auth` and `authserver` schedule.

---

## See also

- [Console Guide](Pramnos_Console_Guide.md) — command scaffolding, dashboards, terminal helpers.
- [Queue Guide](Pramnos_Queue_Guide.md) — the delayed-queue capability (`DelayedQueue`).
- [Realtime Guide](Pramnos_Realtime_Guide.md) — `broadcast:serve` (a `CommandBase` worker).

### `broadcast:serve` is supervised for you

When `broadcasting.transport` is `websocket`, the orchestrator adds the WebSocket
daemon to what it supervises, the same way it adds the schedule worker. An
application does not have to declare it.

That is not convenience. `broadcast:serve` is the process that turns a published
event into a frame in a browser; without it every subscription is a perfectly
healthy socket that never receives anything — the publish succeeded, the channel
exists, the client connected, and nothing anywhere says what is missing.

An application that declares its own entry keeps it, recognised by the
`broadcast:serve` token or by the command line, so passing `--channels`, a
certificate or a different port works as before. To take it over entirely:

```php
protected function includeBroadcastServer(): bool
{
    return false;
}
```
