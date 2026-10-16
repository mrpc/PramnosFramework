<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

/**
 * The figures the framework's widgets read, each worked out once per request.
 *
 * The dashboard's controller hands the same figures to a view of the older shape, and the health
 * checks are not cheap: asked twice, they would run twice.
 */
final class Figures
{
    /** @var array<string, mixed> */
    private static array $once = [];

    /** Active-user counts: now, last hour, day, week, month. */
    public static function activeUsers(): array
    {
        return self::once('activeUsers', static fn () => (new \Pramnos\Application\Statistics\ActiveUsersService())->getCounts());
    }

    /** The database server's size, connections and cache hit ratio. */
    public static function database(): array
    {
        return self::once('database', static fn () => (new \Pramnos\Application\Statistics\DatabaseStatsService())->getStats());
    }

    /** The API's requests, errors and latency over the last day. */
    public static function api(): array
    {
        return self::once('api', static fn () => (new \Pramnos\Application\Statistics\ApiPerformanceService())
            ->getSummary(\Pramnos\Application\Statistics\ApiPerformanceService::WINDOW_24H));
    }

    /** Every health check's result, by name. */
    public static function health(): array
    {
        return self::once('health', static fn () => \Pramnos\Health\HealthRegistry::runAll()['checks'] ?? []);
    }

    /**
     * Whether this person may open the screen a card summarises — its ability, at the framework
     * screens' floor. A card shows no more than its screen does.
     */
    public static function opens(?object $user, string $ability): bool
    {
        return \Pramnos\Auth\AdminAccess::allows($user, $ability, \Pramnos\Auth\AdminAccess::defaultUsertype());
    }

    /** For a test, or a second dashboard in one process: work them out again. */
    public static function forget(): void
    {
        self::$once = [];
    }

    /** A byte count as people read it. */
    public static function bytes(int|float $bytes): string
    {
        return $bytes > 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB';
    }

    private static function once(string $key, callable $work): array
    {
        if (!array_key_exists($key, self::$once)) {
            $value = $work();
            self::$once[$key] = is_array($value) ? $value : [];
        }

        return self::$once[$key];
    }
}
