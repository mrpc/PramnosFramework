<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Notification\Channels\PushChannel;
use Pramnos\Push\Log;

/**
 * Every reason a push does not go out writes a row.
 *
 * The rows worth having. A delivered notification is not usually what somebody is looking for
 * when they open the log — «they say they did not get it» is — and every one of the four
 * refusals was silent by design: a `return` with, at best, one line in a log file that nobody
 * reads until they already suspect the answer.
 *
 * Asserted through seams rather than against a `pushlog` table, because what has to hold is the
 * decision: that the channel records each of the four, with the reason and with which
 * notification it was.
 */
#[CoversClass(PushChannel::class)]
class PushLogWiringTest extends TestCase
{
    /**
     * An account with nothing subscribed is recorded, not silently skipped.
     *
     * The single commonest answer to "why did they not get the notification", and until now it
     * left no trace anywhere: the channel returned, and the question could not be answered from
     * any table.
     */
    public function testNothingSubscribedIsRecordedWithTheReason(): void
    {
        // Arrange
        $channel = $this->channel(subscriptions: []);

        // Act
        $channel->send($this->notifiable(), $this->notification());

        // Assert
        $this->assertSame([[7, Log::NO_SUBSCRIPTION]], $channel->refusals);
        $this->assertSame([], $channel->delivered);
    }

    /**
     * An installation with no key pair is recorded against the account it failed for.
     */
    public function testAMissingKeyPairIsRecorded(): void
    {
        // Arrange
        $channel = $this->channel(vapid: null);

        // Act
        $channel->send($this->notifiable(), $this->notification());

        // Assert
        $this->assertSame([[7, Log::NO_KEYS]], $channel->refusals);
    }

    /**
     * Push switched off — `push.send` false, or a callable that says no — sends nothing, and the
     * log says why rather than leaving the push missing; on by default, and a callable that says
     * yes lets it through. The routine notifications ask the same switch before naming `push`.
     */
    public function testPushSwitchedOffIsRecordedAndOmitted(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;

        try {
            // Act
            $app->applicationInfo['push'] = ['send' => false];
            $off = $this->channel(vapid: null);
            $off->send($this->notifiable(), $this->notification());
            $offChannels = \Pramnos\Llm\Chat\RoutineReady::channels((object) ['userid' => 7]);
            $app->applicationInfo['push'] = ['send' => static fn (): bool => false];
            $byCallable = \Pramnos\Notification\Channels\PushChannel::sends();
            $app->applicationInfo['push'] = ['send' => static fn (): bool => true];
            $onByCallable = \Pramnos\Notification\Channels\PushChannel::sends();
            unset($app->applicationInfo['push']);
            $default = \Pramnos\Notification\Channels\PushChannel::sends();
            $on = $this->channel(vapid: null);
            $on->send($this->notifiable(), $this->notification());
        } finally {
            $app->applicationInfo = $info;
        }

        // Assert
        $this->assertSame([[7, Log::SWITCHED_OFF]], $off->refusals, 'not sent, and said why');
        $this->assertSame(['database'], $offChannels, 'the routine notifications leave push out');
        $this->assertSame([false, true, true], [$byCallable, $onByCallable, $default]);
        $this->assertSame([[7, Log::NO_KEYS]], $on->refusals, 'switched on, it goes on to the next check');
    }

    /**
     * So is an installation with no encryption library.
     *
     * The library is a composer *suggestion*, so this is the state of any project that turned
     * push on without reading the last step of the guide — and from the outside it is
     * indistinguishable from notifications simply not arriving.
     */
    public function testAMissingLibraryIsRecorded(): void
    {
        // Arrange
        $channel = $this->channel(library: '\\No\\Such\\Library');

        // Act
        $channel->send($this->notifiable(), $this->notification());

        // Assert
        $this->assertSame([[7, Log::NO_LIBRARY]], $channel->refusals);
    }

    /**
     * And a notification that produced no title.
     *
     * A push with no title renders as the site's name and nothing else, so the channel refuses
     * it. Refusing silently means an application whose `toPush()` has a bug ships it.
     */
    public function testANotificationWithNoTitleIsRecorded(): void
    {
        // Arrange — a library that exists, so the check before this one passes
        $channel = $this->channel(library: \stdClass::class);

        // Act
        $channel->send($this->notifiable(), $this->notification(['body' => 'no title here']));

        // Assert
        $this->assertSame([[7, Log::NO_PAYLOAD]], $channel->refusals);
    }

    /**
     * The notification's class travels with every row.
     *
     * A log of forty thousand titles cannot answer "did the sign-in alerts go out". The class
     * name is the one thing that groups them, and it is knowable only here.
     */
    public function testTheNotificationClassIsRecorded(): void
    {
        // Arrange
        $channel = $this->channel(subscriptions: []);
        $notification = $this->notification();

        // Act
        $channel->send($this->notifiable(), $notification);

        // Assert
        $this->assertSame([$notification::class], $channel->names);
    }

    /**
     * A notifiable this channel cannot place is not recorded against account 0.
     *
     * There is no account to record it against, and a log full of rows for user 0 is worse than
     * no rows: it looks like an account with a problem.
     */
    public function testAnUnplaceableNotifiableWritesNothing(): void
    {
        // Arrange
        $channel = $this->channel(subscriptions: []);

        // Act
        $channel->send(new \stdClass(), $this->notification());

        // Assert
        $this->assertSame([], $channel->refusals);
    }

    /** A channel whose subscriptions, key pair, library and log are all given. */
    private function channel(
        array $subscriptions = [['endpoint' => 'https://push.example/1', 'p256dh' => 'k',
            'auth_secret' => 's', 'content_encoding' => 'aes128gcm']],
        ?array $vapid = ['publicKey' => 'p', 'privateKey' => 'v', 'subject' => 'mailto:a@b'],
        string $library = '\\Minishlink\\WebPush\\WebPush'
    ): object {
        return new class ($subscriptions, $vapid, $library) extends PushChannel {
            /** @var list<array{0:int,1:string}> */
            public array $refusals = [];

            /** @var list<string> */
            public array $names = [];

            /** @var list<array<string, mixed>> */
            public array $delivered = [];

            public function __construct(
                private array $subs,
                private ?array $keys,
                private string $lib
            ) {
            }

            protected function subscriptionsFor(int $userId): array { return $this->subs; }

            protected function vapid(): ?array { return $this->keys; }

            protected function libraryClass(): string { return ltrim($this->lib, '\\'); }

            protected function refuse(
                int $userId,
                string $reason,
                array $payload,
                string $notification
            ): void {
                $this->refusals[] = [$userId, $reason];
                $this->names[]    = $notification;
            }

            protected function deliver(
                array $subscriptions,
                string $payload,
                array $vapid,
                int $userId = 0,
                string $notification = ''
            ): void {
                $this->delivered[] = ['payload' => $payload, 'userid' => $userId];
                $this->names[]     = $notification;
            }
        };
    }

    private function notifiable(): object
    {
        return new class {
            public int $userid = 7;
        };
    }

    /** @param array<string, mixed> $push */
    private function notification(array $push = ['title' => 'Hello', 'body' => 'World']): object
    {
        return new class ($push) implements \Pramnos\Notification\NotificationInterface {
            public function __construct(private array $push)
            {
            }

            public function via(mixed $notifiable): array { return ['push']; }

            /** @return array<string, mixed> */
            public function toPush(mixed $notifiable): array { return $this->push; }
        };
    }
}
