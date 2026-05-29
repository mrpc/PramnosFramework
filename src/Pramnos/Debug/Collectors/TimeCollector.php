<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Collects wall-clock timing for the current request and any named timers.
 *
 * DebugBar::startTimer('name') / DebugBar::stopTimer('name') feed into this
 * collector. The request start time is set when the collector is created
 * (typically at middleware boot time).
 *
 */
class TimeCollector implements CollectorInterface
{
    private float $startTime;

    /** @var array<string, array{start: float, end: float|null}> */
    private array $timers = [];

    public function __construct(?float $startTime = null)
    {
        // Prefer the PHP request start time so the timeline covers the full
        // request lifecycle rather than just the DebugBar boot moment.
        $this->startTime = $startTime
            ?? (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true));
    }

    public function name(): string
    {
        return 'timers';
    }

    public function startTimer(string $name): void
    {
        $this->timers[$name] = ['start' => microtime(true), 'end' => null];
    }

    public function stopTimer(string $name): void
    {
        if (isset($this->timers[$name])) {
            $this->timers[$name]['end'] = microtime(true);
        }
    }

    /**
     * Adds a retroactive timeline segment for work that has already completed.
     *
     * Used by DebugBar::recordMigration() to place migration execution blocks
     * on the timeline even though migrations run before the collector was asked
     * to display them.  The start offset is back-calculated from now minus the
     * known duration.
     *
     * @param string $name       Label shown in the timeline (e.g. "migration:slug").
     * @param float  $durationMs How long the work took, in milliseconds.
     */
    public function addCompletedSegment(string $name, float $durationMs): void
    {
        $nowMs    = (microtime(true) - $this->startTime) * 1000;
        $offsetMs = max(0.0, $nowMs - $durationMs);
        // Convert back to absolute epoch floats so collect() can compute pct correctly.
        $start = $this->startTime + ($offsetMs / 1000);
        $end   = $start + ($durationMs / 1000);
        $this->timers[$name] = ['start' => $start, 'end' => $end];
    }

    public function collect(): array
    {
        $now     = microtime(true);
        $elapsed = round(($now - $this->startTime) * 1000, 2);

        $named = [];
        foreach ($this->timers as $name => $t) {
            $end       = $t['end'] ?? $now;
            $duration  = round(($end - $t['start']) * 1000, 2);
            $offsetMs  = round(($t['start'] - $this->startTime) * 1000, 2);
            $named[] = [
                'name'      => $name,
                'ms'        => $duration,
                'offset_ms' => max(0.0, $offsetMs),
            ];
        }

        return [
            'request_ms'   => $elapsed,
            'start_time'   => date('H:i:s', (int) $this->startTime),
            'named_timers' => $named,
        ];
    }
}
