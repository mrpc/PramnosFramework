<?php

namespace Pramnos\Health;

/**
 * Contract for all health checks.
 *
 * Implement this interface to create a custom check and register it with
 * HealthRegistry::register():
 *
 * ```php
 * class MyServiceCheck implements HealthCheck
 * {
 *     public function getName(): string { return 'my_service'; }
 *
 *     public function run(): HealthCheckResult
 *     {
 *         // probe the service …
 *         return HealthCheckResult::ok($this->getName(), 'Reachable');
 *     }
 * }
 *
 * HealthRegistry::register(new MyServiceCheck());
 * ```
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
interface HealthCheck
{
    /**
     * Returns a short machine-friendly identifier for this check, e.g.
     * `'database'`, `'disk_space'`, `'redis'`.
     */
    public function getName(): string;

    /**
     * Executes the check and returns a result.
     *
     * Implementations must not throw — all errors must be caught internally
     * and returned as a HealthCheckResult::down() or degraded() result.
     */
    public function run(): HealthCheckResult;
}
