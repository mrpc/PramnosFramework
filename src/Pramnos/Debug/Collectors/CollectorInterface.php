<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Contract for DebugBar data collectors.
 *
 * Each collector is responsible for gathering one category of debugging data
 * (queries, timing, memory, routes, logs, session) and serialising it to an
 * array that the HTML renderer can display in a tab.
 *
 * @package PramnosFramework
 */
interface CollectorInterface
{
    /**
     * Short identifier used as the tab label in the debug toolbar.
     * Examples: 'queries', 'timers', 'memory', 'route', 'logs', 'session'.
     */
    public function name(): string;

    /**
     * Collect and return data for this tab.
     *
     * Called once at render time. Must not throw — any error should be caught
     * internally and included in the returned array as an 'error' key.
     *
     * @return array<string, mixed>
     */
    public function collect(): array;
}
