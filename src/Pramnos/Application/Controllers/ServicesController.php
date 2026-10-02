<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;

/**
 * Admin controller for monitoring and controlling registered daemon/worker services.
 *
 * Service lifecycle is managed by a CLI DaemonOrchestrator instance. This
 * controller reads from the shared state file that the orchestrator writes and
 * uses the stop-file sentinel mechanism to request graceful stops or restarts:
 *   - Stop:    creates `{lockFile}.stop` — the daemon exits on next heartbeat
 *   - Restart: removes `{lockFile}.stop` — orchestrator respawns on next cycle
 *   - Start:   same as restart (no-op if already running)
 *
 * Log files are read directly from `ROOT/var/logs/{daemon}-{workerId}.log`.
 *
 * IMPORTANT: The orchestrator CLI process must be running (`pramnos orchestrate`)
 * for start/restart to take effect. This controller cannot spawn processes directly.
 *
 * Actions: display, stop, start, restart, restartall, logs, status,
 *          poolsave, poolstop, poolstart, pooldelete
 * All actions require authentication + usertype >= 80; every change is a POST with the
 * session's token, and is written to the `auth` log with who made it.
 *
 * Scaffolded wrappers live at `src/Controllers/Services.php`.
 *
 */
class ServicesController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.services';

    /** Maximum lines returned by the logs() action. */
    protected int $maxLogLines = 200;

    /** Minimum usertype to access any services action. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'display', 'stop', 'start', 'restart', 'logs', 'status',
            'restartall', 'poolsave', 'poolstop', 'poolstart', 'pooldelete',
        ]);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['stop', 'start', 'restart', 'restartall', 'poolsave', 'poolstop', 'poolstart', 'pooldelete']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * HTML list of registered services with status, PID, uptime, and last-seen time.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Services';

        $view          = $this->getView('services');
        $view->services = $this->loadServiceList();
        // Whether the supervisor is up, because that is what decides if the buttons on
        // this page do anything at all — see orchestratorStatus().
        $view->orchestrator = $this->orchestratorStatus();
        // The pools as the supervisor last saw them, the batches they are working through,
        // and what an operator has stopped: the numbers a scaling decision is made from.
        $view->pools    = $this->loadPools();
        $view->batches  = $this->loadBatches();
        $view->stopped  = $this->controls()->stoppedServices();

        return $view->display();
    }

    /**
     * Request graceful stop for a service by ID.
     * Creates `{lockFile}.stop` — the worker exits on next heartbeat check.
     * Redirects back to display with an appropriate status query param.
     *
     * The service name comes from the URL segment, read the way every other
     * controller here reads one. It used to be declared `string $name` and taken
     * as an argument, which `Controller::exec()` cannot supply: it calls every
     * action with the request's arguments **array**, so the declaration made this
     * a guaranteed `TypeError` and the action unreachable. Each of the four
     * service controls had it, which is to say none of the buttons on the services
     * screen had ever worked.
     */
    public function stop(mixed $name = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $name = (string) \Pramnos\Http\Request::staticGetOption();
        $service = $this->findService($name);

        if ($service === null) {
            $this->addError('That record no longer exists.');
            $this->redirect(adminUrl('services'));
            return;
        }

        $lockFile = (string) ($service['lockFile'] ?? '');
        if ($lockFile === '') {
            $this->addError('That service has no lock file, so it does not appear to be running.');
            $this->redirect(adminUrl('services'));
            return;
        }

        /*
         * Recorded, then signalled. The stop file alone made the worker exit and the
         * supervisor — which still had it on its list — start it again a cycle later, so
         * "Stop" was a restart. Listed in pramnos.stopped_services it stays off the list.
         */
        $kept = $this->tryControl(fn (\Pramnos\Console\DaemonControls $c) => $c->stopService($name, $this->who()));
        file_put_contents($lockFile . '.stop', '1');
        $this->audit('stopped service ' . $name);
        $kept
            ? $this->addMessage('Stopped. It stays stopped until you start it.')
            : $this->addMessage('Stopped. The supervisor will start it again: run migrate so a stop can be kept.');
        $this->redirect(adminUrl('services'));
    }

    /**
     * Request service start (or resume after stop).
     * Removes `{lockFile}.stop` so the orchestrator will respawn the process
     * on its next reconciliation cycle. Has no effect if the service is already
     * running; the orchestrator itself is responsible for spawning new processes.
     */
    public function start(mixed $name = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        // See stop() for why the name is read here rather than taken as an argument.
        $name = (string) \Pramnos\Http\Request::staticGetOption();
        $this->tryControl(fn (\Pramnos\Console\DaemonControls $c) => $c->startService($name));
        $this->clearStopFile($name);
        $this->audit('started service ' . $name);
        $this->addMessage('Started. The supervisor brings it up on its next cycle.');
        $this->redirect(adminUrl('services'));
    }

    /**
     * Restart a service: it exits gracefully and the supervisor starts it again.
     *
     * Through the stop file, which is exactly what a restart is to a supervisor that still
     * wants the process. It used to *remove* the stop file — the same as Start — which did
     * nothing at all to a worker that was running, the only one anybody restarts. A stopped
     * service is started instead.
     */
    public function restart(mixed $name = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        // See stop() for why the name is read here rather than taken as an argument.
        $name    = (string) \Pramnos\Http\Request::staticGetOption();
        $service = $this->findService($name);
        $this->tryControl(fn (\Pramnos\Console\DaemonControls $c) => $c->startService($name));

        $lockFile = (string) ($service['lockFile'] ?? '');
        if ($service !== null && $lockFile !== '' && ($service['status'] ?? '') === 'running') {
            file_put_contents($lockFile . '.stop', '1');
        } else {
            $this->clearStopFile($name);
        }

        $this->audit('restarted service ' . $name);
        $this->addMessage('Restarting. It finishes its current task, exits, and the supervisor starts it again.');
        $this->redirect(adminUrl('services'));
    }

    /**
     * Restart every running service — for a deploy the supervisor cannot see.
     *
     * The supervisor restarts everything when git HEAD changes. A deploy that changes no
     * commit — `composer update`, a copied build, an edited setting a worker reads once —
     * leaves the old code running in every worker until somebody asks.
     */
    public function restartall(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $count = 0;
        foreach ($this->loadServiceList() as $service) {
            $lockFile = (string) ($service['lockFile'] ?? '');
            if ($lockFile !== '' && ($service['status'] ?? '') === 'running') {
                file_put_contents($lockFile . '.stop', '1');
                $count++;
            }
        }

        $this->audit('restarted all services (' . $count . ')');
        $this->addMessage('Restarting ' . $count . ' service(s). Each finishes its current task first.');
        $this->redirect(adminUrl('services'));
    }

    /**
     * Create a pool, or change one — a pool the code declares included.
     *
     * Blank fields are "as declared": a code pool keeps its own value for each. The supervisor
     * applies the change on its next cycle; nothing is spawned from this request.
     */
    public function poolsave(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $request = new \Pramnos\Http\Request();
        $name    = trim((string) $request->get('name', '', 'post'));
        $fields  = [];
        foreach (['types', 'floor', 'ceiling', 'grow_above', 'shrink_below', 'load_percent', 'cooldown'] as $key) {
            $fields[$key] = $request->get($key, '', 'post');
        }
        $existing          = $this->controls()->pools()[$name] ?? null;
        $fields['enabled'] = $existing['enabled'] ?? true;

        try {
            $this->controls()->savePool($name, $fields, $this->who());
        } catch (\InvalidArgumentException $exception) {
            $this->addError($exception->getMessage());
            $this->redirect(adminUrl('services'));
            return;
        } catch (\Throwable) {
            $this->addError('The pool could not be saved. Run migrate: the pools table may not exist yet.');
            $this->redirect(adminUrl('services'));
            return;
        }

        $this->audit('saved pool ' . $name);
        $this->addMessage('Saved. The supervisor applies it on its next cycle.');
        $this->redirect(adminUrl('services'));
    }

    /** Stop a pool: it runs no workers until started. */
    public function poolstop(): void
    {
        $this->setPool(false);
    }

    /** Start a stopped pool. */
    public function poolstart(): void
    {
        $this->setPool(true);
    }

    /**
     * Remove a pool's row: a pool from the screen goes, a code pool returns to its declaration.
     */
    public function pooldelete(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $name = (string) \Pramnos\Http\Request::staticGetOption();
        if (!$this->tryControl(fn (\Pramnos\Console\DaemonControls $c) => $c->deletePool($name))) {
            $this->addError('That pool could not be removed.');
            $this->redirect(adminUrl('services'));
            return;
        }

        $this->audit('removed pool ' . $name);
        $this->addMessage('Removed.');
        $this->redirect(adminUrl('services'));
    }

    /**
     * Return the last N lines of the log file for a service.
     * HTML view with pre-formatted log output.
     */
    public function logs(mixed $name = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        // See stop() for why the name is read here rather than taken as an argument.
        $name = (string) \Pramnos\Http\Request::staticGetOption();
        $service = $this->findService($name);

        if ($service === null) {
            $this->addError('That record no longer exists.');
            $this->redirect(adminUrl('services'));
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Service Logs — ' . htmlspecialchars($name, ENT_QUOTES);

        $view          = $this->getView('services');
        $view->service = $service;
        $view->lines   = $this->readLogTail($service);

        return $view->display('logs');
    }

    /**
     * JSON endpoint: summary status of all registered services.
     * Suitable for monitoring dashboards and health-check scripts.
     *
     * Response shape:
     *   {"total": int, "running": int, "stopped": int, "error": int, "services": [...]}
     */
    public function status(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $services = $this->loadServiceList();
        $counts   = ['running' => 0, 'stopped' => 0, 'error' => 0];

        foreach ($services as $svc) {
            $s = (string) ($svc['status'] ?? 'stopped');
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }

        // The document type, not only the header: with the default HTML
        // document the request went on to render the theme *after* this
        // action echoed, so the response was the JSON followed by a
        // complete web page — and `fetch(...).then(r => r.json())` throws
        // on that. Every AJAX widget on the dashboard was failing that way.
        \Pramnos\Framework\Factory::getDocument('json');
        header('Content-Type: application/json');
        echo json_encode([
            'total'        => count($services),
            'running'      => $counts['running'],
            'stopped'      => $counts['stopped'],
            'error'        => $counts['error'],
            'services'     => $services,
            // A monitor polling this endpoint wants to know about the supervisor before
            // it cares how many workers are down: with the supervisor gone, "0 running"
            // is the expected reading rather than an incident, and nothing this page can
            // do will change it.
            'orchestrator' => $this->orchestratorStatus(),
            'pools'        => $this->loadPools(),
            'batches'      => $this->loadBatches(),
            // Not `stopped`, which is the count above: these are the services an operator
            // stopped, which the supervisor will not start again.
            'stopped_by_operator' => array_keys($this->controls()->stoppedServices()),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────


    /**
     * Load service entries from the orchestrator state file, enriched with
     * live status (running/stopped/error) and uptime.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * A heartbeat this recent means the supervisor is cycling.
     *
     * 120 seconds against a default reconcile interval of 10: long enough that a busy cycle
     * or a slow disk does not read as death, short enough to notice one.
     */
    protected const HEARTBEAT_FRESH_SECONDS = 120;

    /**
     * Whether the supervisor itself is running.
     *
     * The screen's Stop, Start and Restart do not spawn or kill anything: they write and
     * remove a sentinel file next to the worker's lock, and the **orchestrator** is what
     * notices on its next cycle. With no orchestrator running, Stop still works — a daemon
     * checks its own stop file — and Start and Restart do nothing whatsoever. No error, no
     * message: the operator clicks, the page reloads, the service stays down.
     *
     * So the page says which of the two situations it is in. `DaemonOrchestrator::status()`
     * has existed for exactly this ("so an admin/API endpoint can report 'is the supervisor
     * up'") and nothing called it; this is the same reading, taken without an instance,
     * because a web request cannot construct the application's orchestrator subclass.
     *
     * `heartbeat_age_seconds` is the age of the state file, which the reconcile loop
     * rewrites every cycle — so a fresh mtime means the supervisor is not merely alive but
     * actively cycling. A live pid with a stale heartbeat is the third case worth naming:
     * the process is there and stuck, which looks identical to healthy from the pid alone.
     *
     * @return array{running:bool,pid:?int,heartbeat_age_seconds:?int}
     */
    protected function orchestratorStatus(): array
    {
        $lockFile = \Pramnos\Console\DaemonOrchestrator::orchestratorLockPath();
        $pid      = 0;

        if (is_file($lockFile)) {
            $content = @file_get_contents($lockFile);
            $pid     = $content === false ? 0 : max(0, (int) trim((string) $content));
        }

        $stateFile = \Pramnos\Console\DaemonOrchestrator::stateFilePath();
        $age       = is_file($stateFile)
            ? max(0, time() - (int) @filemtime($stateFile))
            : null;

        /*
         * Alive by its pid **or** by a heartbeat that is still moving.
         *
         * The pid alone was the answer, and a pid is only meaningful inside the namespace that
         * issued it. The supervisor's normal home is a container of its own — that is what
         * `pramnos init` now writes, and what this project runs — so the pid in the lock file
         * belongs to *its* namespace, and the web request checking it is in another. There the
         * number either matches something unrelated or nothing at all: a supervisor reported as
         * dead while it works, or as alive because pid 14 happens to be Apache.
         *
         * The state file is the signal that crosses the boundary: it is on the shared volume,
         * and the orchestrator rewrites it every reconcile cycle, so a recent mtime means not
         * merely alive but actively cycling. It cannot produce a false positive — a stopped
         * supervisor stops touching it.
         *
         * The pid check stays for the single-host case, and for the seconds between a
         * supervisor starting and its first cycle. A stale heartbeat with a live pid is left
         * as "running", deliberately: that is the stuck-supervisor state, and the screen has a
         * warning for it that is more useful than silence.
         */
        $running = ($pid > 0 && $this->processIsAlive($pid))
            || ($age !== null && $age <= self::HEARTBEAT_FRESH_SECONDS);

        return [
            'running'               => $running,
            'pid'                   => $running ? $pid : null,
            'heartbeat_age_seconds' => $age,
        ];
    }

    /**
     * Whether a pid is a process this host is running.
     *
     * `posix_kill($pid, 0)` where the extension is there, `/proc` where it is not. Not
     * `ps`: this runs on a web request, and shelling out per page load to answer a
     * question the kernel answers for free is how a status page becomes the slowest one on
     * the site.
     */
    protected function processIsAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            // EPERM (1) means the process exists and belongs to somebody else — which is
            // the normal case here: the orchestrator is usually started as a different
            // user than the web server runs as. Reading that as "not running" would put a
            // permanent warning on the page of every correctly configured installation.
            return posix_get_last_error() === 1;
        }

        return is_dir('/proc/' . $pid);
    }

    /**
     * Every pool: what the supervisor last saw of it, and any saved row it has not seen yet.
     *
     * A pool saved a moment ago is listed before the supervisor has acted on it, marked as
     * waiting, so the operator sees that the save worked and that the next cycle is pending.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadPools(): array
    {
        $seen = [];
        $file = \Pramnos\Console\DaemonOrchestrator::poolsFilePath();
        $json = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        foreach ((array) ($json['pools'] ?? []) as $pool) {
            if (is_array($pool) && isset($pool['name'])) {
                $seen[(string) $pool['name']] = $pool + ['row' => null, 'waiting' => false];
            }
        }

        foreach ($this->controls()->pools() as $name => $row) {
            if (isset($seen[$name])) {
                $seen[$name]['row'] = $row;
                // Saved after the supervisor's last reading: what it shows is about to change.
                $seen[$name]['waiting'] = $row['updated_at'] > (int) ($json['at'] ?? 0);
                continue;
            }
            $seen[$name] = [
                'name' => $name, 'source' => 'screen', 'enabled' => $row['enabled'],
                'types' => $row['types'] === null ? [] : explode(',', $row['types']),
                'size' => null, 'backlog' => null, 'load' => null, 'decision' => '',
                'config' => \Pramnos\Console\DaemonControls::asPoolConfig($row),
                'row' => $row, 'waiting' => true,
            ];
        }

        ksort($seen);

        return array_values($seen);
    }

    /**
     * The batches of the last day, or none when this installation has no queue.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadBatches(): array
    {
        try {
            return (new \Pramnos\Queue\QueueManager($this))->recentBatches();
        } catch (\Throwable) {
            return [];
        }
    }

    /** The screen's controls, against the application's database. */
    protected function controls(): \Pramnos\Console\DaemonControls
    {
        return new \Pramnos\Console\DaemonControls();
    }

    /**
     * Apply a change to the controls, and say whether it could be kept.
     *
     * False when the tables are not there yet or the database refused — the caller says so
     * rather than claiming a decision was saved.
     *
     * @param callable(\Pramnos\Console\DaemonControls): mixed $change
     */
    private function tryControl(callable $change): bool
    {
        try {
            $change($this->controls());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Stop or start the pool named in the URL. */
    private function setPool(bool $enabled): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $name = (string) \Pramnos\Http\Request::staticGetOption();
        if (!$this->tryControl(fn (\Pramnos\Console\DaemonControls $c) => $c->setPoolEnabled($name, $enabled, $this->who()))) {
            $this->addError('That pool could not be changed. Run migrate: the pools table may not exist yet.');
            $this->redirect(adminUrl('services'));
            return;
        }

        $this->audit(($enabled ? 'started' : 'stopped') . ' pool ' . $name);
        $this->addMessage($enabled ? 'Started.' : 'Stopped. Its workers finish their current task and exit.');
        $this->redirect(adminUrl('services'));
    }

    /** The signed-in operator, as recorded beside what they changed. */
    private function who(): string
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return $user !== null && (int) ($user->userid ?? 0) > 0
            ? (string) ($user->username ?? ('user ' . (int) $user->userid))
            : '';
    }

    /**
     * Record a change to the workers where the other administrative actions are recorded.
     */
    protected function audit(string $what): void
    {
        \Pramnos\Logs\Logger::log(
            'Services: ' . $what . ' by ' . ($this->who() ?: 'an unknown user')
            . ' from ' . (string) ($_SERVER['REMOTE_ADDR'] ?? '?'),
            'auth'
        );
    }

    private function loadServiceList(): array
    {
        $stateFile = \Pramnos\Console\DaemonOrchestrator::stateFilePath();

        if (!file_exists($stateFile)) {
            return [];
        }

        $json = @file_get_contents($stateFile);
        if ($json === false || $json === '') {
            return [];
        }

        $state = json_decode($json, true);
        if (!is_array($state)) {
            return [];
        }

        $services = [];
        foreach ($state as $item) {
            $services[] = $this->enrichServiceEntry((array) $item);
        }

        return $services;
    }

    /**
     * Enrich a raw state entry with computed status, uptime, and memory.
     *
     * Status values:
     *   running — process alive, lock file present, no stop file
     *   stopped — stop file present OR lock file absent
     *   error   — stop file absent but process not alive
     *
     * @param  array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function enrichServiceEntry(array $item): array
    {
        $pid      = (int)    ($item['pid']      ?? 0);
        $lockFile = (string) ($item['lockFile'] ?? '');
        $daemon   = (string) ($item['daemon']   ?? '');
        $workerId = (string) ($item['workerId'] ?? '');

        $hasLock   = $lockFile !== '' && file_exists($lockFile);
        $hasStop   = $lockFile !== '' && file_exists($lockFile . '.stop');
        $pidAlive  = $pid > 0 && $this->isProcessRunning($pid);

        if ($hasStop) {
            $status = 'stopped';
        } elseif ($hasLock && $pidAlive) {
            $status = 'running';
        } elseif (!$hasLock && !$hasStop) {
            $status = 'stopped';
        } else {
            $status = 'error';
        }

        $logFile     = $this->resolveLogFile($daemon, $workerId);
        $lastSeenTs  = ($hasLock && file_exists($lockFile)) ? filemtime($lockFile) : null;
        $uptimeMs    = ($lastSeenTs !== null && $status === 'running')
            ? (time() - $lastSeenTs)
            : null;

        return array_merge($item, [
            'status'    => $status,
            'pid_alive' => $pidAlive,
            'has_stop'  => $hasStop,
            'log_file'  => $logFile,
            'last_seen' => $lastSeenTs,
            'uptime_s'  => $uptimeMs,
        ]);
    }

    /**
     * Find a service by its `id` from the state file.
     *
     * @return array<string, mixed>|null
     */
    private function findService(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        foreach ($this->loadServiceList() as $svc) {
            if (($svc['id'] ?? '') === $id) {
                return $svc;
            }
        }

        return null;
    }

    /**
     * Remove the stop sentinel for a service, allowing the orchestrator to respawn it.
     */
    private function clearStopFile(string $id): void
    {
        $service = $this->findService($id);
        if ($service === null) {
            return;
        }

        $lockFile = (string) ($service['lockFile'] ?? '');
        if ($lockFile !== '') {
            $stopFile = $lockFile . '.stop';
            if (file_exists($stopFile)) {
                @unlink($stopFile);
            }
        }
    }

    /**
     * Read the last $maxLogLines lines from the service log file.
     *
     * @param  array<string, mixed> $service
     * @return string[]
     */
    private function readLogTail(array $service): array
    {
        $logFile = (string) ($service['log_file'] ?? '');

        if ($logFile === '' || !file_exists($logFile)) {
            return [];
        }

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        return array_values(array_slice($lines, -$this->maxLogLines));
    }

    /**
     * Returns the expected log file path for a daemon/workerId pair.
     * Matches the pattern used by DaemonOrchestrator::getProcessLogFile().
     */
    private function resolveLogFile(string $daemon, string $workerId): string
    {
        $base = defined('ROOT') ? ROOT : sys_get_temp_dir();
        return $base . '/var/logs/' . $daemon . '-' . $workerId . '.log';
    }

    /**
     * Check whether a process with the given PID is currently running.
     * Uses /proc on Linux; falls back to posix_kill signal 0 if available.
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (is_dir('/proc/' . $pid)) {
            return true;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return false;
    }
}
