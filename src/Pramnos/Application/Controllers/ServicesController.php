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
 * Actions: display, stop, start, restart, logs, status
 * All actions require authentication + usertype >= 80.
 *
 * Scaffolded wrappers live at `src/Controllers/Services.php`.
 *
 */
class ServicesController extends Controller
{
    /** Maximum lines returned by the logs() action. */
    protected int $maxLogLines = 200;

    /** Minimum usertype to access any services action. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'stop', 'start', 'restart', 'logs', 'status']);
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

        file_put_contents($lockFile . '.stop', '1');
        $this->addMessage('Stopped.');
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
        $this->clearStopFile($name);
        $this->addMessage('Started.');
        $this->redirect(adminUrl('services'));
    }

    /**
     * Request a service restart: removes the stop sentinel so the orchestrator
     * will respawn the process. If the service is currently running, the existing
     * process continues until its next heartbeat sees a changed state.
     */
    public function restart(mixed $name = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        // See stop() for why the name is read here rather than taken as an argument.
        $name = (string) \Pramnos\Http\Request::staticGetOption();
        $this->clearStopFile($name);
        $this->addMessage('Restarted.');
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

        $running = $pid > 0 && $this->processIsAlive($pid);

        $stateFile = \Pramnos\Console\DaemonOrchestrator::stateFilePath();
        $age       = is_file($stateFile)
            ? max(0, time() - (int) @filemtime($stateFile))
            : null;

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
