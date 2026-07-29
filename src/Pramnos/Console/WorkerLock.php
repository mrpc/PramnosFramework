<?php

namespace Pramnos\Console;

/**
 * Single-instance lock + heartbeat for long-running CLI workers, in plain PHP.
 *
 * No `flock()`: advisory locks are silently ineffective on some filesystems —
 * notably Docker bind mounts on macOS, where two processes both "acquire" the
 * same lock without any error. Instead the lock is a small JSON file whose
 * *creation* is the atomic step (`fopen($path, 'x')` maps to O_CREAT|O_EXCL,
 * which works everywhere), and the same file doubles as the worker's heartbeat:
 *
 *   {"name":"worker","pid":79,"host":"web1","started_at":...,
 *    "heartbeat_at":...,"status":"running","jobs_processed":12,
 *    "current_job":null,"last_job":"..."}
 *
 * Because the file is not kernel-held, a crashed worker would leave it behind;
 * that is handled by taking the lock over when the holder is provably gone — its
 * pid is dead (checked on the same host) or it stopped writing heartbeats for
 * longer than the stale threshold. Being alive but not progressing ("wedged") is
 * a distinct state a plain pid check would miss.
 *
 * This is a standalone, composable primitive: a bespoke worker script uses it
 * directly (`new WorkerLock('worker', $path)`), and {@see CommandBase} delegates
 * its lock lifecycle to it so console workers get the same guarantees.
 *
 * The path is supplied by the caller (CommandBase passes its job-lock path); the
 * scoping of that path — per install, per host, etc. — is the caller's concern,
 * so this class carries no application coupling.
 */
class WorkerLock
{
    /**
     * A holder that has not written a heartbeat for this long is treated as dead
     * and its lock can be taken over. Keep it comfortably above the time a single
     * unit of work can block.
     */
    public const DEFAULT_STALE_AFTER = 120;

    private string $name;
    private string $path;
    private int $staleAfter;
    private bool $held = false;
    private int $startedAt = 0;

    /** @var array<string,mixed> */
    private array $state = [];

    public function __construct(
        string $name = 'worker',
        ?string $path = null,
        int $staleAfter = self::DEFAULT_STALE_AFTER
    ) {
        $this->name = $name;
        $this->path = $path !== null && $path !== '' ? $path : self::defaultPath($name);
        $this->staleAfter = max(10, $staleAfter);
    }

    /**
     * A default lock path under $dir (ROOT/var when available, else the system
     * temp directory): `<dir>/<name>.lock`. Callers that run several installs on
     * one host should scope $name themselves (or pass an explicit $path).
     */
    public static function defaultPath(string $name, ?string $dir = null): string
    {
        if ($dir === null || $dir === '') {
            $dir = defined('ROOT') ? \ROOT . '/var' : sys_get_temp_dir();
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = sys_get_temp_dir();
        }

        return rtrim($dir, '/') . '/' . $name . '.lock';
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getStaleAfter(): int
    {
        return $this->staleAfter;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    /**
     * Take the lock.
     *
     * Returns false when a live worker already holds it. A lock left behind by a
     * dead or wedged worker is taken over; $takenOverFrom then describes who it
     * belonged to, for logging.
     */
    public function acquire(?string &$takenOverFrom = null): bool
    {
        $takenOverFrom = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            // Atomic create: exactly one process can win this, on any filesystem.
            $handle = @fopen($this->path, 'x');

            if ($handle !== false) {
                fclose($handle);
                $this->held = true;
                $this->startedAt = time();
                $this->state = [];
                $this->write(['status' => 'running', 'jobs_processed' => 0]);

                return true;
            }

            // Someone else owns the file. Give up only if that worker is both
            // alive and making progress; a dead or wedged one is taken over.
            $state = $this->readState();

            if ($state !== null && $this->holderIsAlive($state) && $this->holderIsProgressing($state)) {
                return false;
            }

            $takenOverFrom = $this->describeHolder($state);

            // Remove the abandoned lock and race for it again. If another worker
            // wins that race, the second pass sees a live holder and gives up.
            if (!@unlink($this->path) && file_exists($this->path)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Refresh the heartbeat.
     *
     * Returns false when the lock is no longer ours — another worker considered
     * us dead and took over — so the caller can stop instead of running twice.
     *
     * @param array<string,mixed> $extra Extra fields to record (jobs_processed, last_job, ...)
     */
    public function heartbeat(array $extra = []): bool
    {
        if (!$this->held) {
            return false;
        }

        $state = $this->readState();

        if ($state !== null && (int) ($state['pid'] ?? 0) !== getmypid()) {
            $this->held = false;

            return false;
        }

        $this->write($extra);

        return true;
    }

    /**
     * Release the lock. The state file is kept (marked stopped) so `status` can
     * still report how the last run ended; callers that prefer removal unlink the
     * path afterwards (see {@see getPath()}).
     */
    public function release(string $status = 'stopped'): void
    {
        if (!$this->held) {
            return;
        }

        $this->write(['status' => $status, 'current_job' => null]);
        $this->held = false;
    }

    /**
     * Whether another worker holds the lock and its process is still alive (true
     * for a wedged worker too — see {@see holderIsWedged()}).
     */
    public function isHeldByAnother(): bool
    {
        if ($this->held) {
            return false;
        }

        $state = $this->readState();

        return $state !== null && $this->holderIsAlive($state);
    }

    /**
     * The holder's process is alive but it stopped writing heartbeats: alive and
     * stuck, which is exactly the case a plain pid check would miss.
     */
    public function holderIsWedged(): bool
    {
        if ($this->held) {
            return false;
        }

        $state = $this->readState();

        return $state !== null
            && $this->holderIsAlive($state)
            && !$this->holderIsProgressing($state);
    }

    /**
     * The pid recorded in a lock file, or 0 when absent/unreadable. Reads the JSON
     * `pid` field, falling back to the first all-digit line so a legacy plain-text
     * lock (just "<pid>\n...") is still understood.
     */
    public static function pidFromFile(string $path): int
    {
        if (!file_exists($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return 0;
        }
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded) && isset($decoded['pid'])) {
            return max(0, (int) $decoded['pid']);
        }
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && ctype_digit($line)) {
                return (int) $line;
            }
        }
        return 0;
    }

    /**
     * Last written state, or null when the worker never ran here. Readers do not
     * lock, so a read can land mid-update; a failed decode is retried a couple of
     * times before giving up.
     *
     * @return array<string,mixed>|null
     */
    public function readState(): ?array
    {
        if (!file_exists($this->path)) {
            return null;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = @file_get_contents($this->path);

            if ($raw === false) {
                return null;
            }

            $raw = trim($raw);

            if ($raw !== '') {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            usleep(50000);
        }

        // Present but unreadable (or a legacy plain-text lock): report an unknown
        // holder with the file's age so acquire() can decide by staleness.
        return ['status' => 'unknown', 'heartbeat_at' => @filemtime($this->path) ?: 0];
    }

    /**
     * Whether the supervisor has asked this worker to stop (a `<path>.stop`
     * sentinel). A request, not a signal: the worker finishes the job in hand and
     * exits, and the supervisor fills the slot again on its next cycle.
     */
    public function stopRequested(): bool
    {
        return file_exists($this->path . '.stop');
    }

    /**
     * Seconds since the last heartbeat, or null when unknown.
     *
     * @param array<string,mixed>|null $state
     */
    public function heartbeatAge(?array $state = null): ?int
    {
        $state ??= $this->readState();

        if ($state === null || empty($state['heartbeat_at'])) {
            return null;
        }

        return max(0, time() - (int) $state['heartbeat_at']);
    }

    /**
     * Is the process that wrote this state still running? Checked by pid, but only
     * when the state was written on this host — a pid from another machine says
     * nothing about a local process, so there we fall back to heartbeat freshness.
     *
     * @param array<string,mixed> $state
     */
    private function holderIsAlive(array $state): bool
    {
        $status = (string) ($state['status'] ?? '');

        if ($status !== 'running' && $status !== 'unknown') {
            return false; // released cleanly
        }

        $pid = (int) ($state['pid'] ?? 0);
        $sameHost = ($state['host'] ?? null) === (gethostname() ?: '');

        if ($pid > 0 && $sameHost) {
            return $this->processExists($pid);
        }

        return $this->holderIsProgressing($state);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function holderIsProgressing(array $state): bool
    {
        $age = $this->heartbeatAge($state);

        return $age !== null && $age <= $this->staleAfter;
    }

    private function processExists(int $pid): bool
    {
        // Signal 0 checks for existence without signalling.
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        if (is_dir('/proc')) {
            return is_dir('/proc/' . $pid);
        }
        return true; // cannot tell — assume alive and stay safe
    }

    /**
     * @param array<string,mixed>|null $state
     */
    private function describeHolder(?array $state): string
    {
        if ($state === null) {
            return 'an unreadable lock file';
        }

        $age = $this->heartbeatAge($state);

        return sprintf(
            'pid %s on %s (status %s, last heartbeat %s)',
            (string) ($state['pid'] ?? '?'),
            (string) ($state['host'] ?? '?'),
            (string) ($state['status'] ?? '?'),
            $age === null ? 'unknown' : $age . 's ago'
        );
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function write(array $extra): void
    {
        $this->state = array_merge(
            [
                'name' => $this->name,
                'pid' => getmypid(),
                'host' => gethostname() ?: '',
                'started_at' => $this->startedAt,
                'status' => 'running',
                'jobs_processed' => 0,
            ],
            $this->state,
            $extra,
            ['heartbeat_at' => time()]
        );

        $json = json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            return;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $json . "\n");
        fflush($handle);
        fclose($handle);
    }
}
