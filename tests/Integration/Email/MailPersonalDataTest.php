<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Email\Body;
use Pramnos\Email\BodyStore;
use Pramnos\Email\MailPersonalData;
use Pramnos\Email\Retention;
use Pramnos\Framework\Factory;

/**
 * Retention and erasure with the body store (mail body storage, phase 6): a stripped, pruned or
 * erased message's body goes from wherever it was kept — its file, its file on a disk, its
 * `mail_bodies` row — unless the file is still another message's. An erased message stays in
 * the log without the person. On MySQL (MariaDB with --mariadb) here; on PostgreSQL, with the
 * database store on TimescaleDB, in {@see MailPersonalDataPostgreSQLTest}.
 */
#[CoversClass(BodyStore::class)]
#[CoversClass(MailPersonalData::class)]
#[CoversClass(Retention::class)]
class MailPersonalDataTest extends TestCase
{
    protected Database $db;

    private ?Database $previous = null;

    private ?array $savedInstances = null;

    protected string $folder = '';

    /** The connection this run is on — MySQL, or MariaDB with --mariadb; null skips. */
    protected function connection(): ?Database
    {
        \Pramnos\Application\Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $reference = &Database::getInstance();
        $reference = null;
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db->type === 'postgresql' ? null : $db;
    }

    protected function setUp(): void
    {
        $db = $this->connection();
        if ($db === null) {
            $this->markTestSkipped('This database is not reachable.');
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            \Pramnos\Framework\Migrations\Messaging\AddBodyvarsToMails::class,
        ], $db);
        $this->folder = sys_get_temp_dir() . '/pf-erasure-' . bin2hex(random_bytes(3));
        $this->configure(['store' => 'files', 'path' => $this->folder]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->queryBuilder()->table('#PREFIX#mails')->where('module', 'erasure-test')->delete();
            $this->db->queryBuilder()->table('pramnos.emailtracking')->where('list', 'erasure-test')->delete();
        }
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        BodyStore::forget();
        \Pramnos\Storage\Storage::reset();
        if (is_dir($this->folder)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->folder);
        }
    }

    /** An application with these body store settings. */
    protected function configure(array $bodies): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['email' => ['bodies' => $bodies]];
        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances ??= $ref->getValue() ?? [];
        $ref->setValue(null, ['default' => $stub] + $this->savedInstances);
        BodyStore::forget();
    }

    /** A sent message to `$to` at `$date`, its body kept at `$path`; its id. */
    protected function sent(string $to, int $date, string $path, string $content = ''): int
    {
        $this->db->queryBuilder()->table('#PREFIX#mails')->insert([
            'status' => 1, 'frommail' => 'site@example.com', 'fromname' => 'Site', 'tomail' => $to, 'toname' => 'Name of ' . $to,
            'subject' => 'Hello ' . $to, 'content' => $content, 'date' => $date, 'module' => 'erasure-test', 'moduleinfo' => '',
            'extrainfo' => '{"outbox":{"cc":"' . $to . '"}}', 'path' => $path, 'hash' => md5($path . $content),
        ]);

        return (int) $this->db->queryBuilder()->table('#PREFIX#mails')->where('module', 'erasure-test')->orderBy('id', 'desc')->first()->fields['id'];
    }

    /** A message's row. @return array<string, mixed> */
    protected function row(int $id): array
    {
        return (array) $this->db->queryBuilder()->table('#PREFIX#mails')->where('id', $id)->first()->fields;
    }

    /**
     * An address's copy of its data is every message to it, with its body as it was sent. Erasing
     * it pseudonymises each message to it — stored with a capital, erased all
     * the same — and removes the body it alone had. A newsletter body another reader shares stays,
     * and so does that reader's message; the tracking row keeps its counts and loses the address.
     */
    public function testErasingAnAddressRemovesWhatWasOnlyTheirs(): void
    {
        // Arrange
        $when    = time() - 3600;
        $own     = BodyStore::putFile('<p>Your code is 123</p>', $when);
        $shared  = BodyStore::putFile('<p>This month {{pf-unsubscribe:news}}</p>', $when);
        $mine    = $this->sent('Leaving@Example.com', $when, $own);
        $news    = $this->sent('leaving@example.com', $when + 5, $shared);
        $inRow   = $this->sent('leaving@example.com', $when, '', '<p>kept in the row</p>');
        $theirs  = $this->sent('staying@example.com', $when + 6, $shared);
        $this->db->queryBuilder()->table('pramnos.emailtracking')->insert([
            'tracking_id' => 'erase' . bin2hex(random_bytes(6)), 'mailid' => $news, 'recipient' => 'leaving@example.com',
            'list' => 'erasure-test', 'subject' => 'This month', 'sent_at' => $when, 'opens' => 3,
        ]);

        // Act
        $copy   = MailPersonalData::export('LEAVING@example.com');
        $erased = MailPersonalData::erase('leaving@example.com');
        $row    = $this->row($mine);
        $track  = (array) $this->db->queryBuilder()->table('pramnos.emailtracking')->where('mailid', $news)->first()->fields;

        // Assert
        $this->assertSame(['<p>Your code is 123</p>', '<p>kept in the row</p>'], [$copy[0]['body'], $copy[2]['body']], 'their copy: each message as it was sent');
        $this->assertSame(['Hello Leaving@Example.com', 'erasure-test', 1], [$copy[0]['subject'], $copy[0]['module'], $copy[0]['status']]);
        $this->assertCount(3, $copy, 'every message to them, and no other reader\'s');
        $this->assertSame([], MailPersonalData::export('leaving@example.com'), 'once erased, there is nothing of them to give');
        $this->assertSame([], MailPersonalData::export(''));
        $this->assertSame(3, $erased);
        $this->assertSame(['', '', MailPersonalData::SUBJECT, '', '', '', ''], [
            $row['tomail'], $row['toname'], $row['subject'], $row['content'], $row['path'], trim((string) $row['hash']), $row['extrainfo'],
        ], 'the row stays, without them');
        $this->assertSame('', $this->row($inRow)['content'], 'a body in the row goes too');
        $this->assertNull(Body::file($own), 'their own body is gone');
        $this->assertNotNull(Body::file($shared), 'a body another reader shares stays');
        $this->assertSame('staying@example.com', $this->row($theirs)['tomail']);
        $this->assertStringContainsString('This month', Body::of($this->row($theirs)), 'and that reader\'s message reads as before');
        $this->assertSame(['', MailPersonalData::SUBJECT, 3], [$track['recipient'], $track['subject'], (int) $track['opens']]);
        $this->assertSame(0, MailPersonalData::erase('leaving@example.com'), 'a second erasure finds nothing');
        $this->assertSame(0, MailPersonalData::erase(' '), 'nor does no address');
    }

    /**
     * Stripping old messages removes their kept files, a file still shared by a newer message
     * left until that one is stripped too; pruning removes the rows and their bodies.
     */
    public function testRetentionRemovesTheKeptBodies(): void
    {
        // Arrange — two messages of the same hour share a file; the first is older
        $when   = time() - 86400 * 200;
        $shared = BodyStore::putFile('<p>Newsletter</p>', $when);
        $older  = $this->sent('a@example.com', $when, $shared);
        $newer  = $this->sent('b@example.com', $when + 600, $shared);
        $alone  = BodyStore::putFile('<p>Receipt</p>', $when);
        $other  = $this->sent('c@example.com', $when, $alone);

        // Act — a cutoff between the two shared ones
        $first     = Retention::strip(time() - $when - 300);
        $kept      = Body::file($shared);
        $olderPath = $this->row($older)['path'];
        $second    = Retention::strip(86400 * 100);
        $stats     = Retention::stats(86400 * 100, 86400 * 100);
        $pruned    = Retention::prune(86400 * 100);

        // Assert
        $this->assertSame(2, $first, 'the older shared one and the receipt');
        $this->assertNotNull($kept, 'the shared file stayed while the newer message still named it');
        $this->assertSame('', $olderPath);
        $this->assertNull(Body::file($alone), 'the receipt\'s file went with it');
        $this->assertSame(1, $second, 'then the newer one');
        $this->assertNull(Body::file($shared), 'and the shared file with it');
        $this->assertSame(0, $stats['would_strip'], 'nothing left to strip');
        $this->assertGreaterThanOrEqual(3, $stats['would_delete']);
        $this->assertGreaterThanOrEqual(3, $pruned);
        $this->assertSame(0, $this->db->queryBuilder()->table('#PREFIX#mails')->whereIn('id', [$older, $newer, $other])->count());
    }

    /**
     * A body kept on a storage disk is removed from the disk; one whose disk cannot be reached
     * is logged and left, and the row is emptied all the same.
     */
    public function testABodyOnADiskIsRemovedFromIt(): void
    {
        // Arrange
        \Pramnos\Storage\Storage::init(['default' => 'mail', 'disks' => ['mail' => ['driver' => 'local', 'root' => $this->folder . '/disk']]]);
        $this->configure(['store' => 'files', 'disk' => 'mail']);
        $when   = time() - 3600;
        $onDisk = BodyStore::putFile('<p>On the disk</p>', $when);
        $id     = $this->sent('disk@example.com', $when, $onDisk);
        $lost   = $this->sent('lost@example.com', $when, 'disk:nowhere:2026/10/19/10/x.body.gz');
        $file   = $this->folder . '/disk/' . explode(':', $onDisk, 3)[2];

        // Act
        $before = is_file($file);
        $erased = MailPersonalData::erase('disk@example.com') + MailPersonalData::erase('lost@example.com');

        // Assert
        $this->assertTrue($before);
        $this->assertSame(2, $erased);
        $this->assertFileDoesNotExist($file, 'removed from the disk');
        $this->assertSame('', $this->row($id)['path']);
        $this->assertSame('', $this->row($lost)['path'], 'a disk that is not there leaves the row emptied all the same');
    }

    /**
     * A write the database refuses ends a retention run with what it did, logged, and stops an
     * erasure with an exception — it would otherwise find the same rows for ever.
     */
    public function testARefusedWriteIsSaid(): void
    {
        // Arrange — the column is known to be there, and is not
        $id = $this->sent('refused@example.com', time() - 86400 * 200, '', 'old');
        $this->assertTrue(BodyStore::hasBodyVars());
        $this->db->schema()->alterTable('mails', function ($table) {
            $table->dropColumn('bodyvars');
        });

        // Act
        $error = null;
        try {
            $stripped = Retention::strip(86400 * 100);
            try {
                MailPersonalData::erase('refused@example.com');
            } catch (\Throwable $e) {
                $error = $e;
            }
        } finally {
            BodyStore::forget();
            \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Messaging\AddBodyvarsToMails::class], $this->db);
            BodyStore::forget();
        }

        // Assert
        $this->assertSame(0, $stripped, 'nothing claimed');
        $this->assertNotNull($error, 'the erasure says it could not');
        $this->assertSame('refused@example.com', $this->row($id)['tomail']);
    }
}
