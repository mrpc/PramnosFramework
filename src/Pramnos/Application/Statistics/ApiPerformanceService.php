<?php

declare(strict_types=1);

namespace Pramnos\Application\Statistics;

/**
 * Queries the tokenactions table to produce API performance metrics.
 *
 * The `#PREFIX#tokenactions` table is written by Pramnos\User\Token on every
 * authenticated API call. Columns used here:
 *   - `servertime`        Unix timestamp of the request
 *   - `return_status`     HTTP status code (nullable — added in v1.2)
 *   - `execution_time_ms` Request execution time in milliseconds (nullable — added in v1.2)
 *   - `urlid`             FK identifying the endpoint
 *
 * If the table or columns do not exist (e.g., fresh install before first API
 * call), all queries are wrapped in try/catch and degrade to null values.
 *
 * @package     PramnosFramework
 * @subpackage  Application\Statistics
 */
class ApiPerformanceService
{
    public const WINDOW_1H  = 3600;
    public const WINDOW_24H = 86400;
    public const WINDOW_7D  = 604800;

    private \Pramnos\Database\Database $db;

    public function __construct(?\Pramnos\Database\Database $db = null)
    {
        $this->db = $db ?? \Pramnos\Framework\Factory::getDatabase();
    }

    /**
     * Returns a performance summary for the given time window (in seconds).
     *
     * @return array{
     *   window_seconds: int,
     *   total_requests: int,
     *   error_rate: float|null,
     *   avg_execution_ms: float|null,
     *   p95_execution_ms: float|null,
     *   p99_execution_ms: float|null,
     *   by_status: array<int, int>
     * }
     */
    public function getSummary(int $windowSeconds = self::WINDOW_24H): array
    {
        $since = time() - $windowSeconds;

        $summary = [
            'window_seconds'   => $windowSeconds,
            'total_requests'   => 0,
            'error_rate'       => null,
            'avg_execution_ms' => null,
            'p95_execution_ms' => null,
            'p99_execution_ms' => null,
            'by_status'        => [],
        ];

        try {
            $total = $this->db->queryBuilder()
                ->table('#PREFIX#tokenactions')
                ->where('servertime', '>=', $since)
                ->count();

            $summary['total_requests'] = $total;

            if ($total === 0) {
                return $summary;
            }

            $summary['error_rate']       = $this->computeErrorRate($since);
            $summary['avg_execution_ms'] = $this->computeAvg($since);
            $summary['p95_execution_ms'] = $this->computePercentile($since, 95);
            $summary['p99_execution_ms'] = $this->computePercentile($since, 99);
            $summary['by_status']        = $this->computeByStatus($since);

        } catch (\Exception $e) {
            // Table not yet created — return zeroed summary
        }

        return $summary;
    }

    /**
     * Returns the top N endpoints sorted by average execution time (descending).
     *
     * @param  int $n            Maximum number of endpoints to return
     * @param  int $windowSeconds Time window to analyse
     * @return array<int, array{urlid: int, avg_ms: float, request_count: int}>
     */
    public function getTopSlowEndpoints(
        int $n = 10,
        int $windowSeconds = self::WINDOW_24H
    ): array {
        $since = time() - $windowSeconds;

        try {
            $result = $this->db->queryBuilder()
                ->table('#PREFIX#tokenactions')
                ->select([
                    'urlid',
                    'AVG(execution_time_ms) AS avg_ms',
                    'COUNT(*) AS request_count',
                ])
                ->where('servertime', '>=', $since)
                ->whereNotNull('execution_time_ms')
                ->groupBy(['urlid'])
                ->orderBy('avg_ms', 'desc')
                ->limit($n)
                ->get();

            return $this->rowsFromResult($result, ['urlid' => 'int', 'avg_ms' => 'float', 'request_count' => 'int']);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Returns the top N most-called endpoints (descending request count).
     *
     * @param  int $n            Maximum number of endpoints to return
     * @param  int $windowSeconds Time window to analyse
     * @return array<int, array{urlid: int, request_count: int}>
     */
    public function getTopCalledEndpoints(
        int $n = 10,
        int $windowSeconds = self::WINDOW_24H
    ): array {
        $since = time() - $windowSeconds;

        try {
            $result = $this->db->queryBuilder()
                ->table('#PREFIX#tokenactions')
                ->select(['urlid', 'COUNT(*) AS request_count'])
                ->where('servertime', '>=', $since)
                ->groupBy(['urlid'])
                ->orderBy('request_count', 'desc')
                ->limit($n)
                ->get();

            return $this->rowsFromResult($result, ['urlid' => 'int', 'request_count' => 'int']);
        } catch (\Exception $e) {
            return [];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function computeErrorRate(int $since): ?float
    {
        $errors = $this->db->queryBuilder()
            ->table('#PREFIX#tokenactions')
            ->where('servertime', '>=', $since)
            ->whereNotNull('return_status')
            ->where('return_status', '>=', 400)
            ->count();

        $withStatus = $this->db->queryBuilder()
            ->table('#PREFIX#tokenactions')
            ->where('servertime', '>=', $since)
            ->whereNotNull('return_status')
            ->count();

        return ($withStatus > 0) ? round($errors / $withStatus * 100, 2) : null;
    }

    private function computeAvg(int $since): ?float
    {
        $avg = $this->db->queryBuilder()
            ->table('#PREFIX#tokenactions')
            ->where('servertime', '>=', $since)
            ->whereNotNull('execution_time_ms')
            ->avg('execution_time_ms');

        return ($avg > 0) ? round((float) $avg, 3) : null;
    }

    private function computePercentile(int $since, int $percentile): ?float
    {
        try {
            if ($this->db->type === 'postgresql') {
                // Native percentile function — PostgreSQL 9.4+
                $pct = $percentile / 100;
                $sql = $this->db->prepareQuery(
                    "SELECT PERCENTILE_CONT(%s) WITHIN GROUP (ORDER BY execution_time_ms) AS pct
                     FROM #PREFIX#tokenactions
                     WHERE servertime >= %d AND execution_time_ms IS NOT NULL",
                    (string) $pct,
                    $since
                );
                $r = $this->db->query($sql);
                if ($r && $r->numRows > 0 && $r->fields['pct'] !== null) {
                    return round((float) $r->fields['pct'], 3);
                }
                return null;
            }

            // MySQL: nearest-rank approximation via LIMIT/OFFSET
            $count = $this->db->queryBuilder()
                ->table('#PREFIX#tokenactions')
                ->where('servertime', '>=', $since)
                ->whereNotNull('execution_time_ms')
                ->count();

            if ($count === 0) {
                return null;
            }

            $offset = max(0, (int) ceil($count * ($percentile / 100)) - 1);
            $sql    = $this->db->prepareQuery(
                'SELECT execution_time_ms FROM #PREFIX#tokenactions
                 WHERE servertime >= %d AND execution_time_ms IS NOT NULL
                 ORDER BY execution_time_ms
                 LIMIT 1 OFFSET %d',
                $since,
                $offset
            );
            $r = $this->db->query($sql);

            return ($r && $r->numRows > 0)
                ? round((float) $r->fields['execution_time_ms'], 3)
                : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @return array<int, int>
     */
    private function computeByStatus(int $since): array
    {
        try {
            $result = $this->db->queryBuilder()
                ->table('#PREFIX#tokenactions')
                ->select(['return_status', 'COUNT(*) AS cnt'])
                ->where('servertime', '>=', $since)
                ->whereNotNull('return_status')
                ->groupBy(['return_status'])
                ->orderBy('return_status')
                ->get();

            $map = [];
            if ($result) {
                while ($result->fetch()) {
                    $map[(int) $result->fields['return_status']] = (int) $result->fields['cnt'];
                }
            }
            return $map;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Converts a DB result into an array of typed rows.
     *
     * @param  mixed                 $result  QueryBuilder result object
     * @param  array<string, string> $types   column => 'int'|'float'|'string' cast map
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromResult(mixed $result, array $types): array
    {
        $rows = [];
        if (!$result) {
            return $rows;
        }

        while ($result->fetch()) {
            $row = [];
            foreach ($types as $col => $type) {
                $val = $result->fields[$col] ?? null;
                if ($val === null) {
                    $row[$col] = null;
                    continue;
                }
                $row[$col] = match ($type) {
                    'int'   => (int)   $val,
                    'float' => (float) $val,
                    default => (string) $val,
                };
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
