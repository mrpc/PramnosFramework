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
#[CoversClass(\Pramnos\Messaging\Controllers\MassMessagesController::class)]
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
            // The dispatcher writes `bodypath`/`excerpt`, so the schema this runs against has to
            // be the one an installation actually has after migrating — without it every
            // recipient is recorded as failed and the reason is only in a log.
            \Pramnos\Framework\Migrations\Messaging\AddBodypathToMessages::class,
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
     * An account with no subscribed browser is a failure, not a delivery.
     *
     * The ordinary case: most accounts have never granted notification permission. Counted as
     * delivered, an operator reads "4,812 delivered" about a message that reached forty people
     * — and there is no other number on the screen that would contradict it.
     */
    public function testPushToAnAccountWithNoSubscriptionIsReportedAsFailed(): void
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
     * `only_ids` sends to the accounts somebody named, and to nobody else.
     *
     * The commonest thing anybody wants from this screen — «send this to these three people» —
     * and the one it could not do at all. The band, the language and the second-factor filters
     * are for describing a group you cannot enumerate; a list you can enumerate had no field.
     */
    public function testOnlyIdsNarrowsToTheAccountsNamed(): void
    {
        // Act
        $ids = (new MassMessageAudience($this->db))
            ->resolve(['only_ids' => [$this->users[0], $this->users[2]]]);

        // Assert
        $this->assertContains($this->users[0], $ids);
        $this->assertContains($this->users[2], $ids);
        $this->assertNotContains($this->users[1], $ids);
    }

    /**
     * And naming an account does not override the safety filters.
     *
     * `only_ids` is applied as a filter rather than instead of the rest, deliberately: an
     * operator pasting a list from a spreadsheet has not checked which of those accounts is
     * inactive or has an unvalidated address, and a screen that sent to them anyway would be
     * treating a paste as an override of every check on the page.
     */
    public function testNamingAnAccountDoesNotOverrideTheSafetyFilters(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[0])->update(['active' => 0]);

        // Act
        $ids = (new MassMessageAudience($this->db))
            ->resolve(['only_ids' => [$this->users[0], $this->users[1]]]);

        // Assert
        $this->assertNotContains($this->users[0], $ids, 'switched off is switched off');
        $this->assertContains($this->users[1], $ids);
    }

    /**
     * `exclude_ids` removes accounts from whatever the rest of the criteria matched.
     *
     * «Everybody except these two» — the person who has already been told, the account that
     * complained last time. Without it the only way to express it is a band that happens to
     * leave them out, which is a different audience that also leaves out people it should not.
     */
    public function testExcludeIdsRemovesAccountsFromTheAudience(): void
    {
        // Act
        $ids = (new MassMessageAudience($this->db))
            ->resolve(['exclude_ids' => [$this->users[1]]]);

        // Assert
        $this->assertContains($this->users[0], $ids);
        $this->assertNotContains($this->users[1], $ids);
        $this->assertContains($this->users[2], $ids);
    }

    /**
     * Ids arrive however somebody has them.
     *
     * From a spreadsheet they are newline-separated, from a chat message comma-separated, and
     * from a colleague with spaces between them. All three are the same intention, and refusing
     * two of them is a screen telling somebody their list is wrong when it is the screen that is.
     */
    public function testAPastedListOfIdsIsReadWhateverSeparatesIt(): void
    {
        // Act
        $ids = (new MassMessageAudience($this->db))->resolve([
            'only_ids' => $this->users[0] . ",\n " . $this->users[2],
        ]);

        // Assert
        $this->assertContains($this->users[0], $ids);
        $this->assertContains($this->users[2], $ids);
        $this->assertNotContains($this->users[1], $ids);
    }

    /**
     * A group filter naming groups with nobody in them is an empty audience, not everybody.
     *
     * The dangerous direction. A filter that cannot be satisfied resolving to "no filter" is how
     * a message meant for eleven volunteers goes to every account on the installation, and the
     * operator finds out from the replies.
     */
    public function testAGroupFilterThatMatchesNobodyIsNotEverybody(): void
    {
        // Act — a group id nothing is in
        $ids = (new MassMessageAudience($this->db))->resolve(['groups' => [987654]]);

        // Assert
        $this->assertSame([], $ids);
    }

    /**
     * An organizations filter behaves the same way, including where there is no such table.
     *
     * The membership table belongs to the authserver feature. An installation without it must
     * answer "nobody" rather than raising — and must not answer "everybody".
     */
    public function testAnOrganizationFilterThatMatchesNobodyIsNotEverybody(): void
    {
        // Act
        $ids = (new MassMessageAudience($this->db))->resolve(['organizations' => [987654]]);

        // Assert
        $this->assertSame([], $ids);
    }

    /**
     * A group filter reaches exactly the accounts in that group.
     */
    public function testAGroupFilterReachesTheAccountsInThatGroup(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#usergroups')
            ->insert(['name' => 'Volunteers ' . bin2hex(random_bytes(3)), 'description' => '']);
        $groupId = (int) $this->db->getInsertId();
        $this->db->queryBuilder()->table('#PREFIX#userstogroups')
            ->insert(['userid' => $this->users[1], 'groupid' => $groupId]);

        try {
            // Act
            $ids = (new MassMessageAudience($this->db))->resolve(['groups' => [$groupId]]);

            // Assert
            $this->assertSame([$this->users[1]], $ids);
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#userstogroups')
                ->where('groupid', $groupId)->delete();
            $this->db->queryBuilder()->table('#PREFIX#usergroups')
                ->where('groupid', $groupId)->delete();
        }
    }

    /**
     * An organizations filter reaches the accounts in that organization.
     *
     * The membership table belongs to the authserver feature, so it is built here rather than
     * assumed — an installation without it answers "nobody", which is asserted above, and the
     * reader that walks the rows had never been run at all.
     */
    public function testAnOrganizationFilterReachesItsMembers(): void
    {
        // Arrange
        $table = $this->db->schema()->resolveTableName(
            \Pramnos\Messaging\MassMessageAudience::organizationMembershipTable()
        );
        $column = \Pramnos\Messaging\MassMessageAudience::organizationColumn();

        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
            . '`user_id` bigint NOT NULL, `' . $column . '` bigint NOT NULL, '
            . '`is_active` tinyint NOT NULL DEFAULT 1, '
            . 'PRIMARY KEY (`user_id`, `' . $column . '`))'
        );
        $organizationId = random_int(700000, 799999);
        $this->db->queryBuilder()->table($table)->insert([
            'user_id'  => $this->users[1],
            $column    => $organizationId,
            'is_active' => 1,
        ]);

        try {
            // Act
            $ids = (new MassMessageAudience($this->db))
                ->resolve(['organizations' => [$organizationId]]);

            // Assert
            $this->assertSame([$this->users[1]], $ids);
        } finally {
            $this->db->queryBuilder()->table($table)
                ->where($column, $organizationId)->delete();
        }
    }

    /**
     * A group filter with members reaches them, and only them.
     *
     * The union, not the intersection: "members and volunteers" is a message to both, and an
     * operator who wants the overlap has a smaller audience they can name outright.
     */
    public function testAGroupFilterWithSeveralGroupsIsTheUnion(): void
    {
        // Arrange
        $ids = [];

        foreach (['Members', 'Volunteers'] as $index => $name) {
            $this->db->queryBuilder()->table('#PREFIX#usergroups')
                ->insert(['name' => $name . ' ' . bin2hex(random_bytes(3)), 'description' => '']);
            $groupId = (int) $this->db->getInsertId();
            $ids[] = $groupId;
            $this->db->queryBuilder()->table('#PREFIX#userstogroups')
                ->insert(['userid' => $this->users[$index], 'groupid' => $groupId]);
        }

        try {
            // Act
            $resolved = (new MassMessageAudience($this->db))->resolve(['groups' => $ids]);

            // Assert
            $this->assertContains($this->users[0], $resolved);
            $this->assertContains($this->users[1], $resolved);
            $this->assertNotContains($this->users[2], $resolved, 'and nobody else');
        } finally {
            foreach ($ids as $groupId) {
                $this->db->queryBuilder()->table('#PREFIX#userstogroups')
                    ->where('groupid', $groupId)->delete();
                $this->db->queryBuilder()->table('#PREFIX#usergroups')
                    ->where('groupid', $groupId)->delete();
            }
        }
    }

    /**
     * A preview whose sample cannot be read still reports the count.
     *
     * The count is what an operator decides on; a sample that cannot be rendered is a smaller
     * failure than a preview that refuses to appear. Forced by asking for a window of accounts
     * from a table that is not there.
     */
    public function testAPreviewWhoseSampleCannotBeReadStillCounts(): void
    {
        // Arrange
        $audience = new class ($this->db) extends MassMessageAudience {
            protected function sampleTable(): string
            {
                return '#PREFIX#no_such_table_here';
            }
        };

        // Act
        $preview = $audience->preview([], 5);

        // Assert
        $this->assertGreaterThan(0, $preview['total'], 'the count survived');
        $this->assertSame([], $preview['sample']);
        $this->assertSame($preview['total'], $preview['truncated']);
    }

    /**
     * The preview says how many, and shows enough of them to recognise the filter.
     *
     * The screen asked an operator to choose criteria and then pressed send. What those criteria
     * meant was visible only afterwards, in the recipient rows of a message that had already
     * gone out — and a send to the wrong band of accounts is not something anybody can take back.
     */
    public function testThePreviewSaysHowManyAndWhichOnes(): void
    {
        // Act
        $preview = (new MassMessageAudience($this->db))
            ->preview(['only_ids' => [$this->users[0], $this->users[1]]]);

        // Assert
        $this->assertSame(2, $preview['total']);
        $this->assertCount(2, $preview['sample']);
        $this->assertSame(0, $preview['truncated']);
        $this->assertSame($this->users[0], $preview['sample'][0]['userid']);
        $this->assertNotSame('', $preview['sample'][0]['email'],
            'the address is what an operator recognises an account by');
    }

    /**
     * The sample is a window, and says how much it is not showing.
     *
     * An audience of forty thousand is not a thing to render, and a list that silently stopped
     * at twenty-five would read as an audience of twenty-five.
     */
    public function testThePreviewSampleSaysHowManyItIsNotShowing(): void
    {
        // Act
        $preview = (new MassMessageAudience($this->db))->preview([], 1);

        // Assert
        $this->assertGreaterThanOrEqual(3, $preview['total']);
        $this->assertCount(1, $preview['sample']);
        $this->assertSame($preview['total'] - 1, $preview['truncated']);
    }

    /**
     * A preview of criteria nobody matches says nobody, rather than failing.
     */
    public function testThePreviewOfAnEmptyAudienceIsEmpty(): void
    {
        // Act
        $preview = (new MassMessageAudience($this->db))->preview(['only_ids' => [987654]]);

        // Assert
        $this->assertSame(['total' => 0, 'sample' => [], 'truncated' => 0], $preview);
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

    /**
     * A ceiling is the other end of the band.
     *
     * "Everybody below staff" is a real audience — a notice about something only members see —
     * and with a floor alone it can only be written as "everybody", which then also reaches the
     * operators it was not for.
     */
    public function testAUsertypeCeilingExcludesTheOperators(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[2])->update(['usertype' => 90]);

        // Act
        $ids = (new MassMessageAudience($this->db))->resolve(['usertype_max' => 50]);

        // Assert
        $this->assertNotContains($this->users[2], $ids, 'the administrator is above the ceiling');
        $this->assertContains($this->users[0], $ids);
    }

    /**
     * The language filter reads the account's language, not the operator's.
     *
     * A message written in Greek and sent to everybody also reaches the people who set their
     * account to English, and they cannot read it. One message per language, each to its own
     * audience, is the only honest way to do this — and it needs exactly this filter.
     */
    public function testTheLanguageFilterIsTheAccountsOwn(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[0])->update(['language' => 'el']);
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[1])->update(['language' => 'en']);

        // Act
        $greek = (new MassMessageAudience($this->db))->resolve(['language' => 'el']);

        // Assert
        $this->assertContains($this->users[0], $greek);
        $this->assertNotContains($this->users[1], $greek);
    }

    /**
     * The dormant audience includes accounts that never signed in at all.
     *
     * `lastlogin` is 0 for those, and 0 is before every cutoff. That is the correct answer to
     * the question being asked — never having signed in is the most dormant an account can be —
     * and it is worth pinning, because the opposite reading is equally plausible from the code.
     */
    public function testTheDormantAudienceIncludesAccountsThatNeverSignedIn(): void
    {
        // Arrange — one signed in recently, one long ago, one never
        $now = time();
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[0])->update(['lastlogin' => $now]);
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[1])->update(['lastlogin' => $now - 86400 * 400]);
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[2])->update(['lastlogin' => 0]);

        // Act
        $dormant = (new MassMessageAudience($this->db))->resolve([
            'last_login_before' => $now - 86400 * 30,
        ]);

        // Assert
        $this->assertNotContains($this->users[0], $dormant);
        $this->assertContains($this->users[1], $dormant);
        $this->assertContains($this->users[2], $dormant, 'never signed in is as dormant as it gets');

        // …and the other direction excludes both of them
        $active = (new MassMessageAudience($this->db))->resolve([
            'last_login_after' => $now - 86400 * 30,
        ]);
        $this->assertContains($this->users[0], $active);
        $this->assertNotContains($this->users[1], $active);
        $this->assertNotContains($this->users[2], $active);
    }

    /**
     * Excluding opt-outs is what makes the **count** honest.
     *
     * The dispatcher skips them at delivery either way, so this changes nothing about who
     * receives the message. It changes the number on the compose screen — and that number is
     * the one that decides whether the send happens at all, so a count including nine hundred
     * people who unsubscribed decides it in the wrong direction.
     */
    public function testExcludingOptOutsChangesTheCountNotTheSend(): void
    {
        // Arrange
        $this->runMigrations([\Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class]);

        $address = (string) (new User($this->users[1]))->email;

        $this->db->queryBuilder()->table('pramnos.emailoptouts')->insert([
            'email'    => $address,
            'list'       => 'massmessages',
            'source'     => 'page',
            'created_at' => time(),
        ]);

        try {
            // Act
            $everybody = (new MassMessageAudience($this->db))->resolve();
            $reachable = (new MassMessageAudience($this->db))
                ->resolve(['exclude_optouts' => 'massmessages']);

            // Assert
            $this->assertContains($this->users[1], $everybody,
                'without the filter the count still promises them');
            $this->assertNotContains($this->users[1], $reachable);
            $this->assertContains($this->users[0], $reachable);
        } finally {
            $this->db->queryBuilder()->table('pramnos.emailoptouts')
                ->where('email', $address)->delete();
        }
    }

    /**
     * An opt-out from `all` is an opt-out from every list.
     *
     * Somebody who pressed "stop sending me anything" is not asking to be kept on the
     * announcements list, and a filter that only matched the named list would keep them.
     */
    public function testOptingOutOfEverythingCountsForEveryList(): void
    {
        // Arrange
        $this->runMigrations([\Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class]);

        $address = (string) (new User($this->users[0]))->email;

        $this->db->queryBuilder()->table('pramnos.emailoptouts')->insert([
            'email'    => $address,
            'list'       => \Pramnos\Email\Unsubscribe::LIST_ALL,
            'source'     => 'page',
            'created_at' => time(),
        ]);

        try {
            // Act
            $ids = (new MassMessageAudience($this->db))->resolve(['exclude_optouts' => 'newsletter']);

            // Assert
            $this->assertNotContains($this->users[0], $ids);
        } finally {
            $this->db->queryBuilder()->table('pramnos.emailoptouts')
                ->where('email', $address)->delete();
        }
    }

    /**
     * An installation that cannot push at all records failures, not deliveries.
     *
     * No VAPID pair, or no encryption library. The channel logs and returns — `sendNow()` tells
     * its caller nothing — so without a check here every recipient of a message that was never
     * encrypted would be recorded as delivered. That is the one report this class must never
     * produce, and it is indistinguishable from a successful send on every screen.
     */
    public function testAnInstallationThatCannotPushRecordsFailures(): void
    {
        // Arrange — a subscription exists, so the only thing missing is the ability to send
        $this->createPushTable();

        \Pramnos\Push\Subscriptions::store($this->users[0], [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(6)),
            'keys'     => ['p256dh' => 'BExampleKey', 'auth' => 'ExampleSecret'],
        ]);

        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update(['type' => MassMessage::TYPE_PUSH]);

        $dispatcher = new MassMessageDispatcher($this->db);
        $dispatcher->queue($this->messageId, $this->users);

        try {
            // Act
            $stats = $dispatcher->dispatch(10);

            // Assert — this checkout has neither a key pair nor the library
            $this->assertSame(0, $stats['delivered'],
                'a message that was never encrypted has not been delivered to anybody');
            $this->assertSame(3, $stats['failed']);
        } finally {
            $this->db->queryBuilder()->table('pramnos.pushsubscriptions')
                ->where('userid', $this->users[0])->delete();
        }
    }

    /**
     * With a subscription and a working installation, a push is dispatched per recipient.
     *
     * The notifier is a seam so this can be asserted without a push service: what matters here
     * is that the dispatcher builds one notification per account, aimed at push, carrying the
     * campaign's tag — and reports delivered only for the accounts that had a browser.
     */
    public function testAPushIsDispatchedForEveryAccountWithASubscription(): void
    {
        // Arrange
        $this->createPushTable();

        $this->assertTrue(\Pramnos\Push\Subscriptions::store($this->users[0], [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(6)),
            'keys'     => ['p256dh' => 'BExampleKey', 'auth' => 'ExampleSecret'],
        ]), 'the fixture subscription has to be stored');
        $this->assertCount(1, \Pramnos\Push\Subscriptions::forUser($this->users[0]));

        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update([
                'type'    => MassMessage::TYPE_PUSH,
                'request' => json_encode(['options' => ['link' => 'https://example.com/notice']]),
            ]);

        $dispatcher = new WatchedPushDispatcher($this->db);
        $dispatcher->queue($this->messageId, $this->users);

        try {
            // Act
            $stats = $dispatcher->dispatch(10);

            // Assert
            $this->assertSame(1, $stats['delivered'], 'one account had a browser');
            $this->assertSame(2, $stats['failed'], 'and two did not — which is not a skip');

            $this->assertCount(1, $dispatcher->dispatched);
            $payload = $dispatcher->dispatched[0]->toPush(null);
            $this->assertSame('Scheduled maintenance', $payload['title']);
            $this->assertSame('https://example.com/notice', $payload['url']);
            $this->assertSame('massmessage-' . $this->messageId, $payload['tag'],
                'one tag per campaign, so a device that gets it twice shows it once');
            $this->assertSame(['push'], $dispatcher->dispatched[0]->via(null));
            $this->assertStringNotContainsString('<', $payload['body'],
                'a push shows markup as markup');
        } finally {
            $this->db->queryBuilder()->table('pramnos.pushsubscriptions')
                ->where('userid', $this->users[0])->delete();
        }
    }

    /**
     * A notifier that throws does not take the whole batch down with it.
     *
     * A run is thousands of recipients. One unreachable push service must cost that recipient,
     * not the message.
     */
    public function testAFailingPushIsOneFailedRecipient(): void
    {
        // Arrange
        $this->createPushTable();

        \Pramnos\Push\Subscriptions::store($this->users[0], [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(6)),
            'keys'     => ['p256dh' => 'BExampleKey', 'auth' => 'ExampleSecret'],
        ]);

        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update(['type' => MassMessage::TYPE_PUSH]);

        $dispatcher = new WatchedPushDispatcher($this->db);
        $dispatcher->throwOnSend = true;
        $dispatcher->queue($this->messageId, $this->users);

        try {
            // Act
            $stats = $dispatcher->dispatch(10);

            // Assert
            $this->assertSame(0, $stats['delivered']);
            $this->assertSame(3, $stats['failed']);
            $this->assertSame(
                MassMessage::STATUS_SENT,
                (int) $this->header()['status'],
                'and the message still finishes rather than staying "sending" for ever'
            );
        } finally {
            $this->db->queryBuilder()->table('pramnos.pushsubscriptions')
                ->where('userid', $this->users[0])->delete();
        }
    }

    /** The subscription table, from the real migration. */
    private function createPushTable(): void
    {
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Notifications\CreatePushSubscriptionsTable::class,
        ]);
    }

    /**
     * The send options ride in `request`, beside the criteria, and old rows still read.
     *
     * A column per option is a migration every time somebody adds one, and `request` is
     * already the audit record of what was asked for — the options are part of the same
     * decision. What matters is that a row written before they existed has no `options` key
     * and must not become unreadable.
     */
    public function testTheSendOptionsRideWithTheCriteria(): void
    {
        // Arrange
        $probe = new class ($this->db) extends MassMessageDispatcher {
            /** @param array<string, mixed> $message */
            public function probeOptions(array $message): array
            {
                return $this->optionsOf($message);
            }
        };

        // Assert
        $this->assertSame([], $probe->probeOptions(['request' => '{}']),
            'a row from before the options existed');
        $this->assertSame([], $probe->probeOptions([]), 'and a row with no request at all');
        $this->assertSame([], $probe->probeOptions(['request' => 'not json']));
        $this->assertSame(
            ['tracking' => true, 'list' => 'digest'],
            $probe->probeOptions([
                'request' => '{"usertype_min":0,"options":{"tracking":true,"list":"digest"}}',
            ])
        );
    }

    /**
     * A wrapper, tracking and an action are applied to each recipient's own message.
     *
     * Per recipient, not per campaign: one tracking id shared by forty thousand people counts
     * the first open and nothing else, and the numbers stop meaning anything at all.
     */
    public function testTheMailOptionsAreAppliedToEachMessage(): void
    {
        // Arrange
        $probe = new class ($this->db) extends MassMessageDispatcher {
            /** @param array<string, mixed> $options */
            public function probeApply(\Pramnos\Email\Email $mailer, array $options): void
            {
                $this->applyOptions($mailer, $options);
            }
        };

        $mailer = new class extends \Pramnos\Email\Email {
            public mixed $templateGiven = false;
            public int $trackingCalls = 0;
            public array $blocks = [];

            public function setTemplate(?string $template)
            {
                $this->templateGiven = $template;

                return $this;
            }

            public function enableTracking($trackingId = null)
            {
                $this->trackingCalls++;

                return $this;
            }

            public function addStructuredData(array $data)
            {
                $this->blocks[] = $data;

                return $this;
            }
        };

        // Act
        $probe->probeApply($mailer, [
            'template'    => '',
            'tracking'    => true,
            'action_type' => 'view',
            'action_name' => 'Read it',
            'action_url'  => 'https://example.com/notice',
        ]);

        // Assert
        $this->assertSame('', $mailer->templateGiven, 'no wrapper is a real answer, not "default"');
        $this->assertSame(1, $mailer->trackingCalls);
        $this->assertCount(1, $mailer->blocks);
    }

    /**
     * An action missing any of its three parts is not applied.
     *
     * Half an action is a button with no label, or a labelled button that goes nowhere — for
     * every reader of the campaign at once.
     */
    public function testAnIncompleteActionIsNotApplied(): void
    {
        // Arrange
        $probe = new class ($this->db) extends MassMessageDispatcher {
            /** @param array<string, mixed> $options */
            public function probeApply(\Pramnos\Email\Email $mailer, array $options): void
            {
                $this->applyOptions($mailer, $options);
            }
        };

        $mailer = new class extends \Pramnos\Email\Email {
            public array $blocks = [];

            public function addStructuredData(array $data)
            {
                $this->blocks[] = $data;

                return $this;
            }
        };

        // Act
        $probe->probeApply($mailer, ['action_type' => 'view', 'action_name' => 'Read it']);
        $probe->probeApply($mailer, [
            'action_type' => 'view',
            'action_name' => 'Read it',
            'action_url'  => 'not a url',
        ]);

        // Assert
        $this->assertSame([], $mailer->blocks);
    }

    /**
     * Nobody holds a second factor when there is no such table, and the filter says so.
     *
     * `authserver` is a feature an installation may not have. Asking for "accounts holding a
     * second factor" there must answer nobody rather than everybody — the second is a message
     * about security sent to the people who have none of it.
     */
    public function testTheSecondFactorFilterFailsClosed(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `authserver_user_twofactor`');

        try {
            // Act
            $holders = (new MassMessageAudience($this->db))->resolve(['twofactor' => 'with']);
            $without = (new MassMessageAudience($this->db))->resolve(['twofactor' => 'without']);

            // Assert
            $this->assertSame([], $holders, 'nobody holds one this installation cannot record');
            $this->assertContains($this->users[0], $without,
                'and everybody is therefore without one');
        } finally {
            // Put an empty one back. Four other classes in this suite declare this table in
            // their own fixtures and the order they run in is not this test's business —
            // leaving it dropped makes whichever of them runs next fail for a reason that has
            // nothing to do with what it is testing.
            $this->restoreTwoFactorTable();
        }
    }

    /** An empty `authserver.user_twofactor`, so dropping it does not leak into another class. */
    private function restoreTwoFactorTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `authserver_user_twofactor`');
        $this->db->query(
            'CREATE TABLE `authserver_user_twofactor` ('
            . '`userid` bigint NOT NULL, `enabled` tinyint NOT NULL DEFAULT 0, '
            . '`secret` varchar(64) NULL, `backup_codes` text NULL, '
            . '`last_used` int NOT NULL DEFAULT 0, `setup_completed_at` int NOT NULL DEFAULT 0, '
            . '`created_at` int NOT NULL DEFAULT 0, `updated_at` int NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (`userid`))'
        );
    }

    /**
     * The second-factor filter selects on the real table when there is one.
     *
     * Both directions, because they are two different queries and the `without` one has to
     * include everybody the `with` one left out — an account is in exactly one of them.
     */
    public function testTheSecondFactorFilterSelectsBothWays(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `authserver_user_twofactor`');
        $this->db->query(
            'CREATE TABLE `authserver_user_twofactor` ('
            . '`userid` bigint NOT NULL, `enabled` tinyint NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (`userid`))'
        );
        $this->db->queryBuilder()->table('authserver.user_twofactor')
            ->insert(['userid' => $this->users[0], 'enabled' => 1]);
        $this->db->queryBuilder()->table('authserver.user_twofactor')
            ->insert(['userid' => $this->users[1], 'enabled' => 0]);

        try {
            // Act
            $holders = (new MassMessageAudience($this->db))->resolve(['twofactor' => 'with']);
            $without = (new MassMessageAudience($this->db))->resolve(['twofactor' => 'without']);

            // Assert
            $this->assertContains($this->users[0], $holders);
            $this->assertNotContains($this->users[1], $holders,
                'a row with enabled = 0 is a record of a factor that was switched off');

            $this->assertNotContains($this->users[0], $without);
            $this->assertContains($this->users[1], $without);
            $this->assertContains($this->users[2], $without, 'and one with no row at all');
        } finally {
            $this->restoreTwoFactorTable();
        }
    }

    /**
     * With no opt-out table, the audience comes back unfiltered rather than empty.
     *
     * The safe failure *only* because the dispatcher checks each address again at delivery: the
     * count is optimistic, the send is not. Returning nobody would be the other kind of wrong —
     * a screen reporting an audience of zero for a list everybody is on.
     */
    public function testAMissingOptOutTableLeavesTheAudienceIntact(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->schema()->resolveTableName('pramnos.emailoptouts') . '`');

        try {
            // Act
            $ids = (new MassMessageAudience($this->db))->resolve(['exclude_optouts' => 'massmessages']);

            // Assert
            $this->assertContains($this->users[0], $ids);
        } finally {
            // As above: other classes read this table and did not ask for it to be gone.
            $this->runMigrations([\Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class]);
        }
    }

    /**
     * The languages offered on the compose screen are the ones accounts actually have.
     *
     * Reading them from the installation's catalogue instead would offer a language nobody has
     * set — an audience of nobody, and an operator composing a message before finding out.
     */
    public function testTheLanguageListComesFromTheAccounts(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[0])->update(['language' => 'el']);

        // Act
        $languages = (new class extends \Pramnos\Messaging\Controllers\MassMessagesController {
            public function __construct() {}

            /** @return list<string> */
            public function probeLanguages(): array
            {
                return $this->audienceLanguages();
            }
        })->probeLanguages();

        // Assert
        $this->assertContains('el', $languages);
        $this->assertNotContains('', $languages, 'an account with no language set is not a language');
    }

    /**
     * A campaign's mail carries the options the operator chose, per recipient.
     *
     * The delivery path end to end, with the mailer as a seam: a wrapper, tracking and an
     * unsubscribe list on this campaign's own list rather than the shared one.
     */
    public function testTheCampaignsMailCarriesItsOptions(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update([
                'type'    => MassMessage::TYPE_EMAIL,
                'request' => json_encode(['options' => [
                    'list'        => 'announcements',
                    'template'    => 'receipt',
                    'preheader'   => 'Ten minutes on Sunday morning',
                    'tracking'    => true,
                    'action_type' => 'confirm',
                    'action_name' => 'Confirm it',
                    'action_url'  => 'https://example.com/confirm',
                ]]),
            ]);

        $dispatcher = new WatchedMailDispatcher($this->db);
        $dispatcher->queue($this->messageId, [$this->users[0]]);

        // Act
        $stats = $dispatcher->dispatch(10);

        // Assert
        $this->assertSame(1, $stats['delivered']);
        $this->assertCount(1, $dispatcher->sent);

        $mailer = $dispatcher->sent[0];
        $this->assertSame('receipt', $mailer->templateGiven);
        $this->assertSame('Ten minutes on Sunday morning', $mailer->preheaderGiven);
        $this->assertSame(1, $mailer->trackingCalls, 'one id per recipient, not one per campaign');
        $this->assertSame('announcements', $mailer->list);
        $this->assertCount(1, $mailer->blocks);
        $this->assertSame('ConfirmAction', $mailer->blocks[0]['potentialAction']['@type'] ?? null);
    }

    /**
     * With no options stored, the campaign falls back to the shared unsubscribe list.
     *
     * One name for every mass mailing, because that is what a reader is unsubscribing from:
     * they pressed the button on an announcement and mean "no more announcements", not "no more
     * of announcement 4171".
     */
    public function testWithoutOptionsTheSharedListIsUsed(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update(['type' => MassMessage::TYPE_EMAIL, 'request' => '{}']);

        $dispatcher = new WatchedMailDispatcher($this->db);
        $dispatcher->queue($this->messageId, [$this->users[0]]);

        // Act
        $dispatcher->dispatch(10);

        // Assert
        $this->assertSame(
            MassMessageDispatcher::UNSUBSCRIBE_LIST,
            $dispatcher->sent[0]->list
        );
        $this->assertSame(0, $dispatcher->sent[0]->trackingCalls);
        $this->assertNull($dispatcher->sent[0]->preheaderGiven,
            'without one the body supplies it, which an empty string would prevent');
        $this->assertFalse($dispatcher->sent[0]->templateGiven, 'no wrapper choice was made');
    }

    /**
     * Somebody who unsubscribed is recorded as delivered, and not mailed.
     *
     * Delivered, because the recipient row is a record of what happened to a person and "we
     * honoured their request" is not a failure to retry on the next run — a failed row is
     * picked up again, and this one would be retried for ever.
     */
    public function testAnUnsubscribedRecipientIsHonouredAndCountedDelivered(): void
    {
        // Arrange
        $this->runMigrations([\Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class]);

        $address = (string) (new User($this->users[0]))->email;
        $this->db->queryBuilder()->table('pramnos.emailoptouts')->insert([
            'email'      => $address,
            'list'       => MassMessageDispatcher::UNSUBSCRIBE_LIST,
            'source'     => 'page',
            'created_at' => time(),
        ]);

        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update(['type' => MassMessage::TYPE_EMAIL, 'request' => '{}']);

        $dispatcher = new WatchedMailDispatcher($this->db);
        $dispatcher->queue($this->messageId, [$this->users[0]]);

        try {
            // Act
            $stats = $dispatcher->dispatch(10);

            // Assert
            $this->assertSame(1, $stats['delivered']);
            $this->assertSame([], $dispatcher->sent, 'and nothing was composed');
        } finally {
            $this->db->queryBuilder()->table('pramnos.emailoptouts')
                ->where('email', $address)->delete();
        }
    }

    /**
     * An account whose row went away is a failure, not a crash.
     */
    public function testARecipientWhoNoLongerExistsIsAFailure(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $this->messageId)
            ->update(['type' => MassMessage::TYPE_EMAIL, 'request' => '{}']);

        $dispatcher = new WatchedMailDispatcher($this->db);
        $dispatcher->queue($this->messageId, [$this->users[0]]);
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->users[0])->delete();

        // Act
        $stats = $dispatcher->dispatch(10);

        // Assert
        $this->assertSame(1, $stats['failed']);
        $this->assertSame([], $dispatcher->sent);
    }

    /**
     * The two seams hand back the real collaborators when nothing overrides them.
     *
     * Every other test here substitutes a double, so without this the production path could
     * return anything at all and the suite would stay green.
     */
    public function testTheSeamsReturnTheRealCollaborators(): void
    {
        // Arrange
        $probe = new class ($this->db) extends MassMessageDispatcher {
            public function probeNotifier(): object { return $this->notifier(); }
            public function probeMailer(): object { return $this->mailer(); }
            public function probeCanPush(): bool { return $this->canPush(); }
        };

        // Assert
        $this->assertInstanceOf(\Pramnos\Notification\Notifier::class, $probe->probeNotifier());
        $this->assertInstanceOf(\Pramnos\Email\Email::class, $probe->probeMailer());
        $this->assertFalse($probe->probeCanPush(),
            'this checkout has neither a key pair nor the encryption library');
    }

    /**
     * A `save` action is a `SaveAction`, not the `ViewAction` everything else falls back to.
     */
    public function testEachActionTypeBuildsItsOwnBlock(): void
    {
        // Arrange
        $probe = new class ($this->db) extends MassMessageDispatcher {
            /** @param array<string, mixed> $options */
            public function probeApply(\Pramnos\Email\Email $mailer, array $options): void
            {
                $this->applyOptions($mailer, $options);
            }
        };

        $mailer = new class extends \Pramnos\Email\Email {
            public array $blocks = [];

            public function addStructuredData(array $data)
            {
                $this->blocks[] = $data;

                return $this;
            }
        };

        // Act
        foreach (['view', 'save', 'confirm', 'something else'] as $type) {
            $probe->probeApply($mailer, [
                'action_type' => $type,
                'action_name' => 'Do it',
                'action_url'  => 'https://example.com/x',
            ]);
        }

        // Assert
        $types = array_map(
            static fn (array $block): string => (string) ($block['potentialAction']['@type'] ?? ''),
            $mailer->blocks
        );
        $this->assertSame(
            ['ViewAction', 'SaveAction', 'ConfirmAction', 'ViewAction'],
            $types,
            'an unrecognised type falls back to View, which is the one that cannot misfire'
        );
    }

    /**
     * With an empty opt-out table the audience is returned as it was.
     */
    public function testAnEmptyOptOutTableChangesNothing(): void
    {
        // Arrange
        $this->runMigrations([\Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class]);
        $this->db->queryBuilder()->table('pramnos.emailoptouts')->where('optoutid', '>', 0)->delete();

        // Act
        $ids = (new MassMessageAudience($this->db))->resolve(['exclude_optouts' => 'massmessages']);

        // Assert
        $this->assertContains($this->users[0], $ids);
    }

    /**
     * The criteria read back as a sentence, for the screen and for the audit record.
     *
     * Stored criteria are JSON. The only place anybody ever reads what a send was aimed at is
     * this sentence, so a filter that is applied and not described is a filter nobody knows
     * was there.
     */
    public function testEveryFilterAppearsInTheDescription(): void
    {
        // Act
        $description = MassMessageAudience::describe([
            'usertype_min'      => 10,
            'usertype_max'      => 50,
            'language'          => 'el',
            'twofactor'         => 'without',
            'last_login_before' => 1767225600,
            'exclude_optouts'   => 'massmessages',
        ]);

        // Assert
        $this->assertStringContainsString('10 to 50', $description);
        $this->assertStringContainsString('el', $description);
        $this->assertStringContainsString('without a second factor', $description);
        $this->assertStringContainsString('not signed in since', $description);
        $this->assertStringContainsString('unsubscribed', $description);

        // …and each end of the usertype band on its own reads as the band it is
        $this->assertStringContainsString(
            '90 and above',
            MassMessageAudience::describe(['usertype_min' => 90])
        );
        $this->assertStringContainsString(
            '50 and below',
            MassMessageAudience::describe(['usertype_max' => 50])
        );
        $this->assertStringContainsString(
            'every account',
            MassMessageAudience::describe([])
        );
        $this->assertStringContainsString(
            'holding a second factor',
            MassMessageAudience::describe(['twofactor' => 'with'])
        );
        $this->assertStringContainsString(
            'last signed in after',
            MassMessageAudience::describe(['last_login_after' => 1767225600])
        );
    }
}

/**
 * A dispatcher that can push, and records what it dispatched instead of sending it.
 *
 * This checkout has neither a VAPID pair nor the encryption library — which is the correct
 * state for a framework and makes the delivery path unreachable. The two seams are how it is
 * reached without either.
 */
class WatchedPushDispatcher extends MassMessageDispatcher
{
    public bool $throwOnSend = false;

    /** @var list<\Pramnos\Notification\Message> */
    public array $dispatched = [];

    protected function canPush(): bool
    {
        return true;
    }

    protected function notifier(): \Pramnos\Notification\Notifier
    {
        $watcher = $this;

        return new class ($watcher) extends \Pramnos\Notification\Notifier {
            public function __construct(private WatchedPushDispatcher $watcher)
            {
                // No parent::__construct(): `Notifier` declares none, and calling one that
                // does not exist is a fatal the dispatcher's own catch would swallow — which
                // is exactly how this double first reported "nothing was delivered".
            }

            public function sendNow(mixed $notifiable, \Pramnos\Notification\NotificationInterface $notification): void
            {
                if ($this->watcher->throwOnSend) {
                    throw new \RuntimeException('the push service could not be reached');
                }

                $this->watcher->dispatched[] = $notification;
            }
        };
    }
}

/** A dispatcher whose mailer records what a campaign composed instead of sending it. */
class WatchedMailDispatcher extends MassMessageDispatcher
{
    /** @var list<object> */
    public array $sent = [];

    protected function mailer(): \Pramnos\Email\Email
    {
        $mailer = new class extends \Pramnos\Email\Email {
            public mixed $templateGiven = false;
            public int $trackingCalls = 0;
            public string $list = '';
            public array $blocks = [];
            public ?string $preheaderGiven = null;

            public function preheader($text)
            {
                $this->preheaderGiven = (string) $text;

                return $this;
            }

            public function setTemplate(?string $template)
            {
                $this->templateGiven = $template;

                return $this;
            }

            public function enableTracking($trackingId = null)
            {
                $this->trackingCalls++;

                return $this;
            }

            public function addStructuredData(array $data)
            {
                $this->blocks[] = $data;

                return $this;
            }

            public function offerUnsubscribe(string $list, string $email = '')
            {
                $this->list = $list;

                return $this;
            }

            public function send()
            {
                return true;
            }
        };

        $this->sent[] = $mailer;

        return $mailer;
    }
}
