<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\MailBounces;
use Pramnos\Console\Commands\MailFlush;
use Pramnos\Database\Database;
use Pramnos\Email\Bounces;
use Pramnos\Email\Email;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Messaging\CreateBouncesTable;
use Symfony\Component\Console\Tester\CommandTester;

/** A transport that keeps who each message went to — and refuses with a given reply, if asked. */
class RecipientsTransport extends \Symfony\Component\Mailer\Transport\AbstractTransport
{
    /** @var list<list<string>> */
    public array $sent = [];

    /** @param string $refusal An SMTP reply to fail every send with; '' sends */
    public function __construct(public string $refusal = '')
    {
        parent::__construct();
    }

    /** Record the envelope's recipients, or fail as a server that refused them. */
    protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
    {
        if ($this->refusal !== '') {
            throw new \Symfony\Component\Mailer\Exception\TransportException($this->refusal);
        }
        $this->sent[] = array_map(static fn ($a): string => $a->getAddress(), $message->getEnvelope()->getRecipients());
    }

    /** The transport's name, as Symfony prints it. */
    public function __toString(): string
    {
        return 'recipients://';
    }
}

/** `mail:flush` sending through a recipients transport. */
class BouncesMailFlush extends MailFlush
{
    /** @param RecipientsTransport $transport Where the worker's messages go */
    public function __construct(private RecipientsTransport $transport)
    {
        parent::__construct();
    }

    /** A mailer that sends through the test's transport. */
    protected function mailer(): Email
    {
        return (new Email())->withTransport($this->transport);
    }
}

/**
 * Bounces and blocks (mail body storage, phase 5): an address mail bounced from, or one blocked by
 * hand, receives nothing — a password reset included — and the `mails` row says why. Delivery
 * reports are read into the table, an installation's own `bounces` keeps its rows, and the rules
 * are the installation's to set. On MySQL (MariaDB with --mariadb) here, on PostgreSQL in
 * {@see BouncesPostgreSQLTest}.
 */
#[CoversClass(Bounces::class)]
#[CoversClass(Email::class)]
#[CoversClass(MailFlush::class)]
#[CoversClass(MailBounces::class)]
#[CoversClass(CreateBouncesTable::class)]
class BouncesTest extends TestCase
{
    protected Database $db;

    private ?Database $previous = null;

    private ?array $savedInstances = null;

    /** @var list<string> Files a test wrote, removed after it */
    private array $files = [];

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
        ], $db);
        $this->migrate();
        $this->configure([]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->queryBuilder()->table('#PREFIX#mails')->whereRaw('tomail like ?', ['%@bounces.test%'])->delete();
            foreach (['hard@bounces.test', 'soft@bounces.test', 'blocked@bounces.test', 'ok@bounces.test', 'queued@bounces.test', 'full@bounces.test', 'refused@bounces.test', 'kept@bounces.test'] as $address) {
                Bounces::clear($address);
            }
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        if ($this->files !== []) {
            @rmdir(dirname($this->files[0]));
        }
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        Bounces::forget();
    }

    /** Run the bounces migration on this connection, and forget what was known of the table. */
    private function migrate(): void
    {
        $this->migration()->up();
        Bounces::forget();
    }

    /** The bounces migration, on this connection. */
    private function migration(): CreateBouncesTable
    {
        $application           = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $application->database = $this->db;

        return new CreateBouncesTable($application);
    }

    /** An application with these bounce rules. */
    private function configure(array $bounces): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['email' => ['bounces' => $bounces]];
        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances ??= $ref->getValue() ?? [];
        $ref->setValue(null, ['default' => $stub] + $this->savedInstances);
    }

    /** A delivery report (RFC 3464) for these recipients: [address, action, status, diagnostic]. */
    private function report(array ...$recipients): string
    {
        $parts = '';
        foreach ($recipients as [$address, $action, $status, $diagnostic]) {
            $parts .= "\r\nFinal-Recipient: rfc822; " . $address . "\r\nAction: " . $action . "\r\nStatus: " . $status
                . "\r\nDiagnostic-Code: smtp; " . $diagnostic . "\r\n";
        }

        return "From: MAILER-DAEMON@mx.example\r\nSubject: Undelivered Mail Returned to Sender\r\n"
            . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"b\"\r\n\r\n"
            . "--b\r\nContent-Type: text/plain\r\n\r\nThe message could not be delivered.\r\n\r\n"
            . "--b\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.example\r\n" . $parts
            . "\r\n--b--\r\n";
    }

    /** A message to `$to` through `$transport`. */
    private function mail(string|array $to, RecipientsTransport $transport): Email
    {
        $mail = (new Email())->withTransport($transport);
        $mail->setTo($to)->setSubject('Your code')->setBody('<p>123456</p>');

        return $mail;
    }

    /** The newest `mails` row for an address. */
    private function row(string $to): array
    {
        return (array) $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', $to)->orderBy('id', 'desc')->first()->fields;
    }

    /**
     * A hard bounce read from a delivery report stops every message to the address — this one is
     * a sign-in code, which no unsubscribe could stop — and the row says why. Cleared, mail goes
     * again.
     */
    public function testAHardBounceStopsEveryMessageUntilCleared(): void
    {
        // Arrange
        $transport = new RecipientsTransport();
        $recorded  = Bounces::recordReport($this->report(['Hard@Bounces.test', 'failed', '5.1.1', '550 5.1.1 <hard@bounces.test>: no such user']));

        // Act
        $mail = $this->mail('hard@bounces.test', $transport);
        $sent = $mail->send();
        $row  = $this->row('hard@bounces.test');
        Bounces::clear('hard@bounces.test');
        $again = $this->mail('hard@bounces.test', $transport)->send();

        // Assert
        $this->assertCount(1, $recorded);
        $this->assertSame('hard@bounces.test', $recorded[0]['email'], 'kept lowercased, as the hash is');
        $this->assertFalse($sent, 'not sent');
        $this->assertStringContainsString('hard-bounced 1 time(s)', $mail->getLastError());
        $this->assertStringContainsString('no such user', $mail->getLastError(), 'with the server\'s reason');
        $this->assertSame(0, (int) $row['status'], 'recorded as not sent');
        $this->assertTrue($again, 'cleared, it goes');
        $this->assertSame([['hard@bounces.test']], $transport->sent, 'and only that one went');
    }

    /**
     * A soft bounce holds mail for a day, then lets it go; enough of them stop it for good. Each
     * rule is the installation's to change, and 0 turns it off.
     */
    public function testSoftBouncesWaitADayAndThenCount(): void
    {
        // Arrange
        $address = 'soft@bounces.test';
        Bounces::recordReport($this->report([$address, 'failed', '5.2.2', '552 5.2.2 mailbox full']));
        $hash = (string) Bounces::hash($address);

        // Act + Assert — within the day
        $this->assertStringContainsString('tried again after', (string) Bounces::refusal($address));

        // A day on
        $this->db->queryBuilder()->table('#PREFIX#bounces')->where('hash', $hash)->update(['lastsoft' => time() - 86401]);
        $this->assertNull(Bounces::refusal($address), 'a day later it goes');

        // Fifteen of them
        $this->db->queryBuilder()->table('#PREFIX#bounces')->where('hash', $hash)->update(['softbounces' => 15]);
        $this->assertStringContainsString('soft-bounced 15 time(s)', (string) Bounces::refusal($address));

        // The installation's rules
        $this->configure(['soft' => 0, 'softWithin' => 0]);
        $this->assertNull(Bounces::refusal($address), 'with both rules off, soft bounces stop nothing');
        $this->configure(['hard' => 0]);
        Bounces::record($address, false, 'once');
        $this->assertStringContainsString('soft-bounced', (string) Bounces::refusal($address), 'hard rule off: only the soft one refuses');
    }

    /**
     * A block stops all mail with the reason given, survives bounces recorded after it, and is
     * cleared with them.
     */
    public function testABlockStaysUntilCleared(): void
    {
        // Arrange
        $address = 'blocked@bounces.test';

        // Act
        $this->assertTrue(Bounces::block($address, 'asked by phone'));
        Bounces::record($address, true, '452 4.2.2 over quota');
        $status  = Bounces::status($address);
        $refusal = Bounces::refusal($address);
        Bounces::clear($address);

        // Assert
        $this->assertGreaterThan(0, (int) $status['blocked']);
        $this->assertSame(1, (int) $status['softbounces'], 'the bounce still counted');
        $this->assertStringStartsWith('blocked since', (string) $refusal);
        $this->assertStringContainsString('over quota', (string) $refusal, 'with the latest reason');
        $this->assertNull(Bounces::status($address), 'cleared');
        $this->assertFalse(Bounces::block('not an address'), 'something that is not an address is nothing');
        $this->assertFalse(Bounces::record('', true));
        $this->assertFalse(Bounces::clear('nobody'));
        $this->assertNull(Bounces::status('nobody'));
    }

    /**
     * A message to several addresses goes to those it may go to; only when none is left is it
     * not sent at all. An address with a name is recognised as the address it is.
     */
    public function testOnlyTheRefusedRecipientsAreLeftOut(): void
    {
        // Arrange
        $transport = new RecipientsTransport();
        Bounces::record('hard@bounces.test', false, '550 5.1.1');
        Bounces::block('blocked@bounces.test');

        // Act
        $some   = $this->mail(['ok@bounces.test' => 'Ok', 'hard@bounces.test' => 'Gone'], $transport)->send();
        $listed = $this->mail('ok@bounces.test, hard@bounces.test', $transport)->send();
        $none   = $this->mail(['hard@bounces.test', 'Blocked <blocked@bounces.test>'], $transport);
        $noneSent = $none->send();

        // Assert
        $this->assertTrue($some);
        $this->assertTrue($listed);
        $this->assertSame([['ok@bounces.test'], ['ok@bounces.test']], $transport->sent, 'the bounced address left out, as an array and as a list');
        $this->assertFalse($noneSent, 'nobody left: not sent');
        $this->assertStringContainsString('hard@bounces.test hard-bounced', $none->getLastError());
        $this->assertStringContainsString('blocked@bounces.test> blocked since', $none->getLastError(), 'each named, with why');
    }

    /**
     * A message queued before its address bounced is not sent by the worker: its row fails with
     * the reason, and the transport is not reached.
     */
    public function testAQueuedMessageToAnAddressThatBouncedSinceIsNotSent(): void
    {
        // Arrange
        $transport = new RecipientsTransport();
        // Queued with no transport of its own: one given is sent at once, as a signed message is.
        (new Email())->setTo('queued@bounces.test')->setSubject('Later')->setBody('<p>Later</p>')->queue();
        Bounces::record('queued@bounces.test', false, '550 5.1.1 gone');

        $queued = $this->row('queued@bounces.test');

        // Act
        (new CommandTester(new BouncesMailFlush($transport)))->execute([]);
        $row = $this->row('queued@bounces.test');

        // Assert
        $this->assertSame(\Pramnos\Messaging\Mail::STATUS_QUEUED, (int) $queued['status'], 'it was queued');
        $this->assertSame(\Pramnos\Messaging\Mail::STATUS_FAILED, (int) $row['status']);
        $this->assertStringContainsString('hard-bounced', (string) $row['extrainfo']);
        $this->assertNotContains(['queued@bounces.test'], $transport->sent, 'never handed to the server');
    }

    /**
     * A server that refuses the address itself (`5.1.x`) is a hard bounce, recorded then — no
     * report will follow. A refusal for any other reason, or one that cannot be pinned on one
     * address, records nothing.
     */
    public function testAServerRefusingTheAddressIsAHardBounce(): void
    {
        // Act
        $this->mail('refused@bounces.test', new RecipientsTransport('550 5.1.1 <refused@bounces.test>: Recipient address rejected'))->send();
        $this->mail('kept@bounces.test', new RecipientsTransport('550 5.7.1 Relaying denied'))->send();
        $this->mail(['ok@bounces.test', 'full@bounces.test'], new RecipientsTransport('550 5.1.1 one of them'))->send();
        $queued = new Email();
        $queued->withTransport(new RecipientsTransport('550 5.1.2 bad domain'))->setTo('full@bounces.test');
        $queued->sendRendered();

        // Assert
        $this->assertStringContainsString('hard-bounced', (string) Bounces::refusal('refused@bounces.test'));
        $this->assertNull(Bounces::status('kept@bounces.test'), 'a policy refusal is the sender\'s, not the address\'s');
        $this->assertNull(Bounces::status('ok@bounces.test'), 'which of two cannot be told');
        $this->assertStringContainsString('hard-bounced', (string) Bounces::refusal('full@bounces.test'), 'the worker\'s sends too');
    }

    /**
     * An installation's own `bounces` — the layout applications keep, no defaults, no `blocked`
     * — keeps its rows and gains the two columns; its counts go on from where they were.
     */
    public function testAnApplicationsOwnTableKeepsItsRows(): void
    {
        // Arrange
        $this->db->query('DROP TABLE ' . $this->db->schema()->quoteTable('#PREFIX#bounces'));
        $this->db->query('CREATE TABLE ' . $this->db->schema()->quoteTable('#PREFIX#bounces') . ' (bounceid ' . ($this->db->type === 'postgresql' ? 'serial' : 'integer NOT NULL AUTO_INCREMENT') . ' PRIMARY KEY, hash char(64) NOT NULL UNIQUE, '
            . 'lastsoft integer NOT NULL, lasthard integer NOT NULL, hardbounces integer NOT NULL, softbounces integer NOT NULL)');
        $this->db->queryBuilder()->table('#PREFIX#bounces')->insert([
            'bounceid' => 1, 'hash' => hash('sha256', 'kept@bounces.test'), 'lastsoft' => 0, 'lasthard' => 1000, 'hardbounces' => 0, 'softbounces' => 4,
        ]);
        Bounces::forget();
        $this->assertFalse(Bounces::record('kept@bounces.test', true), 'before the migration: not written to a table without its columns');

        // Act
        $this->migrate();
        $this->migrate();
        Bounces::record('kept@bounces.test', true, '452 4.2.2 over quota');
        $status = Bounces::status('kept@bounces.test');

        // Assert
        $this->assertSame(5, (int) $status['softbounces'], 'the count went on');
        $this->assertSame(1, (int) $status['bounceid'], 'the same row');
        $this->assertSame(0, (int) $status['blocked']);
        $this->assertSame('452 4.2.2 over quota', $status['reason']);

        // And the framework's own table back, for the rest.
        $this->db->query('DROP TABLE ' . $this->db->schema()->quoteTable('#PREFIX#bounces'));
        $this->migrate();
    }

    /**
     * A bounce the database will not take is said, not lost: `record()` throws — a bounce silently
     * not kept is mail that goes on being sent to it — and a send whose refusal could not be
     * recorded still answers as the refused send it was.
     */
    public function testABounceThatCannotBeWrittenIsSaid(): void
    {
        // Arrange — the table loses a column it is known to have
        $this->assertNull(Bounces::status('nobody@bounces.test'), 'the table is known');
        $this->db->schema()->alterTable('bounces', function ($table) {
            $table->dropColumn('reason');
        });
        $mail = $this->mail('refused@bounces.test', new RecipientsTransport('550 5.1.1 no such user'));

        // Act
        $sent  = $mail->send();
        $error = null;
        try {
            Bounces::record('refused@bounces.test', false, 'gone');
        } catch (\Throwable $e) {
            $error = $e;
        }
        $this->db->query('DROP TABLE ' . $this->db->schema()->quoteTable('#PREFIX#bounces'));
        $this->migrate();

        // Assert
        $this->assertFalse($sent);
        $this->assertStringContainsString('5.1.1', $mail->getLastError(), 'the server\'s refusal, not the store\'s');
        $this->assertNotNull($error, 'record() says it could not');
        $this->assertNull(Bounces::status('refused@bounces.test'));
    }

    /** Without the table nothing is refused and nothing written: mail goes as it did. */
    public function testWithoutTheTableNothingIsRefused(): void
    {
        // Arrange
        $migration = $this->migration();
        $this->db->query('DROP TABLE ' . $this->db->schema()->quoteTable('#PREFIX#bounces'));
        Bounces::forget();

        // Act
        $recorded = Bounces::record('hard@bounces.test', false);
        $refusal  = Bounces::refusal('hard@bounces.test');
        $tester   = new CommandTester(new MailBounces());
        $tester->execute(['--block' => 'hard@bounces.test']);
        $migration->up();
        $migration->down();
        $migration->down();
        $columns = $this->db->schema()->hasColumn('bounces', 'blocked');
        $this->db->query('DROP TABLE ' . $this->db->schema()->quoteTable('#PREFIX#bounces'));
        $this->migrate();

        // Assert
        $this->assertFalse($recorded);
        $this->assertNull($refusal);
        $this->assertSame(1, $tester->getStatusCode(), 'the command says there is no table');
        $this->assertStringContainsString('no bounces table', $tester->getDisplay());
        $this->assertFalse($columns, 'down takes away the columns it added, and only those');
    }

    /**
     * `mail:bounces` reads a folder of reports — every failed recipient recorded, a delayed one
     * and a message that is not a report left alone, the reports removed with --delete — and
     * standard input; and keeps the list by hand.
     */
    public function testTheCommandReadsReportsAndKeepsTheList(): void
    {
        // Arrange
        $folder = sys_get_temp_dir() . '/pf-bounces-' . bin2hex(random_bytes(3));
        mkdir($folder);
        $this->files = [
            $folder . '/1.eml', $folder . '/2.eml', $folder . '/3.eml', $folder . '/missing.eml',
        ];
        file_put_contents($this->files[0], $this->report(['hard@bounces.test', 'failed', '5.1.1', '550 no such user'], ['ok@bounces.test', 'delayed', '4.4.1', '451 try later']));
        file_put_contents($this->files[1], $this->report(['soft@bounces.test', 'failed', '4.2.2', '452 over quota']));
        file_put_contents($this->files[2], "Subject: Re: your invoice\r\n\r\nThanks!\r\n");
        $stdin = fopen('php://memory', 'r+');
        fwrite($stdin, $this->report(['full@bounces.test', 'failed', '5.2.2', '552 mailbox full']));
        rewind($stdin);
        $command = new class ($stdin) extends MailBounces {
            /** @param resource $stdin */
            public function __construct(private $stdin)
            {
                parent::__construct();
            }

            protected function stdin()
            {
                return $this->stdin;
            }
        };

        // Act
        $folderRun = new CommandTester(new MailBounces());
        $folderRun->execute(['paths' => [$folder, $this->files[3]], '--delete' => true]);
        $stdinRun = new CommandTester($command);
        $stdinRun->execute([]);
        $notAReport = new CommandTester(new class (fopen('php://memory', 'r')) extends MailBounces {
            /** @param resource $stdin */
            public function __construct(private $stdin)
            {
                parent::__construct();
            }

            protected function stdin()
            {
                return $this->stdin;
            }
        });
        $notAReport->execute([]);
        $hand = new CommandTester(new MailBounces());
        $hand->execute(['--block' => 'blocked@bounces.test', '--reason' => 'asked']);
        $blocked = $hand->getDisplay();
        $hand->execute(['--status' => 'blocked@bounces.test']);
        $status = $hand->getDisplay();
        $hand->execute(['--clear' => 'blocked@bounces.test']);
        $cleared = $hand->getDisplay();
        $hand->execute(['--status' => 'blocked@bounces.test']);
        $nothing = $hand->getDisplay();
        $hand->execute(['--status' => 'not-an-address']);

        // Assert
        $this->assertStringContainsString('2 report(s)', $folderRun->getDisplay(), 'two of the three were reports');
        $this->assertStringContainsString('Cannot read', $folderRun->getDisplay(), 'a file that is not there is said');
        $this->assertFileDoesNotExist($this->files[0], 'a report read is removed');
        $this->assertFileExists($this->files[2], 'a message that is not one is left for a person');
        $this->assertNotNull(Bounces::refusal('hard@bounces.test'));
        $this->assertNull(Bounces::status('ok@bounces.test'), 'a delay is not a bounce');
        $this->assertSame(1, (int) Bounces::status('soft@bounces.test')['softbounces']);
        $this->assertSame(0, $stdinRun->getStatusCode());
        $this->assertStringContainsString('soft  full@bounces.test', $stdinRun->getDisplay(), 'a full mailbox is soft');
        $this->assertSame(1, $notAReport->getStatusCode(), 'standard input that is not a report fails, for the mail server to return');
        $this->assertStringContainsString('No mail goes to', $blocked);
        $this->assertStringContainsString('0 hard, 0 soft bounce(s), blocked', $status);
        $this->assertStringContainsString('not sent: blocked since', $status);
        $this->assertStringContainsString('mail to it is sent again', $cleared);
        $this->assertStringContainsString('Nothing recorded', $nothing);
        $this->assertStringContainsString('Mail to it is sent.', $nothing);
        $this->assertSame(1, $hand->getStatusCode(), 'not an address');
    }
}
