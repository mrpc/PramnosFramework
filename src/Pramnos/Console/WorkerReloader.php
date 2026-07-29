<?php

namespace Pramnos\Console;

/**
 * Keeps a long-running worker current, because a PHP daemon otherwise runs the
 * code and the configuration it started with forever.
 *
 * Two different problems with two different answers:
 *
 * - **Settings** can be picked up in place. Whatever a worker derives from
 *   configuration at construction (an API client, a key, a model, a budget) is a
 *   snapshot; the worker watches a version stamp and rebuilds those objects when
 *   it moves, without dropping its lock or losing queued work. The stamp source
 *   is application-specific, so it is supplied as a resolver callback.
 * - **Code** cannot be reloaded into a running PHP process at all, so the reload
 *   has to be a new process. Under a supervisor, exiting cleanly between jobs is
 *   enough (`Restart=always`); with nothing supervising it, exiting would just
 *   stop the worker, so it starts its own replacement instead. Nothing is lost
 *   either way: durable jobs live in the queue and the lock is released on exit.
 *
 * This is a standalone primitive with no application coupling: the watched paths
 * and the settings-version source are both constructor parameters, so a bespoke
 * worker script and a {@see CommandBase} command can share it.
 */
class WorkerReloader
{
    /**
     * Files whose modification means the running process is out of date, relative
     * to the project root. A sensible default; pass your own to the constructor.
     */
    public const DEFAULT_WATCHED = ['src', 'composer.lock'];

    private string $root;

    /** @var string[] */
    private array $watched;

    /** @var (callable(): string)|null */
    private $settingsVersionResolver;

    private ?string $codeFingerprint = null;
    private ?string $settingsVersion = null;

    /**
     * @param string|null                 $root                    Project root (defaults to two levels up from here).
     * @param string[]                    $watched                 Watched paths relative to $root.
     * @param (callable(): string)|null   $settingsVersionResolver Returns a stamp that moves when settings change;
     *                                                             null disables settings-reload tracking.
     */
    public function __construct(
        ?string $root = null,
        array $watched = self::DEFAULT_WATCHED,
        ?callable $settingsVersionResolver = null
    ) {
        $this->root = rtrim($root ?? dirname(__DIR__, 4), '/');
        $this->watched = $watched;
        $this->settingsVersionResolver = $settingsVersionResolver;
    }

    // ------------------------------------------------------------------
    // Code
    // ------------------------------------------------------------------

    /**
     * A fingerprint of the code the process is running: file names, sizes and
     * modification times of everything watched.
     *
     * Content hashing would be more precise and much more expensive on every tick;
     * size plus mtime changes on any edit a deploy makes.
     */
    public function codeFingerprint(): string
    {
        $parts = [];

        foreach ($this->watched as $relative) {
            $path = $this->root . '/' . $relative;

            if (is_dir($path)) {
                $files = glob($path . '/*.php') ?: [];
                sort($files);
                foreach ($files as $file) {
                    $parts[] = basename($file) . ':' . filesize($file) . ':' . filemtime($file);
                }
                continue;
            }

            if (is_file($path)) {
                $parts[] = $relative . ':' . filesize($path) . ':' . filemtime($path);
            }
        }

        return md5(implode('|', $parts));
    }

    /**
     * Remember the current code (and settings) as the baseline. Call once at startup.
     */
    public function baseline(): void
    {
        $this->codeFingerprint = $this->codeFingerprint();
        $this->settingsVersion = $this->settingsVersion();
    }

    /**
     * Whether the code on disk has changed since the baseline. The caller should
     * finish what it is doing, release its lock and exit; the supervisor brings it
     * back on the new code.
     */
    public function codeChanged(): bool
    {
        if ($this->codeFingerprint === null) {
            $this->baseline();

            return false;
        }

        return $this->codeFingerprint() !== $this->codeFingerprint;
    }

    /**
     * Whether something will restart this process if it exits.
     *
     * It matters because "exit and let the supervisor restart me" is only a reload
     * when a supervisor exists. Run by hand, or in a container with no restart
     * policy, exiting would just stop the worker - so the caller respawns itself
     * instead.
     *
     * systemd sets INVOCATION_ID for every unit it starts, and NOTIFY_SOCKET under
     * Type=notify; supervisord sets SUPERVISOR_ENABLED. Docker's restart policy is
     * invisible from inside the container, hence the explicit override.
     *
     * @param array<string,mixed>|null $env Defaults to the real environment
     */
    public static function isSupervised(?array $env = null): bool
    {
        // Not array_map('strval', ...): $_SERVER holds arrays too (argv).
        $env ??= $_SERVER;

        foreach (['INVOCATION_ID', 'NOTIFY_SOCKET', 'SUPERVISOR_ENABLED', 'WORKER_SUPERVISED'] as $marker) {
            $value = $env[$marker] ?? getenv($marker);

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * A stamp that moves whenever the settings change, from the resolver passed to
     * the constructor. With no resolver, settings-reload tracking is disabled and
     * this is a constant.
     */
    public function settingsVersion(): string
    {
        if ($this->settingsVersionResolver === null) {
            return 'none';
        }

        try {
            $stamp = ($this->settingsVersionResolver)();

            return is_string($stamp) && $stamp !== '' ? $stamp : 'none';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Whether the settings have changed since the baseline. Consumes the change:
     * the caller rebuilds whatever it derived from settings, and the next call
     * reports false again.
     */
    public function settingsChanged(): bool
    {
        $current = $this->settingsVersion();

        if ($this->settingsVersion === null) {
            $this->settingsVersion = $current;

            return false;
        }

        if ($current === $this->settingsVersion) {
            return false;
        }

        $this->settingsVersion = $current;

        return true;
    }
}
