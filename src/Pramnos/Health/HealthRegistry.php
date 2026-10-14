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

    /**
     * The framework's own checks — one list, for `/health` and for `health:check` alike, so the
     * command reports what the endpoint does. `$database` is a connected database, or null to
     * leave its check out (the connection failing is reported where it failed).
     *
     * Re-registering replaces by name, so calling this twice is harmless.
     */
    public static function registerBuiltIns(?\Pramnos\Database\Database $database): void
    {
        $registry = self::class;

        if ($database !== null) {
            $registry::register(new \Pramnos\Health\Checks\DatabaseConnectivityCheck($database));
        }

        $registry::register(new \Pramnos\Health\Checks\DiskSpaceCheck());
        $registry::register(new \Pramnos\Health\Checks\MemoryLimitCheck());

        // Whether the cache is on the backend it was configured for. The
        // fallback is deliberate and silent, and silent is the problem: an
        // application whose image lacks the `redis` extension runs on local disk
        // with redis in its settings, its compose file and its bill. Constructs
        // nothing here — the check resolves the cache when it runs, so a request
        // that never touches the cache still never connects to one.
        $registry::register(new \Pramnos\Health\Checks\CacheBackendCheck());

        // Whether the time-series storage is the shape it was declared to be. Same
        // reasoning as the cache above and a more expensive silence: a declared
        // hypertable that is an ordinary table serves every request correctly and stops
        // being convertible once it is large, and `timescale:ensure` used to report it as
        // converted. Resolves nothing here either — it reads the catalogue when it runs.
        $registry::register(new \Pramnos\Health\Checks\HypertableCheck());

        // Whether the installation knows its own public address. A web request infers one,
        // so nothing on the site says it is missing — and the process that needs it most,
        // the scheduler, has no `Host` header to infer from and builds `http:///…` into an
        // email or a webhook instead. Reads `$_SERVER` and the environment, nothing else.
        $registry::register(new \Pramnos\Health\Checks\SiteUrlCheck());

        // Whether the web root has the framework's page scripts. `composer update` brings a view
        // that loads a new one and not the script, which `project:resync --js --all` publishes;
        // the page then runs as a plain form with nothing saying why. Reads the disk only.
        $registry::register(new \Pramnos\Health\Checks\FrameworkScriptsCheck());

        // Whether an application's own conversation tables, which the framework's migrations
        // leave as they are, take what the framework writes: `space`, and a null organisation.
        $registry::register(new \Pramnos\Health\Checks\LlmTablesCheck());

        // Where PHP keeps sessions. `files` is right on one machine and the quietest
        // failure in a deployment on two: a visitor whose next request lands on the other
        // node has no session, and being signed out at random reads as an expiry rather
        // than as a load balancer. Reads two ini settings and nothing else.
        $registry::register(new \Pramnos\Health\Checks\SessionStorageCheck());
    }
}
