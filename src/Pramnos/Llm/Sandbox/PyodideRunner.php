<?php

declare(strict_types=1);

namespace Pramnos\Llm\Sandbox;

/**
 * One Python script, run in Pyodide (CPython compiled to WebAssembly) under Node.js
 * (`resources/js/sandbox-runner/run.mjs`), inside an empty network namespace (`unshare --net`)
 * wrapped in a memory/CPU-bounded `systemd-run --user --scope`, where each is reachable, and
 * killed outright past its wall-clock seconds either way.
 *
 * **A prototype, not a hardened sandbox** — see docs/Pramnos_LLM_Analyst_Guide.md, "code_exec",
 * for what this still does not do (pre-bundled packages for an offline worker, a fallback when
 * `systemd-run` or `unshare` are themselves not reachable). Needs on the worker it runs on:
 * Node.js, `npm install` run once in `resources/js/sandbox-runner/`, a user session `systemd-run
 * --user` can reach (`loginctl enable-linger`, where the worker is not an interactive login), and
 * an unprivileged user able to make its own network namespace (`unshare --net --map-root-user`;
 * the ordinary case on Linux — see `/proc/sys/kernel/unprivileged_userns_clone` where it is not).
 *
 * Measured, not assumed, each of these — not one taken on faith:
 *
 * - `node --max-old-space-size` does **not** bound Pyodide's own WebAssembly memory (a script was
 *   seen past 4 GB with it set to 128 MB) — only a cgroup (`systemd-run … -p MemoryMax=…`)
 *   actually stops it, with a clean kill at the limit. `ulimit -v` does not work with Node at
 *   all: V8 reserves a large virtual address space up front and aborts before running anything.
 * - A fork bomb cannot happen through Pyodide's `os.fork()` — WebAssembly has no such syscall, so
 *   it raises `OSError` immediately, with nothing to cap.
 * - Python's own `socket`/`urllib` do not reach the real network under Node.js at all —
 *   `connect()` succeeds without performing one, and `recv()` never returns real bytes, isolated
 *   or not — so a network namespace buys that path nothing. What a network namespace does stop:
 *   **`js.fetch(...)`**, Pyodide's own escape hatch into Node's real `fetch()` through its
 *   JavaScript interop (`import js`) — confirmed reaching a real site without isolation, and
 *   failing with `TypeError: fetch failed` once wrapped in `unshare --net --map-root-user`, with
 *   the cgroup scope's wall-clock kill unaffected by the extra process in between. The order
 *   matters: `systemd-run` outside, `unshare` inside — the other way round, `systemd-run --user`
 *   talks to the systemd user manager over its own socket and the child it starts inherits
 *   *that* process's network namespace, not the caller's.
 */
final class PyodideRunner
{
    /** Seconds a run may take before it is killed outright, unless the caller asks for less. */
    public const DEFAULT_TIMEOUT_SECONDS = 20;

    /**
     * The cgroup's memory ceiling, unless the caller asks for less. Measured, not guessed:
     * Pyodide's core interpreter alone fits in 150 MB, but loading pandas (the point of this
     * tool) pushes the Node process past 400 MB RSS before a line of the asked-for code runs —
     * 256 MB looked reasonable and OOM-killed every pandas call.
     */
    public const DEFAULT_MEMORY_MB = 512;

    public function __construct(private readonly string $runnerDir)
    {
    }

    /** `resources/js/sandbox-runner`, wherever this package itself is — the repo root, or vendored. */
    public static function default(): self
    {
        return new self(dirname(__DIR__, 4) . '/resources/js/sandbox-runner');
    }

    /** Whether a run here could work at all: the runner script and its own `node_modules` exist. */
    public function available(): bool
    {
        return is_file($this->runnerDir . '/run.mjs') && is_dir($this->runnerDir . '/node_modules/pyodide') && self::has('node');
    }

    /**
     * Run `$code`, with `$input` written to `/tmp/input.csv` first where it is given.
     *
     * @return array{ok: bool, result?: string, error?: string}
     */
    public function run(string $code, ?string $input, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS, int $memoryMb = self::DEFAULT_MEMORY_MB): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'error' => 'There is no sandbox runtime here — Node.js and resources/js/sandbox-runner/node_modules are needed on this worker.'];
        }

        $dir = sys_get_temp_dir() . '/pf-code-exec-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true)) {
            return ['ok' => false, 'error' => 'Nowhere to write the script.'];
        }
        file_put_contents($dir . '/code.py', $code);
        if ($input !== null) {
            file_put_contents($dir . '/input.csv', $input);
        }

        try {
            return $this->exec($this->command($dir, $memoryMb), max(1, $timeoutSeconds));
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * The command line: `node` itself, wrapped in an empty network namespace where `unshare` can
     * make one, that whole thing wrapped in a memory/CPU-bounded cgroup scope where `systemd-run
     * --user` is reachable. Either wrapper missing is a clean fallback to what remains — never a
     * refusal to run at all — since {@see run()}'s own wall-clock timeout stops it either way.
     *
     * @return list<string>
     */
    private function command(string $dir, int $memoryMb): array
    {
        $node = ['node', $this->runnerDir . '/run.mjs', $dir];

        $sandboxed = self::canUnshareNet()
            ? ['unshare', '--net', '--map-root-user', '--', ...$node]
            : $node;

        if (!self::has('systemd-run')) {
            return $sandboxed;
        }

        return [
            'systemd-run', '--user', '--scope', '--quiet',
            '-p', 'MemoryMax=' . max(64, $memoryMb) . 'M',
            '-p', 'MemorySwapMax=0',
            '--', ...$sandboxed,
        ];
    }

    /**
     * Runs `$command`, reading its stdout/stderr as they come so the pipes never fill and block
     * it, and kills it outright — `SIGKILL`, not the signal's default `SIGTERM` a sandboxed
     * script could catch and ignore — once `$timeoutSeconds` passes.
     *
     * @param list<string> $command
     * @return array{ok: bool, result?: string, error?: string}
     */
    private function exec(array $command, int $timeoutSeconds): array
    {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->runnerDir);
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'Could not start the sandbox.'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out      = '';
        $err      = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $killed   = false;
        $ended    = null;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $ended = $status;
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                $killed = true;
                break;
            }
            $read = array_values(array_filter([$pipes[1], $pipes[2]], static fn ($p) => is_resource($p)));
            $write = $except = null;
            if ($read !== [] && @stream_select($read, $write, $except, 0, 200_000) > 0) {
                foreach ($read as $pipe) {
                    if ($pipe === $pipes[1]) {
                        $out .= (string) fread($pipe, 65536);
                    } else {
                        $err .= (string) fread($pipe, 65536);
                    }
                }
            }
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($killed) {
            return ['ok' => false, 'error' => 'Stopped: took longer than ' . $timeoutSeconds . ' seconds.'];
        }

        $decoded = json_decode(trim($out), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Ended on its own, by a signal it did not catch, with nothing to say why: the cgroup's
        // own memory ceiling is the ordinary cause, since that is exactly how it stops a process.
        $bySignal = is_array($ended) && !empty($ended['signaled']);

        return ['ok' => false, 'error' => $err !== '' ? trim($err)
            : ($bySignal ? 'Stopped, most likely for using more memory than it was allowed.' : 'The sandbox answered nothing readable.')];
    }

    /** Whether `$binary` is on the `PATH` here. */
    private static function has(string $binary): bool
    {
        $found = [];
        $code  = 1;
        @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $found, $code);

        return $code === 0 && $found !== [];
    }

    /**
     * Whether an unprivileged network namespace can actually be made here, cached for the rest
     * of the process — a binary on the `PATH` is not enough to know. Measured: a container's own
     * seccomp profile, or `kernel.unprivileged_userns_clone = 0`, lets `unshare` run at all and
     * still refuses it `unshare failed: Operation not permitted` the moment it is asked to make
     * one — found running this very check inside this framework's own `./dockertest` image.
     */
    private static function canUnshareNet(): bool
    {
        static $can = null;
        if ($can === null) {
            $code = 1;
            @exec('unshare --net --map-root-user -- true 2>/dev/null', $unused, $code);
            $can = $code === 0;
        }

        return $can;
    }

    /** The work directory and whatever was written to it. */
    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
