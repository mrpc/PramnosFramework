<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Reports peak memory usage at render time.
 *
 */
class MemoryCollector implements CollectorInterface
{
    public function name(): string
    {
        return 'memory';
    }

    public function collect(): array
    {
        $peak    = memory_get_peak_usage(true);
        $current = memory_get_usage(true);

        return [
            'peak_bytes'    => $peak,
            'peak_human'    => $this->format($peak),
            'current_bytes' => $current,
            'current_human' => $this->format($current),
        ];
    }

    private function format(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
