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
#[CoversClass(\Pramnos\Database\FromMysql\KeyRanges::class)]
#[CoversClass(\Pramnos\Database\FromMysql\RowConverter::class)]
#[CoversClass(\Pramnos\Database\FromMysql\FdwTransport::class)]
#[CoversClass(ProgressTracker::class)]
#[CoversClass(DatabaseMigrateFromMysql::class)]
class MigrateFromMysqlPhasesTest extends TestCase
{
    /** The source server: MySQL here, MariaDB in the subclass that runs with `--mariadb`. */
    protected static function sourceHost(): string
    {
        return 'db';
    }

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
    protected static function server(string $type, ?string $database = null): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $type === 'mysql' ? static::sourceHost() : 'timescaledb';
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
        return 'mysql://root:secret@' . static::sourceHost() . ':3306/' . $this->name;
    }

    /**
     * Operational, then changes in MySQL, then the cutover, then a verification.
     *
     * The operational copy reads each table a key range at a time (`--no-snapshot`,
     * `--batch-size=1`, so every table takes several). The cutover then catches each table up the
     * way it can: by its change column (`items`), by comparing every row with the target where it
     * has none — a single key (`users`) or a composite one (`pairs`) — and, for a table named
     * append-only, by its new rows alone (`log`), saying what that leaves unseen. Deletions are
     * found by walking the target's keys, composite ones too. A table the copy never saw is
     * named. The verification compares every row: what append-only left behind and a row only
     * the target has are both found, and it fails; the tables caught up compare equal.
     */
    public function testOperationalThenCutover(): void
    {
        // Arrange
        $tester = $this->databases([
            'CREATE TABLE users (userid INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50)) ENGINE=InnoDB',
            "INSERT INTO users VALUES (1, 'maria'), (2, 'nikos')",
            'CREATE TABLE items (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(20), updated_at DATETIME) ENGINE=InnoDB',
            "INSERT INTO items VALUES (1, 'one', '2026-01-01 00:00:00'), (2, 'two', '2026-01-01 00:00:00'), (3, 'three', '2026-01-01 00:00:00')",
            'CREATE TABLE pairs (a INT, b VARCHAR(5), v INT, PRIMARY KEY (a, b)) ENGINE=InnoDB',
            "INSERT INTO pairs VALUES (1, 'x', 1), (1, 'y', 2), (2, 'x', 3)",
            'CREATE TABLE log (id INT AUTO_INCREMENT PRIMARY KEY, msg VARCHAR(20)) ENGINE=InnoDB',
            "INSERT INTO log VALUES (1, 'start')",
            'CREATE TABLE notes (id INT PRIMARY KEY, body VARCHAR(20)) ENGINE=InnoDB',
            "INSERT INTO notes VALUES (1, 'n')",
        ], [
            'CREATE TABLE users (userid SERIAL PRIMARY KEY, username VARCHAR(50))',
            'CREATE TABLE items (id SERIAL PRIMARY KEY, label VARCHAR(20), updated_at TIMESTAMP)',
            'CREATE TABLE pairs (a INT, b VARCHAR(5), v INT, PRIMARY KEY (a, b))',
            'CREATE TABLE log (id SERIAL PRIMARY KEY, msg VARCHAR(20))',
            'CREATE TABLE notes (id INT PRIMARY KEY, body VARCHAR(20))',
        ]);
        $moving = ['--source' => $this->dsn(), '--tables' => 'users,items,pairs,log'];

        // Act — the copy while the application runs, no transaction held open on MySQL
        $copied   = $tester->execute($moving + ['--phase' => 'operational', '--no-snapshot' => true, '--batch-size' => '1']);
        $copyText = $tester->getDisplay();
        // …the application keeps writing to MySQL. The changed row is stamped a minute ahead
        // rather than after a sleep: the machine's clock can step back between two readings.
        foreach ([
            "INSERT INTO users (username) VALUES ('eleni')",
            "UPDATE users SET username = 'NIKOS' WHERE userid = 2",
            "UPDATE items SET label = 'TWO', updated_at = NOW() + INTERVAL 1 MINUTE WHERE id = 2",
            'DELETE FROM items WHERE id = 3',
            "UPDATE pairs SET v = 20 WHERE a = 1 AND b = 'y'",
            "INSERT INTO pairs VALUES (3, 'z', 4)",
            "DELETE FROM pairs WHERE a = 2 AND b = 'x'",
            "INSERT INTO log (msg) VALUES ('more')",
            "UPDATE log SET msg = 'started' WHERE id = 1",
        ] as $sql) {
            $this->source->query($sql);
        }
        // …and the cutover, with writes stopped
        $cut  = $tester->execute(['--source' => $this->dsn(), '--tables' => 'users,items,pairs,log,notes', '--phase' => 'cutover', '--with-deletes' => true,
            '--append-only' => 'log', '--batch-size' => '1'], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);
        $text = $tester->getDisplay();
        $this->target->query("INSERT INTO users (username) VALUES ('next')");
        $this->target->query("INSERT INTO pairs VALUES (9, 'q', 0)");
        $verified = $tester->execute($moving + ['--phase' => 'verify', '--batch-size' => '2']);
        $report   = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $copied, $copyText);
        $this->assertStringContainsString('Stop the application', $copyText);
        $this->assertSame(0, $cut, $text);
        $this->assertSame(['maria', 'NIKOS', 'eleni', 'next'], array_column($this->rows('SELECT username FROM users ORDER BY userid'), 'username'),
            'users has no change column: compared, the new user and the renamed one both found');
        $this->assertStringContainsString('users: compared: 1 new, 1 changed of 3', $text);
        $this->assertSame([['id' => 1, 'label' => 'one'], ['id' => 2, 'label' => 'TWO']], array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => $r['label']], $this->rows('SELECT id, label FROM items ORDER BY id')));
        $this->assertStringContainsString('items: changed by updated_at', $text);
        $this->assertStringContainsString('items: 1 row(s) deleted since the copy', $text);
        $this->assertSame(['1|x|1', '1|y|20', '3|z|4', '9|q|0'], array_map(static fn (array $r): string => $r['a'] . '|' . $r['b'] . '|' . $r['v'], $this->rows('SELECT a, b, v FROM pairs ORDER BY a, b')),
            'the composite key: changed, new and deleted rows all caught up (9|q was written after)');
        $this->assertStringContainsString('pairs: 1 row(s) deleted since the copy', $text);
        $this->assertSame(['start', 'more'], array_column($this->rows('SELECT msg FROM log ORDER BY id'), 'msg'), 'append-only: the new row, not the change');
        $this->assertStringContainsString('log: append-only: rows that existed at the copy and changed since are not seen', $text);
        $this->assertStringContainsString('notes: not copied in the operational phase', $text);
        $this->assertSame(4, (int) $this->rows("SELECT userid FROM users WHERE username = 'next'")[0]['userid'], 'the sequence continues');
        $this->assertCount(4, $this->rows("SELECT * FROM pramnos_migration_progress WHERE phase = 'cutover'"));

        $this->assertSame(1, $verified, $report);
        $this->assertStringContainsString('✓ items: 2 row(s) in MySQL, 2 here', $report);
        $this->assertStringContainsString('✗ log: 2 row(s) in MySQL, 2 here, 1 different — e.g. key 1', $report, 'what append-only left behind');
        $this->assertStringContainsString('✗ pairs: 3 row(s) in MySQL, 4 here, 1 MySQL has not — e.g. key 9, q', $report);
        $this->assertStringContainsString('✗ users: 3 row(s) in MySQL, 4 here, 1 MySQL has not', $report, 'a row the application wrote here since');
    }

    /**
     * An operational copy that stopped is taken up where it stopped. A table it finished is not
     * read again. The table it was copying when it stopped is read on past the highest id already
     * here, and keeps the moment and highest id of the attempt that began it, so the cutover
     * reads changes from the earlier moment and misses nothing between the two. `--restart`
     * copies everything again.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('transports')]
    public function testAStoppedOperationalCopyIsTakenUp(string $transport): void
    {
        // Arrange — a first copy, then the state a stop half way through `items` leaves
        $tester = $this->databases([
            'CREATE TABLE users (userid INT PRIMARY KEY, username VARCHAR(50)) ENGINE=InnoDB',
            "INSERT INTO users VALUES (1, 'maria')",
            'CREATE TABLE items (id INT PRIMARY KEY, label VARCHAR(20)) ENGINE=InnoDB',
            "INSERT INTO items VALUES (1, 'a'), (2, 'b'), (3, 'c'), (4, 'd')",
        ], [
            'CREATE TABLE users (userid INT PRIMARY KEY, username VARCHAR(50))',
            'CREATE TABLE items (id INT PRIMARY KEY, label VARCHAR(20))',
        ]);
        $copy = ['--source' => $this->dsn(), '--tables' => 'users,items', '--phase' => 'operational', '--transport' => $transport];
        $tester->execute($copy);
        $began = $this->rows("SELECT chunk_end AS e, detail FROM pramnos_migration_progress WHERE table_name = 'items' AND phase = 'operational'")[0];
        $this->target->query("UPDATE pramnos_migration_progress SET status = 'IN_PROGRESS', chunk_end = chunk_end - 3600 WHERE table_name = 'items' AND phase = 'operational'");
        $this->target->query('DELETE FROM items WHERE id > 2');
        $this->source->query("INSERT INTO items VALUES (5, 'e')");

        // Act
        $resumed = $tester->execute($copy);
        $text    = $tester->getDisplay();
        $items   = array_column($this->rows('SELECT id FROM items ORDER BY id'), 'id');
        $kept    = (int) $this->rows("SELECT chunk_end AS e FROM pramnos_migration_progress WHERE table_name = 'items' AND phase = 'operational' AND status = 'COMPLETED'")[0]['e'];
        $tester->execute($copy + ['--restart' => true]);
        $restarted = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $resumed, $text);
        $this->assertStringContainsString('users: 0 inserted — copied by an earlier run', $text);
        $this->assertMatchesRegularExpression('/items: 3 inserted( \(mysql_fdw\))? — taken up after id 2/u', $text, 'only past the highest id here, the new row among them');
        $this->assertSame([1, 2, 3, 4, 5], array_map('intval', $items));
        $this->assertSame((int) $began['e'] - 3600, $kept, 'the moment of the attempt that began it, not this one');
        $this->assertStringNotContainsString('copied by an earlier run', $restarted, '--restart copies again');
    }

    /**
     * A `datetime` has no zone: it is the wall-clock time of whoever wrote it, and going into a
     * `timestamptz` it names a moment only in `--source-timezone`. A `timestamp` is a moment,
     * whatever zone the writing session had. And the cutover finds a change by its `datetime`
     * column in that zone — a zone behind UTC compared as UTC would miss every change of the
     * last hours. The dry run says how the values are read, and that the server's zone differs.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('transports')]
    public function testAWallClockIsReadInItsZone(string $transport): void
    {
        // Arrange — written in New York time; the TIMESTAMPs by a session at UTC+05:00
        $tester = $this->databases([
            'CREATE TABLE stamps (id INT PRIMARY KEY, label VARCHAR(10), wall DATETIME, at TIMESTAMP NULL, seen TIMESTAMP NULL, updated_at DATETIME) ENGINE=InnoDB',
            "SET time_zone = '+05:00'",
            "INSERT INTO stamps VALUES (1, 'one', '2026-01-15 12:00:00', '2026-01-15 17:00:00', '2026-01-15 17:00:00', '2026-01-01 00:00:00')",
        ], [
            'CREATE TABLE stamps (id INT PRIMARY KEY, label VARCHAR(10), wall TIMESTAMPTZ, at TIMESTAMPTZ, seen TIMESTAMP, updated_at TIMESTAMP)',
        ]);
        $zone = ['--source' => $this->dsn(), '--source-timezone' => 'America/New_York'];

        // Act
        $tester->execute($zone + ['--dry-run' => true]);
        $dry = $tester->getDisplay();
        $tester->execute($zone + ['--phase' => 'operational', '--transport' => $transport]);
        $moved = $this->rows('SELECT extract(epoch FROM wall)::bigint AS w, extract(epoch FROM at)::bigint AS a, seen::text AS s FROM stamps')[0] ?? [];
        // …the application changes the row a minute after the snapshot, stamping it with New
        // York's clock. Counted from the moment the tool recorded, not from this clock: the
        // machine's clock can step back between two readings (it did, under WSL), and a minute
        // is still four hours short of the UTC reading a wrong zone would compare it with.
        $moment  = (int) ($this->rows("SELECT chunk_end FROM " . \Pramnos\Database\FromMysql\ProgressTracker::TABLE . " WHERE phase = 'operational'")[0]['chunk_end'] ?? 0);
        $changed = (new \DateTimeImmutable('@' . ($moment + 60)))->setTimezone(new \DateTimeZone('America/New_York'));
        $this->source->query("UPDATE stamps SET label = 'ONE', updated_at = '" . $changed->format('Y-m-d H:i:s') . "' WHERE id = 1");
        $cut   = $tester->execute($zone + ['--phase' => 'cutover']);
        $label = $this->rows('SELECT label FROM stamps')[0]['label'] ?? null;
        $badZone = $tester->execute(['--source' => $this->dsn(), '--source-timezone' => 'Mars/Olympus', '--dry-run' => true]);

        // Assert
        $this->assertStringContainsString('read as America/New_York time: stamps.wall', $dry);
        $this->assertStringContainsString('name that zone with --source-timezone', $dry, 'the server runs in UTC, not New York');
        $this->assertSame(gmmktime(17, 0, 0, 1, 15, 2026), (int) $moved['w'], 'noon in New York is 17:00 UTC');
        $this->assertSame(gmmktime(12, 0, 0, 1, 15, 2026), (int) $moved['a'], 'the TIMESTAMP is the moment, not the session\'s wall clock');
        $this->assertSame('2026-01-15 07:00:00', $moved['s'], 'a moment into a column without a zone: New York\'s wall clock');
        $this->assertSame(0, $cut, $tester->getDisplay());
        $this->assertSame('ONE', $label, 'the change was found by its New York time');
        $this->assertSame(1, $badZone);
        $this->assertStringContainsString('No time zone Mars/Olympus', $tester->getDisplay());
    }

    /**
     * Triggers, views, procedures, functions and events carry MySQL's logic in MySQL's dialect;
     * none of it moves. The dry run names each, with what it hangs on, and whether the target
     * already has one of that name — so the one the migrations forgot stands out.
     */
    public function testTheDryRunNamesWhatIsNotATable(): void
    {
        // Arrange
        $tester = $this->databases([
            'CREATE TABLE orders (id INT PRIMARY KEY, total INT) ENGINE=InnoDB',
            'CREATE TRIGGER orders_bi BEFORE INSERT ON orders FOR EACH ROW SET NEW.total = IFNULL(NEW.total, 0)',
            'CREATE VIEW big_orders AS SELECT * FROM orders WHERE total > 100',
            'CREATE FUNCTION twice(x INT) RETURNS INT DETERMINISTIC RETURN x * 2',
            'CREATE EVENT nightly ON SCHEDULE EVERY 1 DAY DO DELETE FROM orders WHERE total = 0',
        ], [
            'CREATE TABLE orders (id INT PRIMARY KEY, total INT)',
            'CREATE VIEW big_orders AS SELECT * FROM orders WHERE total > 100',
        ]);

        // Act
        $tester->execute(['--source' => $this->dsn(), '--dry-run' => true]);
        $dry = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('Not moved — MySQL logic the migrations are to make again:', $dry);
        $this->assertStringContainsString('trigger orders_bi (on orders) — the target has none', $dry);
        $this->assertStringContainsString('view big_orders — the target has one of that name', $dry);
        $this->assertStringContainsString('function twice — the target has none', $dry);
        $this->assertStringContainsString('event nightly — the target has none', $dry);
    }

    /**
     * A backfill window is a range on the time column; without an index in MySQL that starts
     * with it, each window reads the whole table — years of history, window after window. The
     * dry run names such a table with the index to add, and says nothing of one that has it,
     * whether as a plain index or the first column of a composite one.
     */
    public function testTheDryRunNamesATimeColumnWithoutAnIndex(): void
    {
        // Arrange
        $tester = $this->databases([
            'CREATE TABLE bare (id INT PRIMARY KEY, time DATETIME) ENGINE=InnoDB',
            'CREATE TABLE indexed (id INT PRIMARY KEY, time DATETIME, KEY (time)) ENGINE=InnoDB',
            'CREATE TABLE firstcol (id INT PRIMARY KEY, time DATETIME, device INT, KEY (time, device)) ENGINE=InnoDB',
            'CREATE TABLE secondcol (id INT PRIMARY KEY, time DATETIME, device INT, KEY (device, time)) ENGINE=InnoDB',
        ], [
            'CREATE TABLE bare (id INT PRIMARY KEY, time TIMESTAMPTZ)',
            'CREATE TABLE indexed (id INT PRIMARY KEY, time TIMESTAMPTZ)',
            'CREATE TABLE firstcol (id INT PRIMARY KEY, time TIMESTAMPTZ, device INT)',
            'CREATE TABLE secondcol (id INT PRIMARY KEY, time TIMESTAMPTZ, device INT)',
        ]);

        // Act
        $tester->execute(['--source' => $this->dsn(), '--dry-run' => true, '--time-column' => 'time']);
        $dry = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('CREATE INDEX bare_time ON `bare` (`time`) before the backfill', $dry);
        $this->assertStringContainsString('CREATE INDEX secondcol_time ON `secondcol` (`time`)', $dry, 'second in an index is no help to a range on it');
        $this->assertStringNotContainsString('INDEX indexed_time', $dry);
        $this->assertStringNotContainsString('INDEX firstcol_time', $dry);
    }

    /**
     * A hypertable keeps its rows without MySQL's key, so it is checked by time window. The
     * backfill counts each window as it writes it: two MySQL rows that are one row here (the
     * target's `UNIQUE (time, device)` took the first) make March a window that lost a row, named
     * at once, and the run fails. `--phase=verify --time-column` digests every window on both
     * sides: it finds March again, and a value changed here in April — the same count, a
     * different digest — and calls the other windows equal. `--verify` on the backfill digests as
     * it goes.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('transports')]
    public function testEachWindowIsChecked(string $transport): void
    {
        // Arrange — March to May, one row a day, and March 5th twice
        $values = [];
        for ($day = 0; $day < 90; $day++) {
            $values[] = "('" . gmdate('Y-m-d H:i:s', strtotime('2026-03-01 12:00:00 UTC') + $day * 86400) . "', 1, " . $day . ')';
        }
        $tester = $this->databases([
            'CREATE TABLE metrics (id INT AUTO_INCREMENT PRIMARY KEY, time DATETIME NOT NULL, device INT, value DOUBLE) ENGINE=InnoDB',
            'INSERT INTO metrics (time, device, value) VALUES ' . implode(', ', $values) . ", ('2026-03-05 12:00:00', 1, 4)",
        ], [
            'CREATE EXTENSION IF NOT EXISTS timescaledb',
            'CREATE TABLE metrics (time TIMESTAMPTZ NOT NULL, device INT, value DOUBLE PRECISION, UNIQUE (time, device))',
            "SELECT create_hypertable('metrics', 'time', chunk_time_interval => INTERVAL '7 days')",
        ]);
        $window = ['--source' => $this->dsn(), '--tables' => 'metrics', '--time-column' => 'time', '--chunk-interval' => '1 month', '--until' => '2026-06-01 00:00:00 UTC'];

        // Act
        $backfill = $tester->execute($window + ['--phase' => 'backfill', '--throttle-ms' => '0', '--transport' => $transport]);
        $written  = $tester->getDisplay();
        $this->target->query("UPDATE metrics SET value = -1 WHERE time = '2026-04-10 12:00:00+00'");
        $verified = $tester->execute($window + ['--phase' => 'verify', '--batch-size' => '7']);
        $report   = $tester->getDisplay();
        $digested = $tester->execute($window + ['--phase' => 'backfill', '--throttle-ms' => '0', '--restart' => true, '--verify' => true]);
        $again    = $tester->getDisplay();

        // Assert
        $this->assertSame(1, $backfill, $written);
        $this->assertStringContainsString('window 2026-03-01 00:00 → 2026-04-01 00:00: 32 row(s) in MySQL, 31 here', $written, 'counted as it was written');
        $this->assertSame(1, $verified, $report);
        $this->assertStringContainsString('3 window(s) compared', $report);
        $this->assertStringContainsString('window 2026-03-01 00:00 → 2026-04-01 00:00: 32 in MySQL, 31 here', $report);
        $this->assertStringContainsString('window 2026-04-01 00:00 → 2026-05-01 00:00: 30 in MySQL, 30 here, different', $report, 'the same count, not the same rows');
        $this->assertStringNotContainsString('2026-05-01 00:00 → 2026-06-01', $report, 'May is equal');
        $this->assertSame(1, $digested);
        $this->assertStringContainsString('window 2026-04-01 00:00 → 2026-05-01 00:00: 30 row(s) in MySQL, 30 here, different', $again, 'digested as it went');
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
        $this->assertStringContainsString('loose: no primary key the target has', $cutText);
        $this->assertSame(0, $back, $backText);
        $this->assertStringContainsString('events: 3 window(s), 3 inserted' . ($transport === 'fdw' ? ', 3 through mysql_fdw' : ''), $backText);
        $this->assertStringContainsString('quiet: 0 window(s), 0 inserted', $backText, 'an empty table has no windows');
        $this->assertSame(3, (int) $this->rows('SELECT COUNT(*) AS n FROM events WHERE at < NOW()')[0]['n'], 'the unix times as moments');
    }
}
