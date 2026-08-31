<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Messaging\Controllers\MessagesController;
use Pramnos\Messaging\Message;

/**
 * `/messages` — the reader at the other end of a dead end.
 *
 * 102 of 106 statements never executed, on the screen that ends the failure it was written for:
 * `MassMessageDispatcher` wrote a row per recipient, `countUnread()` counted them, the progress
 * screen reported every recipient delivered — and nobody could read a word of it. "Delivered"
 * meant written to a table nothing looked at.
 *
 * Worth being precise about why that survived: every part of the machinery worked. Only the
 * reader was missing, and no test can notice a reader that was never written. Which is exactly
 * why the reader itself needs tests that *run* it rather than describe it.
 *
 * Two things carry the security of this screen, and both are asserted:
 *
 *   - **Ownership is a `where`, not a comparison after loading.** A message id is a small
 *     integer in a URL; "forbidden" and "not found" being different answers is a way to count
 *     how many messages exist.
 *   - **The inbox names the states it wants** rather than excluding the ones it does not. `type`
 *     is overloaded — inbox, sent, archived, deleted — and a `NOT IN` list would show a state
 *     added next year to everybody.
 *
 * Runs on every backend: {@see MessagesInboxPostgreSQLTest} re-runs all of it against
 * PostgreSQL/TimescaleDB through `settingsFixture()`.
 */
#[CoversClass(MessagesController::class)]
class MessagesInboxTest extends BaseTestCase
{
    private $db;

    private const UID = 5100;

    private const OTHER_UID = 5101;

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
            \Pramnos\Framework\Migrations\Messaging\CreateMessagesTable::class,
        ], $this->db);

        $this->clear();

        $_GET = [];
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $this->clear();

        $_GET = [];
        \Pramnos\Http\Request::resetInstance();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The listing ───────────────────────────────────────────────────────────

    /**
     * The account's own messages, newest first, with unread marked.
     */
    public function testTheListingShowsThisAccountsMessagesNewestFirst(): void
    {
        // Arrange
        $this->seed(self::UID, 'Older', Message::TYPE_READ, time() - 3600);
        $this->seed(self::UID, 'Newer', Message::TYPE_NEW, time());
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $messages = $controller->view->messages;
        $this->assertCount(2, $messages);
        $this->assertSame('Newer', $messages[0]['subject'], 'not newest first');
        $this->assertTrue($messages[0]['isUnread']);
        $this->assertFalse($messages[1]['isUnread']);
        $this->assertSame(1, $controller->view->unread);
    }

    /**
     * Somebody else's messages are not in this inbox.
     *
     * The one assertion that would matter most if it failed, and the cheapest to get wrong:
     * `touserid` is a parameter, not a filter applied afterwards.
     */
    public function testAnotherAccountsMessagesAreNotListed(): void
    {
        // Arrange
        $this->seed(self::UID, 'Mine', Message::TYPE_NEW);
        $this->seed(self::OTHER_UID, 'Theirs', Message::TYPE_NEW);
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $subjects = array_column($controller->view->messages, 'subject');
        $this->assertSame(['Mine'], $subjects);
    }

    /**
     * Sent, archived and deleted rows stay out — and a state nobody has invented yet stays out
     * too.
     *
     * The reason the listing names its states instead of excluding others: `type` is one column
     * doing four jobs, and a `NOT IN (deleted, sent)` would put next year's state in everybody's
     * inbox with nothing said.
     */
    public function testOnlyInboxStatesAreListed(): void
    {
        // Arrange
        $this->seed(self::UID, 'Inbox', Message::TYPE_NEW);
        $this->seed(self::UID, 'Sent', Message::TYPE_SENT);
        $this->seed(self::UID, 'Archived', Message::TYPE_INBOX_ARCHIVE);
        $this->seed(self::UID, 'Deleted', Message::TYPE_DELETED);
        $this->seed(self::UID, 'Invented later', 42);
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $this->assertSame(['Inbox'], array_column($controller->view->messages, 'subject'));
    }

    /** An empty inbox is an empty list and a zero badge, not an error. */
    public function testAnEmptyInboxIsEmpty(): void
    {
        // Arrange
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $this->assertSame([], $controller->view->messages);
        $this->assertSame(0, $controller->view->unread);
    }

    /** A visitor who is not signed in is sent to sign in, and nothing is read. */
    public function testAnAnonymousVisitorIsSentToSignIn(): void
    {
        // Arrange
        $this->seed(self::UID, 'Private', Message::TYPE_NEW);
        $controller = $this->controller(userId: 0);

        // Act
        $list = $controller->display();
        $one  = $controller->show(1);
        $all  = $controller->readall();

        // Assert
        $this->assertNull($list);
        $this->assertNull($one);
        $this->assertNull($all);
        $this->assertNull($controller->view, 'a screen was rendered for a visitor');
        $this->assertCount(3, $controller->redirects);
    }

    // ── One message ───────────────────────────────────────────────────────────

    /**
     * Opening a message is what marks it read.
     */
    public function testOpeningAMessageMarksItRead(): void
    {
        // Arrange
        $id         = $this->seed(self::UID, 'Unread one', Message::TYPE_NEW);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->show($id);

        // Assert
        $this->assertSame('show', $controller->view->layout);
        $this->assertSame('Unread one', $controller->view->message['subject']);
        $this->assertSame(Message::TYPE_READ, $this->typeOf($id));
    }

    /**
     * A notification stays a notification once read.
     *
     * The pair of states exists so a screen can tell "something we told you" from "something
     * somebody sent you". Collapsing both into `TYPE_READ` would lose that on first read — and
     * lose it silently, which is the kind of thing nobody notices until a filter is wrong.
     */
    public function testAReadNotificationKeepsItsOwnState(): void
    {
        // Arrange
        $id         = $this->seed(self::UID, 'A notification', Message::TYPE_NOTIFICATION_NEW);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->show($id);

        // Assert
        $this->assertSame(Message::TYPE_NOTIFICATION_READ, $this->typeOf($id));
    }

    /** A message that was already read is not touched. */
    public function testAnAlreadyReadMessageIsLeftAlone(): void
    {
        // Arrange
        $id         = $this->seed(self::UID, 'Read already', Message::TYPE_MARKED_READ);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->show($id);

        // Assert
        $this->assertSame(
            Message::TYPE_MARKED_READ,
            $this->typeOf($id),
            'a state that was not unread was rewritten'
        );
    }

    /**
     * Somebody else's message answers "not in your inbox", and is not marked read.
     *
     * Not "forbidden": the two answers being distinguishable is a way to count how many
     * messages exist. And the row must be untouched — a read receipt for a message the account
     * never saw would be worse than the disclosure.
     */
    public function testAnotherAccountsMessageIsNotReadable(): void
    {
        // Arrange
        $id         = $this->seed(self::OTHER_UID, 'Theirs', Message::TYPE_NEW);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $result = $controller->show($id);

        // Assert
        $this->assertNull($result);
        $this->assertNull($controller->view);
        $this->assertCount(1, $controller->errors);
        $this->assertSame(Message::TYPE_NEW, $this->typeOf($id), 'their message was marked read');
    }

    /** An id that is not a message, and no id at all. */
    public function testANonExistentMessageIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->route(999999);

        // Act
        $controller->show(999999);
        $controller->show(0);

        // Assert
        $this->assertCount(2, $controller->errors);
        $this->assertNull($controller->view);
    }

    /**
     * A deleted message is not readable by its own id either.
     *
     * The listing hides it, and a URL somebody kept must not still open it — otherwise "delete"
     * means "remove from the list".
     */
    public function testADeletedMessageIsNotReadableByItsId(): void
    {
        // Arrange
        $id         = $this->seed(self::UID, 'Deleted', Message::TYPE_DELETED);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->show($id);

        // Assert
        $this->assertNull($controller->view);
        $this->assertSame(Message::TYPE_DELETED, $this->typeOf($id));
    }

    // ── Mark all read ─────────────────────────────────────────────────────────

    /**
     * "Mark all read" moves the unread ones and nothing else.
     *
     * Including, deliberately, nobody else's: the update carries `touserid`.
     */
    public function testReadAllMovesOnlyThisAccountsUnread(): void
    {
        // Arrange
        $new      = $this->seed(self::UID, 'New', Message::TYPE_NEW);
        $unread   = $this->seed(self::UID, 'Unread', Message::TYPE_UNREAD);
        $archived = $this->seed(self::UID, 'Archived', Message::TYPE_INBOX_ARCHIVE);
        $theirs   = $this->seed(self::OTHER_UID, 'Theirs', Message::TYPE_NEW);
        $controller = $this->controller();

        // Act
        $controller->readall();

        // Assert
        $this->assertSame(Message::TYPE_READ, $this->typeOf($new));
        $this->assertSame(Message::TYPE_READ, $this->typeOf($unread));
        $this->assertSame(Message::TYPE_INBOX_ARCHIVE, $this->typeOf($archived), 'an archive was touched');
        $this->assertSame(Message::TYPE_NEW, $this->typeOf($theirs), 'somebody else was marked read');
        $this->assertSame(0, MessagesController::unreadCount(self::UID));
    }

    // ── The badge ─────────────────────────────────────────────────────────────

    /**
     * The unread count is what the navigation asks on every page.
     *
     * Zero for an account with nothing, zero for an impossible account, and zero rather than an
     * exception when it cannot tell — a badge is not worth breaking an unrelated screen over.
     */
    public function testTheUnreadCountCountsTheUnreadStates(): void
    {
        // Arrange
        $this->seed(self::UID, 'New', Message::TYPE_NEW);
        $this->seed(self::UID, 'Unread', Message::TYPE_UNREAD);
        $this->seed(self::UID, 'Notification', Message::TYPE_NOTIFICATION_NEW);
        $this->seed(self::UID, 'Read', Message::TYPE_READ);
        $this->seed(self::UID, 'Archived', Message::TYPE_INBOX_ARCHIVE);

        // Assert
        $this->assertSame(3, MessagesController::unreadCount(self::UID));
        $this->assertSame(0, MessagesController::unreadCount(self::OTHER_UID));
        $this->assertSame(0, MessagesController::unreadCount(0));
        $this->assertSame(0, MessagesController::unreadCount(-1));
    }

    /** With no `messages` table at all, the badge is zero rather than a 500 on every page. */
    public function testTheBadgeIsZeroWhenItCannotTell(): void
    {
        // Arrange
        $this->dropMessages();

        // Assert
        $this->assertSame(0, MessagesController::unreadCount(self::UID));

        // Cleanup — the table belongs to every other test in this class.
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMessagesTable::class,
        ], $this->db);
    }

    /**
     * And the listing says so rather than rendering an empty inbox.
     *
     * An empty list and a broken table look identical to a reader, and the difference matters:
     * one means "nothing was sent to you", the other means "we cannot tell".
     */
    public function testAnUnreadableTableIsReportedRatherThanShownAsEmpty(): void
    {
        // Arrange
        $this->dropMessages();
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $this->assertSame([], $controller->view->messages);
        $this->assertNotSame([], $controller->errors, 'an unreadable inbox rendered as an empty one');

        // Cleanup
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMessagesTable::class,
        ], $this->db);
    }

    /** The three actions are auth-registered. */
    public function testTheActionsAreAuthProtected(): void
    {
        // Arrange
        $controller  = new MessagesController(null);
        $reflection  = new \ReflectionClass(MessagesController::class);
        $registered  = $reflection->getProperty('actions_auth')->getValue($controller);

        // Assert
        foreach (['display', 'show', 'readall'] as $action) {
            $this->assertContains($action, $registered, $action . ' is not auth-protected');
        }
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the signed-in account, the redirect and the view replaced.
     *
     * `currentUserId()` and `database()` are protected on the class itself — the seams were
     * already there, which is why this needs no production change.
     */
    private function controller(int $userId = self::UID): object
    {
        return new class ($userId, $this->db) extends MessagesController {
            public ?object $view = null;

            public array $errors = [];

            public array $redirects = [];

            public function __construct(private int $userId, private $connection)
            {
                $app = Application::getInstance();
                $app->database        = $connection;
                $this->application    = $app;
                $this->controllerName = 'Messages';
            }

            protected function currentUserId(): int
            {
                return $this->userId;
            }

            protected function database(): \Pramnos\Database\Database
            {
                return $this->connection;
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

    private function route(int $id): void
    {
        $_GET['_option'] = (string) $id;
        \Pramnos\Http\Request::resetInstance();
    }

    /** One message row. Returns its id. */
    private function seed(int $toUserId, string $subject, int $type, ?int $when = null): int
    {
        $this->db->queryBuilder()->table('#PREFIX#messages')->insert([
            'touserid'   => $toUserId,
            'fromuserid' => 1,
            'subject'    => $subject,
            'text'       => 'Body of ' . $subject,
            'type'       => $type,
            'date'       => $when ?? time(),
            'html'       => 0,
            /*
             * Explicit, the way `MassMessageDispatcher` is explicit about it and for the same
             * reason: `messages.attachmenttext` is TEXT with no default, so omitting it is a
             * NOT NULL violation — on PostgreSQL always, and on MySQL under strict mode.
             *
             * Found by running this fixture on the second backend, which is the whole argument
             * for having one: the MySQL lane inserted the same row without complaint.
             */
            'attachmenttext' => '',
        ]);

        $row = $this->db->queryBuilder()->table('#PREFIX#messages')
            ->where('touserid', $toUserId)
            ->where('subject', $subject)
            ->orderBy('messageid', 'desc')
            ->first();

        $id = (int) ($row->fields['messageid'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the fixture message was not created');

        return $id;
    }

    private function typeOf(int $id): int
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#messages')
            ->select(['type'])->where('messageid', $id)->first();

        return (int) ($row->fields['type'] ?? -1);
    }

    private function clear(): void
    {
        foreach ([self::UID, self::OTHER_UID] as $userId) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#messages')
                    ->where('touserid', $userId)->delete();
            } catch (\Throwable $exception) {
                // No table yet, or already gone with the test that dropped it.
            }
        }
    }

    private function dropMessages(): void
    {
        $this->db->query(
            'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('#PREFIX#messages')
        );
    }
}
