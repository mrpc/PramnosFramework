<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Messaging\MassMessage;
use Pramnos\Messaging\MassMessageAudience;
use Pramnos\Messaging\MassMessageDispatcher;
use Pramnos\Messaging\MassMessageRecipient;
use Pramnos\User\User;

/**
 * A mass message becomes deliveries, against a real store.
 *
 * The behaviour that matters is all about interruption and repetition, so the assertions
 * are too:
 *
 * - **A batch is a batch.** `dispatch(2)` attempts two recipients and leaves the rest
 *   pending, because a send of thousands cannot be one request and must survive being cut
 *   in half.
 * - **Nothing is sent twice.** Every recipient is marked as it is attempted, and a message
 *   that already has recipients refuses to be queued again — that is the one mistake here
 *   that reaches every person on the list rather than a log.
 * - **A message finishes.** When nothing is pending the header is `sent`, including when
 *   every delivery failed: the status answers "is this still going" and the counts answer
 *   "did it work", and conflating them leaves a message that can never end.
 *
 * Deliveries are internal messages rather than email, so no mail server is involved and
 * what is asserted is the row in the account's own inbox.
 */
#[CoversClass(MassMessageDispatcher::class)]
#[CoversClass(MassMessageAudience::class)]
class MassMessageDispatcherTest extends BaseTestCase
{
    private $db;

    /** @var array<int, int> */
    private array $users = [];

    private int $messageId = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = \Pramnos\Framework\Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('MassMessageDispatcherTest runs on MySQL only.');
        }

        User::setupDb();
        $this->buildTables();

        // Three accounts, so a batch of two leaves one behind — which is the whole point
        // of a batch and cannot be asserted with two.
        foreach (range(1, 3) as $index) {
            $user = new User();
            $user->username  = 'massmsg_' . $index . '_' . bin2hex(random_bytes(4));
            $user->email     = $user->username . '@example.com';
            $user->validated = 1;
            $user->active    = 1;
            $user->save();
            $this->users[] = (int) $user->userid;
        }

        $this->messageId = $this->makeMessage();
    }

    protected function tearDown(): void
    {
        foreach (['#PREFIX#massmessagerecipients', '#PREFIX#massmessages'] as $table) {
            try {
                $this->db->queryBuilder()->table($table)->where('messageid', $this->messageId)->delete();
            } catch (\Throwable) {
                // Nothing to undo.
            }
        }

        foreach ($this->users as $userId) {
            foreach (['#PREFIX#messages', '#PREFIX#users'] as $table) {
                try {
                    $column = $table === '#PREFIX#users' ? 'userid' : 'touserid';
                    $this->db->queryBuilder()->table($table)->where($column, $userId)->delete();
                } catch (\Throwable) {
                    // As above.
                }
            }
        }

        parent::tearDown();
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /** From the real migrations, so a test cannot pass against a schema nobody ships. */
    private function buildTables(): void
    {
        $prefix = $this->db->prefix;

        foreach (['massmessagerecipients', 'massmessages', 'messages'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
        }

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMessagesTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMassmessagesTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMassmessagerecepientsTable::class,
        ]);
    }

    /**
     * An internal-message broadcast, pending, due now.
     *
     * Written with the query builder rather than the model: `Model::__construct()` takes a
     * `Controller`, and standing one up here would put a request's worth of machinery
     * behind a fixture row. The dispatcher reads the table, not the model.
     */
    private function makeMessage(): int
    {
        $this->db->queryBuilder()->table('#PREFIX#massmessages')->insert([
            'subject'         => 'Scheduled maintenance',
            'message'         => '<p>We will be down for ten minutes.</p>',
            'type'            => MassMessage::TYPE_MESSAGE,
            'status'          => MassMessage::STATUS_PENDING,
            'created'         => time(),
            'scheduled'       => 0,
            'totalrecipients' => 0,
            'request'         => '{}',
        ]);

        $result = $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->orderBy('messageid', 'desc')->limit(1)->get();

        return (int) (((array) ($result?->fetch() ?? []))['messageid'] ?? 0);
    }

    /**
     * The message header as an array.
     *
     * `QueryBuilder::first()` returns a `Result`, not a row — it is `get()` with a limit —
     * so every read of one column goes through here rather than through a subscript that
     * looks right and is not.
     *
     * @return array<string, mixed>
     */
    private function header(): array
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)->get();

        return (array) ($result?->fetch() ?? []);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * Queueing writes one row per account and records the total.
     */
    public function testQueueingWritesOneRowPerRecipient(): void
    {
        // Act
        $queued = (new MassMessageDispatcher($this->db))->queue($this->messageId, $this->users);

        // Assert
        $this->assertSame(3, $queued);
        $this->assertSame(
            3,
            (int) $this->header()['totalrecipients']
        );
    }

    /**
     * A duplicate in the audience is one person told twice.
     *
     * An audience is assembled from a query somebody wrote, and a join that fans out is the
     * usual way the same account appears in it more than once.
     */
    public function testADuplicateInTheAudienceIsQueuedOnce(): void
    {
        // Act
        $queued = (new MassMessageDispatcher($this->db))->queue(
            $this->messageId,
            [$this->users[0], $this->users[0], $this->users[1]]
        );

        // Assert
        $this->assertSame(2, $queued);
    }

    /**
     * Queueing twice is refused.
     *
     * The one mistake on this screen that reaches every person on the list.
     */
    public function testAMessageThatAlreadyHasRecipientsIsNotQueuedAgain(): void
    {
        // Arrange
        $dispatcher = new MassMessageDispatcher($this->db);
        $dispatcher->queue($this->messageId, $this->users);

        // Act
        $second = $dispatcher->queue($this->messageId, $this->users);

        // Assert
        $this->assertSame(0, $second);
        $this->assertSame(3, $dispatcher->progress($this->messageId)['total']);
    }

    /**
     * A batch delivers as many as it was asked for and leaves the rest pending.
     */
    public function testABatchLeavesTheRestPending(): void
    {
        // Arrange
        $dispatcher = new MassMessageDispatcher($this->db);
        $dispatcher->queue($this->messageId, $this->users);

        // Act
        $stats = $dispatcher->dispatch(2);

        // Assert
        $this->assertSame(2, $stats['attempted']);
        $this->assertSame(2, $stats['delivered']);

        $progress = $dispatcher->progress($this->messageId);
        $this->assertSame(2, $progress['delivered']);
        $this->assertSame(1, $progress['pending']);

        // …and the header is not "sent" while somebody is still waiting for it
        $this->assertSame(
            MassMessage::STATUS_PENDING,
            (int) $this->header()['status']
        );
    }

    /**
     * The next run finishes it, and the message is marked sent.
     */
    public function testTheNextRunFinishesTheMessage(): void
    {
        // Arrange
        $dispatcher = new MassMessageDispatcher($this->db);
        $dispatcher->queue($this->messageId, $this->users);
        $dispatcher->dispatch(2);

        // Act
        $stats = $dispatcher->dispatch(10);

        // Assert
        $this->assertSame(1, $stats['attempted'], 'only what was still pending');
        $this->assertSame(0, $dispatcher->progress($this->messageId)['pending']);
        $this->assertSame(
            MassMessage::STATUS_SENT,
            (int) $this->header()['status']
        );
    }

    /**
     * An internal message arrives in the account's own inbox.
     *
     * Which is what "delivered" means for this type — the status column says a row was
     * written, and this is the row.
     */
    public function testAnInternalMessageLandsInTheInbox(): void
    {
        // Arrange
        $dispatcher = new MassMessageDispatcher($this->db);
        $dispatcher->queue($this->messageId, [$this->users[0]]);

        // Act
        $dispatcher->dispatch(10);

        // Assert
        $result = $this->db->queryBuilder()->table('#PREFIX#messages')
            ->where('touserid', $this->users[0])->limit(1)->get();
        $row = (array) ($result?->fetch() ?? []);

        $this->assertNotEmpty($row);
        $this->assertSame('Scheduled maintenance', (string) $row['subject']);
        $this->assertSame($this->messageId, (int) $row['massid'],
            'the inbox row has to point back at the send it came from');
    }

    /**
     * A message scheduled for later is not delivered yet.
     */
    public function testAScheduledMessageWaits(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update([
                'status'    => MassMessage::STATUS_SCHEDULED,
                'scheduled' => time() + 3600,
            ]);

        $dispatcher = new MassMessageDispatcher($this->db);
        $dispatcher->queue($this->messageId, $this->users);

        // Act
        $stats = $dispatcher->dispatch(10);

        // Assert
        $this->assertSame(0, $stats['attempted']);
        $this->assertSame(3, $dispatcher->progress($this->messageId)['pending']);
    }

    /**
     * Push is refused rather than silently skipped.
     *
     * The framework has no push transport. An operator who chose it is owed "there is no
     * transport for this", not a message that reports itself sent to nobody.
     */
    public function testPushIsReportedAsFailedRatherThanSent(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update(['type' => MassMessage::TYPE_PUSH]);

        $dispatcher = new MassMessageDispatcher($this->db);
        $dispatcher->queue($this->messageId, $this->users);

        // Act
        $stats = $dispatcher->dispatch(10);

        // Assert
        $this->assertSame(3, $stats['failed']);
        $this->assertSame(0, $stats['delivered']);

        // …and it still finishes, because a message nobody can deliver must not stay
        // "sending" for ever
        $this->assertSame(
            MassMessage::STATUS_SENT,
            (int) $this->header()['status']
        );
        $this->assertSame(3, $dispatcher->progress($this->messageId)['failed']);
    }

    /**
     * The audience is the accounts that can actually receive something.
     *
     * Validated and active are on by default: a validated address is the difference between
     * a send and a bounce, and an inactive account is one somebody switched off — mailing it
     * is the opposite of what that switch meant.
     */
    public function testTheAudienceExcludesUnvalidatedAndInactiveAccounts(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[0])->update(['validated' => 0]);
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[1])->update(['active' => 0]);

        // Act
        $ids = (new MassMessageAudience($this->db))->resolve();

        // Assert
        $this->assertNotContains($this->users[0], $ids, 'an unvalidated address is a bounce');
        $this->assertNotContains($this->users[1], $ids, 'an inactive account was switched off');
        $this->assertContains($this->users[2], $ids);
    }

    /**
     * And the usertype floor is a threshold, like everywhere else.
     */
    public function testTheUsertypeFloorIsAThreshold(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[2])->update(['usertype' => 90]);

        // Act
        $ids = (new MassMessageAudience($this->db))->resolve(['usertype_min' => 90]);

        // Assert
        $this->assertContains($this->users[2], $ids);
        $this->assertNotContains($this->users[0], $ids);
    }
}
