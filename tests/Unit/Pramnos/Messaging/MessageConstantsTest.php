<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Messaging\MassMessage;
use Pramnos\Messaging\MassMessageRecipient;
use Pramnos\Messaging\Message;

/**
 * Unit tests for the TYPE_* and STATUS_* constants on Message, MassMessage,
 * and MassMessageRecipient.
 *
 * The messaging system is an internal inbox/notification engine.  Two rows are
 * written per send (one for the sender, one per recipient), each stamped with a
 * `type` integer that drives inbox/outbox/archive/notification state machines.
 * The constants must remain stable across releases because their integer values
 * are persisted in the database.  A change would silently corrupt existing rows.
 *
 * These tests pin the values so any accidental renumbering is caught immediately.
 */
#[CoversClass(Message::class)]
#[CoversClass(MassMessage::class)]
#[CoversClass(MassMessageRecipient::class)]
class MessageConstantsTest extends TestCase
{
    // =========================================================================
    // Message::TYPE_* — inbox/outbox state machine
    // =========================================================================

    /**
     * Message::TYPE_READ is 0 — sender copy, message has been read.
     * Row created for the sender when the recipient has read the message.
     */
    public function testMessageTypeReadIsZero(): void
    {
        $this->assertSame(0, Message::TYPE_READ);
    }

    /**
     * Message::TYPE_NEW is 1 — recipient inbox, message is unread.
     * The initial state of every delivered message on the recipient side.
     */
    public function testMessageTypeNewIsOne(): void
    {
        $this->assertSame(1, Message::TYPE_NEW);
    }

    /**
     * Message::TYPE_SENT is 2 — sender outbox copy.
     * Created at send time alongside the TYPE_NEW recipient row.
     */
    public function testMessageTypeSentIsTwo(): void
    {
        $this->assertSame(2, Message::TYPE_SENT);
    }

    /**
     * Message::TYPE_INBOX_ARCHIVE is 3 — recipient archived the message.
     */
    public function testMessageTypeInboxArchiveIsThree(): void
    {
        $this->assertSame(3, Message::TYPE_INBOX_ARCHIVE);
    }

    /**
     * Message::TYPE_OUTBOX_ARCHIVE is 4 — sender archived their sent copy.
     */
    public function testMessageTypeOutboxArchiveIsFour(): void
    {
        $this->assertSame(4, Message::TYPE_OUTBOX_ARCHIVE);
    }

    /**
     * Message::TYPE_UNREAD is 5 — legacy alias for an unread inbox item.
     * Kept for backward compatibility; countUnread() counts both TYPE_NEW and
     * TYPE_UNREAD so older rows are not missed.
     */
    public function testMessageTypeUnreadIsFive(): void
    {
        $this->assertSame(5, Message::TYPE_UNREAD);
    }

    /**
     * Message::TYPE_MARKED_READ is 6 — recipient explicitly marked as read.
     */
    public function testMessageTypeMarkedReadIsSix(): void
    {
        $this->assertSame(6, Message::TYPE_MARKED_READ);
    }

    /**
     * Message::TYPE_DELETED is 7 — soft-deleted row (not physically removed).
     */
    public function testMessageTypeDeletedIsSeven(): void
    {
        $this->assertSame(7, Message::TYPE_DELETED);
    }

    /**
     * Message::TYPE_NOTIFICATION_NEW is 8 — unseen system notification.
     * Notification rows have no fromuserid (they are system-generated).
     */
    public function testMessageTypeNotificationNewIsEight(): void
    {
        $this->assertSame(8, Message::TYPE_NOTIFICATION_NEW);
    }

    /**
     * Message::TYPE_NOTIFICATION_READ is 9 — notification has been seen/dismissed.
     */
    public function testMessageTypeNotificationReadIsNine(): void
    {
        $this->assertSame(9, Message::TYPE_NOTIFICATION_READ);
    }

    /**
     * The ten TYPE_* constants cover the complete state space (0-9) with no
     * gaps.  This ensures that range-based SQL WHERE type BETWEEN 0 AND 9
     * would hit every defined state.
     */
    public function testMessageTypeConstantsAreConsecutiveZeroToNine(): void
    {
        // Arrange – collect all defined TYPE_* values
        $constants = [
            Message::TYPE_READ,
            Message::TYPE_NEW,
            Message::TYPE_SENT,
            Message::TYPE_INBOX_ARCHIVE,
            Message::TYPE_OUTBOX_ARCHIVE,
            Message::TYPE_UNREAD,
            Message::TYPE_MARKED_READ,
            Message::TYPE_DELETED,
            Message::TYPE_NOTIFICATION_NEW,
            Message::TYPE_NOTIFICATION_READ,
        ];
        sort($constants);

        // Assert – values are exactly 0-9
        $this->assertSame(range(0, 9), $constants);
    }

    // =========================================================================
    // MassMessage::TYPE_* — delivery channel
    // =========================================================================

    /**
     * MassMessage::TYPE_EMAIL is 0 — message sent via email.
     */
    public function testMassMessageTypeEmailIsZero(): void
    {
        $this->assertSame(0, MassMessage::TYPE_EMAIL);
    }

    /**
     * MassMessage::TYPE_MESSAGE is 1 — message sent as an internal message.
     */
    public function testMassMessageTypeMessageIsOne(): void
    {
        $this->assertSame(1, MassMessage::TYPE_MESSAGE);
    }

    /**
     * MassMessage::TYPE_PUSH is 2 — message sent as a push notification.
     */
    public function testMassMessageTypePushIsTwo(): void
    {
        $this->assertSame(2, MassMessage::TYPE_PUSH);
    }

    // =========================================================================
    // MassMessage::STATUS_* — send lifecycle
    // =========================================================================

    /**
     * MassMessage::STATUS_PENDING is 0 — campaign queued but not yet sent.
     * This is the initial state when a mass-message is scheduled.
     */
    public function testMassMessageStatusPendingIsZero(): void
    {
        $this->assertSame(0, MassMessage::STATUS_PENDING);
    }

    /**
     * MassMessage::STATUS_SENT is 1 — campaign has been dispatched.
     * Updated by the mass-messaging worker after successful delivery.
     */
    public function testMassMessageStatusSentIsOne(): void
    {
        $this->assertSame(1, MassMessage::STATUS_SENT);
    }

    /**
     * MassMessage::STATUS_SCHEDULED is 2 — campaign is scheduled for a future
     * date/time and should not be dispatched until the scheduled time arrives.
     */
    public function testMassMessageStatusScheduledIsTwo(): void
    {
        $this->assertSame(2, MassMessage::STATUS_SCHEDULED);
    }

    // =========================================================================
    // MassMessageRecipient::STATUS_* — per-user delivery tracking
    // =========================================================================

    /**
     * MassMessageRecipient::STATUS_PENDING is 0 — delivery not yet attempted.
     * This is the initial state when the massmessagerecipients row is created.
     */
    public function testRecipientStatusPendingIsZero(): void
    {
        $this->assertSame(0, MassMessageRecipient::STATUS_PENDING);
    }

    /**
     * MassMessageRecipient::STATUS_DELIVERED is 1 — message was successfully
     * delivered to the individual recipient.
     */
    public function testRecipientStatusDeliveredIsOne(): void
    {
        $this->assertSame(1, MassMessageRecipient::STATUS_DELIVERED);
    }

    /**
     * MassMessageRecipient::STATUS_FAILED is 2 — delivery attempt failed.
     * The worker updates this row so failed deliveries can be retried or reported.
     */
    public function testRecipientStatusFailedIsTwo(): void
    {
        $this->assertSame(2, MassMessageRecipient::STATUS_FAILED);
    }

    /**
     * All three recipient STATUS_* constants are distinct integers — an accidental
     * collision would silently misclassify delivery results in the database.
     */
    public function testRecipientStatusConstantsAreDistinct(): void
    {
        // Arrange – collect all STATUS_* values
        $values = [
            MassMessageRecipient::STATUS_PENDING,
            MassMessageRecipient::STATUS_DELIVERED,
            MassMessageRecipient::STATUS_FAILED,
        ];

        // Assert – no duplicates
        $this->assertSame(count($values), count(array_unique($values)));
    }
}
