<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

use Pramnos\Database\Database;

/**
 * Collects framework-level migration activity for the DebugBar.
 *
 * Two data sources:
 *  1. In-request records added via record() — shows what ran *this request*.
 *  2. Full framework migration history read from the history table at collect()
 *     time — displayed in the "All Migrations" panel.
 *
 * Fingerprint rows (key LIKE '__fw_auto_%') are excluded from the history
 * display; they are internal housekeeping entries, not real migrations.
 *
 * @package PramnosFramework
 */
class MigrationsCollector implements CollectorInterface
{
    private ?Database $db;
    private string $historyTable;

    /** @var array<int, array{slug: string, ms: float, status: string}> */
    private array $thisRequest = [];

    public function __construct(?Database $db = null, string $historyTable = 'schemaversion')
    {
        $this->db           = $db;
        $this->historyTable = $historyTable;
    }

    public function name(): string
    {
        return 'migrations';
    }

    /**
     * Records one migration that executed during this HTTP request.
     *
     * Called by DebugBar::recordMigration() from Application::runAutoMigrations().
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
        return [
            'this_request'  => $this->thisRequest,
            'count_request' => count($this->thisRequest),
            'history'       => $this->fetchHistory(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchHistory(): array
    {
        if ($this->db === null) {
            return [];
        }

        try {
            $quote  = $this->db->type === 'postgresql' ? '"' : '`';
            $result = $this->db->query(
                "SELECT {$quote}key{$quote}, {$quote}when{$quote}, {$quote}scope{$quote},
                        {$quote}feature{$quote}, {$quote}batch{$quote}, {$quote}execution_time{$quote},
                        {$quote}result{$quote}, {$quote}error_message{$quote}
                 FROM   {$quote}{$this->historyTable}{$quote}
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
