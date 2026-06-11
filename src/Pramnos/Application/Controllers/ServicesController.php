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

        return $view->display();
    }

    /**
     * Request graceful stop for a service by ID.
     * Creates `{lockFile}.stop` — the worker exits on next heartbeat check.
     * Redirects back to display with an appropriate status query param.
     */
    public function stop(string $name = ''): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $service = $this->findService($name);

        if ($service === null) {
            $this->redirect(sURL . 'services?error=not_found');
            return;
        }

        $lockFile = (string) ($service['lockFile'] ?? '');
        if ($lockFile === '') {
            $this->redirect(sURL . 'services?error=no_lock_file');
            return;
        }

        file_put_contents($lockFile . '.stop', '1');
        $this->redirect(sURL . 'services?message=stopped');
    }

    /**
     * Request service start (or resume after stop).
     * Removes `{lockFile}.stop` so the orchestrator will respawn the process
     * on its next reconciliation cycle. Has no effect if the service is already
     * running; the orchestrator itself is responsible for spawning new processes.
     */
    public function start(string $name = ''): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $this->clearStopFile($name);
        $this->redirect(sURL . 'services?message=started');
    }

    /**
     * Request a service restart: removes the stop sentinel so the orchestrator
     * will respawn the process. If the service is currently running, the existing
     * process continues until its next heartbeat sees a changed state.
     */
    public function restart(string $name = ''): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $this->clearStopFile($name);
        $this->redirect(sURL . 'services?message=restarted');
    }

    /**
     * Return the last N lines of the log file for a service.
     * HTML view with pre-formatted log output.
     */
    public function logs(string $name = ''): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $service = $this->findService($name);

        if ($service === null) {
            $this->redirect(sURL . 'services?error=not_found');
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

        header('Content-Type: application/json');
        echo json_encode([
            'total'    => count($services),
            'running'  => $counts['running'],
            'stopped'  => $counts['stopped'],
            'error'    => $counts['error'],
            'services' => $services,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Redirects to sURL if the current user's usertype is below $minType.
     * Returns true if the redirect was issued (caller should return early).
     */
    protected function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if ($user === null || $user === false || (int) $user->usertype < $minType) {
            $this->redirect(sURL);
            return true;
        }

        return false;
    }

    /**
     * Load service entries from the orchestrator state file, enriched with
     * live status (running/stopped/error) and uptime.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadServiceList(): array
    {
        $base      = defined('ROOT') ? ROOT : sys_get_temp_dir();
        $stateFile = $base . '/var/daemon_orchestrator_state.json';

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
