<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\MailFlush;
use Pramnos\Database\Database;
use Pramnos\Email\Email;
use Pramnos\Framework\Factory;
use Pramnos\Messaging\Mail;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The outbox, end to end, against a live database.
 *
 * `Email::queue()` writes a composed message to `mails` at {@see Mail::STATUS_QUEUED} and
 * `mail:flush` moves it to sent or failed **in place**. Both halves are here because either one
 * alone proves nothing: a row written that no worker recognises is a message that never arrives,
 * and a worker that transitions rows nobody wrote is a worker with no work.
 *
 * ## Non-destructive on purpose
 *
 * `mails` is a shared table with a framework migration and other tests' rows in it, so this
 * neither drops nor creates it. Every row written here carries a unique `module` tag and
 * `tearDown` removes exactly those. A test that dropped `mails` to get a clean slate would take
 * the audit log of whatever ran beside it.
 */
#[\PHPUnit\Framework\Attributes\Group('serial')]
class MailOutboxMySQLTest extends TestCase
{
    protected Database $db;

    /** Distinguishes this test's rows from every other row in a shared table. */
    protected string $tag = '';

    /** Restored in tearDown: a dropped singleton hands every later test this connection. */
    private ?Database $previousSingleton = null;

    protected function setUp(): void
    {
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->createMailsTable();
        $this->tag = 'outbox-test-' . bin2hex(random_bytes(4));
    }

    /** @var array<string, mixed>|null The application instances, before a test set its own */
    private ?array $savedInstances = null;

    /** The application last used, before a test made its own the current one. */
    private ?string $savedLastUsed = null;

    protected function tearDown(): void
    {
        $this->db->schema()->dropTableIfExists('mails');
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
            (new \ReflectionProperty(\Pramnos\Application\Application::class, 'lastUsedApplication'))->setValue(null, $this->savedLastUsed);
            $this->savedInstances = null;
        }

        if ($this->previousSingleton !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previousSingleton;
            $this->previousSingleton = null;
        }
    }

    /**
     * The table, from its own framework migration.
     *
     * Created here rather than assumed: the test database does not carry it, and a suite whose
     * every test skips for a missing table is a suite that proves nothing while reporting
     * green. Dropped in tearDown, which is safe precisely because nothing else in the schema
     * has it.
     */
    protected function createMailsTable(): void
    {
        $this->db->schema()->dropTableIfExists('mails');

        $dir = dirname(__DIR__, 3) . '/database/migrations/framework/messaging';

        foreach (\Pramnos\Database\MigrationLoader::loadFromDirectory($dir, $this->app()) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Messaging\CreateMailsTable) {
                $migration->up();
            }
        }

        if (!$this->db->schema()->hasTable('mails')) {
            $this->fail('the mails migration did not create the table');
        }
    }

    /** The application a migration needs to reach the schema builder. */
    protected function app(): \Pramnos\Application\Application
    {
        $app = new \Pramnos\Application\Application();
        $app->database = $this->db;

        return $app;
    }

    /** Remember the singleton before a lane replaces it. */
    protected function rememberSingleton(Database $current): void
    {
        $this->previousSingleton = $current;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * `queue()` writes one pending row, and it holds the body the caller composed.
     *
     * The body assertion is the one that matters. A spool that stored the *inputs* and rendered
     * later would send a different message from the one composed — the wrapper reads the
     * request's language and its unsubscribe token, and a worker an hour later has neither. So
     * what is stored has to be the final string.
     */
    public function testQueueWritesOnePendingRowHoldingTheComposedBody(): void
    {
        // Arrange + Act
        $this->queueOne('someone@example.com', 'Queued subject', '<p>Queued body</p>');

        // Assert
        $rows = $this->rows();
        $this->assertCount(1, $rows, 'queue() must write exactly one row');
        $this->assertSame(Mail::STATUS_QUEUED, (int) $rows[0]['status']);
        $this->assertSame('someone@example.com', $rows[0]['tomail']);
        $this->assertSame('Queued subject', $rows[0]['subject']);
        $this->assertStringContainsString(
            'Queued body',
            (string) $rows[0]['content'],
            'the stored row does not hold the composed body'
        );
        // No error yet: what it holds is what the worker needs to send it, not a failure.
        $kept = json_decode((string) $rows[0]['extrainfo'], true);
        $this->assertIsArray($kept['outbox'] ?? null, 'a queued row does not keep what the send needs');
    }

    /**
     * A queued message leaves as it would have left at once: copies, reply-to, its thread, its
     * own headers and its unsubscribe headers all survive the outbox.
     *
     * The worker rebuilt a message from the address, the subject and the body, and everything
     * else was dropped — so queued list mail went out without `List-Unsubscribe`, which Gmail
     * requires of bulk senders, and a queued reply went out of its thread.
     */
    public function testAQueuedMessageLeavesWithEverythingItWasQueuedWith(): void
    {
        // Arrange
        $email = $this->mail('reader@example.com', 'Newsletter', '<p>Issue 1</p>');
        $email->setCc('copy@example.com');
        $email->replyto = 'editor@example.com';
        $email->addHeader('X-Campaign', 'issue-1');
        $email->inReplyTo('previous@example.com');
        $email->unsubscribe         = 'https://example.com/unsubscribe/abc';
        $email->unsubscribeList     = 'newsletter';
        $email->unsubscribeOneClick = true;
        $email->queue();
        $transport = new OutboxCapturingTransport();

        // Act
        $this->flush(new FlushThroughTransportMailer($transport));

        // Assert
        $this->assertCount(1, $transport->sent, 'the worker sent nothing');
        $raw = $transport->sent[0];
        $this->assertStringContainsString('Cc: copy@example.com', $raw);
        $this->assertStringContainsString('Reply-To: editor@example.com', $raw);
        $this->assertStringContainsString('X-Campaign: issue-1', $raw);
        $this->assertStringContainsString('In-Reply-To: <previous@example.com>', $raw);
        $this->assertStringContainsString('List-Unsubscribe: <https://example.com/unsubscribe/abc>', $raw);
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $raw);
        $this->assertSame(Mail::STATUS_SENT, (int) $this->rows()[0]['status']);
        $this->assertSame('', (string) $this->rows()[0]['extrainfo'], 'a sent row still holds the outbox state');
    }

    /**
     * The worker delivers it and the row becomes sent — the same row, not a second one.
     *
     * One row for a message's whole life is what lets the history screens read `mails` without a
     * union, and what makes "was this ever sent" answerable by looking at one place.
     */
    public function testFlushDeliversThePendingRowInPlace(): void
    {
        // Arrange
        $this->queueOne('sent@example.com', 'Will go out', '<p>Body</p>');

        $mailer = new FlushSpyMailer(true);

        // Act
        $this->flush($mailer);

        // Assert
        $rows = $this->rows();
        $this->assertCount(1, $rows, 'the worker wrote a second row instead of moving the first');
        $this->assertSame(Mail::STATUS_SENT, (int) $rows[0]['status']);
        // Sent as of the row's date — what its links count from, and are issued again from.
        $this->assertSame((int) $rows[0]['date'], $mailer->stamp());
    }

    /**
     * A queued message whose body is kept in a file — `content` empty, `path` naming it, as an
     * application keeps them — leaves with that body, not an empty one.
     */
    public function testAQueuedBodyKeptInAFileIsSent(): void
    {
        // Arrange
        $this->queueOne('file@example.com', 'From disk', '<p>Body</p>');
        $relative = '/var/pf-outbox-' . bin2hex(random_bytes(3)) . '.html.gz';
        file_put_contents(ROOT . $relative, gzencode('<p>Kept on disk</p>'));
        $this->db->queryBuilder()->table('#PREFIX#mails')->where('module', $this->tag)->update(['content' => '', 'path' => $relative]);
        $mailer = new FlushSpyMailer(true);

        // Act
        $this->flush($mailer);
        @unlink(ROOT . $relative);

        // Assert
        $this->assertSame(1, $mailer->attempts);
        $this->assertSame('<p>Kept on disk</p>', $mailer->body);
    }

    /**
     * A permanent refusal fails the row immediately, with the reason on it.
     *
     * A 5xx is the server saying «never»: no such mailbox, rejected for policy. Retrying it
     * spends a full SMTP connection every run for as long as the deadline allows, on an address
     * that will not accept the message at any point.
     */
    public function testAPermanentRefusalFailsTheRowAtOnce(): void
    {
        // Arrange
        $this->queueOne('nobody@example.invalid', 'Hard bounce', '<p>Body</p>');

        // Act
        $this->flush(new FlushSpyMailer(false, '550 5.1.1 recipient rejected'));

        // Assert
        $rows = $this->rows();
        $this->assertSame(Mail::STATUS_FAILED, (int) $rows[0]['status']);
        $this->assertStringContainsString('550', (string) $rows[0]['extrainfo'],
            'the reason it will never arrive is not recorded');
    }

    /**
     * A transient refusal leaves the row pending, for the next run.
     *
     * This is the case the outbox exists for. Treating a 4xx as fatal throws a message away
     * because a mail server had a bad minute — and that failure is invisible, because a row
     * marked failed looks exactly like a row that was genuinely undeliverable.
     */
    public function testATransientRefusalLeavesTheRowPending(): void
    {
        // Arrange
        $this->queueOne('later@example.com', 'Try again', '<p>Body</p>');

        // Act
        $this->flush(new FlushSpyMailer(false, '451 4.7.1 try again later'));

        // Assert
        $rows = $this->rows();
        $this->assertSame(Mail::STATUS_QUEUED, (int) $rows[0]['status'],
            'a temporary failure must not consume the message');
    }

    /**
     * A failure with no recognisable code is treated as transient.
     *
     * A DNS failure, a timeout, a refused connection — none carries an SMTP code, and all three
     * are ordinarily temporary. The safe direction is to retry: being wrong costs one more
     * attempt, against silently discarding a message that would have gone.
     */
    public function testAFailureWithNoCodeIsTreatedAsTransient(): void
    {
        // Arrange
        $this->queueOne('dns@example.com', 'No code', '<p>Body</p>');

        // Act
        $this->flush(new FlushSpyMailer(false, 'Connection could not be established'));

        // Assert
        $this->assertSame(Mail::STATUS_QUEUED, (int) $this->rows()[0]['status']);
    }

    /**
     * Past the deadline it is given up on, without another attempt.
     *
     * Bounded by time rather than by attempts, which is how a real MTA works and why there is no
     * counter column: the useful question is whether this has been undeliverable long enough to
     * stop, not how many times it was tried.
     *
     * The mailer asserts the second half — it must not be called at all for an expired row.
     */
    public function testPastTheDeadlineTheMessageIsGivenUpOn(): void
    {
        // Arrange — queued, then aged past the deadline
        $this->queueOne('stale@example.com', 'Too old', '<p>Body</p>');
        $this->db->query(
            'UPDATE ' . $this->db->prefix . 'mails SET date = ' . (time() - 90000)
            . " WHERE module = '" . $this->db->prepareInput($this->tag) . "'"
        );

        // Act — a deadline of one hour, so the row is well past it
        $mailer = new FlushSpyMailer(true);
        $this->flush($mailer, ['--deadline' => '3600']);

        // Assert
        $rows = $this->rows();
        $this->assertSame(Mail::STATUS_FAILED, (int) $rows[0]['status']);
        $this->assertSame(0, $mailer->attempts, 'an expired message must not be attempted again');
        $this->assertStringContainsString('deadline', strtolower((string) $rows[0]['extrainfo']));
    }

    /**
     * A row dated in the future is mail queued for later: it stays pending until its time, and a
     * row that is due goes. `mail:flush` sent everything pending at once, so mail an application
     * queued for tomorrow went today (FW-195).
     */
    public function testMailQueuedForLaterWaitsForItsTime(): void
    {
        // Arrange — one for an hour from now, one due a minute ago
        $this->queueOne('later@example.com', 'Later', '<p>Body</p>');
        $this->queueOne('due@example.com', 'Due', '<p>Body</p>');
        $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', 'later@example.com')->update(['date' => time() + 3600]);
        $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', 'due@example.com')->update(['date' => time() - 60]);

        // Act
        $mailer = new FlushSpyMailer(true);
        $this->flush($mailer);

        // Assert
        $rows = $this->rows();
        $this->assertCount(2, $rows, 'the sweep found nothing to check');
        $status = [];
        foreach ($rows as $row) {
            $status[$row['tomail']] = (int) $row['status'];
        }
        $this->assertSame(Mail::STATUS_QUEUED, $status['later@example.com'], 'mail queued for later was sent now');
        $this->assertSame(Mail::STATUS_SENT, $status['due@example.com']);
        $this->assertSame(1, $mailer->attempts);
    }

    /** An application whose `mail.outbox` is this, as the current one. */
    private function outboxConfig(array $outbox): void
    {
        $stub = new class extends \Pramnos\Application\Application {
            /** Nothing to boot: only the configuration is read. */
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['mail' => ['outbox' => $outbox]];
        $property = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $last     = new \ReflectionProperty(\Pramnos\Application\Application::class, 'lastUsedApplication');
        if ($this->savedInstances === null) {
            $this->savedLastUsed = $last->getValue();
        }
        $this->savedInstances ??= $property->getValue() ?? [];
        $property->setValue(null, ['default' => $stub] + $this->savedInstances);
        // The current application is the one last used: an earlier test's, under another name, would
        // be read instead of this one — and its configuration has no outbox.
        $last->setValue(null, 'default');
    }

    /** Queue one, then date it `$ago` seconds back. */
    private function queueAged(string $to, int $ago): void
    {
        $this->queueOne($to, 'Aged', '<p>Body</p>');
        $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', $to)->update(['date' => time() - $ago]);
    }

    /** Each row's status, by recipient. @return array<string, int> */
    private function statuses(): array
    {
        $rows = $this->rows();
        $this->assertNotEmpty($rows, 'the sweep found nothing to check');
        $status = [];
        foreach ($rows as $row) {
            $status[$row['tomail']] = (int) $row['status'];
        }

        return $status;
    }

    /**
     * An application's hooks around each send (FW-200): `beforeSend()` refusing fails the row with
     * its reason, and only the rows that go reach the transport; `afterSent()` is called once per
     * message sent, `afterFailed()` once per message failed for good — refused, or 5xx — and not
     * for one left to try again.
     */
    public function testTheApplicationsHooksAroundEachSend(): void
    {
        // Arrange
        $this->queueOne('blocked@example.com', 'Refused', '<p>Body</p>');
        $this->queueOne('fine@example.com', 'Goes', '<p>Body</p>');
        $command = new HookedMailFlush(new FlushSpyMailer(true));

        // Act
        (new CommandTester($command))->execute([]);
        $status = $this->statuses();
        $reason = (string) $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', 'blocked@example.com')->first()->fields['extrainfo'];

        // Assert
        $this->assertSame(Mail::STATUS_FAILED, $status['blocked@example.com']);
        $this->assertSame('On the blocklist', $reason);
        $this->assertSame(Mail::STATUS_SENT, $status['fine@example.com']);
        $this->assertSame(['fine@example.com'], $command->sent);
        $this->assertSame(['blocked@example.com' => 'On the blocklist'], $command->failed);
        $this->assertSame(1, $command->spyAttempts(), 'the refused one reached the transport');
    }

    /**
     * Sending switched off (`mail.outbox.enabled` false): nothing is sent and nothing given up on
     * — an aged row stays queued too, ready when it is switched back on (FW-200).
     */
    public function testSwitchedOffNothingIsSentAndNothingFailed(): void
    {
        // Arrange
        $this->outboxConfig(['enabled' => false]);
        $this->queueAged('waiting@example.com', 60);
        $this->queueAged('old@example.com', 3 * 86400);
        $mailer = new FlushSpyMailer(true);

        // Act
        $tester = $this->flush($mailer);

        // Assert
        $this->assertSame(['waiting@example.com' => Mail::STATUS_QUEUED, 'old@example.com' => Mail::STATUS_QUEUED], $this->statuses());
        $this->assertSame(0, $mailer->attempts);
        $this->assertStringContainsString('switched off', $tester->getDisplay());
    }

    /**
     * With the deadline `never` — from the configuration or the option — a row queued three days
     * ago is sent rather than given up on (FW-200).
     */
    public function testWithNoDeadlineAnOldRowIsStillSent(): void
    {
        // Arrange
        $this->outboxConfig(['deadline' => 'never']);
        $this->queueAged('three-days@example.com', 3 * 86400);
        $mailer = new FlushSpyMailer(true);

        // Act
        $this->flush($mailer);
        $byConfig = $this->statuses();
        $this->outboxConfig([]);
        $this->queueAged('also-old@example.com', 3 * 86400);
        $this->flush($mailer, ['--deadline' => 'never']);

        // Assert
        $this->assertSame(Mail::STATUS_SENT, $byConfig['three-days@example.com']);
        $this->assertSame(Mail::STATUS_SENT, $this->statuses()['also-old@example.com'], 'by the option');
    }

    /**
     * A row with no sender of its own is sent from `mail.outbox.from`; one with its own keeps it
     * (FW-200).
     */
    public function testARowWithNoSenderIsFromTheConfiguredOne(): void
    {
        // Arrange
        $this->outboxConfig(['from' => ['address' => 'admin@example.com', 'name' => 'The Team']]);
        $this->queueOne('to@example.com', 'From whom', '<p>Body</p>');
        $this->db->queryBuilder()->table('#PREFIX#mails')->update(['frommail' => '']);
        $mailer = new FlushSpyMailer(true);

        // Act
        $this->flush($mailer);

        // Assert
        $this->assertSame(['admin@example.com' => 'The Team'], $mailer->from);
    }

    /**
     * A message queued with names goes out with them: the names are kept in `fromname` /
     * `toname`, and the worker puts them back — it went out as a bare address (FW-202).
     */
    public function testAQueuedMessageKeepsItsNames(): void
    {
        // Arrange
        $email = $this->mail('', 'Named', '<p>Body</p>');
        $email->setTo(['person@example.com' => 'A Person']);
        $email->setFrom(['info@example.com' => 'The Shop']);
        $email->queue();
        $row    = $this->rows()[0];
        $mailer = new FlushSpyMailer(true);

        // Act
        $this->flush($mailer);

        // Assert
        $this->assertSame('A Person', $row['toname']);
        $this->assertSame('The Shop', $row['fromname']);
        $this->assertSame(['person@example.com' => 'A Person'], $mailer->to);
        $this->assertSame(['info@example.com' => 'The Shop'], $mailer->from);
    }

    /**
     * A message the server keeps refusing for now is not tried on every run: after its n-th
     * transient failure it waits `base · 2^(n−1)` seconds, and is tried again once that is past
     * (FW-202). Every run used to try it — 1,440 times a day.
     */
    public function testATransientFailureWaitsBeforeTheNextAttempt(): void
    {
        // Arrange
        $this->queueOne('busy@example.com', 'Busy', '<p>Body</p>');
        $mailer = new FlushSpyMailer(false, '451 4.3.0 try again later');

        // Act — two runs: the first fails for now, the second comes before its time
        $this->flush($mailer);
        $info    = json_decode((string) $this->rows()[0]['extrainfo'], true);
        $retryAt = (int) $info['retry_at'];
        $this->flush($mailer);
        $attemptsWhileWaiting = $mailer->attempts;
        // Its time comes
        $info['retry_at'] = time() - 1;
        $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', 'busy@example.com')->update(['extrainfo' => json_encode($info)]);
        $this->flush($mailer);

        // Assert
        $this->assertSame(1, $info['attempts']);
        $this->assertGreaterThan(time(), $retryAt + 1, 'no wait was set');
        $this->assertLessThanOrEqual(time() + 60, $retryAt, 'the first wait is the base, a minute');
        $this->assertIsArray($info['outbox'] ?? null, 'what it was queued with was lost');
        $this->assertSame(1, $attemptsWhileWaiting, 'tried again before its time');
        $this->assertSame(2, $mailer->attempts, 'not tried again once its time had come');
        $this->assertSame(2, json_decode((string) $this->rows()[0]['extrainfo'], true)['attempts']);
        $this->assertSame(Mail::STATUS_QUEUED, (int) $this->rows()[0]['status']);
    }

    /**
     * With `mail.outbox.backoff` false every run tries again, as it did; and a row whose
     * `extrainfo` is not the outbox's JSON keeps that text as a note.
     */
    public function testTheBackoffCanBeSwitchedOff(): void
    {
        // Arrange
        $this->outboxConfig(['backoff' => false]);
        $this->queueOne('busy@example.com', 'Busy', '<p>Body</p>');
        $mailer = new FlushSpyMailer(false, '451 try again later');

        // Act
        $this->flush($mailer);
        $this->flush($mailer);
        $this->outboxConfig(['backoff' => ['base' => 30, 'max' => 45]]);
        $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', 'busy@example.com')->update(['extrainfo' => 'an old note']);
        $this->flush($mailer);
        $info = json_decode((string) $this->rows()[0]['extrainfo'], true);

        // Assert
        $this->assertSame(3, $mailer->attempts, 'switched off, every run tries');
        $this->assertSame('an old note', $info['note']);
        $this->assertLessThanOrEqual(time() + 30, $info['retry_at'], 'the configured base');
    }

    /**
     * A dry run reports and changes nothing.
     *
     * Worth its own test because this command *sends*, and a `--dry-run` that sent anyway would
     * be discovered by somebody using it to inspect a backlog on a live installation.
     */
    public function testADryRunSendsNothingAndChangesNothing(): void
    {
        // Arrange
        $this->queueOne('dry@example.com', 'Untouched', '<p>Body</p>');

        // Act
        $mailer = new FlushSpyMailer(true);
        $tester = $this->flush($mailer, ['--dry-run' => true]);

        // Assert
        $this->assertSame(0, $mailer->attempts, 'a dry run sent the message');
        $this->assertSame(Mail::STATUS_QUEUED, (int) $this->rows()[0]['status']);
        $this->assertStringContainsString('would be sent', $tester->getDisplay());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** An Email tagged so this test can find and clean up its own rows. */
    protected function mail(string $to, string $subject, string $body): Email
    {
        $email = new Email();
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setBody($body);
        // No wrapper: this test is about the outbox, and a theme that cannot be found would
        // fail it for a reason that has nothing to do with what it asserts.
        $email->setTemplate('');
        $email->module = $this->tag;

        return $email;
    }

    protected function queueOne(string $to, string $subject, string $body): void
    {
        $this->mail($to, $subject, $body)->queue();
    }

    /** Run `mail:flush` with a mailer that opens no connection. */
    protected function flush(FlushSpyMailer $mailer, array $options = []): CommandTester
    {
        $command = new TestableMailFlush($mailer);
        $tester  = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    /** This test's rows only. @return list<array<string, mixed>> */
    protected function rows(): array
    {
        $result = $this->db->query(
            'SELECT * FROM ' . $this->db->prefix . 'mails '
            . "WHERE module = '" . $this->db->prepareInput($this->tag) . "' ORDER BY id ASC"
        );

        $rows = [];
        while ($result && ($row = $result->fetch()) !== null) {
            $rows[] = $row;
        }

        return $rows;
    }
}

/** An Email that records what it was asked to send and opens no connection. */
class FlushSpyMailer extends Email
{
    public int $attempts = 0;

    public function __construct(private bool $succeeds, private string $error = '')
    {
        parent::__construct();
    }

    public function sendRendered(): bool
    {
        $this->attempts++;

        return $this->succeeds;
    }

    public function getLastError()
    {
        return $this->error;
    }

    /** The moment it was sent as, which its links count from. */
    public function stamp(): ?int
    {
        return $this->sentStamp;
    }
}

/** `mail:flush` with an application's hooks: a blocklist before, a record after. */
class HookedMailFlush extends TestableMailFlush
{
    /** @var list<string> Recipients sent */
    public array $sent = [];

    /** @var array<string, string> Recipient => reason, failed for good */
    public array $failed = [];

    /** @param FlushSpyMailer $mailer The transport */
    public function __construct(private FlushSpyMailer $mailer)
    {
        parent::__construct($mailer);
    }

    /** How often the transport was asked. */
    public function spyAttempts(): int
    {
        return $this->mailer->attempts;
    }

    /** The blocklist. */
    protected function beforeSend(array $row, Email $email): ?string
    {
        return str_starts_with((string) $row['tomail'], 'blocked@') ? 'On the blocklist' : null;
    }

    /** Recorded. */
    protected function afterSent(array $row, Email $email): void
    {
        $this->sent[] = (string) $row['tomail'];
    }

    /** Recorded. */
    protected function afterFailed(array $row, string $error): void
    {
        $this->failed[(string) $row['tomail']] = $error;
    }
}

/** `mail:flush` with its transport replaced. */
class TestableMailFlush extends MailFlush
{
    public function __construct(private FlushSpyMailer $spy)
    {
        parent::__construct();
    }

    protected function mailer(): Email
    {
        return $this->spy;
    }
}

/** A transport that keeps each message as it would have gone on the wire. */
class OutboxCapturingTransport extends \Symfony\Component\Mailer\Transport\AbstractTransport
{
    /** @var list<string> */
    public array $sent = [];

    protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
    {
        $this->sent[] = $message->toString();
    }

    public function __toString(): string
    {
        return 'capture://';
    }
}

/** The worker's mailer, sending for real through a capturing transport. */
class FlushThroughTransportMailer extends FlushSpyMailer
{
    public function __construct(private OutboxCapturingTransport $capture)
    {
        parent::__construct(true);
    }

    public function sendRendered(): bool
    {
        $this->withTransport($this->capture);

        return Email::sendRendered();
    }

    public function getLastError()
    {
        return Email::getLastError();
    }
}
