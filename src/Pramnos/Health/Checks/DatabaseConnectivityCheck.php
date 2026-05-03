<?php

namespace Pramnos\Health\Checks;

use Pramnos\Database\Database;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Verifies that the primary database connection is reachable and can execute
 * a simple read query.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Health
 */
class DatabaseConnectivityCheck implements HealthCheck
{
    public function __construct(private readonly Database $db)
    {
    }

    public function getName(): string
    {
        return 'database';
    }

    public function run(): HealthCheckResult
    {
        try {
            $start  = microtime(true);
            $result = $this->db->query('SELECT 1');
            $ms     = round((microtime(true) - $start) * 1000, 2);

            if ($result === false || $result === null) {
                return HealthCheckResult::down(
                    $this->getName(),
                    'Query returned no result',
                    ['latency_ms' => $ms]
                );
            }

            return HealthCheckResult::ok(
                $this->getName(),
                'Reachable',
                ['latency_ms' => $ms, 'driver' => $this->db->type ?? 'unknown']
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::down(
                $this->getName(),
                'Connection failed: ' . $e->getMessage()
            );
        }
    }
}
