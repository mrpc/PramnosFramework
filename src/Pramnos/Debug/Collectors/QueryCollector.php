<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

use Pramnos\Database\Database;

/**
 * Collects all SQL queries executed during the current request.
 *
 * Reads from Database::getQueryLog() which is only populated when
 * Database::enableQueryLog() has been called (done by DebugBarServiceProvider).
 *
 * @package PramnosFramework
 */
class QueryCollector implements CollectorInterface
{
    public function __construct(private readonly Database $db) {}

    public function name(): string
    {
        return 'queries';
    }

    public function collect(): array
    {
        $log   = $this->db->getQueryLog();
        $total = 0.0;
        $rows  = [];
        foreach ($log as $entry) {
            $total  += $entry['time'];
            $rows[]  = [
                'sql'  => $entry['sql'],
                'time' => round($entry['time'] * 1000, 2), // ms
            ];
        }

        return [
            'count'    => count($rows),
            'total_ms' => round($total * 1000, 2),
            'queries'  => $rows,
        ];
    }
}
