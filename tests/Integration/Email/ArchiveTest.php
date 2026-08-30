<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Email\BodyStore;
use Pramnos\Email\Retention;
use Pramnos\Framework\Factory;

/**
 * Moving bodies out of the database, against the real table.
 *
 * The property under test is that **nothing is lost**: after an archive run every message reads
 * back exactly as it did before. That is the whole difference from emptying the column, and it
 * is not something a unit test of the store can show — it needs the row, the column and the
 * reader together.
 */
#[CoversClass(Retention::class)]
#[CoversClass(BodyStore::class)]
#[CoversClass(\Pramnos\Email\Email::class)]
class ArchiveTest extends TestCase
{
    private $db;

    private string $root;

    private mixed $previous = null;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->db = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->root = sys_get_temp_dir() . '/archive-' . bin2hex(random_bytes(5));

        $app = Application::getInstance();
        $this->previous = $app->applicationInfo['mail'] ?? null;
        $app->applicationInfo['mail'] = ['body_store' => ['enabled' => true, 'path' => $this->root]];

        $this->db->query('DROP TABLE IF EXISTS `mails`');
        $this->runMigrations();
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();

        if ($this->previous === null) {
            unset($app->applicationInfo['mail']);
        } else {
            $app->applicationInfo['mail'] = $this->previous;
        }

        $this->db->query('DROP TABLE IF EXISTS `mails`');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * A body moves to disk and reads back identically.
     *
     * The one assertion this whole feature exists to make true.
     */
    public function testABodyMovesToDiskAndStillReadsBack(): void
    {
        // Arrange
        $html = '<html><body>' . str_repeat('<p>Γεια σας</p>', 60) . '</body></html>';
        $id   = $this->insert($html, time() - 86400);

        // Act
        $result = Retention::archive();

        // Assert
        $this->assertSame(1, $result['moved']);
        $this->assertSame(0, $result['failed']);

        $row = $this->row($id);
        $this->assertSame('', $row['content'], 'the column is empty');
        $this->assertNotSame('', $row['bodypath'], 'and the row knows where the body went');
        $this->assertGreaterThan(0, (int) $row['bodybytes']);
        $this->assertSame($html, BodyStore::bodyOf($row), 'read back byte for byte');
    }

    /**
     * The message report keeps working, which is the point of doing it this way.
     *
     * Stripping the column makes the table just as small and leaves this screen with nothing to
     * say. Everything it derives — the plain-text half, the links, the tracking pixel — comes
     * from the body, so this is the assertion that separates the two approaches.
     */
    public function testTheMessageReportKeepsWorkingAfterArchiving(): void
    {
        // Arrange
        $html = '<html><body><p>' . str_repeat('x', 600) . '</p>'
            . '<a href="https://example.com/offer">Read it</a></body></html>';
        $id   = $this->insert($html, time() - 86400);

        Retention::archive();

        // Act
        $report = new \Pramnos\Email\MessageReport($this->row($id), null);

        // Assert
        $this->assertSame($html, $report->body());
        $this->assertStringContainsString('Read it', $report->plainText());
        $this->assertCount(1, $report->links());
    }

    /**
     * Forty thousand copies of one campaign are one file.
     *
     * The send that makes this table large in the first place, and the reason a body is
     * addressed by its digest rather than by its row.
     */
    public function testACampaignIsOneFile(): void
    {
        // Arrange — the same body, twenty times, as a mass send writes it
        $html = '<html><body>' . str_repeat('<p>Newsletter</p>', 80) . '</body></html>';

        for ($i = 0; $i < 20; $i++) {
            $this->insert($html, time() - 86400);
        }

        // Act
        $result = Retention::archive();

        // Assert
        $this->assertSame(20, $result['moved']);
        $this->assertCount(1, $this->files(), 'one body, one file');

        $stats = Retention::stats();
        $this->assertSame(20, $stats['archived']);
        $this->assertSame(1, $stats['archive_files']);
        $this->assertGreaterThan(
            $stats['archive_bytes'],
            $stats['archived_bytes'],
            'summing the rows counts one file twenty times — that is the number the first '
            . 'version of this report printed as disk usage'
        );
    }

    /**
     * A body too small to be worth a file leaves the row anyway.
     *
     * It used not to: below a few hundred bytes a file costs more than the column does — an
     * inode, a directory entry and a seek, to save nothing — so anything under 512 bytes stayed
     * behind. The arithmetic was right and the answer was wrong, because it left an "unless" in
     * *the body is not in the database*, and a GDPR erasure, a table-size estimate and a backup
     * plan all had to carry that "unless" with them. The invariant is worth more than the block.
     */
    public function testATinyBodyIsArchivedToo(): void
    {
        // Arrange
        $id = $this->insert('<p>ok</p>', time() - 86400);

        // Act
        $result = Retention::archive();

        // Assert
        $this->assertSame(1, $result['moved']);
        $row = $this->row($id);
        $this->assertSame('', (string) $row['content'], 'nothing of it is left in the database');
        $this->assertNotSame('', (string) $row['bodypath']);
        $this->assertSame('<p>ok</p>', \Pramnos\Email\BodyStore::bodyOf($row),
            'and it reads back unchanged');
    }

    /**
     * A row keeps its body when the store cannot take it.
     *
     * The only thing worse than a large table is a table missing the message somebody is asking
     * about, so a disk that refuses the write leaves the row exactly as it was.
     */
    public function testAFailedStoreLeavesTheRowAlone(): void
    {
        // Arrange — a file where the store's directory has to go
        $html = '<html><body>' . str_repeat('<p>x</p>', 100) . '</body></html>';
        $id   = $this->insert($html, time() - 86400);

        file_put_contents($this->root, 'not a directory');

        try {
            // Act
            $result = Retention::archive();

            // Assert
            $this->assertSame(0, $result['moved']);
            $this->assertSame(1, $result['failed']);
            $this->assertSame($html, $this->row($id)['content'], 'the body is still there');
        } finally {
            @unlink($this->root);
        }
    }

    /**
     * With the store off, nothing moves.
     */
    public function testWithTheStoreOffNothingMoves(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['mail'] = ['body_store' => ['enabled' => false]];
        $this->insert('<html><body>' . str_repeat('<p>x</p>', 100) . '</body></html>', time() - 86400);

        // Act & Assert
        $this->assertSame(['moved' => 0, 'freed' => 0, 'failed' => 0], Retention::archive());
    }

    /**
     * A cutoff archives only what is older than it.
     */
    public function testACutoffLeavesRecentMessagesAlone(): void
    {
        // Arrange
        $html   = '<html><body>' . str_repeat('<p>x</p>', 100) . '</body></html>';
        $old    = $this->insert($html, time() - 86400 * 60);
        $recent = $this->insert($html . '<p>different</p>', time() - 3600);

        // Act
        Retention::archive(86400 * 30);

        // Assert
        $this->assertSame('', $this->row($old)['content']);
        $this->assertNotSame('', $this->row($recent)['content']);
    }

    /**
     * A file two rows share is not an orphan when only one of them goes.
     *
     * Bodies are content-addressed, so a per-row delete would take the file another row still
     * points at — which is why files are collected against the rows that remain instead.
     */
    public function testASharedFileIsNotAnOrphanUntilBothRowsGo(): void
    {
        // Arrange
        $html  = '<html><body>' . str_repeat('<p>shared</p>', 80) . '</body></html>';
        $first = $this->insert($html, time() - 86400);
        $this->insert($html, time() - 86400);

        Retention::archive();
        $this->assertCount(1, $this->files());

        // Act — one of the two rows goes
        $this->db->queryBuilder()->table('#PREFIX#mails')->where('id', $first)->delete();

        // Assert
        $this->assertSame([], BodyStore::orphans(), 'the other row still names it');

        // …and once both are gone, it is collectable — once it is older than the sweep, which
        // never collects a file younger than itself in case it is a send still in flight.
        $this->db->query('DELETE FROM ' . $this->db->prefix . 'mails');
        touch($this->files()[0], time() - 3600);
        $this->assertCount(1, BodyStore::orphans());
    }

    /**
     * A body written while the sweep is running is not collected.
     *
     * `orphans()` cannot read the rows and the directory in the same instant — it reads the rows
     * first. A message sent in between writes a file that no row named *at the time the rows were
     * read*, so it looks exactly like an orphan, and it is the body of something somebody just
     * received. On an installation that sends all day this is not a theoretical window, and the
     * damage is silent: the row keeps its `bodypath` and the file behind it is gone.
     *
     * A file newer than the sweep is therefore never a candidate. A real orphan is still there
     * on the next run; a body deleted a second after it was written is not recoverable.
     */
    public function testABodyWrittenDuringTheSweepIsNotCollected(): void
    {
        // Arrange — a file on disk that no row references, written just now
        $path = BodyStore::put('<html><body>' . str_repeat('<p>μόλις τώρα</p>', 60)
            . '</body></html>', time());
        $this->assertNotNull($path);
        $this->assertCount(1, $this->files());

        // Act
        $orphans = BodyStore::orphans();

        // Assert
        $this->assertSame([], $orphans,
            'a file younger than the sweep is a send in progress, not an orphan');
    }

    /**
     * An old body that nothing references is still collected.
     *
     * The counterpart, so the guard above cannot be satisfied by never collecting anything.
     */
    public function testAnOldUnreferencedBodyIsStillCollected(): void
    {
        // Arrange
        $path = BodyStore::put('<html><body>' . str_repeat('<p>παλιό</p>', 60) . '</body></html>',
            time() - 86400 * 30);
        $full = $this->root . '/' . $path;
        touch($full, time() - 3600);

        // Act
        $orphans = BodyStore::orphans();

        // Assert
        $this->assertSame([$path], $orphans);
    }

    /**
     * A failed lookup collects nothing, rather than everything.
     *
     * "Which files does nothing reference" answered from a broken query is "all of them", and
     * the caller deletes the archive.
     */
    public function testAFailedLookupCollectsNothing(): void
    {
        // Arrange
        $this->insert('<html><body>' . str_repeat('<p>x</p>', 100) . '</body></html>', time() - 86400);
        Retention::archive();
        $this->assertCount(1, $this->files());

        $this->db->query('DROP TABLE IF EXISTS `mails`');

        // Act & Assert
        $this->assertSame([], BodyStore::orphans());
    }

    /**
     * `Email::send()` writes the body to the store, not to the column.
     *
     * The archive command is the migration for what is already there; this is the path every
     * new message takes, and it is the one that has to be right for the table to stop growing.
     */
    public function testASentMessageWritesItsBodyToTheStore(): void
    {
        // Arrange
        $html = '<html><body>' . str_repeat('<p>Sent now</p>', 80) . '</body></html>';

        $mailer = new class extends \Pramnos\Email\Email {
            protected function sendWithSymfonyMailer()
            {
                // No SMTP: what is under test is the audit row, which is written either way.
                return true;
            }
        };

        $mailer->subject = 'A message';
        $mailer->body    = $html;
        $mailer->to      = 'someone@example.com';
        $mailer->setTemplate('');

        // Act
        $mailer->send();

        // Assert
        $row = $this->latest();
        $this->assertSame('', $row['content'], 'the body did not go in the column');
        $this->assertNotSame('', $row['bodypath']);
        $this->assertGreaterThan(0, (int) $row['bodybytes']);
        $this->assertSame($html, BodyStore::bodyOf($row));
    }

    /**
     * With the store off, a sent message keeps its body in the row.
     *
     * The default, and the behaviour every existing installation has.
     */
    public function testWithTheStoreOffASentMessageKeepsItsBodyInline(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['mail'] = ['body_store' => ['enabled' => false]];
        $html = '<html><body>' . str_repeat('<p>Sent now</p>', 80) . '</body></html>';

        $mailer = new class extends \Pramnos\Email\Email {
            protected function sendWithSymfonyMailer()
            {
                return true;
            }
        };

        $mailer->subject = 'A message';
        $mailer->body    = $html;
        $mailer->to      = 'someone@example.com';
        $mailer->setTemplate('');

        // Act
        $mailer->send();

        // Assert
        $row = $this->latest();
        $this->assertSame($html, $row['content']);
        $this->assertSame('', (string) $row['bodypath']);
    }

    /**
     * The count of what is still waiting is the number the command reports.
     */
    public function testItCountsWhatIsStillWaiting(): void
    {
        // Arrange
        $html = '<html><body>' . str_repeat('<p>x</p>', 100) . '</body></html>';
        $this->insert($html, time() - 86400 * 60);
        $this->insert($html . '<p>b</p>', time() - 3600);
        $this->insert('<p>tiny</p>', time() - 86400 * 60);

        // Assert
        $this->assertSame(3, Retention::archivable(), 'the tiny one goes to a file as well');
        $this->assertSame(2, Retention::archivable(86400 * 30), 'and two are old enough');
    }

    /**
     * With no table, both the count and the move answer nothing rather than throwing.
     *
     * This runs from a scheduled job. A fatal there is a cron mail nobody reads.
     */
    public function testAMissingTableIsNotAnException(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `mails`');

        // Assert
        $this->assertSame(0, Retention::archivable());
        $this->assertSame(['moved' => 0, 'freed' => 0, 'failed' => 0], Retention::archive());
    }

    /** @return array<string, mixed> */
    private function latest(): array
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#mails')
            ->orderBy('id', 'desc')->limit(1)->get();

        return (array) ($result?->fetch() ?? []);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    private function runMigrations(): void
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;

        foreach (\Pramnos\Database\MigrationLoader::loadFromDirectory(
            dirname(__DIR__, 3) . '/database/migrations/framework/messaging',
            $app
        ) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Messaging\CreateMailsTable
                || $migration instanceof \Pramnos\Framework\Migrations\Messaging\AddBodypathToMails
            ) {
                $migration->up();
            }
        }
    }

    private function insert(string $content, int $date): int
    {
        $this->db->queryBuilder()->table('#PREFIX#mails')->insert([
            'status' => 1, 'frommail' => 'from@example.com', 'fromname' => 'From',
            'tomail' => 'to@example.com', 'toname' => 'To', 'subject' => 'Subject',
            'content' => $content, 'date' => $date, 'module' => 'test',
            'moduleinfo' => '', 'extrainfo' => '', 'path' => '',
            'hash' => md5($content . $date . random_bytes(4)),
        ]);

        return (int) $this->db->getInsertId();
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#mails')->where('id', $id)->get();

        return (array) ($result?->fetch() ?? []);
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_values(array_filter(
            (array) glob($this->root . '/*/*/*/*'),
            static fn ($path): bool => is_file((string) $path)
        ));
    }
}
