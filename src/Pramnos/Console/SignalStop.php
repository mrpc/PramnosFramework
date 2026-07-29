<?php

namespace Pramnos\Console;

/**
 * Cooperative graceful-stop for long-running CLI loops.
 *
 * A daemon should not be cut off mid-job by SIGTERM/SIGINT (a deploy, `systemctl
 * stop`, or Ctrl+C): it should finish the unit of work in hand and then exit. This
 * installs async signal handlers that just raise a flag; the loop checks the flag
 * between jobs and returns, so the process shuts down at a safe point.
 *
 * A standalone primitive (like {@see WorkerLock}), so a bespoke worker script and
 * {@see CommandBase} can share one implementation instead of each hand-rolling the
 * same `pcntl_signal` dance. When the `pcntl` extension is unavailable the handlers
 * are simply not installed and the flag stays false (the loop relies on its other
 * exits — max-runtime, the supervisor's `.stop` sentinel, lease loss).
 */
class SignalStop
{
    private bool $requested = false;

    /** @var int[] */
    private array $signals;

    /** @var (callable(int|null): void)|null */
    private $onStop;

    /**
     * @param int[]                          $signals Signals to trap; defaults to SIGTERM+SIGINT when pcntl is present.
     * @param (callable(int|null): void)|null $onStop  Optional callback run when a stop is first requested.
     */
    public function __construct(array $signals = [], ?callable $onStop = null)
    {
        if ($signals === [] && function_exists('pcntl_signal')) {
            $signals = [SIGTERM, SIGINT];
        }

        $this->signals = $signals;
        $this->onStop = $onStop;
    }

    /**
     * Install the signal handlers. No-op (and harmless) when pcntl is unavailable.
     */
    public function install(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ($this->signals as $signal) {
            pcntl_signal($signal, function (int $received): void {
                $this->stop($received);
            });
        }
    }

    /**
     * Request a stop. Also usable directly (e.g. from a test or another handler),
     * not only via a trapped signal.
     */
    public function stop(?int $signal = null): void
    {
        $alreadyRequested = $this->requested;
        $this->requested = true;

        if (!$alreadyRequested && $this->onStop !== null) {
            ($this->onStop)($signal);
        }
    }

    /** Whether a stop has been requested (finish the current job, then exit). */
    public function requested(): bool
    {
        return $this->requested;
    }

    /** Clear the flag — for reuse across loops, or in tests. */
    public function reset(): void
    {
        $this->requested = false;
    }
}
