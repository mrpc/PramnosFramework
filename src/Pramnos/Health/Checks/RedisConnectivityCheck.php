<?php

namespace Pramnos\Health\Checks;

use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Redis\ConnectionManager;

/**
 * Verifies that Redis is reachable by issuing a PING through the central
 * {@see ConnectionManager}.
 *
 * The connection manager is injectable so the check can target a specific
 * configuration (or a test double); by default it uses the shared instance,
 * matching whatever the cache / broadcasting / queue Redis drivers use.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class RedisConnectivityCheck implements HealthCheck
{
    private ConnectionManager $manager;

    public function __construct(?ConnectionManager $manager = null)
    {
        $this->manager = $manager ?? ConnectionManager::getInstance();
    }

    public function getName(): string
    {
        return 'redis';
    }

    public function run(): HealthCheckResult
    {
        try {
            $start = microtime(true);
            $pong  = $this->manager->connection()->ping();
            $ms    = round((microtime(true) - $start) * 1000, 2);

            // phpredis returns true or '+PONG'/'PONG' depending on version/mode.
            if ($pong === true || $pong === '+PONG' || $pong === 'PONG') {
                return HealthCheckResult::ok($this->getName(), 'PONG', ['latency_ms' => $ms]);
            }

            return HealthCheckResult::down(
                $this->getName(),
                'Unexpected PING reply',
                ['latency_ms' => $ms]
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::down(
                $this->getName(),
                'Connection failed: ' . $e->getMessage()
            );
        }
    }
}
