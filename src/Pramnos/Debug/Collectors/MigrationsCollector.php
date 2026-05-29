<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Collects migrations that executed during the current HTTP request.
 *
 * Records are pushed via record() from DebugBar::recordMigration(), which is
 * called by Application::runAutoMigrations() for each migration that runs.
 *
 */
class MigrationsCollector implements CollectorInterface
{
    /** @var array<int, array{slug: string, ms: float, status: string}> */
    private array $thisRequest = [];

    public function name(): string
    {
        return 'migrations';
    }

    /**
     * Records one migration that executed during this HTTP request.
     *
     * @param string $slug   Migration slug (the `key` column value in schemaversion).
     * @param float  $ms     Execution time in milliseconds.
     * @param string $status 'ran' (success) or 'failed'.
     */
    public function record(string $slug, float $ms, string $status = 'ran'): void
    {
        $this->thisRequest[] = ['slug' => $slug, 'ms' => $ms, 'status' => $status];
    }

    public function collect(): array
    {
        return [
            'this_request'  => $this->thisRequest,
            'count_request' => count($this->thisRequest),
        ];
    }
}
