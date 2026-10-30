---
use_cases:
  - Writing a console command of the application's own on CommandBase
  - Running long-lived workers under the DaemonOrchestrator
  - Using the console's advanced features
---

# Writing commands and daemons

Part of the [console guide](Pramnos_Console_Guide.md).

## CommandBase

`Pramnos\Console\CommandBase` — abstract base class for lock-guarded and interactive console commands. All long-running or daemon-style commands should extend this instead of `Command` directly.

```php
abstract class CommandBase extends \Symfony\Component\Console\Command\Command
{
    abstract protected function getJobName(): string;
}
```

### Lock-file job guards

```php
class MyDaemon extends \Pramnos\Console\CommandBase
{
    protected function getJobName(): string { return 'my_daemon'; }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->beginJob($output)) {
            $output->writeln('<error>Already running.</error>');
            return Command::FAILURE;
        }

        while (true) {
            $this->heartbeat();  // touch lock file so orchestrator knows we're alive
            // ... do work ...
        }

        $this->endJob();
        return Command::SUCCESS;
    }
}
```

| Method | Description |
|---|---|
| `beginJob(OutputInterface, bool $registerShutdown=true): bool` | Returns `false` if already running; acquires the lock (+ SIGINT cleanup handler) |
| `endJob(): void` | Releases and removes the lock (also called by shutdown/signal handlers); notifies systemd `STOPPING` |
| `heartbeat(array $extra=[]): bool` | Refreshes the lock's heartbeat (+ records `$extra` state, pings the systemd watchdog); `false` once the lock was taken over |
| `checkIfRunning(): bool` | Checks lock file + PID liveness; treats stale locks (>2 h) as gone |
| `installStopSignals(?callable $onStop=null): void` | Trap SIGTERM/SIGINT for a **cooperative** stop (see below) |
| `shouldStop(): bool` | The single loop guard: a trapped signal, or the supervisor's `.stop` sentinel |

The lock lifecycle is delegated to `Pramnos\Console\WorkerLock` (see below): `startJob()`
acquires it (writing a JSON lock), `heartbeat()` refreshes it, `endJob()` releases and
removes it. `readPidFromLockFile()` uses `WorkerLock::pidFromFile()`, which reads the JSON
`pid` and also accepts a plain-text lock holding only a pid.

!!! warning "The supervisor decides the lock path, not the worker"
    `DaemonOrchestrator` writes the `.stop` sentinel beside the `lockFile` declared in
    a worker's desired-process entry, and exports that path to the child as
    `PRAMNOS_JOB_LOCK_FILE` (`CommandBase::LOCK_FILE_ENV`). `CommandBase` reads it in
    preference to anything the command computes (the `final`
    `resolvedJobLockFilePath()`), so **an override cannot disagree with the
    supervisor.** Two independent computations of one path would fail silently: a
    sentinel read where nothing writes is indistinguishable from no sentinel — no
    error, no log, a worker reporting itself healthy while ignoring every stop request.

    `getJobLockFilePath()` stays overridable (default `ROOT/var/<job>`): it is a
    legitimate application default, and it applies when a command is run by hand with
    no supervisor.

**Two stop models — pick one per command.** A simple lock-guarded command uses
`beginJob()`, whose SIGINT handler cleans up and exits immediately (fine for one-shot
work). A **long-running worker** instead calls `installStopSignals()` and loops on
`while (!$this->shouldStop())`: SIGTERM (systemctl stop / deploy) and SIGINT then only
raise a flag, so the current job finishes before the process exits. `shouldStop()` also
honours the supervisor's `<lock>.stop` sentinel, and `heartbeat()` returns `false` when
another worker took the lock over — three stop sources behind one guard:

```php
$this->installStopSignals();
$this->systemd()->ready();                 // no-op off systemd Type=notify
while (!$this->shouldStop()) {
    $processed = $this->processBatch(...);
    if (!$this->heartbeat(['jobs_processed' => $processed])) {
        break;                             // lease lost — a replacement took over
    }
    sleep($idle);
}
$this->endJob();                           // releases the lock, notifies STOPPING
```

### Worker liveness primitives

> **See the [Workers & Daemons Guide](Pramnos_Workers_And_Daemons_Guide.md)** for the full
> mental model (CommandBase / ProcessQueue / DaemonOrchestrator + these primitives), a
> decision guide, and end-to-end examples including a standalone-script worker and systemd/
> cron recipes. The summary below is the primitive reference.

Four standalone `Pramnos\Console` primitives for long-running CLI workers, each usable
directly by a bespoke worker script and all composed by `CommandBase` (so console workers
get them for free): **`WorkerLock`** (single-instance lock + heartbeat), **`WorkerReloader`**
(code/settings reload), **`SignalStop`** (cooperative graceful stop), **`SystemdNotifier`**
(sd_notify watchdog).

**`Pramnos\Console\WorkerLock`** — a single-instance lock + heartbeat that works where
advisory `flock()` silently doesn't (Docker bind mounts on macOS). The lock is a JSON
file whose atomic *creation* (`fopen($path,'x')`) is the mutex, and which doubles as the
heartbeat.

```php
use Pramnos\Console\WorkerLock;

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

| Method | Description |
|---|---|
| `acquire(?string &$takenOverFrom=null): bool` | Win the lock; take over a dead/wedged holder (describing it in `$takenOverFrom`), refuse a live+progressing one |
| `heartbeat(array $extra=[]): bool` | Refresh the heartbeat; `false` once another worker has taken over |
| `release(string $status='stopped'): void` | Mark stopped and drop the held flag (keeps the file for status reads) |
| `isHeldByAnother(): bool` / `holderIsWedged(): bool` | Foreign-holder liveness / alive-but-not-heartbeating |
| `readState(): ?array` / `heartbeatAge(?array): ?int` / `stopRequested(): bool` | State inspection |
| `static pidFromFile(string): int` | JSON `pid`, or a plain-text lock holding only a pid (0 if absent) |
| `static defaultPath(string $name, ?string $dir=null): string` | `<dir>/<name>.lock` (`ROOT/var` or temp) |

A holder is respected only when **both alive and progressing** (pid alive on the same host
*and* a fresh heartbeat); a crashed or wedged one is taken over. Scope the path/name per
install yourself when several installs share a host.

**`Pramnos\Console\WorkerReloader`** — keeps a daemon from running forever on stale code
and configuration. Both inputs are constructor parameters:

```php
use Pramnos\Console\WorkerReloader;

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

`codeChanged()` fingerprints watched files' size+mtime; `settingsChanged()` fires once per
stamp move (the resolver's return value); `isSupervised()` detects systemd/supervisord/
`WORKER_SUPERVISED` so the worker knows whether exiting reloads or just stops. With no
resolver, settings tracking is disabled.

**`Pramnos\Console\SignalStop`** — cooperative graceful stop: async SIGTERM/SIGINT handlers
that only raise a flag (or run an `$onStop` callback), so a loop finishes the job in hand
before exiting. No-op when `pcntl` is unavailable.

```php
use Pramnos\Console\SignalStop;

$stop = new SignalStop();          // defaults to SIGTERM + SIGINT
$stop->install();
while (!$stop->requested()) {
    /* ... one job ... */
}
// or, to break a blocking server loop, pass a callback:
$stop = new SignalStop([], fn () => $server->stop());
```

| Method | Description |
|---|---|
| `install(): void` | Install the async signal handlers (no-op without pcntl) |
| `requested(): bool` / `reset(): void` | Whether a stop was asked for / clear it |
| `stop(?int $signal=null): void` | Request a stop directly (runs `$onStop` once) |

**`Pramnos\Console\SystemdNotifier`** — the `sd_notify` protocol for units running as
`Type=notify`. Every method is a no-op when there is no `NOTIFY_SOCKET`, so a worker behaves
identically under cron, by hand, or supervised.

```php
use Pramnos\Console\SystemdNotifier;

$sd = new SystemdNotifier();       // reads NOTIFY_SOCKET
$sd->ready();                      // startup complete
$sd->watchdog();                   // liveness ping (with WatchdogSec set)
$sd->stopping();                   // deliberate shutdown
```

| Method | Description |
|---|---|
| `enabled(): bool` | Running under `Type=notify` (a reachable `NOTIFY_SOCKET`) |
| `ready()` / `watchdog()` / `stopping()` / `status(string)` | Send the corresponding sd_notify datagram |
| `notify(string): bool` | Send a raw state line (best-effort; `false` if disabled/failed) |

Via `CommandBase`, `installStopSignals()` wires `SignalStop`, `shouldStop()` folds it in with
the `.stop` sentinel, `heartbeat()` pings the `SystemdNotifier` watchdog, and `endJob()`
sends `STOPPING` — so a command-based worker gets all four primitives without wiring them by
hand. `ProcessQueue` and `broadcast:serve` use exactly this.

### Terminal control

| Method | Description |
|---|---|
| `clearScreen(OutputInterface)` | Clear terminal |
| `hideCursor(OutputInterface)` | Hide cursor during live dashboard |
| `showCursor(OutputInterface)` | Restore cursor |
| `detectTerminalSize(): array{int,int}` | Returns `[height, width]` via `stty size` |

### Progress bar

```php
$output->write("\r" . $this->buildProgressBar($current, $total));
// → " [████████████..........] 60 of 100 (60%)"
```

### Text utilities

| Method | Description |
|---|---|
| `formatBytes(int|float, int $precision=2): string` | `1024 → "1 KB"`, `1048576 → "1 MB"` |
| `formatTime(int $seconds): string` | `3723 → "01:02:03"` (HH:MM:SS) |
| `visibleLength(string): int` | Character count ignoring ANSI escape sequences |
| `truncateText(string, int $maxLen): string` | Adds `...` if visible length exceeds maxLen |

### Dashboard rendering

Used to build live bordered terminal dashboards:

```
┌──────────────────────────────────────┐
│           QUEUE PROCESSOR v2         │
├──────────────────────────────────────┤
│ Time: 2026-05-05 14:30:00 │ Uptime: 00:12:34 │ CPU: 2.1 │ Memory: 48 MB │
├──────────────────────────────────────┤
│ Mode: Normal │ State: Running │ Tasks today: 1024     │
└──────────────────────────────────────┘
```

| Method | Description |
|---|---|
| `buildDashboardHeader(string $title, int $borderLen): string` | Top border with centered title |
| `buildDashboardSectionSeparator(int $borderLen): string` | Section divider `├──┤` |
| `buildDashboardFooter(int $borderLen): string` | Bottom border `└──┘` |
| `padDashboardLine(string $content, int $borderLen): string` | Pad content with side borders `│ … │` |
| `buildDashboardRows(string[] $segments, int $borderLen): string` | Fit segments side-by-side with ` │ ` separator |
| `buildSystemStatusSegments(int $startTime, float $cpu, int|float $mem): string[]` | `['Time: …', 'Uptime: …', 'CPU: …', 'Memory: …']` |
| `renderDashboardFrame(OutputInterface, string $title, string[] $systemSegments, string[] $sections, int $terminalWidth): void` | Full frame render (cursor-home → write → erase-below) |
| `renderDashboardFrameAutoSystem(OutputInterface, string $title, string[] $sections, int $terminalWidth): void` | As above with auto-built system segments |

---

## DaemonOrchestrator

`Pramnos\Console\DaemonOrchestrator` — abstract generic process supervisor that reconciles desired-vs-actual daemon state. Handles crash detection, heartbeat monitoring, git-hash restart on deploy, pre-spawn dedup, graceful stop, and a live interactive dashboard.

### Extending

Override three abstract methods and optionally hook lifecycle checks:

```php
use Pramnos\Console\DaemonOrchestrator;

class MyOrchestrator extends DaemonOrchestrator
{
    protected function getJobName(): string        { return 'my_orchestrator'; }
    protected function getEntryPoint(): string     { return ROOT . '/bin/myapp'; }
    protected function getDashboardTitle(): string { return ' MY APP ORCHESTRATOR '; }

    protected function buildDesiredProcesses(): array
    {
        return [[
            'id'       => 'queue-1',
            'daemon'   => 'queue',
            'workerId' => 'worker-1',
            'lockFile' => ROOT . '/var/QUEUE_WORKER_1',
            'tokens'   => ['queue:process', '--worker-id', 'worker-1', '--daemon'],
        ]];
    }

    // Optional — read from application settings:
    protected function isOrchestratorEnabled(): bool
    {
        return \Pramnos\Application\Settings::getSetting('daemon_enabled', true);
    }
}
```

```bash
php bin/myapp daemons:start
php bin/myapp daemons:start --once           # single reconcile cycle
php bin/myapp daemons:start --interactive    # live terminal dashboard
php bin/myapp daemons:start --dry-run        # show planned actions, no changes
php bin/myapp daemons:start --interval=5     # reconcile every 5 seconds
```

### Process definition keys

| Key | Required | Description |
|---|---|---|
| `id` | yes | Unique slot identifier for state tracking |
| `daemon` | yes | Daemon type label (`'queue'`, `'kafka'`, etc.) |
| `workerId` | yes | `--worker-id` argument value |
| `lockFile` | yes | Absolute path to the worker's lock file |
| `tokens` | yes | CLI arguments passed to `getEntryPoint()` |
| `requireLockFile` | no | Whether a healthy lock file is required (default `true`) |
| `shellCommand` | no | Raw shell command — overrides `tokens` |

### Reading orchestrator health — `status()`

`status()` is a public, read-only health snapshot for status dashboards. It
reports the orchestrator's own liveness plus its managed daemons **without**
running a reconcile cycle, so an admin/API endpoint can answer "is the supervisor
up?" by constructing the orchestrator and calling it:

```php
$status = (new MyOrchestrator())->status();
// [
//   'running'               => bool,   // singleton-lock pid is alive
//   'pid'                   => ?int,
//   'heartbeat_age_seconds' => ?int,   // age of the state file (rewritten each cycle)
//   'daemons'               => [ ['id'=>.., 'pid'=>?int, 'running'=>bool, ...], ... ],
// ]
```

`running`/`pid` come from the singleton lock file; `heartbeat_age_seconds` is the
age of the state file (a fresh mtime means the loop is actively cycling); each
managed daemon from the last-persisted state is enriched with live process
status. Safe to call before the orchestrator has ever run (reports not-running).

---

## Advanced Features

### Foreign Key Detection

The generators read the table's foreign keys from the schema (or from the migration
wizard's answers). No relationship getter is generated; what a foreign key changes is:

- **The model.** An integer foreign key declared `ON DELETE SET NULL` makes `save()` store a
  `0` as `null`:

  ```php
  public function save($autoGetValues = false, $debug = false): static
  {
      if ($this->category_id == 0) {
          $this->category_id = null;
      }
      parent::_save(null, null, $autoGetValues, $debug);

      return $this;
  }
  ```

- **The web controller.** The edit form gets a `<select>` for the column, and the controller
  gets an `fkOptions()` action (public, beside `show` and `data`) mapping each foreign-key
  column to the referenced model and key:

  ```php
  $fkMap = [
      'category_id' => ['\MyApp\Models\Categories', 'id'],
  ];
  ```

  It answers `GET …/fkOptions?field=category_id&q=…&page=…` with Select2's
  `{results: [{id, text}], pagination: {more}}`, reusing the referenced model's
  `_getApiList()` (20 per page) and taking the label from `name`, `title`, `label` or
  `username`. A foreign key to `users` maps to `\Pramnos\User\User` / `userid`. See
  [Select2 changes what a foreign-key field loads](Pramnos_Console_Guide.md#select2-changes-what-a-foreign-key-field-loads).

- **The SPA screen.** The field becomes a searchable picker against the referenced
  resource's own list endpoint.

### Data Type Handling

The generators write field assignments by column type.

In the web controller's `save()`:

```php
// Integer fields
$model->count = $request->get('count', '', 'post', 'int');

// Float fields
$model->price = (float) $request->get('price', '', 'post');

// Boolean fields
$model->active = (bool) $request->get('active', '', 'post');

// String fields (with sanitization)
$model->name = trim(strip_tags((string) $request->get('name', '', 'post')));
```

In the API controller's create action:

```php
// Integer fields (a nullable one turns 0 into null)
$model->count = \Pramnos\Http\Request::staticGet('count', 0, 'post', 'int');

// Boolean fields
$tmpVar = \Pramnos\Http\Request::staticGet('active', null, 'post');
if ($tmpVar == 'true' || $tmpVar == 'on' || $tmpVar == "yes" || $tmpVar === '1' || $tmpVar === 1) {
    $tmpVar = true;
} else {
    $tmpVar = false;
}
$model->active = $tmpVar;

// String fields (with sanitization; a nullable one keeps null)
$model->name = trim(strip_tags((string) \Pramnos\Http\Request::staticGet('name', '', 'post')));
```

### API Documentation Generation

Generated API controllers include comprehensive PHPDoc annotations compatible with API documentation tools:

```php
/**
 * @api {get} 1.0/user List
 * @apiVersion 1.0.0
 * @apiGroup User
 * @apiName listUser
 * @apiDescription List of User objects with pagination, search, sorting and field selection
 *
 * @apiHeader {String} apiKey Application unique api key
 * @apiHeader {String} accessToken Authenticated user access token
 *
 * @apiParam  {Number} [page=0] Page number for pagination
 * @apiParam  {Number} [limit=20] Limit number of results per page
 * @apiParam  {String} [sort] Sort by field. Syntax: [+-]fieldname,[+-]fieldname
 * @apiParam  {String} [search] Global search term or JSON field-specific search
 * @apiParam  {String} [fields] Comma-separated or JSON array of fields to return
 *
 * @apiSuccess {Array} data List of User objects
 * @apiSuccess {Object} [pagination] Pagination information (only when page > 0)
 * @apiSuccess {Number} pagination.currentpage Current page number
 * @apiSuccess {Number} pagination.itemsperpage Items per page
 * @apiSuccess {Number} pagination.totalitems Total number of items
 * @apiSuccess {Number} pagination.totalpages Total number of pages
 * @apiSuccess {Boolean} pagination.hasnext Whether there is a next page
 * @apiSuccess {Boolean} pagination.hasprevious Whether there is a previous page
 * @apiSuccess {Array} fields List of fields included in the response
 */
```
