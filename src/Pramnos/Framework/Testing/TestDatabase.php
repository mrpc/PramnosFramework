<?php

namespace Pramnos\Framework\Testing;

use PDO;
use PHPUnit\Framework\Assert;
use Pramnos\Application\Settings;

/**
 * Standalone, init-less test database helper.
 *
 * Unlike {@see BaseTestCase} (whose setUp() boots the full MVC request lifecycle
 * via Application::init() — session start, session tracking, addons), this helper
 * provides ONLY a raw \PDO connection to the configured database plus a couple of
 * row-existence assertions. It runs no lifecycle, so it suits "Services + API +
 * SPA" applications that deliberately do not use init() (and whose own schema —
 * e.g. a bespoke `sessions` table — would collide with the framework's).
 *
 * The connection is built from the framework `database` settings
 * (hostname/port/database/user/password/type, and the optional `timezone`), so a
 * test seeds the very same database the app uses. It is a per-process singleton
 * (persistent), and {@see setConnection()} injects a mock/alternate for a unit
 * test.
 *
 * Intended for tests only; there are no production callers.
 */
final class TestDatabase
{
    private static ?PDO $connection = null;

    /**
     * The shared test connection (built lazily from the `database` settings).
     */
    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config = self::config();
        $type   = (string) ($config['type'] ?? 'mysql');
        $host   = (string) ($config['hostname'] ?? 'localhost');
        $port   = $config['port'] ?? null;
        $dbName = (string) ($config['database'] ?? '');
        $user   = (string) ($config['user'] ?? '');
        $pass   = (string) ($config['password'] ?? '');

        $isPostgres = ($type === 'postgresql' || $type === 'pgsql');
        $dsn = ($isPostgres ? 'pgsql' : 'mysql') . ":host={$host};dbname={$dbName}";
        if ($port) {
            $dsn .= ";port={$port}";
        }

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => true,
        ]);

        // Match the app's session timezone when configured, so seeded rows render
        // identically to rows written through the framework database layer.
        $tz = (string) ($config['timezone'] ?? '');
        if ($tz !== '') {
            $quoted = str_replace("'", "''", $tz);
            $pdo->exec($isPostgres ? "SET TIME ZONE '{$quoted}'" : "SET time_zone = '{$quoted}'");
        }

        return self::$connection = $pdo;
    }

    /**
     * Inject a mock/alternate connection (test seam). Pass null to reset.
     */
    public static function setConnection(?PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    /**
     * Drop the cached connection (test isolation).
     */
    public static function reset(): void
    {
        self::$connection = null;
    }

    /**
     * Assert a row matching $criteria (column => value) exists in $table.
     */
    public static function assertDatabaseHas(string $table, array $criteria, ?PDO $pdo = null): void
    {
        Assert::assertGreaterThan(
            0,
            self::countRows($pdo ?? self::connection(), $table, $criteria),
            "Failed asserting that a matching row exists in '{$table}'."
        );
    }

    /**
     * Assert NO row matching $criteria exists in $table.
     */
    public static function assertDatabaseMissing(string $table, array $criteria, ?PDO $pdo = null): void
    {
        Assert::assertSame(
            0,
            self::countRows($pdo ?? self::connection(), $table, $criteria),
            "Failed asserting that no matching row exists in '{$table}'."
        );
    }

    /**
     * @param array<string,mixed> $criteria
     */
    private static function countRows(PDO $pdo, string $table, array $criteria): int
    {
        $where  = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            $where[]  = "{$column} = ?";
            $params[] = $value;
        }
        $sql = "SELECT COUNT(*) FROM {$table}"
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The `database` settings section as an array (init-less config read).
     *
     * @return array<string,mixed>
     */
    private static function config(): array
    {
        $config = Settings::getSetting('database');
        if (is_object($config)) {
            return (array) $config;
        }
        return is_array($config) ? $config : [];
    }
}
