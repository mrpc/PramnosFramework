<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\EventsStatus;
use Pramnos\Event\Event;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;
use Pramnos\Event\Transport\Envelope;
use Pramnos\Event\Transport\EventTransport;
use Pramnos\Event\Transport\QueueTransport;
use Pramnos\Event\Transport\Transports;
use Pramnos\Queue\QueueManager;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A queued listener that does nothing: only where its events go is under test.
 */
final class QuietQueuedListener extends QueuedListener
{
    /** Nothing to do. */
    public function handle(mixed ...$args): mixed
    {
        return true;
    }
}

/**
 * A second one, to be set apart from the first.
 */
final class OtherQueuedListener extends QueuedListener
{
    /** Nothing to do. */
    public function handle(mixed ...$args): mixed
    {
        return true;
    }
}

/**
 * A transport that keeps what it is given, and can say it cannot carry events.
 */
final class KeepingTransport implements EventTransport
{
    /** @var list<array{0: Envelope, 1: string}> What was delivered, to whom */
    public array $delivered = [];

    /** @param bool $available Whether it can carry events here */
    public function __construct(private bool $available = true)
    {
    }

    /** Its name in `events.transport`. */
    public function name(): string
    {
        return 'keeping';
    }

    /** Whether it can carry events. */
    public function available(): bool
    {
        return $this->available;
    }

    /** Keep the envelope; answer its id. */
    public function deliver(Envelope $envelope, QueuedListener|string $listener): int|string|null
    {
        $this->delivered[] = [$envelope, is_string($listener) ? $listener : $listener::class];

        return 'entry:' . $envelope->id();
    }
}

/**
 * Event::fire() hands each queued listener to the transport it is set to use: the queue by
 * default; the installation's choice, or a listener's own; and the queue where the one chosen is
 * unknown or cannot carry events — an event is never lost to a setting. events:status shows the
 * choice for every listener without firing anything.
 */
#[CoversClass(Event::class)]
#[CoversClass(Envelope::class)]
#[CoversClass(QueueTransport::class)]
#[CoversClass(Transports::class)]
#[CoversClass(EventsStatus::class)]
final class EventTransportsTest extends TestCase
{
    /** @var list<array<string, mixed>> Every addKeyed() call the stub queue received */
    private array $written = [];

    /** A stub queue answering ids from 100, and no events settings. */
    protected function setUp(): void
    {
        Event::forget();
        QueuedEvents::reset();
        Transports::reset();
        $this->written = [];
        $queue = $this->getMockBuilder(QueueManager::class)->disableOriginalConstructor()
            ->onlyMethods(['addKeyed', 'hasEventColumns'])->getMock();
        $queue->method('hasEventColumns')->willReturn(true);
        $queue->method('addKeyed')->willReturnCallback(function (string $type, mixed $data): int {
            $this->written[] = $data;

            return 99 + count($this->written);
        });
        QueuedEvents::$queue = $queue;
        unset(Application::getInstance()->applicationInfo['events']);
    }

    /** Leave nothing behind for the next test. */
    protected function tearDown(): void
    {
        Event::forget();
        QueuedEvents::reset();
        QueuedEvents::$queue = null;
        Transports::reset();
        unset(Application::getInstance()->applicationInfo['events']);
    }

    /** Set the application's `events` settings. @param array<string, mixed> $events */
    private function settings(array $events): void
    {
        Application::getInstance()->applicationInfo['events'] = $events;
    }

    /**
     * With nothing set, every queued listener goes by the queue: a task each, its id in the
     * results — what every installation did before transports.
     */
    public function testTheQueueIsTheDefault(): void
    {
        // Arrange
        Event::listen('job.created', QuietQueuedListener::class);
        Event::listen('job.created', OtherQueuedListener::class);

        // Act
        $results = Event::fire('job.created', 7);

        // Assert
        $this->assertSame([100, 101], $results);
        $this->assertCount(2, $this->written, 'one task per listener');
        $this->assertSame($this->written[0]['meta']['eventId'], $this->written[1]['meta']['eventId'], 'one firing, one id');
        $this->assertSame('queue', Transports::configured(QuietQueuedListener::class));
        $this->assertInstanceOf(QueueTransport::class, Transports::for(QuietQueuedListener::class));
    }

    /**
     * The installation's transport carries every listener that has none of its own; a listener
     * named apart keeps the queue. Both see the same envelope: one firing, one id, the arguments
     * as given.
     */
    public function testTheInstallationsChoiceAndAListenersOwn(): void
    {
        // Arrange
        $keeping = new KeepingTransport();
        Transports::register($keeping);
        $this->settings(['transport' => 'keeping', 'listeners' => [OtherQueuedListener::class => 'queue']]);
        Event::listen('job.created', QuietQueuedListener::class);
        Event::listen('job.created', new OtherQueuedListener());

        // Act
        $results = Event::fire('job.created', 7, 'x');

        // Assert
        $envelope = $keeping->delivered[0][0];
        $this->assertSame(['entry:' . $envelope->id(), 100], $results);
        $this->assertSame(QuietQueuedListener::class, $keeping->delivered[0][1]);
        $this->assertSame(['job.created', [7, 'x']], [$envelope->event, $envelope->args]);
        $this->assertSame($envelope->id(), $this->written[0]['meta']['eventId'], 'the queued one carries the same firing');
        $this->assertSame(['keeping', 'queue'], [Transports::configured(QuietQueuedListener::class), Transports::configured(OtherQueuedListener::class)]);
    }

    /**
     * A transport that is not known here, or cannot carry events, is passed over for the queue —
     * the event still reaches its listener.
     */
    public function testAnUnknownOrUnavailableTransportFallsBackToTheQueue(): void
    {
        // Arrange
        Event::listen('job.created', QuietQueuedListener::class);
        $this->settings(['transport' => 'stream']);

        // Act
        $unknown = Event::fire('job.created', 1);
        Transports::register(new KeepingTransport(false));
        $this->settings(['transport' => 'keeping']);
        $unavailable = Event::fire('job.created', 2);

        // Assert
        $this->assertSame([100], $unknown);
        $this->assertSame([101], $unavailable);
        $this->assertSame('keeping', Transports::configured(QuietQueuedListener::class), 'what it is set to stays what it was set to');
        $this->assertSame('queue', Transports::for(QuietQueuedListener::class)->name());
    }

    /**
     * events:status lists every queued listener with what it is set to and what it would go by,
     * as a table or as JSON, without firing anything; with none registered, it says so.
     */
    public function testStatusShowsEachListenersTransport(): void
    {
        // Arrange
        Transports::register(new KeepingTransport(false));
        $this->settings(['transport' => 'queue', 'listeners' => [OtherQueuedListener::class => 'keeping']]);
        $tester = new CommandTester(new EventsStatus());
        $empty  = (clone $tester);
        $empty->execute([]);
        Event::listen('job.created', QuietQueuedListener::class);
        Event::listen('job.created', OtherQueuedListener::class);
        Event::listen('job.created', static fn (): bool => true);

        // Act
        $tester->execute([]);
        $table = $tester->getDisplay();
        $tester->execute(['--json' => true]);
        $json = json_decode($tester->getDisplay(), true);

        // Assert
        $this->assertStringContainsString('No queued listeners are registered.', $empty->getDisplay());
        $this->assertStringContainsString('keeping (cannot carry events here)', $table);
        $this->assertStringContainsString('queue (instead)', $table, 'set to keeping, going by the queue');
        $this->assertSame([
            ['event' => 'job.created', 'listener' => QuietQueuedListener::class, 'configured' => 'queue', 'effective' => 'queue'],
            ['event' => 'job.created', 'listener' => OtherQueuedListener::class, 'configured' => 'keeping', 'effective' => 'queue'],
        ], $json['listeners'], 'a plain closure is not queued and not listed');
        $this->assertSame(['queue' => true, 'keeping' => false], $json['transports']);
        $this->assertSame([], $this->written, 'nothing was fired');
    }
}
