<?php

namespace Pramnos\Health;

/**
 * Central registry for health checks.
 *
 * ## Usage
 *
 * Register checks at bootstrap (typically inside a ServiceProvider::boot()):
 * ```php
 * use Pramnos\Health\HealthRegistry;
 * use Pramnos\Health\Checks\DatabaseConnectivityCheck;
 *
 * HealthRegistry::register(new DatabaseConnectivityCheck($db));
 * HealthRegistry::register(new DiskSpaceCheck());
 * HealthRegistry::register(new MemoryLimitCheck());
 * ```
 *
 * Run all checks:
 * ```php
 * $report = HealthRegistry::runAll();
 * // $report['status']  — worst overall status ('ok', 'degraded', 'down')
 * // $report['checks']  — array of HealthCheckResult::toArray() per check
 * ```
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class HealthRegistry
{
    /** @var array<string, HealthCheck> Registered checks keyed by name. */
    private static array $checks = [];

    // =========================================================================
    // Registration
    // =========================================================================

    /**
     * Registers a health check.
     *
     * If a check with the same name is already registered, it is replaced.
     */
    public static function register(HealthCheck $check): void
    {
        static::$checks[$check->getName()] = $check;
    }

    /**
     * Returns all registered check names.
     *
     * @return string[]
     */
    public static function getNames(): array
    {
        return array_keys(static::$checks);
    }

    /**
     * Returns a registered check by name, or null if not found.
     */
    public static function get(string $name): ?HealthCheck
    {
        return static::$checks[$name] ?? null;
    }

    // =========================================================================
    // Execution
    // =========================================================================

    /**
     * Runs a single check by name.
     *
     * @throws \InvalidArgumentException When the check name is not registered.
     */
    public static function run(string $name): HealthCheckResult
    {
        $check = static::$checks[$name] ?? null;
        if ($check === null) {
            throw new \InvalidArgumentException("Health check '{$name}' is not registered.");
        }

        return $check->run();
    }

    /**
     * Runs all registered checks and returns an aggregated report.
     *
     * The report shape:
     * ```
     * [
     *   'status'  => 'ok' | 'degraded' | 'down',
     *   'checks'  => [
     *     'database'    => ['status' => 'ok',   'name' => '...', 'message' => '...', 'details' => [...]],
     *     'disk_space'  => ['status' => 'ok',   ...],
     *   ],
     * ]
     * ```
     *
     * @return array{status: string, checks: array<string, array{status: string, name: string, message: string, details: array<string, mixed>}>}
     */
    public static function runAll(): array
    {
        $overall = HealthStatus::Ok;
        $checks  = [];

        foreach (static::$checks as $name => $check) {
            $result         = $check->run();
            $checks[$name]  = $result->toArray();
            $overall        = $overall->worst($result->status);
        }

        return [
            'status' => $overall->value,
            'checks' => $checks,
        ];
    }

    // =========================================================================
    // State management (tests)
    // =========================================================================

    /**
     * Removes all registered checks.
     *
     * Intended for test isolation only.
     */
    public static function reset(): void
    {
        static::$checks = [];
    }
}
