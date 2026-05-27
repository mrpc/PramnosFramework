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
 * @package PramnosFramework
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
