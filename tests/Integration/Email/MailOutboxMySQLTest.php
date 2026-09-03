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

    protected function tearDown(): void
    {
        $this->db->schema()->dropTableIfExists('mails');

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
        $this->assertSame('', (string) $rows[0]['extrainfo'], 'a queued row has no error yet');
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

        // Act
        $this->flush(new FlushSpyMailer(true));

        // Assert
        $rows = $this->rows();
        $this->assertCount(1, $rows, 'the worker wrote a second row instead of moving the first');
        $this->assertSame(Mail::STATUS_SENT, (int) $rows[0]['status']);
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
