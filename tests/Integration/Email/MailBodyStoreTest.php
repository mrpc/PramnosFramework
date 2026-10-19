<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\MailFlush;
use Pramnos\Email\Actions;
use Pramnos\Email\Body;
use Pramnos\Email\BodyStore;
use Pramnos\Email\Email;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** A transport that keeps the HTML of each message exactly as it went out. */
class HtmlCapturingTransport extends \Symfony\Component\Mailer\Transport\AbstractTransport
{
    /** @var list<string> */
    public array $html = [];

    protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
    {
        $original     = $message->getOriginalMessage();
        $this->html[] = $original instanceof \Symfony\Component\Mime\Email ? (string) $original->getHtmlBody() : '';
    }

    public function __toString(): string
    {
        return 'capture-html://';
    }
}

/** `mail:flush` sending through a capturing transport — with a mailer whose keeping fails, if asked. */
class BodyStoreMailFlush extends MailFlush
{
    public function __construct(private HtmlCapturingTransport $capture, private bool $keepingFails = false)
    {
        parent::__construct();
    }

    protected function mailer(): Email
    {
        $mail = $this->keepingFails ? new class extends Email {
            public function keepSentBody(int $mailId, int $date, string $tomail): array
            {
                throw new \RuntimeException('the store is away');
            }
        } : new Email();

        return $mail->withTransport($this->capture);
    }
}

/**
 * The body store (mail body storage, phase 3): a sent message's body is kept before what differs
 * per recipient — the unsubscribe link, the tracking — where the store keeps bodies, and read
 * back exactly as it was sent, to the byte. A newsletter is one body however many receive it.
 */
#[CoversClass(BodyStore::class)]
#[CoversClass(Body::class)]
#[CoversClass(Email::class)]
#[CoversClass(MailFlush::class)]
#[CoversClass(\Pramnos\Console\Commands\MailBodiesToDatabase::class)]
#[CoversClass(\Pramnos\Email\TemplateValues::class)]
#[CoversClass(\Pramnos\Framework\Migrations\Messaging\AddBodyvarsToMails::class)]
class MailBodyStoreTest extends BaseTestCase
{
    protected \Pramnos\Database\Database $db;

    private ?array $savedInstances = null;

    private string $folder = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL, like the rest of the messaging suite.');
        }
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            \Pramnos\Framework\Migrations\Messaging\AddBodyvarsToMails::class,
        ], $this->db);
        $this->folder = sys_get_temp_dir() . '/pf-mailbodies-' . bin2hex(random_bytes(3));
        $this->configure(['store' => 'files', 'path' => $this->folder]);
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('#PREFIX#mails')->whereRaw('tomail like ?', ['%@bodystore.test'])->delete();
        // The tracking rows too: another test reads "no tracked mail this week" off this table.
        $this->db->queryBuilder()->table('pramnos.emailtracking')->whereRaw('recipient like ?', ['%@bodystore.test'])->delete();
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
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
        parent::tearDown();
    }

    /** An application with tracking on and these body store settings. */
    private function configure(array $bodies, array $more = []): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = array_replace_recursive(['email' => ['tracking' => true, 'bodies' => $bodies]], $more);
        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances ??= $ref->getValue() ?? [];
        $ref->setValue(null, ['default' => $stub] + $this->savedInstances);
        BodyStore::forget();
    }

    /**
     * A tracked newsletter to `$to`: a link, the list's unsubscribe link as a template prints it —
     * the framework's, for this reader — and a Gmail action.
     */
    private function newsletter(string $to, HtmlCapturingTransport $transport): Email
    {
        $stop = htmlspecialchars(\Pramnos\Email\Unsubscribe::url(\Pramnos\Email\Unsubscribe::token($to, 'newsletter')), ENT_QUOTES);
        $mail = (new Email())->withTransport($transport);
        $mail->setTo($to)
            ->setSubject('This month ' . bin2hex(random_bytes(2)))
            ->setBody('<html><head><title>x</title></head><body><p>Read <a href="https://example.com/issue?a=1&amp;b=2">the issue</a>.</p>'
                . '<p><a href="' . $stop . '">Stop these</a></p></body></html>')
            ->offerUnsubscribe('newsletter', $to)
            ->enableTracking();
        $mail->addStructuredData(Actions::view('Read it', 'https://example.com/issue'));

        return $mail;
    }

    /** The newest row for an address. */
    private function row(string $to): array
    {
        return (array) $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', $to)->orderBy('id', 'desc')->first()->fields;
    }

    /**
     * What is kept is the body before what differs per recipient — the unsubscribe link a
     * placeholder, no tracking — and read back it is the body that was sent, to the byte: links
     * wrapped as they were, the pixel, the structured data, the reader's own unsubscribe link.
     */
    public function testTheBodyReadBackIsTheBodySentToTheByte(): void
    {
        // Arrange
        $transport = new HtmlCapturingTransport();
        $to        = 'reader@bodystore.test';

        // Act
        $this->assertTrue((bool) $this->newsletter($to, $transport)->send());
        $row  = $this->row($to);
        $kept = (string) gzdecode((string) file_get_contents((string) Body::file((string) $row['path'])));

        // Assert
        $this->assertSame('', (string) $row['content'], 'not in the row');
        $this->assertStringEndsWith(BodyStore::REBUILD_EXTENSION, (string) $row['path']);
        $this->assertSame(md5($kept), (string) $row['hash'], 'the hash is the kept body\'s');
        $this->assertStringNotContainsString('emailpixel', $kept, 'no tracking kept');
        $this->assertStringNotContainsString(\Pramnos\Email\Unsubscribe::token($to, 'newsletter'), $kept, 'nor the reader\'s link');
        $this->assertStringContainsString('{{pf-unsubscribe', $kept);
        $this->assertStringContainsString('application/ld+json', $transport->html[0], 'the action was sent');
        $this->assertStringContainsString(\Pramnos\Email\Unsubscribe::token($to, 'newsletter'), $transport->html[0], 'the reader\'s link was sent');
        $this->assertSame($transport->html[0], Body::of($row), 'read back as sent, to the byte');
    }

    /**
     * A newsletter to three readers in one hour is one body kept — and each reads back as their
     * own: their unsubscribe link, their tracking.
     */
    public function testANewsletterIsOneBody(): void
    {
        // Arrange
        $transport = new HtmlCapturingTransport();
        $readers   = ['a@bodystore.test', 'b@bodystore.test', 'c@bodystore.test'];
        $subject   = null;

        // Act
        foreach ($readers as $to) {
            $mail = $this->newsletter($to, $transport);
            $mail->setSubject($subject ??= (string) $mail->subject);
            $mail->send();
        }
        $rows = array_map(fn (string $to): array => $this->row($to), $readers);

        // Assert
        $this->assertCount(1, array_unique(array_column($rows, 'path')), 'one body kept');
        foreach ($rows as $i => $row) {
            $this->assertSame($transport->html[$i], Body::of($row), $readers[$i] . ' reads back their own');
        }
        $this->assertNotSame($transport->html[0], $transport->html[1], 'and each was sent their own');
    }

    /**
     * A queued message keeps its body in the row for the worker; once the worker has sent it,
     * the body moves to the store, kept as a sent one — and reads back as it was sent.
     */
    public function testAQueuedMessageIsKeptOnceSent(): void
    {
        // Arrange
        $to   = 'queued@bodystore.test';
        $mail = new Email();
        $mail->setTo($to)->setSubject('Queued ' . bin2hex(random_bytes(2)))
            ->setBody('<p>Later, <a href="https://example.com/later">here</a>.</p>')
            ->offerUnsubscribe('newsletter', $to)
            ->enableTracking();
        $mail->queue();
        $queued    = $this->row($to);
        $transport = new HtmlCapturingTransport();

        // Act
        (new CommandTester(new BodyStoreMailFlush($transport)))->execute([]);
        $sent = $this->row($to);
        // The worker sends whatever is waiting; this message's is the one with its link.
        $html = (string) current(array_filter($transport->html, static fn (string $h): bool => str_contains($h, 'Later, ')));

        // Assert
        $this->assertNotSame('', (string) $queued['content'], 'queued: in the row');
        $this->assertSame('', (string) $sent['content'], 'sent: moved to the store');
        $this->assertStringEndsWith(BodyStore::REBUILD_EXTENSION, (string) $sent['path']);
        $this->assertStringContainsString('emailpixel', $html, 'sent tracked');
        $this->assertSame($html, Body::of($sent));
    }

    /**
     * The store is chosen in app.php: `content` keeps the body in the row as before; a storage
     * disk keeps it there, the row naming the disk. One that cannot be written keeps the body in
     * the row rather than losing it.
     */
    public function testTheStoreIsChosenAndNeverLosesABody(): void
    {
        // Arrange
        $transport = new HtmlCapturingTransport();

        // Act — in the row
        $this->configure(['store' => 'content']);
        $this->newsletter('row@bodystore.test', $transport)->send();
        $inRow = $this->row('row@bodystore.test');

        // …on a storage disk
        $this->configure(['store' => 'files', 'disk' => 'mailbodies']);
        \Pramnos\Storage\Storage::init(['default' => 'mailbodies', 'disks' => ['mailbodies' => ['driver' => 'local', 'root' => $this->folder . '/disk']]]);
        $this->newsletter('disk@bodystore.test', $transport)->send();
        $onDisk = $this->row('disk@bodystore.test');

        // …a folder that cannot be written
        $this->configure(['store' => 'files', 'path' => '/proc/pf-cannot-write']);
        $this->newsletter('refused@bodystore.test', $transport)->send();
        $refused = $this->row('refused@bodystore.test');

        // Assert
        $this->assertNotSame('', (string) $inRow['content']);
        $this->assertSame('', (string) $inRow['path']);
        $this->assertStringStartsWith('disk:mailbodies:', (string) $onDisk['path']);
        $this->assertSame($transport->html[1], Body::of($onDisk), 'read back from the disk as sent');
        $this->assertNotSame('', (string) $refused['content'], 'kept in the row');
        $this->assertSame('', (string) $refused['path']);
    }

    /**
     * An unsubscribe link of the application's own — not the one the framework issues for this
     * address and list — is kept as it is, and the setting's other values pass through.
     */
    public function testAnApplicationsOwnLinkIsKeptAsItIs(): void
    {
        // Arrange
        $own = 'https://example.com/my-unsubscribe?u=1';

        // Act + Assert
        $this->assertSame('<a href="' . $own . '">x</a>', BodyStore::prepare('<a href="' . $own . '">x</a>', 'a@bodystore.test', $own, 'newsletter'));
        $this->assertSame('<p>x</p>', BodyStore::prepare('<p>x</p>', 'a@bodystore.test', '', ''), 'no list, nothing to replace');
        $this->configure(['store' => 'database']);
        $this->assertSame(BodyStore::DATABASE, BodyStore::mode());
        $this->configure([]);
        $this->assertSame(BodyStore::FILES, BodyStore::mode(), 'auto, with no mail_bodies here: files');
    }

    /**
     * A list message sent untracked is kept the same way and reads back as sent — its reader's
     * unsubscribe link, no tracking to put back.
     */
    public function testAnUntrackedListMessageReadsBackAsSent(): void
    {
        // Arrange
        $transport = new HtmlCapturingTransport();
        $to        = 'untracked@bodystore.test';
        $stop      = \Pramnos\Email\Unsubscribe::url(\Pramnos\Email\Unsubscribe::token($to, 'news'));
        $mail      = (new Email())->withTransport($transport);
        $mail->setTo($to)->setSubject('Untracked')->setBody('<p><a href="' . $stop . '">stop</a></p>')->offerUnsubscribe('news', $to);

        // Act
        $mail->send();
        $row = $this->row($to);

        // Assert
        $this->assertStringEndsWith(BodyStore::REBUILD_EXTENSION, (string) $row['path']);
        $this->assertSame($transport->html[0], Body::of($row));
    }

    /**
     * What cannot be read is no body, never an error: a `db:` row where there is no
     * `mail_bodies` (this MySQL), a disk nobody configured.
     */
    public function testAStoreThatCannotBeReadIsNoBody(): void
    {
        // Act + Assert
        $this->assertSame('', Body::of(['path' => BodyStore::IN_DATABASE, 'id' => 1, 'date' => time()]));
        $this->assertSame('', Body::of(['path' => 'disk:nobody:2026/a.body.gz']));
    }

    /**
     * A folder that is there but where the body cannot be put — here, a directory standing where
     * the file goes — keeps the body in the row: never half a body, never none.
     */
    public function testABodyThatCannotBeWrittenStaysInTheRow(): void
    {
        // Arrange
        $transport = new HtmlCapturingTransport();
        $to        = 'blocked@bodystore.test';
        $mail      = (new Email())->withTransport($transport);
        $mail->setTo($to)->setSubject('Blocked')->setBody('<p>Blocked</p>');
        $stamp = time();
        // This hour's and the next's, so a send on the turn of the hour is blocked all the same.
        $hours = array_unique([date('Y/m/d/H', $stamp), date('Y/m/d/H', $stamp + 60)]);
        $this->assertNotEmpty($hours, 'the sweep found nothing to check');
        foreach ($hours as $hour) {
            mkdir($this->folder . '/' . $hour . '/' . md5('<p>Blocked</p>') . BodyStore::REBUILD_EXTENSION, 0777, true);
        }

        // Act
        $mail->send();
        $row = $this->row($to);

        // Assert
        $this->assertSame('<p>Blocked</p>', (string) $row['content']);
        $this->assertSame('', (string) $row['path']);
        $this->assertSame([], glob($this->folder . '/' . date('Y/m/d/H', $stamp) . '/*.body.gz.*') ?: [], 'no temporary file left behind');
    }

    /**
     * Where `mail_bodies` refuses a body — chosen, but not there, as on this MySQL — the body goes
     * back into its row: sent at once, or by the worker. A worker whose keeping fails altogether
     * leaves the row as the outbox had it. Never a row that names a body nobody kept.
     */
    public function testARefusedDatabaseStoreKeepsTheBodyInTheRow(): void
    {
        // Arrange
        $this->configure(['store' => 'database']);
        $transport = new HtmlCapturingTransport();

        // Act — sent at once
        (new Email())->withTransport($transport)->setTo('now@bodystore.test')->setSubject('Now')->setBody('<p>Now</p>')->send();
        $now = $this->row('now@bodystore.test');

        // …queued, then sent by the worker
        (new Email())->setTo('later@bodystore.test')->setSubject('Later')->setBody('<p>Later</p>')->queue();
        (new CommandTester(new BodyStoreMailFlush($transport)))->execute([]);
        $later = $this->row('later@bodystore.test');

        // …and by a worker whose keeping fails
        (new Email())->setTo('failing@bodystore.test')->setSubject('Failing')->setBody('<p>Failing</p>')->queue();
        (new CommandTester(new BodyStoreMailFlush($transport, true)))->execute([]);
        $failing = $this->row('failing@bodystore.test');

        // Assert
        $this->assertSame(['<p>Now</p>', ''], [(string) $now['content'], (string) $now['path']]);
        $this->assertSame(['<p>Later</p>', ''], [(string) $later['content'], (string) $later['path']]);
        $this->assertSame(1, (int) $later['status']);
        $this->assertSame(['<p>Failing</p>', ''], [(string) $failing['content'], (string) $failing['path']]);
        $this->assertSame(1, (int) $failing['status'], 'sent all the same');
    }

    /** Without `mail_bodies` — here, MySQL — the move says where the table comes from, and fails. */
    public function testTheMoveNeedsTheTable(): void
    {
        // Arrange
        $tester = new CommandTester(new \Pramnos\Console\Commands\MailBodiesToDatabase());

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is no mail_bodies table', $tester->getDisplay());
    }

    /** An account to send to, with a name that has a character HTML would read. */
    private static function account(int $id, string $first): \Pramnos\User\User
    {
        $user = (new \ReflectionClass(\Pramnos\User\User::class))->newInstanceWithoutConstructor();
        $user->userid    = $id;
        $user->firstname = $first;
        $user->lastname  = 'Smith';
        $user->username  = 'user' . $id;
        $user->email     = 'user' . $id . '@bodystore.test';

        return $user;
    }

    /**
     * A templated message, through the installation's wrapper, to three people: one body kept —
     * the wrapped template — and each reads back as it was sent to them, to the byte: their name
     * in the body, the subject and the preheader, their unsubscribe link, their tracking. The row
     * keeps their values and their filled subject.
     */
    public function testATemplatedMessageIsKeptOnceAndFilledForEach(): void
    {
        // Arrange
        $transport = new HtmlCapturingTransport();
        $people    = [self::account(901, 'Anna'), self::account(902, 'Bob & <Co>'), self::account(903, 'Chloé')];

        // Act
        foreach ($people as $user) {
            $mail = (new Email())->withTransport($transport);
            $mail->setTo((string) $user->email)->setSubject('Hello {firstname}')->setTemplate('default')
                ->setBodyTemplate('<p>Dear {firstname}, your plan renews on {renewal}. <a href="https://example.com/plan">Your plan</a></p>', ['renewal' => '1/11'], $user)
                ->offerUnsubscribe('newsletter', (string) $user->email)
                ->enableTracking();
            $mail->send();
        }
        $rows = array_map(fn ($user): array => $this->row((string) $user->email), $people);

        // Assert
        $this->assertCount(1, array_unique(array_column($rows, 'path')), 'one body kept for the three');
        foreach ($rows as $i => $row) {
            $this->assertSame($transport->html[$i], Body::of($row), 'as sent to ' . $people[$i]->firstname);
            $this->assertNotNull(json_decode((string) $row['bodyvars'], true));
        }
        $this->assertSame('Hello Anna', (string) $rows[0]['subject'], 'the subject, filled');
        $this->assertStringContainsString('Dear Anna, your plan renews on 1/11.', $transport->html[0]);
        $this->assertStringContainsString('Dear Bob &amp; &lt;Co&gt;', $transport->html[1], 'the account\'s text escaped');
        $this->assertStringContainsString('<title>Hello Chloé</title>', $transport->html[2], 'the wrapper\'s subject, filled');
    }

    /**
     * The application's values join the account's and win over them; given values win over
     * both. Without `bodyvars` — its migration not run — a templated message is kept as filled,
     * and still reads back as sent.
     */
    public function testTheValuesAndATableWithoutBodyvars(): void
    {
        // Arrange
        $this->configure(['store' => 'files', 'path' => $this->folder], ['email' => ['template_values' => BodyStoreValues::class]]);
        $user = self::account(904, 'Dora');

        // Act
        $values = \Pramnos\Email\TemplateValues::forUser($user);
        $given  = (new Email())->setBodyTemplate('{firstname} {credits}', ['credits' => '7'], $user);
        (new \ReflectionProperty(BodyStore::class, 'bodyVars'))->setValue(null, false);
        $transport = new HtmlCapturingTransport();
        $mail      = (new Email())->withTransport($transport);
        $mail->setTo('novars@bodystore.test')->setSubject('No vars')->setBodyTemplate('<p>Hi {firstname}</p>', [], $user)->send();
        $row = $this->row('novars@bodystore.test');

        // Assert
        $this->assertSame('Dora', $values['firstname']);
        $this->assertSame('42', $values['credits'], 'the application\'s');
        $this->assertSame('Theirs', $values['username'], 'the application\'s win over the account\'s');
        $this->assertSame('{firstname} {credits}', $given->body, 'the template, until it is sent');
        $this->assertNull($row['bodyvars']);
        $this->assertSame('<p>Hi Dora</p>', Body::of($row), 'kept as filled');
        $this->assertSame($transport->html[0], Body::of($row));
        $this->assertArrayNotHasKey('firstname', \Pramnos\Email\TemplateValues::forUser(null), 'no account: none of its values');
        $this->assertArrayHasKey('login_url', \Pramnos\Email\TemplateValues::forUser(null), 'the site\'s still');
    }
}

/** An application's own template values. */
class BodyStoreValues implements \Pramnos\Email\TemplateValueProvider
{
    public function values(?object $user): array
    {
        return ['credits' => 42, 'username' => 'Theirs'];
    }
}

