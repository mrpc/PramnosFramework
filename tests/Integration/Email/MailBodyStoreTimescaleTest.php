<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Email\Actions;
use Pramnos\Email\Body;
use Pramnos\Email\BodyStore;
use Pramnos\Email\Email;
use Pramnos\Framework\Factory;

/**
 * The body store in the database (mail body storage, phase 3): on TimescaleDB, `mail_bodies` is a
 * compressed hypertable, chosen by 'auto' once it exists; a sent message's row says `db:`, and its
 * body reads back as it was sent — compressed or not.
 */
#[CoversClass(BodyStore::class)]
#[CoversClass(Body::class)]
#[CoversClass(Email::class)]
#[CoversClass(\Pramnos\Framework\Migrations\Messaging\CreateMailBodiesTable::class)]
#[CoversClass(\Pramnos\Console\Commands\MailBodiesToDatabase::class)]
final class MailBodyStoreTimescaleTest extends TestCase
{
    private ?Database $previous = null;

    private ?array $savedInstances = null;

    private Database $db;

    protected function setUp(): void
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('timescaledb not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('timescaledb not reachable: ' . $e->getMessage());
        }
        if (!$db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('No TimescaleDB on this connection.');
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailBodiesTable::class,
        ], $db);

        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['email' => ['tracking' => true]];
        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $ref->getValue() ?? [];
        $ref->setValue(null, ['default' => $stub] + $this->savedInstances);
        BodyStore::forget();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->queryBuilder()->table('#PREFIX#mails')->whereRaw('tomail like ?', ['%@bodystore.test'])->delete();
            // The tracking rows too: another test reads "no tracked mail this week" off this table.
            $this->db->queryBuilder()->table('pramnos.emailtracking')->whereRaw('recipient like ?', ['%@bodystore.test'])->delete();
        }
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        BodyStore::forget();
    }

    /**
     * With `mail_bodies` there, 'auto' keeps bodies in it: the row's path is `db:`, its content
     * empty, and the body reads back as it was sent, to the byte — and still does once its chunk
     * is compressed.
     */
    public function testABodyKeptInTheDatabaseReadsBackAsSent(): void
    {
        // Arrange
        $transport = new HtmlCapturingTransport();
        $to        = 'db-' . bin2hex(random_bytes(3)) . '@bodystore.test';
        $stop      = htmlspecialchars(\Pramnos\Email\Unsubscribe::url(\Pramnos\Email\Unsubscribe::token($to, 'newsletter')), ENT_QUOTES);
        $mail      = (new Email())->withTransport($transport);
        $mail->setTo($to)->setSubject('In the database')
            ->setBody('<html><head></head><body><p><a href="https://example.com/x">x</a> <a href="' . $stop . '">stop</a></p></body></html>')
            ->offerUnsubscribe('newsletter', $to)
            ->enableTracking();
        $mail->addStructuredData(Actions::view('Open', 'https://example.com/x'));

        // Act
        $mail->send();
        $row = (array) $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', $to)->first()->fields;
        $before = Body::of($row);
        $this->db->query("SELECT compress_chunk(c, if_not_compressed => true) FROM show_chunks('" . $this->db->prefix . "pramnos.mail_bodies') c");
        $after = Body::of($row);

        // Assert
        $this->assertSame(BodyStore::DATABASE, BodyStore::mode(), 'auto: the table is there');
        $this->assertSame(BodyStore::IN_DATABASE, (string) $row['path']);
        $this->assertSame('', (string) $row['content']);
        $this->assertSame($transport->html[0], $before, 'as sent, to the byte');
        $this->assertSame($transport->html[0], $after, 'and compressed');
    }

    /**
     * `mail:bodies-to-database` moves bodies kept as files — an application's `.html.gz`, the
     * store's `.body.gz` — and, asked, those in `content`, into `mail_bodies`: each row then says
     * `db:` and reads exactly as before. A dry run counts and moves nothing; a file that is gone is
     * counted and its row left; a second run finds nothing left to move.
     */
    public function testBodiesMoveFromFilesIntoTheDatabase(): void
    {
        // Arrange — four rows, the bodies kept the ways installations keep them
        $folder = '/var/pf-tomove-' . bin2hex(random_bytes(3));
        mkdir(ROOT . $folder, 0777, true);
        file_put_contents(ROOT . $folder . '/legacy.html.gz', gzencode('<p>As an application kept it</p>'));
        $to   = 'kept-' . bin2hex(random_bytes(3)) . '@bodystore.test';
        file_put_contents(ROOT . $folder . '/kept.body.gz', gzencode('<p><a href="{{pf-unsubscribe:news}}">stop</a></p>'));
        $base = ['status' => 1, 'frommail' => 'a@b.c', 'fromname' => '', 'toname' => '', 'subject' => 'move', 'date' => time() - 86400,
            'module' => 'system', 'moduleinfo' => '', 'extrainfo' => '', 'hash' => ''];
        $ids = [];
        foreach ([
            ['tomail' => 'legacy@bodystore.test', 'content' => '', 'path' => $folder . '/legacy.html.gz'],
            ['tomail' => $to, 'content' => '', 'path' => $folder . '/kept.body.gz'],
            ['tomail' => 'content@bodystore.test', 'content' => '<p>In the row</p>', 'path' => ''],
            ['tomail' => 'gone@bodystore.test', 'content' => '', 'path' => $folder . '/gone.html.gz'],
        ] as $row) {
            $this->db->queryBuilder()->table('#PREFIX#mails')->insert($base + $row);
            $ids[$row['tomail']] = (int) $this->db->getInsertId();
        }
        $read   = fn (int $id): array => (array) $this->db->queryBuilder()->table('#PREFIX#mails')->where('id', $id)->first()->fields;
        $before = array_map(fn (int $id): string => Body::of($read($id)), $ids);
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\MailBodiesToDatabase());

        // Act
        $tester->execute(['--dry-run' => true, '--include-content' => true]);
        $dry = $tester->getDisplay();
        $unmovedAfterDry = (string) $read($ids['legacy@bodystore.test'])['path'];
        $code = $tester->execute(['--include-content' => true, '--batch' => '2']);
        $run  = $tester->getDisplay();
        $after = array_map(fn (int $id): string => Body::of($read($id)), $ids);
        $tester->execute(['--include-content' => true]);
        $again = $tester->getDisplay();
        foreach (['legacy.html.gz', 'kept.body.gz'] as $file) {
            unlink(ROOT . $folder . '/' . $file);
        }
        rmdir(ROOT . $folder);

        // Assert
        $this->assertMatchesRegularExpression('/\d+ row\(s\) to move/', $dry);
        $this->assertSame($folder . '/legacy.html.gz', $unmovedAfterDry, 'a dry run moves nothing');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('row(s) whose file is not there any more', $run);
        foreach (['legacy@bodystore.test', $to, 'content@bodystore.test'] as $mail) {
            $row = $read($ids[$mail]);
            $this->assertSame([BodyStore::IN_DATABASE, ''], [(string) $row['path'], (string) $row['content']], $mail . ' moved');
            $this->assertSame($before[$mail], $after[$mail], $mail . ' reads as before');
        }
        $this->assertStringContainsString(\Pramnos\Email\Unsubscribe::token($to, 'news'), $after[$to], 'a kept body is rebuilt as before');
        $this->assertSame($folder . '/gone.html.gz', (string) $read($ids['gone@bodystore.test'])['path'], 'a gone file\'s row is left');
        $this->assertStringContainsString('0 body/bodies moved', $again, 'nothing left to move');
    }

    /**
     * A body on a storage disk moves too; one on a disk nobody configured is counted as gone. A
     * batch the database refuses is rolled back whole and the run stops, saying where: what
     * earlier batches moved stays moved.
     */
    public function testDisksAndARefusedBatch(): void
    {
        // Arrange
        $folder = sys_get_temp_dir() . '/pf-movedisk-' . bin2hex(random_bytes(3));
        mkdir($folder, 0777, true);
        file_put_contents($folder . '/on-disk.html.gz', gzencode('<p>On a disk</p>'));
        file_put_contents($folder . '/nul.html.gz', gzencode('<p>Refused</p>'));
        \Pramnos\Storage\Storage::init(['default' => 'mb', 'disks' => ['mb' => ['driver' => 'local', 'root' => $folder]]]);
        $base = ['status' => 1, 'frommail' => 'a@b.c', 'fromname' => '', 'toname' => '', 'subject' => 'disk', 'content' => '', 'date' => time() - 3600,
            'module' => 'system', 'moduleinfo' => '', 'extrainfo' => '', 'hash' => ''];
        $ids = [];
        foreach (['disk' => 'disk:mb:on-disk.html.gz', 'nodisk' => 'disk:nobody:x.html.gz', 'nul' => 'disk:mb:nul.html.gz'] as $name => $path) {
            $this->db->queryBuilder()->table('#PREFIX#mails')->insert($base + ['tomail' => $name . '@bodystore.test', 'path' => $path]);
            $ids[$name] = (int) $this->db->getInsertId();
        }
        $read   = fn (int $id): array => (array) $this->db->queryBuilder()->table('#PREFIX#mails')->where('id', $id)->first()->fields;
        $refused = $ids['nul'];
        $tester  = new \Symfony\Component\Console\Tester\CommandTester(new class ($refused) extends \Pramnos\Console\Commands\MailBodiesToDatabase {
            public function __construct(private int $refused)
            {
                parent::__construct();
            }

            /** The database refusing one row's body. */
            protected function keep(array $row, string $body, bool $rebuild): void
            {
                if ((int) $row['id'] === $this->refused) {
                    throw new \RuntimeException('refused');
                }
                parent::keep($row, $body, $rebuild);
            }
        });

        // Act — one row a batch, so the disk's row is moved before the refused batch
        $code = $tester->execute(['--batch' => '1']);
        $output = $tester->getDisplay();
        \Pramnos\Storage\Storage::reset();
        array_map('unlink', glob($folder . '/*') ?: []);
        rmdir($folder);

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Stopped at mail ' . $ids['nul'], $output);
        $this->assertSame(BodyStore::IN_DATABASE, (string) $read($ids['disk'])['path'], 'moved before the refusal, and kept');
        $this->assertSame('<p>On a disk</p>', Body::of($read($ids['disk'])));
        $this->assertSame('disk:nobody:x.html.gz', (string) $read($ids['nodisk'])['path'], 'no such disk: left as it is');
        $this->assertSame('disk:mb:nul.html.gz', (string) $read($ids['nul'])['path'], 'the refused batch rolled back');
    }
}

