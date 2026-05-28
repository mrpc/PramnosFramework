<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

use Pramnos\Database\Database;

/**
 * Collects framework-level migration activity for the DebugBar.
 *
 * Two data sources:
 *  1. In-request records added via record() — shows what ran *this request*.
 *  2. Full framework migration history loaded eagerly at construction time —
 *     displayed in the "All Migrations" panel.
 *
 * History is loaded in the constructor (called during DebugBarServiceProvider::boot())
 * BEFORE the ob_start output buffer is installed.  Loading at collect() time would
 * execute a DB query inside the ob_start callback (during PHP script shutdown), which
 * can cause PostgreSQL connection blocking on some environments.
 *
 * Fingerprint rows (key LIKE '__fw_auto_%') are excluded from the history display;
 * they are internal housekeeping entries, not real migrations.
 *
 * @package PramnosFramework
 */
class MigrationsCollector implements CollectorInterface
{
    /** @var array<int, array{slug: string, ms: float, status: string}> */
    private array $thisRequest = [];

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    public function __construct(?Database $db = null, string $historyTable = 'schemaversion')
    {
        // Pre-load history now (boot phase) so collect() never does a DB query
        // inside the ob_start shutdown callback.
        $this->history = $this->loadHistory($db, $historyTable);
    }

    public function name(): string
    {
        return 'migrations';
    }

    /**
     * Records one migration that executed during this HTTP request.
     *
     * Called by DebugBar::recordMigration() from Application::runAutoMigrations().
     * This always runs during exec(), well before the ob_start callback fires.
     *
     * @param string $slug   Migration slug (the `key` column value in history).
     * @param float  $ms     Execution time in milliseconds.
     * @param string $status 'ran' (success) or 'failed'.
     */
    public function record(string $slug, float $ms, string $status = 'ran'): void
    {
        $this->thisRequest[] = ['slug' => $slug, 'ms' => $ms, 'status' => $status];
    }

    public function collect(): array
    {
        // No DB queries here — history was loaded at construction time.
        return [
            'this_request'  => $this->thisRequest,
            'count_request' => count($this->thisRequest),
            'history'       => $this->history,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadHistory(?Database $db, string $historyTable): array
    {
        if ($db === null) {
            return [];
        }

        try {
            $quote  = $db->type === 'postgresql' ? '"' : '`';
            $result = $db->query(
                "SELECT {$quote}key{$quote}, {$quote}when{$quote}, {$quote}scope{$quote},
                        {$quote}feature{$quote}, {$quote}batch{$quote}, {$quote}execution_time{$quote},
                        {$quote}result{$quote}, {$quote}error_message{$quote}
                 FROM   {$quote}{$historyTable}{$quote}
                 WHERE  {$quote}scope{$quote} = 'framework'
                   AND  {$quote}key{$quote} NOT LIKE '__fw_auto_%'
                 ORDER BY {$quote}when{$quote} ASC"
            );

            if ($result === false) {
                return [];
            }

            $rows = [];
            while ($result->fetch()) {
                $rows[] = $result->fields;
            }
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }
}
