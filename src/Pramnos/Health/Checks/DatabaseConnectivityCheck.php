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
            // Ensure a live connection exists before issuing a query.
            // connect() throws RuntimeException on failure, which is caught below.
            // This prevents query() from reaching runMysqlQuery()'s
            // setError('0', 'not connected') path, which calls error_log() as a
            // side effect before throwing — causing output pollution in unit tests.
            if (!$this->db->connected) {
                $this->db->connect();
            }
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

            $details = ['latency_ms' => $ms, 'driver' => $this->db->type ?? 'unknown'];
            try {
                $vr = $this->db->query('SELECT VERSION() AS v');
                $details['version'] = $vr ? ($vr->fields['v'] ?? null) : null;
            } catch (\Throwable) {
            }

            return HealthCheckResult::ok($this->getName(), 'Reachable', $details);
        } catch (\Throwable $e) {
            return HealthCheckResult::down(
                $this->getName(),
                'Connection failed: ' . $e->getMessage()
            );
        }
    }
}
