<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Messaging\MessageArchive;
use Pramnos\Storage\BodyStore;

/**
 * Message bodies leaving the database, against the real table.
 *
 * The property is the same one the mail side has to satisfy — **nothing is lost** — but the table
 * it has to hold on is worse: `messages` grows one row per *recipient*, so the interesting case
 * is forty thousand rows carrying one identical body, and the interesting number is how many
 * files that becomes.
 */
#[CoversClass(MessageArchive::class)]
#[CoversClass(BodyStore::class)]
class MessageBodyStoreTest extends TestCase
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

        $this->root = sys_get_temp_dir() . '/messages-' . bin2hex(random_bytes(5));

        $app = Application::getInstance();
        $this->previous = $app->applicationInfo['mail'] ?? null;
        $app->applicationInfo['mail'] = ['body_store' => ['enabled' => true, 'path' => $this->root]];

        $this->db->query('DROP TABLE IF EXISTS `messages`');
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

        $this->db->query('DROP TABLE IF EXISTS `messages`');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * A body moves to disk and reads back identically.
     *
     * The one assertion this feature exists to make true.
     */
    public function testABodyMovesToDiskAndStillReadsBack(): void
    {
        // Arrange
        $html = '<html><body>' . str_repeat('<p>Καλησπέρα</p>', 60) . '</body></html>';
        $id   = $this->insert($html);

        // Act
        $result = MessageArchive::run();

        // Assert
        $this->assertSame(1, $result['moved']);
        $this->assertSame(0, $result['failed']);

        $row = $this->row($id);
        $this->assertSame('', (string) $row['text'], 'the column is empty');
        $this->assertNotSame('', (string) $row['bodypath']);
        $this->assertGreaterThan(0, (int) $row['bodybytes']);
        $this->assertSame($html, BodyStore::bodyOf($row), 'read back byte for byte');
    }

    /**
     * A mass send is one body, however many people it went to.
     *
     * This is the case the store is here for. `mails` grows a row per message; `messages` grows a
     * row per recipient, so a campaign writes its body once per person. Content-addressing turns
     * those copies into path strings pointing at a single file — and if that ever stops being
     * true, this table is the one that runs an installation out of disk.
     */
    public function testACampaignToManyPeopleIsOneFile(): void
    {
        // Arrange
        $html = '<html><body>' . str_repeat('<p>Προσφορά</p>', 80) . '</body></html>';

        for ($i = 0; $i < 40; $i++) {
            $this->insert($html, $i + 1);
        }

        // Act
        $result = MessageArchive::run();

        // Assert
        $this->assertSame(40, $result['moved']);
        $this->assertCount(1, $this->files(), '40 recipients, one stored body');
    }

    /**
     * The listing has something to show without opening a file.
     *
     * The inbox draws a preview line under every subject. With the body in a file that would be
     * one decompression per row — two hundred to paint one page — so the excerpt is written at
     * archive time and stays on the row. Without it an archived inbox goes blank under every
     * subject line, which is the kind of breakage that looks like data loss and is not.
     */
    public function testTheRowKeepsAPreviewAfterTheBodyLeaves(): void
    {
        // Arrange
        $id = $this->insert('<h1>Τίτλος</h1><p>Το κυρίως κείμενο του μηνύματος.</p>');

        // Act
        MessageArchive::run();

        // Assert
        $row = $this->row($id);
        $this->assertStringContainsString('Το κυρίως κείμενο', (string) $row['excerpt']);
        $this->assertStringNotContainsString('<h1>', (string) $row['excerpt'], 'plain text');
    }

    /**
     * Run twice, and the second pass has nothing to do.
     *
     * It is safe to interrupt and safe to repeat, which is what makes it usable on a table with
     * years of messages in it: nobody has to know where the last run stopped.
     */
    public function testASecondPassIsANoop(): void
    {
        // Arrange
        $this->insert('<p>ένα</p>');
        $this->insert('<p>δύο</p>');

        // Act
        $first  = MessageArchive::run();
        $second = MessageArchive::run();

        // Assert
        $this->assertSame(2, $first['moved']);
        $this->assertSame(0, $second['moved']);
        $this->assertSame(0, MessageArchive::pending());
    }

    /**
     * With the store off, nothing moves.
     *
     * An installation that has said `false` means it, and a migration command is the last place
     * that should be reinterpreting the setting.
     */
    public function testTheStoreBeingOffStopsIt(): void
    {
        // Arrange
        $this->insert('<p>μένει εδώ</p>');
        Application::getInstance()->applicationInfo['mail'] = ['body_store' => ['enabled' => false]];

        // Act
        $result = MessageArchive::run();

        // Assert
        $this->assertSame(0, $result['moved']);
        $this->assertSame(1, MessageArchive::pending());
    }

    /**
     * A stored message body is not an orphan.
     *
     * The trap this whole change had to avoid. `orphans()` was written when `mails` was the only
     * table naming a file; sharing the store without teaching it about `messages` would make
     * every message body look unreferenced, and `mail:archive --gc` would delete the lot.
     */
    public function testAMessageBodyIsNotCollectableGarbage(): void
    {
        // Arrange
        $this->insert('<html><body>' . str_repeat('<p>x</p>', 100) . '</body></html>');
        MessageArchive::run();
        $this->assertCount(1, $this->files());

        // Act
        $orphans = BodyStore::orphans();

        // Assert
        $this->assertSame([], $orphans, 'a message row names this file');
    }

    /**
     * `Message::save()` writes the body to the store, and `load()` brings it back.
     *
     * The archive command is the migration for what is already there; this is the path every new
     * message takes, and it is the one that has to be right for the table to stop growing.
     */
    public function testTheModelRoundTripsThroughTheStore(): void
    {
        // Arrange
        $html = '<html><body>' . str_repeat('<p>Μήνυμα</p>', 50) . '</body></html>';

        $controller        = new \Pramnos\Application\Controller();
        $message           = new \Pramnos\Messaging\Message($controller);
        $message->type     = 1;
        $message->subject  = 'Θέμα';
        $message->text     = $html;
        $message->touserid = 7;
        $message->date     = time();
        $message->html     = 1;
        $message->attachmenttext = '';

        // Act
        $message->save();
        $id = (int) $message->messageid;

        // Assert — the column is empty, the file has it
        $row = $this->row($id);
        $this->assertSame('', (string) $row['text'], 'the body did not stay in the column');
        $this->assertNotSame('', (string) $row['bodypath']);
        $this->assertNotSame('', (string) $row['excerpt'], 'and the listing has its preview');

        // …and the object the caller still holds is unchanged
        $this->assertSame($html, $message->text,
            'save() must not leave the caller holding an emptied body');

        // …and a fresh load returns it
        $loaded = new \Pramnos\Messaging\Message($controller);
        $loaded->load($id);
        $this->assertSame($html, (string) $loaded->text, 'read back byte for byte');
    }

    private function insert(string $text, int $touserid = 1): int
    {
        $this->db->queryBuilder()->table('#PREFIX#messages')->insert([
            'type' => 1, 'subject' => 'Θέμα', 'text' => $text, 'touserid' => $touserid,
            'date' => time() - 86400, 'html' => 1, 'attachmenttext' => '',
        ]);

        return (int) $this->db->getInsertId();
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#messages')
            ->where('messageid', $id)->get();

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

    private function runMigrations(): void
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;

        foreach (\Pramnos\Database\MigrationLoader::loadFromDirectory(
            dirname(__DIR__, 3) . '/database/migrations/framework/messaging',
            $app
        ) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Messaging\CreateMessagesTable
                || $migration instanceof \Pramnos\Framework\Migrations\Messaging\AddBodypathToMessages
            ) {
                $migration->up();
            }
        }
    }
}
