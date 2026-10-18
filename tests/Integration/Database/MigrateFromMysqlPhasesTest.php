<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DatabaseMigrateFromMysql;
use Pramnos\Database\Database;
use Pramnos\Database\FromMysql\Migrator;
use Pramnos\Database\FromMysql\ProgressTracker;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The live move, phase by phase, MySQL into PostgreSQL/TimescaleDB on the test servers.
 *
 * Operational: the tables copied while the application runs, their moment and highest ids
 * recorded. Cutover: what changed since — new rows past the highest id, changed rows by their
 * change column, deleted rows on request — written over the copy, and a table whose changes
 * cannot be seen named. Backfill: history window by window into a hypertable, its compression
 * policy out of the way and back, each window remembered so a second run reads nothing, chunks
 * compressed as windows finish and decompressed when a window is written again.
 */
#[CoversClass(Migrator::class)]
#[CoversClass(\Pramnos\Database\FromMysql\FdwTransport::class)]
#[CoversClass(ProgressTracker::class)]
#[CoversClass(DatabaseMigrateFromMysql::class)]
final class MigrateFromMysqlPhasesTest extends TestCase
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
    private static function server(string $type, ?string $database = null): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $type === 'mysql' ? 'db' : 'timescaledb';
        $db->port     = $type === 'mysql' ? 3306 : 5432;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = $database ?? TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                self::markTestSkipped($db->server . ' not reachable');
            }
        } catch (\Throwable $e) {
            self::markTestSkipped($db->server . ' not reachable: ' . $e->getMessage());
        }

        return $db;
    }

    /**
     * Two empty databases of the same name, the statements run in each, and the command over
     * the PostgreSQL one.
     *
     * @param list<string> $mysql
     * @param list<string> $postgres
     */
    private function databases(array $mysql, array $postgres): CommandTester
    {
        $this->mysql    = self::server('mysql');
        $this->postgres = self::server('postgresql');
        $this->name     = 'pfphase_' . bin2hex(random_bytes(3));
        $this->mysql->query('CREATE DATABASE `' . $this->name . '` CHARACTER SET utf8mb4');
        $this->postgres->query('CREATE DATABASE "' . $this->name . '"');
        $this->source = self::server('mysql', $this->name);
        $this->target = self::server('postgresql', $this->name);
        foreach ($mysql as $sql) {
            $this->source->query($sql);
        }
        foreach ($postgres as $sql) {
            $this->target->query($sql);
        }
        $app = clone (\Pramnos\Application\Application::currentInstance() ?? \Pramnos\Application\Application::getInstance());
        $app->database = $this->target;
        $console = new ConsoleApplication();
        $console->add(new class ($app) extends DatabaseMigrateFromMysql {
            public function __construct(private \Pramnos\Application\Application $app)
            {
                parent::__construct();
            }

            /** The test's application. */
            protected function application(): ?\Pramnos\Application\Application
            {
                return $this->app;
            }
        });

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
     * Operational, then changes in MySQL, then the cutover: the new row arrives, the changed row
     * is written over, the deleted row is deleted, a table without a change column is named, a
     * table the operational copy never saw is named, and the sequence continues past the new id.
     */
    public function testOperationalThenCutover(): void
    {
        // Arrange
        $tester = $this->databases([
            'CREATE TABLE users (userid INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50)) ENGINE=InnoDB',
            "INSERT INTO users VALUES (1, 'maria'), (2, 'nikos')",
            'CREATE TABLE items (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(20), updated_at DATETIME) ENGINE=InnoDB',
            "INSERT INTO items VALUES (1, 'one', '2026-01-01 00:00:00'), (2, 'two', '2026-01-01 00:00:00'), (3, 'three', '2026-01-01 00:00:00')",
            'CREATE TABLE notes (id INT PRIMARY KEY, body VARCHAR(20)) ENGINE=InnoDB',
            "INSERT INTO notes VALUES (1, 'n')",
        ], [
            'CREATE TABLE users (userid SERIAL PRIMARY KEY, username VARCHAR(50))',
            'CREATE TABLE items (id SERIAL PRIMARY KEY, label VARCHAR(20), updated_at TIMESTAMP)',
            'CREATE TABLE notes (id INT PRIMARY KEY, body VARCHAR(20))',
        ]);

        // Act — the copy while the application runs
        $copied = $tester->execute(['--source' => $this->dsn(), '--phase' => 'operational', '--tables' => 'users,items']);
        $copyText = $tester->getDisplay();
        // …the application keeps writing to MySQL…
        sleep(1);
        foreach ([
            "INSERT INTO users (username) VALUES ('eleni')",
            "UPDATE users SET username = 'NIKOS' WHERE userid = 2",
            "UPDATE items SET label = 'TWO', updated_at = NOW() WHERE id = 2",
            'DELETE FROM items WHERE id = 3',
        ] as $sql) {
            $this->source->query($sql);
        }
        // …and the cutover, with writes stopped
        $cut  = $tester->execute(['--source' => $this->dsn(), '--phase' => 'cutover', '--tables' => 'users,items,notes', '--with-deletes' => true]);
        $text = $tester->getDisplay();
        $this->target->query("INSERT INTO users (username) VALUES ('next')");

        // Assert
        $this->assertSame(0, $copied, $copyText);
        $this->assertStringContainsString('Stop the application', $copyText);
        $this->assertSame(0, $cut, $text);
        $this->assertSame(['maria', 'nikos', 'eleni', 'next'], array_column($this->rows('SELECT username FROM users ORDER BY userid'), 'username'),
            'the new user arrived; the renamed one was not seen — users has no change column');
        $this->assertStringContainsString('users: no change column', $text);
        $this->assertSame([['id' => 1, 'label' => 'one'], ['id' => 2, 'label' => 'TWO']], array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => $r['label']], $this->rows('SELECT id, label FROM items ORDER BY id')));
        $this->assertStringContainsString('items: 1 row(s) deleted since the copy', $text);
        $this->assertStringContainsString('notes: not copied in the operational phase', $text);
        $this->assertSame(4, (int) $this->rows("SELECT userid FROM users WHERE username = 'next'")[0]['userid'], 'the sequence continues');
        $this->assertCount(2, $this->rows("SELECT * FROM pramnos_migration_progress WHERE phase = 'cutover'"));
    }

    /**
     * The backfill into a hypertable: windows newest first up to `--until`, nothing after it;
     * the compression policy removed and put back; each finished window's chunks compressed; a
     * second run reads nothing; `--restart` writes the windows again over compressed chunks; a
     * table without the time column is named.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('transports')]
    public function testBackfillIntoAHypertable(string $transport): void
    {
        // Arrange — ninety days of history and one row after the cutover's moment
        $mysql = ['CREATE TABLE metrics (id INT AUTO_INCREMENT PRIMARY KEY, time DATETIME NOT NULL, device INT, value DOUBLE) ENGINE=InnoDB',
            'CREATE TABLE plain (id INT PRIMARY KEY) ENGINE=InnoDB'];
        $values = [];
        for ($day = 0; $day < 90; $day++) {
            $values[] = "('" . gmdate('Y-m-d H:i:s', strtotime('2026-03-01 12:00:00 UTC') + $day * 86400) . "', 1, " . $day . ')';
        }
        $mysql[] = 'INSERT INTO metrics (time, device, value) VALUES ' . implode(', ', $values) . ", ('2026-07-15 00:00:00', 1, 999)";
        $tester = $this->databases($mysql, [
            'CREATE EXTENSION IF NOT EXISTS timescaledb',
            'CREATE TABLE metrics (time TIMESTAMPTZ NOT NULL, device INT, value DOUBLE PRECISION, UNIQUE (time, device))',
            "SELECT create_hypertable('metrics', 'time', chunk_time_interval => INTERVAL '7 days')",
            "ALTER TABLE metrics SET (timescaledb.compress, timescaledb.compress_segmentby = 'device')",
            "SELECT add_compression_policy('metrics', INTERVAL '30 days')",
            'CREATE TABLE plain (id INT PRIMARY KEY)',
        ]);
        $options = ['--source' => $this->dsn(), '--phase' => 'backfill', '--tables' => 'metrics', '--time-column' => 'time',
            '--chunk-interval' => '1 month', '--until' => '2026-07-01 00:00:00 UTC', '--throttle-ms' => '0', '--compress-after-chunk' => true, '--batch-size' => '20',
            '--transport' => $transport];

        // Act
        $first     = $tester->execute($options);
        $firstText = $tester->getDisplay();
        $policy    = $this->rows("SELECT config->>'compress_after' AS a FROM timescaledb_information.jobs WHERE proc_name = 'policy_compression' AND hypertable_name = 'metrics'");
        $compressed = (int) $this->rows("SELECT COUNT(*) AS n FROM timescaledb_information.chunks WHERE hypertable_name = 'metrics' AND is_compressed")[0]['n'];
        $tester->execute($options);
        $secondText = $tester->getDisplay();
        $tester->execute($options + ['--restart' => true]);
        $restartText = $tester->getDisplay();
        $count = (int) $this->rows('SELECT COUNT(*) AS n FROM metrics')[0]['n'];
        $tester->execute(['--source' => $this->dsn(), '--phase' => 'backfill', '--tables' => 'plain', '--time-column' => 'time']);
        $noColumn = $tester->getDisplay();
        $badInterval = $tester->execute(['--chunk-interval' => '0 days'] + $options);
        $badIntervalText = $tester->getDisplay();
        $nonsense = $tester->execute(['--chunk-interval' => 'banana'] + $options);
        $policyAfter = $this->rows("SELECT 1 FROM timescaledb_information.jobs WHERE proc_name = 'policy_compression' AND hypertable_name = 'metrics'");

        // Assert
        $this->assertSame(0, $first, $firstText);
        $this->assertSame(90, $count, 'every row before --until, and not the one after');
        $this->assertMatchesRegularExpression('/metrics: \d+ window\(s\), 90 inserted' . ($transport === 'fdw' ? ', \d+ through mysql_fdw' : '') . ', \d+ chunk\(s\) compressed, compression policy put back/', $firstText);
        $this->assertStringNotContainsString('mysql_fdw refused', $firstText, 'every window moved the way it was asked to');
        $this->assertSame([['a' => '30 days']], $policy, 'the policy, back as it was');
        $this->assertGreaterThan(0, $compressed);
        $this->assertMatchesRegularExpression('/metrics: 0 window\(s\), 0 inserted, \d+ window\(s\) done before/', $secondText, 'a second run reads nothing');
        $this->assertMatchesRegularExpression('/metrics: \d+ window\(s\), 0 inserted/', $restartText, 'written again over compressed chunks, adding nothing');
        $this->assertStringContainsString('plain: has no column time', $noColumn);
        $this->assertSame(1, $badInterval);
        $this->assertStringContainsString('does not move back in time', $badIntervalText);
        $this->assertSame(1, $nonsense);
        $this->assertCount(1, $policyAfter, 'a refused interval leaves the policy where it was');
    }

    /** @return array<string, array{string}> */
    public static function transports(): array
    {
        return ['through PHP' => ['php'], 'through mysql_fdw' => ['fdw']];
    }

    /** A backfill without its tables or time column is refused before anything runs. */
    public function testABackfillNamesItsTablesAndColumn(): void
    {
        // Arrange
        $tester = $this->databases([], []);

        // Act
        $code = $tester->execute(['--source' => $this->dsn(), '--phase' => 'backfill', '--tables' => 'metrics']);

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('A backfill names its tables and the column its windows are cut on', $tester->getDisplay());
    }

    /**
     * Unix times in integer columns, as legacy tables keep them: the cutover reads changes by an
     * integer change column it is named; the backfill cuts windows on an integer time column into
     * a plain table, pausing between batches; an empty table has no windows; a table without a
     * primary key is named in the cutover rather than half moved.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('transports')]
    public function testUnixTimesInIntegerColumns(string $transport): void
    {
        // Arrange
        $now    = time();
        $tester = $this->databases([
            'CREATE TABLE ticks (id INT AUTO_INCREMENT PRIMARY KEY, n INT, changed INT) ENGINE=InnoDB',
            'INSERT INTO ticks VALUES (1, 1, 100), (2, 2, 100)',
            'CREATE TABLE loose (a INT, b VARCHAR(5)) ENGINE=InnoDB',
            "INSERT INTO loose VALUES (1, 'x')",
            'CREATE TABLE events (id INT PRIMARY KEY, at INT NOT NULL, v INT) ENGINE=InnoDB',
            'INSERT INTO events VALUES (1, ' . ($now - 25 * 86400) . ', 1), (2, ' . ($now - 15 * 86400) . ', 2), (3, ' . ($now - 5 * 86400) . ', 3)',
            'CREATE TABLE quiet (id INT PRIMARY KEY, at INT) ENGINE=InnoDB',
        ], [
            'CREATE TABLE ticks (id SERIAL PRIMARY KEY, n INT, changed INT)',
            'CREATE TABLE loose (a INT, b VARCHAR(5))',
            'CREATE TABLE events (id INT PRIMARY KEY, at TIMESTAMPTZ NOT NULL, v INT)',
            'CREATE TABLE quiet (id INT PRIMARY KEY, at TIMESTAMPTZ)',
        ]);

        // Act
        $tester->execute(['--source' => $this->dsn(), '--phase' => 'operational', '--tables' => 'ticks,loose']);
        $this->source->query('UPDATE ticks SET n = 20, changed = ' . (time() + 5) . ' WHERE id = 2');
        $tester->execute(['--source' => $this->dsn(), '--phase' => 'cutover', '--tables' => 'ticks,loose', '--changed-column' => 'changed']);
        $cutText = $tester->getDisplay();
        $back = $tester->execute(['--source' => $this->dsn(), '--phase' => 'backfill', '--tables' => 'events,quiet', '--time-column' => 'at',
            '--chunk-interval' => '10 days', '--throttle-ms' => '1', '--batch-size' => '1', '--until' => '@' . $now, '--transport' => $transport]);
        $backText = $tester->getDisplay();

        // Assert
        $this->assertSame([1, 20], array_map('intval', array_column($this->rows('SELECT n FROM ticks ORDER BY id'), 'n')), 'the integer change column found the change');
        $this->assertStringContainsString('loose: no single primary key or change column', $cutText);
        $this->assertSame(0, $back, $backText);
        $this->assertStringContainsString('events: 3 window(s), 3 inserted' . ($transport === 'fdw' ? ', 3 through mysql_fdw' : ''), $backText);
        $this->assertStringContainsString('quiet: 0 window(s), 0 inserted', $backText, 'an empty table has no windows');
        $this->assertSame(3, (int) $this->rows('SELECT COUNT(*) AS n FROM events WHERE at < NOW()')[0]['n'], 'the unix times as moments');
    }
}
