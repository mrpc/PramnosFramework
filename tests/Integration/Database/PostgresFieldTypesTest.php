<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Result column types are read without PHP's `select oid,typname from pg_type`.
 *
 * `pg_field_type()` makes PHP read the whole of `pg_type` once per connection — on TimescaleDB
 * one row per chunk and table — and a production server showed it as its most expensive
 * statement. The framework now maps built-in OIDs itself; these tests hold the map to the
 * server's catalogue and prove the statement is gone.
 */
#[CoversClass(Database::class)]
class PostgresFieldTypesTest extends TestCase
{
    /** A fresh connection to the test PostgreSQL, or the test is skipped. */
    private function connect(): Database
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $settings = Settings::getSetting('postgresql');

        if (!$settings) {
            $this->markTestSkipped('No PostgreSQL settings in the test fixtures.');
        }

        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = $settings->hostname;
        $db->user     = $settings->user;
        $db->password = $settings->password;
        $db->database = $settings->database;
        $db->port     = $settings->port ?? 5432;

        if (!$db->connect(false)) {
            $this->markTestSkipped('PostgreSQL is not reachable.');
        }

        return $db;
    }

    /**
     * Every OID in the map names the type the server gives it.
     *
     * A wrong entry would silently change `Result::$columnTypes` and the PHP type a value is
     * converted to, so the map is checked against `pg_type` itself, entry by entry.
     */
    public function testTheBuiltinMapMatchesTheServersCatalogue(): void
    {
        // Arrange
        $db = $this->connect();

        // Act
        $catalogue = [];
        $result = $db->query(
            'SELECT oid::int AS oid, typname FROM pg_type WHERE oid IN ('
            . implode(',', array_keys(Database::PG_BUILTIN_TYPES)) . ')'
        );
        while ($result->fetch()) {
            $catalogue[(int) $result->fields['oid']] = (string) $result->fields['typname'];
        }
        ksort($catalogue);
        $map = Database::PG_BUILTIN_TYPES;
        ksort($map);

        // Assert
        $this->assertSame($map, $catalogue);
    }

    /**
     * A query on a new connection reads its column types without touching `pg_type`, and the
     * types and converted values are what they were.
     *
     * `pg_stat_statements` (preloaded in the test container) is the witness: it is reset, a new
     * PHP process runs one query, and the statement PHP would have sent is not recorded.
     *
     * A separate process, because PHP caches what it read from `pg_type` for the whole process,
     * not per connection: in this one, any earlier query has already filled the cache, and the
     * test passed with the old code. A child process is what a web request is.
     */
    public function testANewConnectionDoesNotReadPgType(): void
    {
        // Arrange
        $admin   = $this->connect();
        $created = (int) ($admin->query(
            "SELECT count(*) AS n FROM pg_extension WHERE extname = 'pg_stat_statements'"
        )->fields['n'] ?? 0) === 0;
        $admin->query('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');

        try {
            $admin->query('SELECT pg_stat_statements_reset()');

            // Act — one query, from a process that has never read pg_type
            $child = <<<'PHP'
                require $argv[1] . '/vendor/autoload.php';
                $c = json_decode($argv[2], true);
                $db = new \Pramnos\Database\Database();
                $db->type = 'postgresql';
                foreach ($c as $k => $v) { $db->$k = $v; }
                $db->connect(false);
                $r = $db->query("SELECT 7::int4 AS i, 2.5::numeric AS n, true AS b, 'x'::text AS t,
                                        now() AS ts, '{}'::jsonb AS j");
                echo json_encode(['types' => $r->columnTypes, 'fields' => $r->fields]);
                PHP;
            $settings = ['server' => $admin->server, 'user' => $admin->user,
                'password' => $admin->password, 'database' => $admin->database,
                'port' => $admin->port];
            $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child)
                . ' ' . escapeshellarg(ROOT) . ' ' . escapeshellarg(json_encode($settings)));
            $result = json_decode((string) $output, true);

            // Assert — same names, same conversions
            $this->assertIsArray($result, 'the child printed: ' . $output);
            $this->assertSame(['i' => 'int4', 'n' => 'numeric', 'b' => 'bool', 't' => 'text',
                'ts' => 'timestamptz', 'j' => 'jsonb'], $result['types']);
            $this->assertSame(7, $result['fields']['i']);
            $this->assertSame(2.5, $result['fields']['n']);
            $this->assertTrue($result['fields']['b']);

            // …and PHP never asked the server for the whole of pg_type.
            $asked = (int) ($admin->query(
                "SELECT count(*) AS n FROM pg_stat_statements
                 WHERE query ILIKE 'select oid,typname from pg_type%'"
            )->fields['n'] ?? 0);
            $this->assertSame(0, $asked);
        } finally {
            if ($created) {
                $admin->query('DROP EXTENSION IF EXISTS pg_stat_statements');
            }
        }
    }
}
