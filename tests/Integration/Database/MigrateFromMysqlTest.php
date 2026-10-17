<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DatabaseMigrateFromMysql;
use Pramnos\Database\Database;
use Pramnos\Database\FromMysql\Migrator;
use Pramnos\Database\FromMysql\TopologicalSorter;
use Pramnos\Database\FromMysql\TypeMapper;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/** The command over a given application: the PostgreSQL target of the test. */
final class MigrateFromMysqlOver extends DatabaseMigrateFromMysql
{
    public function __construct(private \Pramnos\Application\Application $app)
    {
        parent::__construct();
    }

    /** The test's application. */
    protected function application(): ?\Pramnos\Application\Application
    {
        return $this->app;
    }
}

/**
 * `db:migrate-from-mysql --phase=full`, MySQL into PostgreSQL, on the test servers.
 *
 * The source is a MySQL database typed the way a long-lived installation's is — a flag in a
 * `tinyint(1)`, a unix time in an `int`, JSON in `text`, a zero date, bytes that are not UTF-8 —
 * and the target is what migrations make of it: `boolean`, `timestamptz`, `jsonb`, a schema for
 * `authserver_*`. What has to hold: every value arrives as the target column takes it or as a
 * counted NULL; parents load before children; a second run adds nothing; orphans are counted and
 * can be set to NULL; sequences continue past the moved ids; a table only MySQL had is created;
 * a column only the target requires refuses its rows and is named in the plan.
 */
#[CoversClass(Migrator::class)]
#[CoversClass(DatabaseMigrateFromMysql::class)]
#[CoversClass(TypeMapper::class)]
#[CoversClass(TopologicalSorter::class)]
final class MigrateFromMysqlTest extends TestCase
{
    private ?Database $mysql = null;

    private ?Database $postgres = null;

    private ?Database $source = null;

    private ?Database $target = null;

    private string $name = '';

    protected function tearDown(): void
    {
        $this->source?->close();
        $this->target?->close();
        if ($this->name !== '') {
            $this->mysql?->query('DROP DATABASE IF EXISTS `' . $this->name . '`');
            $this->postgres?->query('DROP DATABASE IF EXISTS "' . $this->name . '" WITH (FORCE)');
        }
    }

    /** A server connection, or the test is skipped. */
    private static function server(string $type): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $type === 'mysql' ? 'db' : 'timescaledb';
        $db->port     = $type === 'mysql' ? 3306 : 5432;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                self::markTestSkipped($db->server . ' not reachable');
            }
        } catch (\Throwable $e) {
            self::markTestSkipped($db->server . ' not reachable: ' . $e->getMessage());
        }

        return $db;
    }

    /** The MySQL source and the PostgreSQL target, built; the command over the target. */
    private function databases(): CommandTester
    {
        $this->mysql    = self::server('mysql');
        $this->postgres = self::server('postgresql');
        $this->name     = 'pfmove_' . bin2hex(random_bytes(3));
        $this->mysql->query('CREATE DATABASE `' . $this->name . '` CHARACTER SET utf8mb4');
        $this->postgres->query('CREATE DATABASE "' . $this->name . '"');

        $this->source = self::server('mysql');
        $this->source->query('USE `' . $this->name . '`');
        $this->source->database = $this->name;
        foreach ([
            'SET SESSION sql_mode = \'\'',
            'CREATE TABLE users (userid INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50), active TINYINT(1), lastlogin INT, created DATETIME, prefs TEXT, avatar BLOB, legacy_col VARCHAR(10), bio VARBINARY(20)) ENGINE=InnoDB',
            "INSERT INTO users VALUES (1, 'maria', 1, 1760000000, '2026-01-02 03:04:05', '{\"theme\":\"dark\"}', X'00FF', 'x', X'636166E9'),"
                . " (2, 'nik\\0os', 0, 0, '0000-00-00 00:00:00', '{broken', NULL, 'y', NULL), (5, 'eleni', NULL, NULL, NULL, '', NULL, NULL, NULL)",
            'CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, userid INT UNSIGNED, total DECIMAL(10,2), note VARCHAR(20)) ENGINE=InnoDB',
            "INSERT INTO orders VALUES (1, 1, 12.50, 'first'), (2, 999, 3.00, 'orphan'), (3, 5, 0, '')",
            'CREATE TABLE authserver_things (id INT PRIMARY KEY, label VARCHAR(10)) ENGINE=InnoDB',
            "INSERT INTO authserver_things VALUES (1, 'thing')",
            'CREATE TABLE only_mysql (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(5) NOT NULL, n INT UNSIGNED DEFAULT 7) ENGINE=InnoDB',
            "INSERT INTO only_mysql (v) VALUES ('a'), ('b')",
            'CREATE TABLE strict_t (id INT PRIMARY KEY) ENGINE=InnoDB',
            'INSERT INTO strict_t VALUES (1)',
        ] as $sql) {
            $this->source->query($sql);
        }

        $this->target = self::server('postgresql');
        $this->target->close();
        $this->target->database = $this->name;
        $this->target->connect(false);
        foreach ([
            'CREATE TABLE users (userid SERIAL PRIMARY KEY, username VARCHAR(50) NOT NULL, active BOOLEAN, lastlogin TIMESTAMPTZ, created TIMESTAMP, prefs JSONB, avatar BYTEA, bio TEXT)',
            'CREATE TABLE orders (id SERIAL PRIMARY KEY, userid INT REFERENCES users(userid), total NUMERIC(10,2), note VARCHAR(20))',
            'CREATE SCHEMA authserver',
            'CREATE TABLE authserver.things (id INT PRIMARY KEY, label VARCHAR(10))',
            'CREATE TABLE strict_t (id INT PRIMARY KEY, must_have VARCHAR(5) NOT NULL)',
        ] as $sql) {
            $this->target->query($sql);
        }

        $app = clone (\Pramnos\Application\Application::currentInstance() ?? \Pramnos\Application\Application::getInstance());
        $app->database = $this->target;
        $console = new ConsoleApplication();
        $console->add(new MigrateFromMysqlOver($app));

        return new CommandTester($console->find('db:migrate-from-mysql'));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $result = $this->target->query($sql);
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }

    private function dsn(): string
    {
        return 'mysql://root:secret@db:3306/' . $this->name;
    }

    /**
     * The dry run names what moves and what does not, and changes nothing: the order is parents
     * first, `authserver_things` goes to `authserver.things`, the table only MySQL has is to be
     * created, the column only MySQL has is not moved, the column only the target requires is
     * named.
     */
    public function testTheDryRunSaysWhatWouldMove(): void
    {
        // Arrange
        $tester = $this->databases();

        // Act
        $code    = $tester->execute(['--source' => $this->dsn(), '--dry-run' => true]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $code, $display);
        $this->assertLessThan(strpos($display, 'orders →'), strpos($display, 'users →'), 'parents first');
        $this->assertStringContainsString('authserver_things → authserver.things', $display);
        $this->assertStringContainsString('only_mysql → only_mysql (created: no migration makes it)', $display);
        $this->assertStringContainsString('not moved, the target has no such column: legacy_col', $display);
        $this->assertStringContainsString('the target requires, and the source has not: must_have', $display);
        $this->assertSame([], $this->rows('SELECT * FROM users'), 'nothing moved');
    }

    /**
     * The full move: values as the target takes them, orphans named and set to NULL on request,
     * sequences past the moved ids, the created table filled, the required column's rows refused;
     * and a second run adds nothing.
     */
    public function testTheFullMove(): void
    {
        // Arrange
        $tester = $this->databases();

        // Act
        $code    = $tester->execute(['--source' => $this->dsn(), '--null-orphans' => true, '--batch-size' => '2'], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);
        $display = $tester->getDisplay();
        $users   = $this->rows('SELECT userid, username, active, lastlogin, created, prefs::text AS prefs, encode(avatar, \'hex\') AS avatar, bio FROM users ORDER BY userid');
        $orders  = $this->rows('SELECT id, userid FROM orders ORDER BY id');
        $this->target->query("INSERT INTO users (username) VALUES ('next')");
        $next    = $this->rows("SELECT userid FROM users WHERE username = 'next'")[0]['userid'] ?? null;
        $made    = $this->rows('SELECT id, v, n FROM only_mysql ORDER BY id');
        $things  = $this->rows('SELECT label FROM authserver.things');
        $tester->execute(['--source' => $this->dsn()]);
        $again   = $tester->getDisplay();

        // Assert
        $this->assertSame(1, $code, 'strict_t refused its row');
        $this->assertSame([1, 2, 5], array_map('intval', array_column($users, 'userid')));
        $this->assertSame(['maria', 'nikos', 'eleni'], array_column($users, 'username'), 'the NUL dropped');
        $this->assertSame('caf?', $users[0]['bio'], 'bytes that are not UTF-8, made text');
        $this->assertSame([true, false, null], array_map(static fn ($v) => $v === null ? null : in_array($v, [true, 't'], true), array_column($users, 'active')));
        $this->assertStringStartsWith('2025-10-09 08:53:20', (string) $users[0]['lastlogin'], 'the unix time as a moment');
        $this->assertNull($users[1]['lastlogin']);
        $this->assertNull($users[1]['created'], 'the zero date');
        $this->assertSame('{"theme": "dark"}', $users[0]['prefs']);
        $this->assertNull($users[1]['prefs'], 'invalid JSON as NULL');
        $this->assertSame('00ff', $users[0]['avatar']);
        $this->assertStringContainsString('1 value(s) that were not what the column takes, as NULL', $display);
        $this->assertSame([1, null, 5], array_map(static fn ($v) => $v === null ? null : (int) $v, array_column($orders, 'userid')), 'the orphan set to NULL');
        $this->assertStringContainsString('1 row(s) of orders.userid name no users — set to NULL', $display);
        $this->assertSame(6, (int) $next, 'the sequence continues past the moved ids');
        $this->assertSame([['id' => 1, 'v' => 'a', 'n' => 7], ['id' => 2, 'v' => 'b', 'n' => 7]], array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'v' => $r['v'], 'n' => (int) $r['n']], $made));
        $this->assertSame([['label' => 'thing']], $things);
        $this->assertStringContainsString('strict_t: 0 inserted, 1 refused', $display);
        $this->assertStringContainsString('  users: 2 rows', $display, '-v reports each batch');
        $this->assertStringContainsString('users: 0 inserted', $again, 'a second run adds nothing');
    }

    /**
     * With the keys checked, a row whose reference names nothing is refused rather than loaded,
     * and the rest of its batch still arrives.
     */
    public function testWithKeysCheckedAnOrphanIsRefused(): void
    {
        // Arrange
        $tester = $this->databases();

        // Act
        $tester->execute(['--source' => $this->dsn(), '--keep-constraints' => true, '--tables' => 'users,orders']);
        $display = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('orders: 2 inserted, 1 refused', $display);
        $this->assertStringContainsString('violates foreign key constraint', $display);
        $this->assertStringNotContainsString('strict_t', $display, 'only the tables asked for');
    }

    /** A source that is not a MySQL address, and a target that is not PostgreSQL, are refused. */
    public function testWhatItRefuses(): void
    {
        // Arrange
        $tester = $this->databases();
        $mysqlApp = clone (\Pramnos\Application\Application::currentInstance() ?? \Pramnos\Application\Application::getInstance());
        $mysqlApp->database = $this->mysql;
        $console = new ConsoleApplication();
        $console->add(new MigrateFromMysqlOver($mysqlApp));
        $onMysql = new CommandTester($console->find('db:migrate-from-mysql'));

        // Act
        $badSource = $tester->execute(['--source' => 'postgres://x@y/z']);
        $badSourceText = $tester->getDisplay();
        $badPhase  = $tester->execute(['--source' => $this->dsn(), '--phase' => 'backfill']);
        $badPhaseText = $tester->getDisplay();
        $noHost    = $tester->execute(['--source' => 'mysql://root:secret@nohost.invalid:3306/x']);
        $notPg     = $onMysql->execute(['--source' => $this->dsn()]);

        // Assert
        $this->assertSame(1, $badSource);
        $this->assertStringContainsString('mysql://user:password@host:port/name', $badSourceText);
        $this->assertSame(1, $badPhase);
        $this->assertStringContainsString('Only --phase=full is available', $badPhaseText);
        $this->assertSame(1, $noHost);
        $this->assertStringContainsString('Could not open the MySQL source', $tester->getDisplay());
        $this->assertSame(1, $notPg);
        $this->assertStringContainsString('not on PostgreSQL', $onMysql->getDisplay());
    }
}
