<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\MailHypertable as MailHypertableCommand;
use Pramnos\Database\Database;
use Pramnos\Email\MailHypertable;
use Pramnos\Email\MailPersonalData;
use Pramnos\Email\Retention;
use Pramnos\Framework\Factory;
use Symfony\Component\Console\Tester\CommandTester;

/** A transport that keeps the HTML of each message exactly as it went out. */
class HypertableCapture extends \Symfony\Component\Mailer\Transport\AbstractTransport
{
    /** @var list<string> */
    public array $html = [];

    /** Keep the message's HTML. */
    protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
    {
        $original     = $message->getOriginalMessage();
        $this->html[] = $original instanceof \Symfony\Component\Mime\Email ? (string) $original->getHtmlBody() : '';
    }

    /** The transport's name. */
    public function __toString(): string
    {
        return 'hypertable-capture://';
    }
}

/**
 * `mails` made a TimescaleDB hypertable with the site up (mail body storage, phase 7): the
 * conversion is a rename and a new table, new mail goes to it at once with the right ids, the old
 * rows move newest first in batches a run can stop and resume, and what reaches every message —
 * an erasure, a retention policy, a person's copy — reads both tables while they do. In a
 * database of its own: index names are the schema's, and the suite's `mails` keeps its shape.
 */
#[CoversClass(MailHypertable::class)]
#[CoversClass(MailHypertableCommand::class)]
#[CoversClass(MailPersonalData::class)]
#[CoversClass(Retention::class)]
#[CoversClass(Database::class)]
#[CoversClass(\Pramnos\Email\Email::class)]
final class MailHypertableTest extends TestCase
{
    private const PROBE = 'pramnos_mailht_probe';

    private ?Database $db = null;

    private ?Database $previous = null;

    /** A connection to `$name` on the TimescaleDB container. */
    private static function connection(string $name): Database
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = $name;

        return $db;
    }

    protected function setUp(): void
    {
        $admin = self::connection(TEST_DATABASE);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped('timescaledb not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('timescaledb not reachable: ' . $e->getMessage());
        }
        if (!$admin->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('No TimescaleDB on this connection.');
        }
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->query('CREATE DATABASE ' . self::PROBE);
        $admin->close();
        $this->db = self::connection(self::PROBE);
        $this->db->connect(false);
        $this->db->query('CREATE EXTENSION IF NOT EXISTS timescaledb');
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            \Pramnos\Framework\Migrations\Messaging\AddBodyvarsToMails::class,
        ], $this->db);
        \Pramnos\Email\BodyStore::forget();
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        \Pramnos\Email\BodyStore::forget();
        if ($this->db !== null) {
            $this->db->close();
            $admin = self::connection(TEST_DATABASE);
            if ($admin->connect(false)) {
                $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
                $admin->close();
            }
        }
    }

    /** A message to `$to`, `$daysAgo` old; its id. */
    private function mail(string $to, int $daysAgo, string $content = 'body', int $status = 1): int
    {
        $this->db->queryBuilder()->table('mails')->insert([
            'status' => $status, 'frommail' => 'site@example.com', 'fromname' => 'Site', 'tomail' => $to, 'toname' => 'Them',
            'subject' => 'Message to ' . $to, 'content' => $content, 'date' => time() - 86400 * $daysAgo, 'module' => 'ht',
            'moduleinfo' => '', 'extrainfo' => '', 'path' => '', 'hash' => md5($to . $daysAgo),
        ]);

        return (int) $this->db->getInsertId();
    }

    /** How many rows a table holds. */
    private function rowsIn(string $table): int
    {
        return $this->db->queryBuilder()->table($table)->count();
    }

    /**
     * Converted, the table is a hypertable at once and new mail goes to it with the id it was
     * given — also the message that opens a chunk. The old rows move newest first, a run stopped
     * part way is resumed by the next, and once they are all across the old table is gone and the
     * id sequence goes on.
     */
    public function testTheTableIsConvertedAndItsRowsMove(): void
    {
        // Arrange — a year and a half of mail
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = $this->mail('reader' . $i . '@example.com', $i * 30);
        }
        $mails = new MailHypertable($this->db);

        // Act
        $before = $mails->state();
        $mails->convert();
        $during = $mails->state();
        $fresh  = $this->mail('fresh@example.com', 0, 'body', 2);
        $opener = $this->mail('backdated@example.com', 3000);
        $newest = $this->db->queryBuilder()->table('mails')->select(['id'])->orderBy('id', 'desc')->first()->fields['id'];
        $first  = $mails->drain(7, 7);
        $moved  = (array) $this->db->queryBuilder()->table('mails')->select(['id'])->where('module', 'ht')->whereIn('id', $ids)->orderBy('id')->get()->fetchAll();
        $seen   = [];
        $rest   = $mails->drain(5, 0, function (int $moved, int $left) use (&$seen): void {
            $seen[] = [$moved, $left];
        });
        $after  = $this->mail('after@example.com', 0);
        $jobs   = $this->db->query("SELECT count(*) AS n FROM timescaledb_information.jobs WHERE hypertable_name = 'mails' AND proc_name = 'policy_compression'")->fields['n'];
        $index  = $this->db->query("SELECT indexdef FROM pg_indexes WHERE tablename = 'mails' AND indexname = 'idx_mails_status'")->fields['indexdef'];
        // The policy, run now: chunks past ninety days compress, and a row in one still updates.
        $job = $this->db->query("SELECT job_id FROM timescaledb_information.jobs WHERE hypertable_name = 'mails' AND proc_name = 'policy_compression'")->fields['job_id'];
        $this->db->query('CALL run_job(' . (int) $job . ')');
        $compressed = (int) $this->db->query("SELECT count(*) AS n FROM timescaledb_information.chunks WHERE hypertable_name = 'mails' AND is_compressed")->fields['n'];
        $this->db->queryBuilder()->table('mails')->where('id', $ids[19])->update(['status' => 0]);
        $updated = (int) $this->db->queryBuilder()->table('mails')->where('id', $ids[19])->first()->fields['status'];

        // Assert
        $this->assertSame([MailHypertable::PLAIN, MailHypertable::DRAINING], [$before, $during]);
        $this->assertSame(max($ids) + 1, $fresh, 'new mail goes to the new table, numbered on from the old');
        $this->assertSame($fresh + 1, $opener, 'the message that opened a chunk has its own id, not the chunk\'s');
        $this->assertSame($opener, (int) $newest);
        $this->assertSame(7, $first);
        $this->assertSame(array_slice($ids, -7), array_map(static fn (array $row): int => (int) $row['id'], $moved), 'newest first');
        $this->assertSame(13, $rest);
        $this->assertSame([10, 3], $seen[1], 'progress: moved, and left');
        $this->assertSame(MailHypertable::HYPERTABLE, $mails->state());
        $this->assertFalse($this->db->schema()->hasTable('mails_legacy'), 'the old table is gone');
        $this->assertSame(23, $this->rowsIn('mails'));
        $this->assertSame($opener + 1, $after, 'and the id sequence outlived it');
        $this->assertSame(1, (int) $jobs, 'old chunks are compressed by a policy');
        $this->assertStringContainsString('(status, date)', (string) $index, 'the outbox reads status and date from one index');
        $this->assertGreaterThan(0, $compressed, 'the policy compresses by the Unix timestamp');
        $this->assertSame(0, $updated, 'a message in a compressed chunk is still updated by its id');
        $this->assertSame(0, $mails->drain(), 'nothing left to move');
    }

    /** @return array<string, array{string}> The two kinds of identity column */
    public static function identities(): array
    {
        return ['by default' => ['BY DEFAULT'], 'always' => ['ALWAYS']];
    }

    /**
     * A table whose `id` is an IDENTITY column — every table `db:migrate-from-mysql` made — converts
     * and drains like a serial one.
     *
     * The conversion used to hand the old id sequence to the new table with `OWNED BY`, which an
     * identity's sequence refuses ("cannot change ownership of identity sequence"), so it rolled
     * back every time. The new column has an identity of its own now, numbered on from the old
     * table's highest id, so new mail never takes an id a row waiting to move still has; and the
     * move keeps each row's id, also under `GENERATED ALWAYS`.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('identities')]
    public function testATableWithAnIdentityIdConvertsAndDrains(string $kind): void
    {
        // Arrange — `mails.id` an identity, as a migrated table has it, and rows in it
        $this->db->query('ALTER TABLE mails ALTER COLUMN id DROP DEFAULT');
        $this->db->query('DROP SEQUENCE IF EXISTS mails_id_seq');
        $this->db->query('ALTER TABLE mails ALTER COLUMN id ADD GENERATED ' . $kind . ' AS IDENTITY');
        for ($i = 0; $i < 12; $i++) {
            $this->mail('reader' . $i . '@example.com', $i * 30);
        }
        $highest = (int) $this->db->query('SELECT MAX(id) AS m FROM mails')->fields['m'];
        $mails   = new MailHypertable($this->db);

        // Act
        $mails->convert();
        // This connection read the table's sequence before the conversion, as a mail worker
        // running through it has: getInsertId() must still answer the new row's id.
        $answered = $this->mail('fresh@example.com', 0);
        $fresh    = (int) $this->db->query("SELECT id FROM mails WHERE tomail = 'fresh@example.com'")->fields['id'];
        $moved = $mails->drain(5);
        $ids   = array_map(static fn (array $row): int => (int) $row['id'], $this->db->query('SELECT id FROM mails ORDER BY id')->fetchAll());

        // Assert
        $this->assertSame($highest + 1, $fresh, 'new mail is numbered on from the old table');
        $this->assertSame($fresh, $answered, 'the insert id read by its old name is the new table\'s');
        $this->assertSame(12, $moved);
        $this->assertSame(MailHypertable::HYPERTABLE, $mails->state());
        $this->assertSame(range(1, $highest + 1), $ids, 'every row kept its id; none taken twice');
        $this->assertSame('YES', $this->db->query("SELECT is_identity FROM information_schema.columns WHERE table_name = 'mails' AND column_name = 'id'")->fields['is_identity']);
        $this->assertSame($highest + 2, $this->mail('after@example.com', 0), 'the identity outlived the old table, under its old name');
    }

    /**
     * While the rows move, a person's copy, an erasure and a retention policy reach both tables:
     * nothing of theirs is missed for being in the old one.
     */
    public function testWhatReachesEveryMessageReadsBothTablesWhileTheyMove(): void
    {
        // Arrange
        $old  = $this->mail('person@example.com', 400, 'from long ago');
        $this->mail('other@example.com', 400, 'from long ago');
        $mails = new MailHypertable($this->db);
        $mails->convert();
        $new  = $this->mail('person@example.com', 0, 'from today');

        // Act
        $copy     = MailPersonalData::export('person@example.com');
        $erased   = MailPersonalData::erase('person@example.com');
        $stripped = Retention::strip(86400 * 365);
        $legacy   = (array) $this->db->queryBuilder()->table('mails_legacy')->where('id', $old)->first()->fields;
        $stats    = Retention::stats(86400 * 300, 86400 * 300);

        // Assert
        $this->assertSame(['from long ago', 'from today'], array_column($copy, 'body'), 'the copy has both, oldest first');
        $this->assertSame(2, $erased, 'the old message is erased too');
        $this->assertSame(['', MailPersonalData::SUBJECT], [$legacy['tomail'], $legacy['subject']]);
        $this->assertSame(1, $stripped, 'the other old message is stripped where it is');
        $this->assertSame(2, $stats['would_delete'], 'and a policy counts both tables');
        $this->assertSame('', (string) $this->db->queryBuilder()->table('mails')->where('id', $new)->first()->fields['tomail']);
    }

    /**
     * A table that is not ordinary is not converted again, a conversion that fails part way
     * leaves the table as it was, and a database without TimescaleDB is said to be one.
     */
    public function testAConversionThatCannotHappenChangesNothing(): void
    {
        // Arrange — a name the conversion needs is taken
        $this->mail('kept@example.com', 1);
        $this->db->query('CREATE TABLE elsewhere (x integer)');
        $this->db->query('CREATE INDEX idx_mails_tomail_legacy ON elsewhere (x)');
        $mails = new MailHypertable($this->db);

        // Act
        $failed = null;
        try {
            $mails->convert();
        } catch (\Throwable $e) {
            $failed = $e;
        }
        $state = $mails->state();
        $rows  = $this->rowsIn('mails');
        $this->db->query('DROP TABLE elsewhere');
        $mails->convert();
        $again = null;
        try {
            $mails->convert();
        } catch (\RuntimeException $e) {
            $again = $e;
        }

        // Assert
        $this->assertNotNull($failed);
        $this->assertSame(MailHypertable::PLAIN, $state, 'rolled back: still the ordinary table');
        $this->assertSame(1, $rows, 'with its rows');
        $this->assertStringContainsString('it is draining', $again?->getMessage() ?? '');
        $this->assertSame(MailHypertable::UNSUPPORTED, (new MailHypertable(self::mysql()))->state(), 'MySQL keeps its table');
    }

    /**
     * `mail:hypertable`: the dry run says where the table is in each state, `--no-drain`
     * converts only, and a run moves the rest; on a database without TimescaleDB it refuses.
     */
    public function testTheCommand(): void
    {
        // Arrange
        $this->mail('a@example.com', 10);
        $this->mail('b@example.com', 20);
        $run = static function (array $options): CommandTester {
            $tester = new CommandTester(new MailHypertableCommand());
            $tester->execute($options);

            return $tester;
        };

        // Act
        $plain    = $run(['--dry-run' => true])->getDisplay();
        $convert  = $run(['--no-drain' => true])->getDisplay();
        $draining = $run(['--dry-run' => true])->getDisplay();
        $partial  = $run(['--batch' => 1, '--limit' => 1])->getDisplay();
        $drain    = $run([])->getDisplay();
        $done     = $run(['--dry-run' => true])->getDisplay();
        $nothing  = $run([])->getDisplay();
        $singleton = &Factory::getDatabase();
        $singleton = self::mysql();
        $refused   = $run([]);
        $singleton = $this->db;

        // Assert
        $this->assertStringContainsString('ordinary table of 2 row(s)', $plain);
        $this->assertStringContainsString('is a hypertable.', $convert);
        $this->assertStringContainsString('2 row(s) are still to move', $draining);
        $this->assertStringContainsString('1 left: run again', $partial);
        $this->assertStringContainsString('and it is gone', $drain);
        $this->assertStringContainsString('nothing left to move', $done);
        $this->assertStringContainsString('Nothing to move.', $nothing);
        $this->assertSame(1, $refused->getStatusCode());
        $this->assertStringContainsString('not PostgreSQL with TimescaleDB', $refused->getDisplay());
    }

    /**
     * A message sent through the framework once the table is a hypertable — the first, which
     * opens its chunk — is kept with its own id: its body in `mail_bodies` and its tracking row
     * tied to it, read back exactly as it was sent.
     */
    public function testAMessageSentToTheHypertableReadsBackAsSent(): void
    {
        // Arrange
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailBodiesTable::class,
        ], $this->db);
        (new MailHypertable($this->db))->convert();
        $stub = new class extends Application {
            /** No application to build: only its settings are read. */
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['email' => ['tracking' => true, 'bodies' => ['store' => 'database']]];
        $ref   = new \ReflectionProperty(Application::class, 'appInstances');
        $saved = $ref->getValue() ?? [];
        $ref->setValue(null, ['default' => $stub] + $saved);
        $transport = new HypertableCapture();

        // Act
        try {
            $mail = (new \Pramnos\Email\Email())->withTransport($transport);
            $mail->setTo('reader@example.com')->setSubject('Hello')
                ->setBody('<p>Read <a href="https://example.com/a">this</a>.</p>')
                ->offerUnsubscribe('news', 'reader@example.com')->enableTracking();
            $sent = $mail->send();
            $row  = (array) $this->db->queryBuilder()->table('mails')->orderBy('id', 'desc')->first()->fields;
            $body = \Pramnos\Email\Body::of($row);
        } finally {
            $ref->setValue(null, $saved);
            \Pramnos\Email\BodyStore::forget();
        }

        // Assert
        $this->assertTrue((bool) $sent);
        $this->assertSame(\Pramnos\Email\BodyStore::IN_DATABASE, $row['path']);
        $this->assertNotNull(\Pramnos\Email\Tracking::idForMail((int) $row['id']), 'the tracking row names this message');
        $this->assertSame($transport->html[0], $body, 'and its body reads back as it was sent');
    }

    /**
     * The id an insert is given, on PostgreSQL: the table's own sequence, not the session's last
     * one — TimescaleDB takes one of its own whenever an insert opens a chunk. An insert that
     * gives its own id leaves a transaction it runs in usable.
     */
    public function testTheInsertIdIsTheRowsOwn(): void
    {
        // Arrange
        $this->db->query('CREATE TABLE ticks (id serial, at bigint NOT NULL, PRIMARY KEY (id, at))');
        $this->db->query("SELECT create_hypertable('ticks', 'at', chunk_time_interval => 100)");

        // Act
        $ids = [];
        foreach ([5, 150, 999, 5000] as $at) {
            $this->db->queryBuilder()->table('ticks')->insert(['at' => $at]);
            $ids[] = (int) $this->db->getInsertId();
        }
        $this->db->startTransaction();
        $this->db->queryBuilder()->table('ticks')->insert(['id' => 100, 'at' => 7]);
        $this->db->getInsertId();
        $still = $this->db->query('SELECT count(*) AS n FROM ticks')->fields['n'];
        $this->db->commitTransaction();
        // After a statement that is not an INSERT, and where the catalogue cannot be asked: the
        // session's last sequence, as before.
        $this->db->query('SELECT 1');
        $afterSelect = (int) $this->db->getInsertId();
        $blind = new class extends Database {
            /** The catalogue question fails; everything else runs. */
            public function query($sql, $cache = false, $cachetime = 60, $category = '', $dieOnFatalError = false, $skipDebug = false)
            {
                if (str_contains((string) $sql, 'to_regclass')) {
                    throw new \RuntimeException('the catalogue is away');
                }

                return parent::query($sql, $cache, $cachetime, $category, $dieOnFatalError, $skipDebug);
            }
        };
        foreach (['type' => 'postgresql', 'server' => 'timescaledb', 'port' => 5432, 'user' => 'postgres', 'password' => 'secret', 'database' => self::PROBE] as $key => $value) {
            $blind->{$key} = $value;
        }
        $blind->connect(false);
        $blind->queryBuilder()->table('ticks')->insert(['at' => 6000]);
        $fallback = (int) $blind->getInsertId();
        $blind->close();

        // Assert
        $this->assertSame([1, 2, 3, 4], $ids, 'each insert opened a chunk, and each has its own id');
        $this->assertSame(5, (int) $still, 'the transaction went on');
        $this->assertSame(4, $afterSelect, 'the last sequence the session took');
        $this->assertGreaterThan(0, $fallback, 'an answer still comes back');
    }

    /** The suite's MySQL connection. */
    private static function mysql(): Database
    {
        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->port     = 3306;
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        $db->connect(false);

        return $db;
    }
}
