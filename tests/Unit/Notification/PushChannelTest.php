<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Notification\Channels\PushChannel;
use Pramnos\Notification\NotificationInterface;

/**
 * The push channel's two silent decisions: what goes into the payload, and what a report means.
 *
 * Both are places where being wrong is invisible. An oversized payload is rejected by the push
 * service with a status nobody reads; a misread report deletes a live subscription. Neither
 * produces an error anybody sees.
 */
#[CoversClass(PushChannel::class)]
class PushChannelTest extends TestCase
{
    private PushChannelProbe $channel;

    protected function setUp(): void
    {
        $this->channel = new PushChannelProbe();
        FakeWebPush::reset();
    }

    /**
     * A notification with no title produces nothing at all.
     *
     * A push with an empty title renders as the site name and a blank line — a notification the
     * person cannot act on and cannot identify. Better not sent.
     */
    public function testANotificationWithoutATitleIsNotSent(): void
    {
        // Assert
        $this->assertSame('', $this->channel->probePayload([]));
        $this->assertSame('', $this->channel->probePayload(['body' => 'no title here']));
    }

    /**
     * Title and body are capped, because the whole payload is limited to ~4KB after encryption.
     *
     * A service that receives more rejects the message outright, so a long body does not arrive
     * truncated — it does not arrive.
     */
    public function testTheTitleAndBodyAreCapped(): void
    {
        // Act
        $payload = json_decode($this->channel->probePayload([
            'title' => str_repeat('t', 500),
            'body'  => str_repeat('b', 5000),
        ]), true);

        // Assert
        $this->assertSame(120, strlen($payload['title']));
        $this->assertSame(400, strlen($payload['body']));
    }

    /**
     * At most two action buttons, because that is all a notification shows.
     *
     * Chrome renders two; the rest are silently dropped by the browser. Sending eight only makes
     * the payload bigger and the choice of which two survive somebody else's.
     */
    public function testAtMostTwoActionsSurvive(): void
    {
        // Act
        $payload = json_decode($this->channel->probePayload([
            'title'   => 'Hello',
            'actions' => [
                ['action' => 'a', 'title' => 'A'],
                ['action' => 'b', 'title' => 'B'],
                ['action' => 'c', 'title' => 'C'],
            ],
        ]), true);

        // Assert
        $this->assertCount(2, $payload['actions']);
        $this->assertSame('a', $payload['actions'][0]['action']);
    }

    /**
     * Empty fields are left out rather than sent as empty strings.
     *
     * `icon: ""` is not "no icon" to a browser — several treat it as an icon URL that resolves to
     * the current page. And every empty key spends bytes from a 4KB budget.
     */
    public function testEmptyFieldsAreOmitted(): void
    {
        // Act
        $payload = json_decode($this->channel->probePayload(['title' => 'Hello']), true);

        // Assert
        $this->assertSame(['title' => 'Hello'], $payload);
    }

    /**
     * Greek and a slash survive the encoding readable.
     *
     * A URL with `\/` in it is valid JSON and works, but it is also what a developer sees in a
     * log while trying to work out why a notification went to the wrong place.
     */
    public function testTheJsonIsReadable(): void
    {
        // Act
        $json = $this->channel->probePayload([
            'title' => 'Νέα σύνδεση',
            'url'   => 'https://example.com/account/sessions',
        ]);

        // Assert
        $this->assertStringContainsString('Νέα σύνδεση', $json);
        $this->assertStringContainsString('https://example.com/account/sessions', $json);
    }

    /**
     * A report with no response at all is **not** a 410.
     *
     * This is the assertion the class exists for. A DNS failure, a timeout, a proxy that dropped
     * the connection — the library reports failure with a null response, and reading that as
     * "gone" deletes a perfectly live subscription because the network hiccuped for a second.
     */
    public function testAFailureWithNoResponseIsNotGone(): void
    {
        // Arrange
        $report = new class {
            public function isSuccess(): bool { return false; }
            public function getResponse(): mixed { return null; }
        };

        // Assert
        $this->assertSame(0, $this->channel->probeStatus($report));
    }

    /**
     * A real status is read through, whatever wrapper the library puts it in.
     */
    public function testARealStatusIsReadThrough(): void
    {
        // Arrange
        $report = new class {
            public function isSuccess(): bool { return false; }
            public function getResponse(): object
            {
                return new class {
                    public function getStatusCode(): int { return 410; }
                };
            }
        };

        // Assert
        $this->assertSame(410, $this->channel->probeStatus($report));
    }

    /**
     * A success is a success without consulting anything else.
     */
    public function testASuccessfulReportIsTwoHundred(): void
    {
        // Arrange
        $report = new class {
            public function isSuccess(): bool { return true; }
        };

        // Assert
        $this->assertSame(200, $this->channel->probeStatus($report));
    }

    /**
     * A report shaped like nothing this channel recognises is treated as a soft failure.
     *
     * The library's report class has changed shape across major versions. An unrecognised one
     * must not resolve to a status that deletes rows.
     */
    public function testAnUnrecognisedReportIsASoftFailure(): void
    {
        // Assert
        $this->assertSame(0, $this->channel->probeStatus(new \stdClass()));
    }

    /**
     * A notification that does not implement `toPush()` is skipped, not crashed on.
     *
     * `via()` naming a channel the notification has no method for is an ordinary mistake, and it
     * must not take down the whole dispatch — the mail and database channels beside it are
     * probably the ones that mattered.
     */
    public function testANotificationWithNoPushMethodIsSkipped(): void
    {
        // Arrange
        $notification = new class implements NotificationInterface {
            public function via(mixed $notifiable): array { return ['push']; }
        };

        // Act & Assert — no exception, and nothing looked up
        $this->channel->send((object) ['userid' => 1], $notification);
        $this->assertTrue(true);
    }

    /**
     * A notifiable that is not an account reaches nobody rather than everybody.
     */
    public function testANotifiableWithNoAccountResolvesToNothing(): void
    {
        // Assert
        $this->assertNull($this->channel->probeUserId('a string'));
        $this->assertNull($this->channel->probeUserId(new \stdClass()));
        $this->assertSame(7, $this->channel->probeUserId((object) ['userid' => 7]));
        $this->assertSame(9, $this->channel->probeUserId((object) ['id' => 9]));
    }

    /**
     * `routeNotificationFor('push')` wins over the object's own id, as it does elsewhere.
     */
    public function testARoutedNotifiableDecidesForItself(): void
    {
        // Arrange
        $notifiable = new class {
            public int $userid = 1;
            public function routeNotificationFor(string $channel): mixed
            {
                return $channel === 'push' ? 42 : null;
            }
        };

        // Assert
        $this->assertSame(42, $this->channel->probeUserId($notifiable));
    }

    /**
     * Four hours, not four weeks.
     *
     * The service default is long enough that «somebody signed in to your account» can arrive
     * days after the sign-in — information that is now only alarming.
     */
    public function testUndeliveredNotificationsExpireInHoursNotWeeks(): void
    {
        // Assert
        $this->assertSame(14400, PushChannel::TTL);
        $this->assertLessThan(86400, PushChannel::TTL);
    }

    /**
     * The library names the channel reaches for are the real ones.
     *
     * Every other test here substitutes a double for them, so without this the constants could
     * name anything at all and the suite would stay green while no installation could ever send.
     */
    public function testItReachesForTheRealLibrary(): void
    {
        // Assert
        $this->assertSame('Minishlink\\WebPush\\WebPush', $this->channel->probeLibraryClass());
        $this->assertSame('Minishlink\\WebPush\\Subscription', $this->channel->probeSubscriptionClass());
    }

    /**
     * On an installation with no key pair, the channel's own lookup answers null.
     *
     * The value `send()` branches on, and this framework's checkout is exactly that
     * installation — which is why the branch is reachable here at all.
     */
    public function testTheKeyPairLookupAnswersNullWithoutOne(): void
    {
        // Assert
        $this->assertNull($this->channel->probeVapid());
    }

    /**
     * With nothing subscribed, nothing is attempted.
     *
     * The common case for most accounts, and it must not be a failure or a log line — a person
     * who never granted permission is not an error.
     */
    public function testNothingSubscribedIsNotAFailure(): void
    {
        // Arrange
        $channel = new SendingPushChannel([], ['publicKey' => 'p', 'privateKey' => 'k', 'subject' => 'mailto:a@b.c']);

        // Act
        $channel->send((object) ['userid' => 1], new PushableNotification());

        // Assert
        $this->assertSame([], $channel->sent, 'nothing was queued');
    }

    /**
     * A notifiable that is not an account is dropped before anything is looked up.
     *
     * `via()` naming `'push'` on something that is not a user — a model, a plain string — must
     * not become a query for subscriptions belonging to user 0.
     */
    public function testANotifiableWithNoAccountIsDroppedEarly(): void
    {
        // Arrange
        $channel = new SendingPushChannel(
            [$this->subscription()],
            ['publicKey' => 'p', 'privateKey' => 'k', 'subject' => 'mailto:a@b.c']
        );

        // Act
        $channel->send('a string', new PushableNotification());

        // Assert
        $this->assertSame([], $channel->sent);
    }

    /**
     * Without a key pair nothing is sent, and the log says which command fixes it.
     *
     * The failure a fresh installation hits, and it is silent from the application's side: the
     * notification is dispatched, no exception is raised and nobody is notified.
     */
    public function testWithoutAKeyPairNothingIsSent(): void
    {
        // Arrange
        $channel = new SendingPushChannel([$this->subscription()], null);

        // Act
        $channel->send((object) ['userid' => 1], new PushableNotification());

        // Assert
        $this->assertSame([], $channel->sent);
    }

    /**
     * Without the encryption library nothing is sent either — and the key pair is not consulted.
     */
    public function testWithoutTheLibraryNothingIsSent(): void
    {
        // Arrange
        $channel = new SendingPushChannel(
            [$this->subscription()],
            ['publicKey' => 'p', 'privateKey' => 'k', 'subject' => 'mailto:a@b.c']
        );
        $channel->library = 'No\\Such\\PushLibrary';

        // Act
        $channel->send((object) ['userid' => 1], new PushableNotification());

        // Assert
        $this->assertSame([], $channel->sent);
    }

    /**
     * A notification whose payload comes out empty is not sent as an empty push.
     */
    public function testAnEmptyPayloadIsNotSent(): void
    {
        // Arrange
        $channel = new SendingPushChannel(
            [$this->subscription()],
            ['publicKey' => 'p', 'privateKey' => 'k', 'subject' => 'mailto:a@b.c']
        );

        // Act — a notification with no title
        $channel->send((object) ['userid' => 1], new PushableNotification(['body' => 'orphan']));

        // Assert
        $this->assertSame([], $channel->sent);
    }

    /**
     * With everything in place, every subscription is queued and flushed **once**.
     *
     * One batch rather than a send per subscription is the whole performance story: the library
     * flushes with `curl_multi`, so ten thousand subscriptions are ten thousand parallel
     * requests instead of ten thousand sequential TLS handshakes — and the handshakes, not the
     * encryption, are what makes a large send slow.
     */
    public function testEverySubscriptionIsQueuedAndFlushedOnce(): void
    {
        // Arrange
        $channel = new SendingPushChannel(
            [
                $this->subscription('https://a.example/1'),
                $this->subscription('https://b.example/2'),
            ],
            ['publicKey' => 'p', 'privateKey' => 'k', 'subject' => 'mailto:a@b.c']
        );

        // Act
        $channel->send((object) ['userid' => 1], new PushableNotification());

        // Assert
        $this->assertCount(2, $channel->sent);
        $this->assertSame(1, FakeWebPush::$flushes, 'one batch, not one send per subscription');
        $this->assertSame(
            ['TTL' => \Pramnos\Notification\Channels\PushChannel::TTL, 'urgency' => 'normal'],
            FakeWebPush::$options
        );
        $this->assertStringContainsString('Νέο μήνυμα', $channel->sent[0]['payload']);
    }

    /**
     * A subscription with no stored content encoding still gets a valid one.
     *
     * The column defaults to `aes128gcm`, but a row written before that column existed — or by
     * something else — can be empty, and the library refuses an empty encoding outright.
     */
    public function testAMissingContentEncodingFallsBackRatherThanFailing(): void
    {
        // Arrange
        $subscription = $this->subscription();
        $subscription['content_encoding'] = '';

        $channel = new SendingPushChannel(
            [$subscription],
            ['publicKey' => 'p', 'privateKey' => 'k', 'subject' => 'mailto:a@b.c']
        );

        // Act
        $channel->send((object) ['userid' => 1], new PushableNotification());

        // Assert
        $this->assertSame('aes128gcm', $channel->sent[0]['contentEncoding']);
    }

    /**
     * Each report is recorded against the endpoint it names, with the status it carries.
     *
     * The point at which a 410 deletes a row. Recording the wrong status against the wrong
     * endpoint would unsubscribe a live browser because a different one was gone.
     */
    public function testEachReportIsRecordedAgainstItsOwnEndpoint(): void
    {
        // Arrange
        FakeWebPush::$reports = [
            ['endpoint' => 'https://a.example/1', 'success' => true,  'status' => null],
            ['endpoint' => 'https://b.example/2', 'success' => false, 'status' => 410],
        ];

        $channel = new SendingPushChannel(
            [$this->subscription('https://a.example/1'), $this->subscription('https://b.example/2')],
            ['publicKey' => 'p', 'privateKey' => 'k', 'subject' => 'mailto:a@b.c']
        );

        // Act
        $channel->send((object) ['userid' => 1], new PushableNotification());

        // Assert
        $this->assertSame(
            [['https://a.example/1', 200], ['https://b.example/2', 410]],
            $channel->recorded
        );
    }

    /**
     * A library that throws does not take down whatever queued the notification.
     *
     * A push send is usually one line inside something that mattered more — a password change, a
     * sign-in. An unreachable push service must not turn that into a 500.
     */
    public function testAFailingBatchDoesNotEscape(): void
    {
        // Arrange
        FakeWebPush::$throwOnFlush = true;

        $channel = new SendingPushChannel(
            [$this->subscription()],
            ['publicKey' => 'p', 'privateKey' => 'k', 'subject' => 'mailto:a@b.c']
        );

        // Act & Assert — no exception
        $channel->send((object) ['userid' => 1], new PushableNotification());
        $this->assertSame([], $channel->recorded);
    }

    /** @return array<string, mixed> */
    private function subscription(string $endpoint = 'https://a.example/1'): array
    {
        return [
            'endpoint'         => $endpoint,
            'p256dh'           => 'BBrowserKey',
            'auth_secret'      => 'AuthSecret',
            'content_encoding' => 'aes128gcm',
        ];
    }
}

/** A channel whose subscriptions, key pair and library are all supplied by the test. */
class SendingPushChannel extends PushChannel
{
    public string $library = FakeWebPush::class;

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var list<array{0:string,1:int}> */
    public array $recorded = [];

    public function __construct(private array $subscriptions, private ?array $keys)
    {
        FakeWebPush::$channel = $this;
    }

    protected function subscriptionsFor(int $userId): array { return $this->subscriptions; }

    protected function vapid(): ?array { return $this->keys; }

    protected function record(string $endpoint, int $status): void
    {
        $this->recorded[] = [$endpoint, $status];
    }

    protected function libraryClass(): string { return $this->library; }

    protected function subscriptionClass(): string { return FakeSubscription::class; }
}

/** Stands in for `Minishlink\WebPush\WebPush`. */
class FakeWebPush
{
    public static ?SendingPushChannel $channel = null;

    public static int $flushes = 0;

    public static array $options = [];

    public static bool $throwOnFlush = false;

    /** @var list<array{endpoint:string, success:bool, status:int|null}> */
    public static array $reports = [];

    public function __construct(public array $auth) {}

    public static function reset(): void
    {
        self::$flushes      = 0;
        self::$options      = [];
        self::$throwOnFlush = false;
        self::$reports      = [];
    }

    public function setDefaultOptions(array $options): void { self::$options = $options; }

    public function queueNotification(object $subscription, string $payload): void
    {
        self::$channel->sent[] = $subscription->fields + ['payload' => $payload];
    }

    public function flush(): array
    {
        self::$flushes++;

        if (self::$throwOnFlush) {
            throw new \RuntimeException('the push service could not be reached');
        }

        $reports = [];

        foreach (self::$reports ?: array_map(
            static fn (array $sent): array => ['endpoint' => $sent['endpoint'], 'success' => true, 'status' => null],
            self::$channel->sent
        ) as $report) {
            $reports[] = new FakeReport($report['endpoint'], $report['success'], $report['status']);
        }

        return $reports;
    }
}

/** Stands in for `Minishlink\WebPush\Subscription`. */
class FakeSubscription
{
    private function __construct(public array $fields) {}

    public static function create(array $fields): self { return new self($fields); }
}

/** Stands in for the library's report object. */
class FakeReport
{
    public function __construct(
        private string $endpoint,
        private bool $success,
        private ?int $status
    ) {}

    public function getEndpoint(): string { return $this->endpoint; }

    public function isSuccess(): bool { return $this->success; }

    public function getResponse(): ?object
    {
        if ($this->status === null) {
            return null;
        }

        return new class ($this->status) {
            public function __construct(private int $status) {}
            public function getStatusCode(): int { return $this->status; }
        };
    }
}

/** A notification that has a push representation. */
class PushableNotification implements NotificationInterface
{
    public function __construct(private array $data = ['title' => 'Νέο μήνυμα', 'body' => 'Γεια']) {}

    public function via(mixed $notifiable): array { return ['push']; }

    public function toPush(mixed $notifiable): array { return $this->data; }
}

/** Exposes the three decisions that are protected on the channel itself. */
class PushChannelProbe extends PushChannel
{
    public function probePayload(array $data): string { return $this->payload($data); }

    public function probeStatus(object $report): int { return $this->statusOf($report); }

    public function probeUserId(mixed $notifiable): ?int { return $this->resolveUserId($notifiable); }

    public function probeLibraryClass(): string { return $this->libraryClass(); }

    public function probeSubscriptionClass(): string { return $this->subscriptionClass(); }

    public function probeVapid(): ?array { return $this->vapid(); }
}
