<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Notification\ChannelInterface;
use Pramnos\Notification\NotifiableInterface;
use Pramnos\Notification\NotificationInterface;
use Pramnos\Notification\Notifier;
use Pramnos\User\User;

/**
 * A channel that records who it was asked to deliver to.
 *
 * A named class, and static state, because `via()` returns a class **name**: the notifier
 * constructs the channel itself, so there is no instance for a test to hold.
 */
class RecordingChannel implements ChannelInterface
{
    /** @var list<mixed> */
    public static array $received = [];

    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        self::$received[] = $notifiable;
    }
}

/**
 * `$user->notify($notification)` — the line the Notifications Guide opens with.
 *
 * WHAT: `Pramnos\User\User` is a `NotifiableInterface`, `notify()` reaches the channels,
 *       and the trait's default routing answers the address each one wants.
 *
 * WHY:  the guide said *"`Pramnos\User\User` already is one"* and it was not. The class
 *       implemented neither the interface nor the trait, so the guide's whole worked
 *       example ended in `Call to undefined method Pramnos\User\User::notify()` — on the
 *       first line anybody writes from it.
 *
 *       What kept it unreported is where it fails. A notification goes out from a
 *       scheduled pass, and **a fatal in a scheduled pass is a silent nothing**: the
 *       daemon logs it and the next tick runs. There is no red screen and nobody is
 *       waiting on the response.
 *
 *       Every channel was already built expecting this shape — `DatabaseChannel` falls
 *       back to `$notifiable->userid` — so only the entry point was missing, which is why
 *       a test asserting the *routing* would have passed throughout. The assertion that
 *       matters is that the method exists and reaches a channel.
 */
#[CoversClass(User::class)]
class UserIsNotifiableTest extends TestCase
{
    /** A notification that records which channels it was asked for. */
    private function notification(array $channels): NotificationInterface
    {
        return new class ($channels) implements NotificationInterface {
            /** @param list<string> $channels */
            public function __construct(private array $channels)
            {
            }

            public function via(mixed $notifiable): array
            {
                return $this->channels;
            }
        };
    }

    /**
     * It declares the contract.
     *
     * The guide's claim, as a type. An application checking
     * `$user instanceof NotifiableInterface` before sending — which is the careful thing
     * to write — got `false` and skipped every notification silently.
     */
    public function testUserIsNotifiable(): void
    {
        // Act + Assert
        $this->assertInstanceOf(NotifiableInterface::class, new User());
    }

    /**
     * And `notify()` reaches a channel.
     *
     * **The assertion the bug is about.** A `User` that satisfied the interface without
     * dispatching anywhere would pass the test above and still deliver nothing — and
     * nothing is what a fatal in a scheduled pass looks like too.
     *
     * The channel is named by its **fully-qualified class name** rather than by an alias,
     * because that is the only thing that works through `notify()`:
     * `NotifiableTrait::notify()` constructs its own `Notifier`, so an alias registered on
     * any other instance does not exist as far as this call is concerned. The framework's
     * own error message says so, and this is the path it points at.
     */
    public function testNotifyReachesTheChannel(): void
    {
        // Arrange
        RecordingChannel::$received = [];

        $user         = new User();
        $user->userid = 42;

        // Act
        $user->notify($this->notification([RecordingChannel::class]));

        // Assert
        $this->assertCount(1, RecordingChannel::$received, 'notify() dispatched to no channel');
        $this->assertSame(
            $user,
            RecordingChannel::$received[0],
            'the channel was handed something other than the user'
        );
    }

    /**
     * The trait's default routing is the one this class needs, unchanged.
     *
     * Worth pinning rather than assuming: the argument for adding the trait rather than
     * writing `notify()` by hand is that `email` and `userid` are already the right
     * answers. If that stops being true the trait is the wrong default and this says so.
     */
    public function testTheDefaultRoutingAnswersWhatEachChannelWants(): void
    {
        // Arrange
        $user         = new User();
        $user->email  = 'someone@example.com';
        $user->userid = 7;

        // Act + Assert
        $this->assertSame('someone@example.com', $user->routeNotificationFor('mail'));
        $this->assertSame(7, $user->routeNotificationFor('database'));

        // A channel the user has no address for is skipped rather than guessed at.
        $this->assertNull($user->routeNotificationFor('sms'));
    }
}
