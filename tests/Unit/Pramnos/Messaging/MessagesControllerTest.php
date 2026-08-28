<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Messaging\Controllers\MessagesController;
use Pramnos\Messaging\Message;

/**
 * The inbox's own definitions of "in my inbox" and "unread".
 *
 * `messages.type` is one column carrying several unrelated meanings: whether an item is read,
 * whether it is in an inbox or an outbox, whether it is archived, whether it is deleted, and
 * whether it is a notification rather than a message. A listing built on that column is one
 * careless edit away from showing somebody their deleted mail, or their sent mail, or nothing
 * at all.
 *
 * So the states are named rather than excluded, and the lists are asserted here: a
 * `NOT IN (deleted, sent)` would put a state added next year into everybody's inbox, and the
 * screen would look exactly as correct as it does today.
 */
#[CoversClass(MessagesController::class)]
class MessagesControllerTest extends TestCase
{
    /**
     * The inbox lists read, unread and notification states — and nothing else.
     */
    public function testTheInboxStatesAreNamedNotExcluded(): void
    {
        // Act & Assert
        $this->assertSame(
            [
                Message::TYPE_READ,
                Message::TYPE_NEW,
                Message::TYPE_UNREAD,
                Message::TYPE_MARKED_READ,
                Message::TYPE_NOTIFICATION_NEW,
                Message::TYPE_NOTIFICATION_READ,
            ],
            MessagesController::INBOX_TYPES
        );

        foreach ([
            Message::TYPE_SENT,
            Message::TYPE_INBOX_ARCHIVE,
            Message::TYPE_OUTBOX_ARCHIVE,
            Message::TYPE_DELETED,
        ] as $excluded) {
            $this->assertNotContains($excluded, MessagesController::INBOX_TYPES,
                'a deleted, sent or archived message is not in an inbox');
        }
    }

    /**
     * Unread is the three states that mean "not looked at yet".
     */
    public function testUnreadIsTheThreeUnseenStates(): void
    {
        // Act & Assert
        $this->assertSame(
            [Message::TYPE_NEW, Message::TYPE_UNREAD, Message::TYPE_NOTIFICATION_NEW],
            MessagesController::UNREAD_TYPES
        );

        // Every unread state has to be one the inbox actually lists, or a badge counts
        // messages nobody can reach.
        foreach (MessagesController::UNREAD_TYPES as $state) {
            $this->assertContains($state, MessagesController::INBOX_TYPES);
        }
    }

    /**
     * A message that is already read is not written to again.
     *
     * The write is guarded by the state rather than attempted and shrugged off, which is what
     * makes opening a read message free — and it is asserted through a controller whose
     * database access throws: if the guard were missing, this test would see the exception.
     */
    public function testReadingAnAlreadyReadMessageWritesNothing(): void
    {
        // Arrange
        $probe = new class extends MessagesController {
            public function __construct()
            {
            }

            public function mark(int $userId, int $messageId, int $type): void
            {
                $this->markRead($userId, $messageId, $type);
            }

            protected function database(): \Pramnos\Database\Database
            {
                throw new \RuntimeException('this test has no database');
            }
        };

        // Act — no exception means no attempt was made
        $probe->mark(1, 1, Message::TYPE_READ);
        $probe->mark(1, 1, Message::TYPE_NOTIFICATION_READ);
        $probe->mark(1, 1, Message::TYPE_DELETED);

        // Assert
        $this->assertTrue(true, 'nothing to do, so nothing is attempted');
    }

    /**
     * An unread count for nobody is zero, without asking anything.
     */
    public function testTheUnreadCountForNobodyIsZero(): void
    {
        // Act & Assert
        $this->assertSame(0, MessagesController::unreadCount(0));
        $this->assertSame(0, MessagesController::unreadCount(-1));
    }
}
