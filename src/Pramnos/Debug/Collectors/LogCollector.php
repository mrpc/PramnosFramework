<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Collects log entries written during the current request.
 *
 * Works by listening on a ring-buffer populated via addEntry(). The
 * DebugBarServiceProvider installs a Logger listener at boot time that calls
 * addEntry() for every Logger::log() call. Only the last $maxEntries are kept
 * to avoid unbounded memory growth on verbose requests.
 *
 * @package PramnosFramework
 */
class LogCollector implements CollectorInterface
{
    /** @var list<array{level: string, message: string, time: float}> */
    private array $entries = [];

    public function __construct(private readonly int $maxEntries = 100) {}

    public function name(): string
    {
        return 'logs';
    }

    /** Called by the Logger bridge to record each log entry. */
    public function addEntry(string $level, string $message): void
    {
        $this->entries[] = [
            'level'   => $level,
            'message' => $message,
            'time'    => microtime(true),
        ];
        if (count($this->entries) > $this->maxEntries) {
            array_shift($this->entries);
        }
    }

    public function collect(): array
    {
        return [
            'count'   => count($this->entries),
            'entries' => $this->entries,
        ];
    }
}
