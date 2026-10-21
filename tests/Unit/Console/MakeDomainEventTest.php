<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Application as ConsoleApplication;
use Pramnos\Console\Commands\Make\MakeEvent;
use Pramnos\Console\Commands\Make\MakeListener;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Event\Event;
use Pramnos\Event\ListenerInterface;
use Pramnos\Event\QueueableEvent;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;
use Pramnos\Queue\QueueManager;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * create:event and create:listener write code that works: the generated event dispatches, a
 * generated typed listener takes it in the request, and a generated queued listener takes it
 * built again in the worker.
 *
 * The commands are run with their file writing replaced by returning the source, which is then
 * written under the system's temporary directory and loaded — never into the project.
 */
#[CoversClass(MakeCommandBase::class)]
#[CoversClass(MakeListener::class)]
final class MakeDomainEventTest extends TestCase
{
    /** A namespace of this test's own, so generated classes never collide. */
    private string $namespace;

    /** @var list<string> Files to remove */
    private array $files = [];

    /** @var list<array<string, mixed>> Every addKeyed() call the stub queue received */
    private array $written = [];

    /** A unique namespace, a clean bus, and a stub queue. */
    protected function setUp(): void
    {
        $this->namespace = 'GenApp' . bin2hex(random_bytes(4));
        Event::forget();
        QueuedEvents::reset();
        $this->written = [];

        $queue = $this->getMockBuilder(QueueManager::class)->disableOriginalConstructor()
            ->onlyMethods(['addKeyed', 'hasEventColumns'])->getMock();
        $queue->method('hasEventColumns')->willReturn(true);
        $queue->method('addKeyed')->willReturnCallback(function (string $type, mixed $data): int {
            $this->written[] = $data;

            return 99 + count($this->written);
        });
        QueuedEvents::$queue = $queue;
    }

    /** Remove the generated files and reset the bus. */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        Event::forget();
        QueuedEvents::reset();
    }

    /**
     * Run a generator command whose create method answers with the source instead of writing.
     *
     * @param array<string, mixed> $input
     */
    private function generate(string $command, array $input): string
    {
        $namespace = $this->namespace;
        $console   = new class extends ConsoleApplication {
            /** Only the commands added below. */
            protected function registerCommands(): void
            {
            }
        };
        $console->add($command === 'create:event'
            ? new class ($namespace) extends MakeEvent {
                /** @param string $app The application's namespace */
                public function __construct(private string $app)
                {
                    parent::__construct();
                }

                /** The source, not a file. */
                public function createEvent(string $eventName): string
                {
                    return $this->eventSource($this->app . '\\Events', $eventName);
                }
            }
            : new class ($namespace) extends MakeListener {
                /** @param string $app The application's namespace */
                public function __construct(private string $app)
                {
                    parent::__construct();
                }

                /** The source, not a file. */
                public function createListener(string $listenerName): string
                {
                    return $this->listenerSource($this->app . '\\Listeners', $listenerName, $this->app);
                }
            });
        $tester = new CommandTester($console->find($command));
        $tester->execute($input);

        return $tester->getDisplay();
    }

    /** Write generated source to a temporary file and load it. */
    private function load(string $source): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gen') . '.php';
        file_put_contents($file, $source);
        $this->files[] = $file;
        require $file;
    }

    /**
     * (e) The generated event is named from its class, carries a readonly payload, travels as
     * its id, and comes back from fromQueue() as the same class.
     */
    public function testTheGeneratedEventHasItsNameAndTravels(): void
    {
        // Arrange
        $source = $this->generate('create:event', ['name' => 'JobPostCreated']);

        // Act
        $this->load($source);
        $class = $this->namespace . '\\Events\\JobPostCreated';
        $event = new $class(12);
        $again = $class::fromQueue(json_decode((string) json_encode($event), true));

        // Assert
        $this->assertSame('jobpost.created', $class::EVENT);
        $this->assertInstanceOf(QueueableEvent::class, $event);
        $this->assertInstanceOf($class, $again);
        $this->assertSame(12, $again->id);
        $this->assertTrue((new \ReflectionProperty($class, 'id'))->isReadOnly());
    }

    /**
     * (e) A one-word event is named by the word alone.
     */
    public function testAOneWordEventIsNamedByTheWord(): void
    {
        // Act
        $this->load($this->generate('create:event', ['name' => 'Rebooted']));

        // Assert
        $this->assertSame('rebooted', constant($this->namespace . '\\Events\\Rebooted::EVENT'));
    }

    /**
     * (e) and (a) A listener generated with --event takes that event in handle(), and dispatch()
     * calls it with the instance — it loads, so its handle() is accepted although it is typed.
     * Without options it is the plain ListenerInterface listener it always was.
     */
    public function testAGeneratedTypedListenerRunsInTheRequest(): void
    {
        // Arrange
        $this->load($this->generate('create:event', ['name' => 'OrderPaid']));
        $typed = $this->generate('create:listener', ['name' => 'SendReceipt', '--event' => 'OrderPaid']);
        $plain = $this->generate('create:listener', ['name' => 'Audit']);
        $this->load($typed);
        $this->load($plain);
        $event = $this->namespace . '\\Events\\OrderPaid';
        Event::listen($event::EVENT, $this->namespace . '\\Listeners\\SendReceipt');

        // Act
        $results = Event::dispatch(new $event(5));

        // Assert
        $this->assertSame([null], $results);
        $this->assertStringContainsString('public function handle(OrderPaid $event): mixed', $typed);
        $this->assertStringContainsString('use ' . $event . ';', $typed);
        $this->assertTrue(is_a($this->namespace . '\\Listeners\\Audit', ListenerInterface::class, true));
    }

    /**
     * (e) and (b) A listener generated with --queued and --event extends QueuedListener: dispatch
     * queues it, and the worker's run hands its typed handle() the event built again — were it
     * handed the task's array, the typed parameter would throw.
     */
    public function testAGeneratedQueuedListenerRunsInTheWorker(): void
    {
        // Arrange
        $this->load($this->generate('create:event', ['name' => 'UserDeactivated']));
        $source = $this->generate('create:listener', ['name' => 'SyncProvider', '--event' => $this->namespace . '\\Events\\UserDeactivated', '--queued' => true]);
        $this->load($source);
        $event    = $this->namespace . '\\Events\\UserDeactivated';
        $listener = $this->namespace . '\\Listeners\\SyncProvider';
        Event::listen($event::EVENT, $listener);

        // Act
        $results = Event::dispatch(new $event(9));
        $message = QueuedEvents::run(json_decode((string) json_encode($this->written[0]), true), 100);

        // Assert
        $this->assertSame([100], $results);
        $this->assertTrue(is_a($listener, QueuedListener::class, true));
        $this->assertStringContainsString('Ran ' . $listener, $message);
    }

    /**
     * (e) --queued alone writes a queued listener taking the event's arguments as they come.
     */
    public function testAQueuedListenerWithoutAnEventTakesItsArguments(): void
    {
        // Act
        $source = $this->generate('create:listener', ['name' => 'Reindex', '--queued' => true]);

        // Assert
        $this->assertStringContainsString('class Reindex extends QueuedListener', $source);
        $this->assertStringContainsString('public function handle(mixed ...$args): mixed', $source);
        $this->assertStringNotContainsString('ListenerInterface', $source);
    }
}
