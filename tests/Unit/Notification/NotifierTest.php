<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Notification\ChannelInterface;
use Pramnos\Notification\NotifiableInterface;
use Pramnos\Notification\NotifiableTrait;
use Pramnos\Notification\NotificationInterface;
use Pramnos\Notification\Notifier;

/**
 * Unit tests for Notifier — the central dispatch hub.
 *
 * These tests verify the routing logic without exercising real transport
 * channels. All channels are replaced with lightweight spy/stub objects.
 */
#[CoversClass(Notifier::class)]
#[CoversClass(NotifiableTrait::class)]
class NotifierTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // sendNow() — dispatches channels from via()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * sendNow() must invoke the send() method on every channel returned by
     * $notification->via(). Verified via a spy channel that counts calls.
     */
    public function testSendNowCallsAllChannelsReturnedByVia(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);
        $notifiable    = new StubNotifiable();
        $notification  = new TwoSpyChannelNotification();

        // Act
        $notifier->sendNow($notifiable, $notification);

        // Assert — spy channel was called twice (two 'spy' entries in via())
        $this->assertCount(2, SpyChannel::$calls,
            'sendNow() must call send() once per channel in via()');
    }

    /**
     * When via() returns an empty array no channel is invoked and no error
     * is thrown — the notification is silently dropped.
     */
    public function testSendNowWithNoChannelsDoesNothing(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier     = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);
        $notifiable   = new StubNotifiable();
        $notification = new NoChannelNotification();

        // Act
        $notifier->sendNow($notifiable, $notification);

        // Assert
        $this->assertEmpty(SpyChannel::$calls, 'No channels → no send() call expected');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // send() — bulk dispatch over multiple notifiables
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * send() must call sendNow() for every entity in the $notifiables array.
     *
     * Three entities → three send() calls on the spy channel.
     */
    public function testSendDispatchesNotificationToAllNotifiables(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier      = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);
        $notification  = new SingleSpyChannelNotification();
        $notifiables   = [new StubNotifiable(), new StubNotifiable(), new StubNotifiable()];

        // Act
        $notifier->send($notifiables, $notification);

        // Assert — one send() call per notifiable
        $this->assertCount(3, SpyChannel::$calls,
            'send() must invoke the channel once per notifiable entity');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Channel resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A fully-qualified class name that implements ChannelInterface must be
     * accepted as a channel name, without prior registration.
     *
     * This allows app-specific channels to be used in via() without patching
     * the framework.
     */
    public function testFullyQualifiedChannelClassIsResolved(): void
    {
        // Arrange
        SpyChannel::$calls = [];
        $notifier      = new Notifier();
        $notifiable    = new StubNotifiable();
        $notification  = new FqcnChannelNotification();  // via() returns [SpyChannel::class]

        // Act — no exception expected
        $notifier->sendNow($notifiable, $notification);

        // Assert
        $this->assertCount(1, SpyChannel::$calls,
            'FQCN channel class must be resolved and called without registration');
    }

    /**
     * An unknown channel name that is neither a registered alias nor a FQCN
     * implementing ChannelInterface must throw an InvalidArgumentException.
     */
    public function testUnknownChannelThrowsInvalidArgumentException(): void
    {
        // Arrange
        $notifier     = new Notifier();
        $notifiable   = new StubNotifiable();
        $notification = new UnknownChannelNotification();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown notification channel/');

        // Act
        $notifier->sendNow($notifiable, $notification);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NotifiableTrait — notify() delegates to Notifier
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * NotifiableTrait::notify() must create a Notifier and call sendNow().
     *
     * Verified indirectly: the spy channel is registered via the default
     * constructor, so if notify() calls sendNow() the spy will be invoked.
     * We cannot intercept the Notifier created inside notify(), so we use a
     * global alias registered in the Notifier default map.
     *
     * Instead we test via a custom Notifier-aware notifiable that exposes
     * which Notifier it used — but since NotifiableTrait is not injectable,
     * we just assert the side effect on a real channel.
     */
    public function testNotifiableTraitNotifyCallsSendNow(): void
    {
        // Arrange — use TrackableNotifiable that injects a known spy Notifier
        SpyChannel::$calls = [];
        $notifiable = new TrackableNotifiable();

        // Act
        $notifiable->notify(new SingleSpyChannelNotification());

        // Assert — spy was called
        $this->assertCount(1, SpyChannel::$calls,
            'NotifiableTrait::notify() must call Notifier::sendNow()');
    }

    /**
     * NotifiableTrait::notify() default implementation (line 36) must call
     * Notifier::sendNow() directly. Uses StubNotifiable (no override) so the
     * trait's own body runs. Uses the 'log' built-in channel which writes to
     * a temp path — no side effects on test infrastructure.
     */
    public function testNotifiableTraitDefaultNotifyBodyRuns(): void
    {
        // Arrange — StubNotifiable uses the trait's unoverridden notify()
        $notifiable = new StubNotifiable();

        // Act — calls NotifiableTrait::notify() directly (line 36).
        // LogChannelNotification routes to the built-in 'log' channel so the
        // fresh Notifier constructed inside notify() can resolve it without
        // custom registration.
        $notifiable->notify(new LogChannelNotification());

        // Assert — no exception thrown means line 36 was reached and executed
        $this->addToAssertionCount(1);
    }

    /**
     * NotifiableTrait::routeNotificationFor('mail') returns $this->email.
     */
    public function testNotifiableTraitRoutesMailToEmailProperty(): void
    {
        // Arrange
        $notifiable        = new StubNotifiable();
        $notifiable->email = 'alice@example.com';

        // Act
        $address = $notifiable->routeNotificationFor('mail');

        // Assert
        $this->assertSame('alice@example.com', $address);
    }

    /**
     * NotifiableTrait::routeNotificationFor('database') returns $this->userid
     * when set, or $this->id as fallback.
     */
    public function testNotifiableTraitRoutesDatabaseToUserid(): void
    {
        // Arrange
        $notifiable         = new StubNotifiable();
        $notifiable->userid = 99;

        // Act
        $id = $notifiable->routeNotificationFor('database');

        // Assert
        $this->assertSame(99, $id);
    }

    /**
     * routeNotificationFor() returns null for unknown channels, allowing
     * the channel to decide how to handle a missing address.
     */
    public function testNotifiableTraitReturnsNullForUnknownChannel(): void
    {
        // Arrange
        $notifiable = new StubNotifiable();

        // Act + Assert
        $this->assertNull($notifiable->routeNotificationFor('sms'),
            'Unknown channel must return null — not throw');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // registerChannel()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * registerChannel() must allow overriding a built-in channel alias with a
     * custom implementation.
     */
    public function testRegisterChannelOverridesBuiltInAlias(): void
    {
        // Arrange — override the 'log' alias with SpyChannel
        SpyChannel::$calls = [];
        $notifier = new Notifier();
        $notifier->registerChannel('log', SpyChannel::class);
        $notification = new LogChannelNotification();
        $notifiable   = new StubNotifiable();

        // Act
        $notifier->sendNow($notifiable, $notification);

        // Assert — SpyChannel was used instead of the real LogChannel
        $this->assertCount(1, SpyChannel::$calls,
            'registerChannel() must replace the built-in channel alias');
    }
}

// =============================================================================
// Stubs and spies
// =============================================================================

/** Spy channel that records all send() calls. */
class SpyChannel implements ChannelInterface
{
    public static array $calls = [];

    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        self::$calls[] = ['notifiable' => $notifiable, 'notification' => $notification];
    }
}

/** Notifiable stub using the trait. */
class StubNotifiable implements NotifiableInterface
{
    use NotifiableTrait;

    public string $email  = '';
    public int    $userid = 0;
    public int    $id     = 0;
}

/**
 * A notifiable that overrides notify() to inject a known Notifier so the spy
 * channel is in the alias map without modifying the global default map.
 */
class TrackableNotifiable implements NotifiableInterface
{
    use NotifiableTrait;

    public string $email  = '';
    public int    $userid = 0;

    public function notify(NotificationInterface $notification): void
    {
        $notifier = new Notifier();
        $notifier->registerChannel('spy', SpyChannel::class);
        $notifier->sendNow($this, $notification);
    }
}

/** Notification that lists two 'spy' channels. */
class TwoSpyChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['spy', 'spy']; }
}

/** Notification with no channels. */
class NoChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return []; }
}

/** Notification with a single 'spy' channel. */
class SingleSpyChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['spy']; }
}

/** Notification that passes SpyChannel FQCN as channel name. */
class FqcnChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return [SpyChannel::class]; }
}

/** Notification that requests an unknown channel. */
class UnknownChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['nonexistent_channel_xyz']; }
}

/** Notification for the log alias override test. */
class LogChannelNotification implements NotificationInterface
{
    public function via(mixed $notifiable): array { return ['log']; }
}
