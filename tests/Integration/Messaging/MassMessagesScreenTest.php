<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Http\Request;
use Pramnos\Messaging\Controllers\MassMessagesController;
use Pramnos\Messaging\MassMessage;

/**
 * Composing, queueing and refusing a message to everybody.
 *
 * 156 of 283 statements never executed, on the screen with the least recoverable action in the
 * framework: everything else here can be corrected, and this one reaches every person on the
 * list. So what is asserted is mostly the refusals.
 *
 * The subtle half is `criteriaFrom()`, where each decision **silently selects a different set of
 * people**. Two of them are documented in the code as traps and both are pinned here:
 *
 *   - the two booleans are written *after* `array_filter`, because their `false` is meaningful —
 *     "include unvalidated accounts" — and the filter drops it, restoring the default;
 *   - `template => ''` is a real answer ("no wrapper for this campaign"), so it is stored,
 *     while `__default__` means the caller said nothing.
 *
 * Runs on every backend: {@see MassMessagesScreenPostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB.
 */
#[CoversClass(MassMessagesController::class)]
class MassMessagesScreenTest extends BaseTestCase
{
    private $db;

    /** @var list<int> */
    private array $created = [];

    /** @var list<int> accounts this test created, removed in tearDown */
    private array $users = [];

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMassmessagesTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMassmessagerecepientsTable::class,
        ], $this->db);
        \Pramnos\User\User::setupDb();

        $_POST   = [];
        $_GET    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Request::resetInstance();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            foreach (['#PREFIX#massmessagerecipients', '#PREFIX#massmessages'] as $table) {
                try {
                    // `messageid` in both tables. This said `massid` for the recipients, so the
                    // delete threw, was swallowed, and the rows stayed — a cleanup that looked
                    // exactly like a cleanup with nothing to do.
                    $this->db->queryBuilder()->table($table)->where('messageid', $id)->delete();
                } catch (\Throwable $exception) {
                    // Nothing to undo.
                }
            }
        }
        $this->created = [];

        foreach ($this->users as $userId) {
            foreach (['#PREFIX#userdetails', '#PREFIX#users'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $userId)->delete();
                } catch (\Throwable $exception) {
                    // Nothing to undo.
                }
            }
        }
        $this->users = [];
        \Pramnos\User\User::clearUserCache();

        $_POST   = [];
        $_GET    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Request::resetInstance();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The criteria, where a wrong default mails the wrong people ────────────

    /**
     * A false boolean survives the filter that drops empty values.
     *
     * The trap the code names: `array_filter` cannot tell "the operator unticked this" from
     * "the operator said nothing", and dropping it restores the default — which for
     * `validated_only` means quietly *excluding* accounts the operator chose to include. The
     * count on the screen would then disagree with the send.
     */
    public function testAnUntickedBooleanIsStoredAsFalseRatherThanDropped(): void
    {
        // Arrange
        $_POST = ['validated_only' => 0, 'active_only' => 0];
        Request::resetInstance();

        // Act
        $criteria = $this->probe()->probeCriteria(new Request());

        // Assert
        $this->assertArrayHasKey('validated_only', $criteria);
        $this->assertArrayHasKey('active_only', $criteria);
        $this->assertFalse($criteria['validated_only']);
        $this->assertFalse($criteria['active_only']);
    }

    /** And a ticked one is true. */
    public function testATickedBooleanIsTrue(): void
    {
        // Arrange
        $_POST = ['validated_only' => 1, 'active_only' => 1];
        Request::resetInstance();

        // Act
        $criteria = $this->probe()->probeCriteria(new Request());

        // Assert
        $this->assertTrue($criteria['validated_only']);
        $this->assertTrue($criteria['active_only']);
    }

    /** Empty and zero filters are left out, so the audience is not narrowed by a blank field. */
    public function testBlankFiltersAreNotCriteria(): void
    {
        // Arrange
        $_POST = ['usertype_min' => 0, 'language' => '', 'twofactor' => ''];
        Request::resetInstance();

        // Act
        $criteria = $this->probe()->probeCriteria(new Request());

        // Assert
        $this->assertArrayNotHasKey('usertype_min', $criteria);
        $this->assertArrayNotHasKey('language', $criteria);
        $this->assertArrayNotHasKey('twofactor', $criteria);
    }

    /**
     * Ids arrive as an array from a multi-select or as a pasted string from a spreadsheet.
     *
     * Both, because an operator pasting a column of ids is the normal way a named list gets
     * into this form, and a screen that only accepted the multi-select would send them back to
     * ticking four hundred boxes.
     */
    public function testIdsAreAcceptedAsAnArrayOrAsPastedText(): void
    {
        // Arrange
        $_POST = ['only_ids' => [7, '9'], 'exclude_ids' => "11, 12\n13"];
        Request::resetInstance();

        // Act
        $criteria = $this->probe()->probeCriteria(new Request());

        // Assert
        $this->assertSame([7, 9], $criteria['only_ids']);
        $this->assertSame([11, 12, 13], $criteria['exclude_ids']);
    }

    /** A list that names nothing is not stored, so it cannot narrow the audience to nobody. */
    public function testAnEmptyIdListIsNotStored(): void
    {
        // Arrange
        $_POST = ['only_ids' => [], 'groups' => '', 'organizations' => '   '];
        Request::resetInstance();

        // Act
        $criteria = $this->probe()->probeCriteria(new Request());

        // Assert
        $this->assertArrayNotHasKey('only_ids', $criteria);
        $this->assertArrayNotHasKey('groups', $criteria);
        $this->assertArrayNotHasKey('organizations', $criteria);
    }

    // ── The options ───────────────────────────────────────────────────────────

    /**
     * An empty template is a decision; `__default__` is silence.
     *
     * "No wrapper for this campaign" is a thing an operator chooses, and it looks exactly like
     * an empty form field. The sentinel is what tells them apart.
     */
    public function testAnEmptyTemplateIsStoredAndTheSentinelIsNot(): void
    {
        // Arrange
        $_POST = ['template' => ''];
        Request::resetInstance();
        $chosen = $this->probe()->probeOptions(new Request());

        $_POST = ['template' => '__default__'];
        Request::resetInstance();
        $silent = $this->probe()->probeOptions(new Request());

        // Assert
        $this->assertArrayHasKey('template', $chosen);
        $this->assertSame('', $chosen['template']);
        $this->assertArrayNotHasKey('template', $silent);
    }

    /** Everything else empty is dropped, and tracking off is not an option worth recording. */
    public function testEmptyOptionsAreDropped(): void
    {
        // Arrange
        $_POST = ['link' => '', 'preheader' => '  ', 'tracking' => 0, 'action_url' => ''];
        Request::resetInstance();

        // Act
        $options = $this->probe()->probeOptions(new Request());

        // Assert
        $this->assertArrayNotHasKey('link', $options);
        $this->assertArrayNotHasKey('preheader', $options);
        $this->assertArrayNotHasKey('tracking', $options);
        $this->assertArrayNotHasKey('action_url', $options);
    }

    /**
     * The audit record carries no `options` key when nothing was chosen.
     *
     * An empty object in the record reads as a decision somebody made, and this record is what
     * anybody asking "who was this aimed at, and how" will read months later.
     */
    public function testTheAuditRecordOmitsOptionsWhenThereAreNone(): void
    {
        // Act
        $bare = json_decode($this->probe()->probeRequestJson(['language' => 'el'], []), true);
        $with = json_decode(
            $this->probe()->probeRequestJson(['language' => 'el'], ['tracking' => true]),
            true
        );

        // Assert
        $this->assertSame(['language' => 'el'], $bare);
        $this->assertSame(['language' => 'el', 'options' => ['tracking' => true]], $with);
    }

    /**
     * A row written before options existed reads as none, and unreadable JSON does too.
     *
     * `request` is a text column. An older row, or one written by something else, is not a
     * reason to fail rendering the screen — the screen is how somebody finds out what happened.
     */
    public function testTheStoredCriteriaAreReadDefensively(): void
    {
        // Arrange
        $probe   = $this->probe();
        $message = new MassMessage($probe);

        // Act & Assert
        $message->request = '';
        $this->assertIsArray($probe->probeCriteriaOf($message));

        $message->request = 'not json at all';
        $this->assertIsArray($probe->probeCriteriaOf($message), 'garbage must not break the screen');

        $message->request = '{"language":"el"}';
        $this->assertSame('el', $probe->probeCriteriaOf($message)['language'] ?? null);
    }

    // ── Saving ────────────────────────────────────────────────────────────────

    /** A composed message is stored pending, with nothing sent. */
    public function testAComposedMessageIsSavedPending(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = [
            'messageid' => 0,
            'subject'   => 'Scheduled maintenance',
            'message'   => '<p>We will be down for <b>ten minutes</b>.</p>',
            'type'      => (string) MassMessage::TYPE_EMAIL,
            'language'  => 'el',
        ];
        Request::resetInstance();

        // Act
        $controller->save();

        // Assert
        $row = $this->rowBySubject('Scheduled maintenance');
        $this->assertNotNull($row, 'nothing was saved');
        $this->created[] = (int) $row['messageid'];

        $this->assertSame(MassMessage::STATUS_PENDING, (int) $row['status']);
        $this->assertSame(0, (int) $row['totalrecipients']);
        $this->assertStringContainsString('<b>ten minutes</b>', (string) $row['message'],
            'the body is markup, and a mass message that cannot contain markup is useless');
        /*
         * Decoded, not matched as a string.
         *
         * On MySQL `request` is a JSON column and the server **reformats** the document on the
         * way back — `{"language": "el", …}`, with spaces and its own key order — while on
         * PostgreSQL it is text and comes back byte for byte. Asserting the serialisation would
         * be asserting the server's formatter, and would pass on exactly one backend.
         */
        $stored = json_decode((string) $row['request'], true);
        $this->assertSame('el', $stored['language'] ?? null);
        $this->assertFalse($stored['validated_only'] ?? null, 'the booleans reached the record');
        $this->assertSame(['Message saved. Nothing has been sent yet.'], $controller->messages);
    }

    /** A future date makes it scheduled rather than pending. */
    public function testAScheduledMessageIsMarkedScheduled(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = [
            'messageid' => 0,
            'subject'   => 'Later',
            'scheduled' => date('Y-m-d H:i:s', time() + 3600),
        ];
        Request::resetInstance();

        // Act
        $controller->save();

        // Assert
        $row = $this->rowBySubject('Later');
        $this->assertNotNull($row);
        $this->created[] = (int) $row['messageid'];
        $this->assertSame(MassMessage::STATUS_SCHEDULED, (int) $row['status']);
        $this->assertGreaterThan(time(), (int) $row['scheduled']);
    }

    /** A message with no subject is refused rather than saved unnamed. */
    public function testASubjectIsRequired(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = ['messageid' => 0, 'subject' => '   ', 'message' => 'body'];
        Request::resetInstance();

        // Act
        $controller->save();

        // Assert
        $this->assertSame(['A message needs a subject.'], $controller->errors);
        $this->assertNull($this->rowBySubject(''));
    }

    /**
     * A sent message cannot be edited.
     *
     * It is a record of what people received. Editing it would change the record without
     * changing what anybody got — which makes the record a lie rather than out of date.
     */
    public function testASentMessageCannotBeEdited(): void
    {
        // Arrange
        $id         = $this->seed('Already out', MassMessage::STATUS_SENT);
        $controller = $this->controller();
        $_POST      = ['messageid' => $id, 'subject' => 'Rewritten history'];
        Request::resetInstance();

        // Act
        $controller->save();

        // Assert
        $this->assertSame(['That message has been sent; it cannot be edited.'], $controller->errors);
        $this->assertSame('Already out', (string) ($this->rowById($id)['subject'] ?? ''));
    }

    // ── Queueing ──────────────────────────────────────────────────────────────

    /**
     * A GET cannot queue a message, token or not.
     *
     * A GET that mails everybody is one link prefetch — a browser, a chat client unfurling a
     * URL, a crawler with a session — away from happening by itself.
     */
    public function testAGetCannotQueueAnything(): void
    {
        // Arrange
        $id         = $this->seed('Not by GET', MassMessage::STATUS_PENDING);
        $controller = $this->controller();
        $this->route($id);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Request::resetInstance();

        // Act
        $controller->send($id);

        // Assert
        $this->assertStringContainsString('could not be verified', $controller->errors[0] ?? '');
        $this->assertSame(0, $this->recipientCount($id));
    }

    /** And a POST without the token cannot either. */
    public function testAPostWithoutTheTokenCannotQueueAnything(): void
    {
        // Arrange
        $id         = $this->seed('Not without a token', MassMessage::STATUS_PENDING);
        $controller = $this->controller();
        $this->route($id);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['whatever' => '1'];
        Request::resetInstance();

        // Act
        $controller->send($id);

        // Assert
        $this->assertStringContainsString('could not be verified', $controller->errors[0] ?? '');
        $this->assertSame(0, $this->recipientCount($id));
    }

    /** Criteria matching nobody queue nobody, and say so. */
    public function testCriteriaMatchingNobodyQueueNothing(): void
    {
        // Arrange — an id nobody has.
        $id = $this->seed('Nobody', MassMessage::STATUS_PENDING, '{"only_ids":[987654321]}');
        $controller = $this->controller();
        $this->postWithToken($id);

        // Act
        $controller->send($id);

        // Assert
        $this->assertStringContainsString('match nobody', $controller->errors[0] ?? '');
        $this->assertSame(0, $this->recipientCount($id));
    }

    /** A message that is not there is refused before anything is resolved. */
    public function testAMissingMessageCannotBeQueued(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->postWithToken(987655);

        // Act
        $controller->send(987655);

        // Assert
        $this->assertSame(['That message no longer exists.'], $controller->errors);
    }

    /**
     * A real audience is queued once, and the count is what the operator is told.
     *
     * The success path, which had never run — every other test here stops at a refusal. The
     * number matters more than it looks: it is the only figure the operator has before the
     * schedule starts delivering, and a message saying "queued" with no count leaves them
     * refreshing the view screen to find out whether it matched four people or four thousand.
     *
     * Delivery is **not** inline. Queueing writes the recipient rows and returns; the schedule
     * sends. A screen that mailed thousands of people inside a request would time out somewhere
     * in the middle, and nothing would say where.
     */
    public function testARealAudienceIsQueuedAndCounted(): void
    {
        // Arrange — two accounts, and a message naming exactly them.
        $first  = $this->seedUser();
        $second = $this->seedUser();
        $id     = $this->seed(
            'Real send',
            MassMessage::STATUS_PENDING,
            json_encode(['only_ids' => [$first, $second]])
        );

        $controller = $this->controller();
        $this->postWithToken($id);

        // Act
        $controller->send($id);

        // Assert
        $this->assertSame([], $controller->errors, 'the send was refused: ' . json_encode($controller->errors));
        $this->assertSame(2, $this->recipientCount($id), 'the recipient rows were not written');
        $this->assertStringContainsString('2 recipient(s) queued', $controller->messages[0] ?? '');
        $this->assertStringContainsString(
            'schedule',
            $controller->messages[0] ?? '',
            'the operator is not told that delivery happens later'
        );
    }

    /**
     * Pressing send twice does not queue it twice.
     *
     * The least recoverable action on the screen with the least recoverable action. A double
     * press, a double-submitted form, a browser retrying a request it thinks failed — any of them
     * would reach every person on the list a second time, and there is no undo for a mail that
     * has been delivered.
     *
     * The refusal names the reason rather than reporting success, because an operator told
     * "queued" twice has no way to know whether the second press did anything, and the way to
     * find out is to wait and see what people receive.
     */
    public function testSendingTwiceDoesNotQueueTwice(): void
    {
        // Arrange
        $recipient = $this->seedUser();
        $id        = $this->seed(
            'Sent once',
            MassMessage::STATUS_PENDING,
            json_encode(['only_ids' => [$recipient]])
        );

        $first = $this->controller();
        $this->postWithToken($id);
        $first->send($id);
        $this->assertSame(1, $this->recipientCount($id), 'precondition: the first send queued');

        // Act — the same message again.
        $second = $this->controller();
        $this->postWithToken($id);
        $second->send($id);

        // Assert
        $this->assertSame(
            1,
            $this->recipientCount($id),
            'the second send queued the whole list again'
        );
        $this->assertStringContainsString('already has recipients', $second->errors[0] ?? '');
        $this->assertSame([], $second->messages, 'a refused second send reported success');
    }

    /**
     * Naming an account explicitly does not get past the safety filters.
     *
     * `only_ids` is applied as a filter rather than instead of the rest, and this is the reason:
     * "send this to these three people" must not become a way to mail an account somebody
     * switched off, or one whose address was never validated. A send to an unvalidated address is
     * a bounce, and bounces are counted against the domain that produced them.
     */
    public function testNamingAnUnvalidatedAccountStillDoesNotSendToIt(): void
    {
        // Arrange
        $unvalidated = $this->seedUser(validated: false);
        $id          = $this->seed(
            'Named but unvalidated',
            MassMessage::STATUS_PENDING,
            json_encode(['only_ids' => [$unvalidated]])
        );

        $controller = $this->controller();
        $this->postWithToken($id);

        // Act
        $controller->send($id);

        // Assert
        $this->assertSame(0, $this->recipientCount($id), 'an unvalidated account was queued');
        $this->assertStringContainsString('match nobody', $controller->errors[0] ?? '');
    }

    // ── The screens ───────────────────────────────────────────────────────────

    /**
     * The list screen hands the view its rows and the pickers beside them.
     *
     * The pickers are the reason this is worth a test rather than a glance: the audience is
     * chosen from them, so a screen that rendered with an empty group list would offer "everybody"
     * as the only option a person could pick.
     */
    public function testTheListScreenCarriesItsRowsAndItsPickers(): void
    {
        // Arrange
        $id = $this->seed('On the list', MassMessage::STATUS_PENDING);
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $this->assertNotNull($controller->view, 'nothing was rendered');
        $rows     = (array) ($controller->view->messages ?? []);
        $subjects = array_column($rows, 'subject');
        $this->assertContains('On the list', $subjects, 'the message is not on its own list screen');
        $this->assertIsArray($controller->view->types ?? null, 'the type labels are missing');

        // The progress is read per row, so a list with a row has one.
        $mine = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) ($row['messageid'] ?? 0) === $id
        ));
        $this->assertIsArray($mine[0]['progress'] ?? null, 'the row carries no progress');
    }

    /**
     * The view screen carries the message, its criteria and how far delivery has got.
     *
     * The screen an operator watches after pressing send, so the progress figures are the
     * feature: without them the answer to "is it going out?" is to ask the people receiving it.
     */
    public function testTheViewScreenCarriesTheMessageAndItsProgress(): void
    {
        // Arrange
        $recipient = $this->seedUser();
        $id        = $this->seed(
            'Being watched',
            MassMessage::STATUS_PENDING,
            json_encode(['only_ids' => [$recipient]])
        );

        $sender = $this->controller();
        $this->postWithToken($id);
        $sender->send($id);

        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->view($id);

        // Assert
        $this->assertNotNull($controller->view, 'nothing was rendered');
        $this->assertSame(
            'Being watched',
            (string) (((array) ($controller->view->message ?? []))['subject'] ?? ''),
            'the view screen does not show the message it was asked for'
        );
        $this->assertIsArray($controller->view->progress ?? null, 'no delivery progress');
        $this->assertSame(
            1,
            (int) (((array) ($controller->view->progress ?? []))['total'] ?? 0),
            'the progress does not count the recipient that was queued'
        );
        $this->assertNotSame(
            '',
            (string) ($controller->view->audience ?? ''),
            'the screen does not say who the message was for'
        );
    }

    /** The compose screen for an existing draft is filled in from it. */
    public function testTheEditScreenIsFilledInFromTheDraft(): void
    {
        // Arrange
        $id = $this->seed('A draft', MassMessage::STATUS_PENDING, '{"validated_only":true}');
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->edit($id);

        // Assert
        $this->assertNotNull($controller->view, 'nothing was rendered');
        $this->assertSame(
            'A draft',
            (string) (((array) ($controller->view->message ?? []))['subject'] ?? ''),
            'the compose screen opened empty over an existing draft'
        );
        $this->assertSame(
            true,
            ((array) ($controller->view->criteria ?? []))['validated_only'] ?? null,
            'the stored criteria were not put back on the form'
        );
        $this->assertIsArray($controller->view->groups ?? null, 'the group picker is missing');
        $this->assertIsArray($controller->view->languages ?? null, 'the language picker is missing');
        $this->assertIsArray(
            $controller->view->preview ?? null,
            'the compose screen does not say who the criteria currently mean'
        );
    }

    /**
     * A screen asked for a message that is not there says so and goes back to the list.
     *
     * A link from an old ticket, or a message somebody else deleted. A blank compose form would
     * be the worst answer: the operator fills it in and saves a *new* message, believing they
     * edited one.
     */
    public function testAScreenAskedForAMissingMessageGoesBack(): void
    {
        // Act & Assert
        foreach (['view', 'edit'] as $action) {
            $controller = $this->controller();
            $this->route(987656);

            $controller->$action(987656);

            $this->assertNull($controller->view, $action . ' rendered a screen for nothing');
            $this->assertSame(['That message no longer exists.'], $controller->errors);
            $this->assertNotSame([], $controller->redirects);
        }
    }

    // ── Deleting ──────────────────────────────────────────────────────────────

    /** A message that was never sent can be deleted. */
    public function testAnUnsentMessageCanBeDeleted(): void
    {
        // Arrange
        $id         = $this->seed('Draft', MassMessage::STATUS_PENDING);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->delete($id);

        // Assert
        $this->assertNull($this->rowById($id));
        $this->assertSame(['Message deleted.'], $controller->messages);
    }

    /**
     * A sent one stays, because it is the record of what people received.
     *
     * The only copy of "four thousand people were told this, on that date". Deleting it leaves
     * the recipient rows pointing at nothing and nobody able to answer what was sent.
     */
    public function testASentMessageIsKept(): void
    {
        // Arrange
        $id         = $this->seed('History', MassMessage::STATUS_SENT);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->delete($id);

        // Assert
        $this->assertNotNull($this->rowById($id));
        $this->assertStringContainsString('it stays', $controller->errors[0] ?? '');
    }

    // ── The gate ──────────────────────────────────────────────────────────────

    /**
     * Below usertype 90 nothing renders, nothing is written and nothing is queued.
     *
     * This screen mails everybody. The floor is higher than the other admin screens on purpose,
     * and a gate that only skipped the render would leave `send` reachable.
     */
    public function testEveryActionStopsBelowTheFloor(): void
    {
        // Arrange
        $id         = $this->seed('Gated', MassMessage::STATUS_PENDING);
        $controller = $this->controller(refused: true);
        $this->postWithToken($id);
        $_POST['messageid'] = $id;
        $_POST['subject']   = 'Changed';
        Request::resetInstance();

        // Act
        $controller->display();
        $controller->view($id);
        $controller->edit($id);
        $controller->save();
        $controller->send($id);
        $controller->delete($id);

        // Assert
        $this->assertNull($controller->view);
        $this->assertSame([], $controller->messages);
        $this->assertSame(0, $this->recipientCount($id), 'the gate queued a send');
        $this->assertSame('Gated', (string) ($this->rowById($id)['subject'] ?? ''));
    }

    /** The floor is 90, and every action is auth-registered. */
    public function testTheFloorAndTheRegistrationAreDeclared(): void
    {
        // Arrange
        $controller = new MassMessagesController(null);
        $reflection = new \ReflectionClass(MassMessagesController::class);

        // Assert
        $this->assertGreaterThanOrEqual(
            90,
            $reflection->getProperty('requiredUserType')->getValue($controller)
        );
        $registered = $reflection->getProperty('actions_auth')->getValue($controller);
        foreach (['display', 'view', 'edit', 'preview', 'save', 'send', 'delete'] as $action) {
            $this->assertContains($action, $registered, $action . ' is not auth-protected');
        }
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** The controller with the gate, the redirect and the view replaced. */
    private function controller(bool $refused = false): object
    {
        return new class ($refused, $this->db) extends MassMessagesController {
            public ?object $view = null;

            public array $errors = [];

            public array $messages = [];

            public array $redirects = [];

            public function __construct(private bool $refused, \Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database        = $db;
                $this->application    = $app;
                $this->controllerName = 'MassMessages';
            }

            protected function requireMinUserType($type): bool
            {
                return $this->refused;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $this->view = new class ($name) {
                    public array $assigned = [];

                    public string $layout = '';

                    public function __construct(public string $name)
                    {
                    }

                    public function __set($key, $value)
                    {
                        $this->assigned[$key] = $value;
                    }

                    public function __get($key)
                    {
                        return $this->assigned[$key] ?? null;
                    }

                    public function display($layout = '')
                    {
                        $this->layout = (string) $layout;

                        return 'rendered';
                    }
                };

                return $this->view;
            }
        };
    }

    /** The protected helpers, reachable. They are where the audience is decided. */
    private function probe(): object
    {
        return new class ($this->db) extends MassMessagesController {
            public function __construct(\Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            public function probeCriteria(Request $request): array
            {
                return $this->criteriaFrom($request);
            }

            public function probeOptions(Request $request): array
            {
                return $this->optionsFrom($request);
            }

            public function probeRequestJson(array $criteria, array $options): string
            {
                return $this->requestJson($criteria, $options);
            }

            public function probeCriteriaOf(MassMessage $message): array
            {
                return $this->criteriaOf($message);
            }
        };
    }

    private function route(int $id): void
    {
        $_GET['_option'] = (string) $id;
        Request::resetInstance();
    }

    /** A POST carrying the anti-CSRF token the form would have carried. */
    private function postWithToken(int $id): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        $this->route($id);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST[$session->getToken()] = $session->getFingerprint();
        Request::resetInstance();
    }

    /**
     * One real account, because an audience is resolved against `users`.
     *
     * `only_ids` is checked against the table rather than trusted, which is what makes a send to
     * a stale id queue nothing rather than a row pointing at nobody.
     */
    private function seedUser(bool $validated = true): int
    {
        $user = new \Pramnos\User\User();
        $user->username = 'massmsg_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->active    = 1;
        $user->validated = $validated ? 1 : 0;
        $user->save();

        $id = (int) $user->userid;
        $this->assertGreaterThan(0, $id, 'the fixture account was not created');
        $this->users[] = $id;

        return $id;
    }

    /** One mass message row. Returns its id. */
    private function seed(string $subject, int $status, string $request = '{}'): int
    {
        $this->db->queryBuilder()->table('#PREFIX#massmessages')->insert([
            'subject'         => $subject,
            'message'         => 'Body of ' . $subject,
            'type'            => MassMessage::TYPE_EMAIL,
            'status'          => $status,
            'created'         => time(),
            'scheduled'       => 0,
            'totalrecipients' => 0,
            'request'         => $request,
        ]);

        $row = $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('subject', $subject)->orderBy('messageid', 'desc')->first();

        $id = (int) ($row->fields['messageid'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the fixture message was not created');
        $this->created[] = $id;

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function rowById(int $id): ?array
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('messageid', $id)->first();

        return ($row === null || ($row->numRows ?? 0) === 0) ? null : (array) $row->fields;
    }

    /** @return array<string, mixed>|null */
    private function rowBySubject(string $subject): ?array
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#massmessages')
            ->where('subject', $subject)->orderBy('messageid', 'desc')->first();

        return ($row === null || ($row->numRows ?? 0) === 0) ? null : (array) $row->fields;
    }

    /**
     * How many recipients are queued for this message.
     *
     * `messageid`, and **no `try`**. It was `massid` inside a `catch` that returned 0 — a column
     * that does not exist, so every call threw and every call answered "none queued". Three
     * assertions of the form `assertSame(0, $this->recipientCount($id))` were therefore passing
     * against a query error rather than against an empty table, including the one that checks a
     * refused send queued nothing and the one that checks the usertype floor stops a send. A
     * fixture that swallows a query error asserts nothing, quietly, for as long as nobody writes
     * the positive case that would have caught it.
     */
    private function recipientCount(int $id): int
    {
        return (int) $this->db->queryBuilder()->table('#PREFIX#massmessagerecipients')
            ->where('messageid', $id)->count();
    }
}
