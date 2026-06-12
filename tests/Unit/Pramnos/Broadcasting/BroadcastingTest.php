<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\BroadcastingServiceProvider;
use Pramnos\Broadcasting\Broadcastable;
use Pramnos\Broadcasting\Drivers\LogDriver;
use Pramnos\Broadcasting\Drivers\NullDriver;
use Pramnos\Broadcasting\Drivers\PusherDriver;

class DummyModel
{
    use Broadcastable;

    public function toArray(): array
    {
        return ['id' => 123, 'name' => 'Test'];
    }
}

// DummyApp removed to use real Application singleton

#[CoversClass(BroadcastingManager::class)]
#[CoversClass(NullDriver::class)]
#[CoversClass(LogDriver::class)]
#[CoversClass(PusherDriver::class)]
#[CoversClass(BroadcastingServiceProvider::class)]
#[CoversClass(Broadcastable::class)]
class TestBroadcastingApp extends \Pramnos\Application\Application
{
    public $applicationInfo = [];
    public $container;
    public function __construct()
    {
        $this->container = new class {
            private array $bindings = [];
            public function singleton(string $name, callable $resolver)
            {
                $this->bindings[$name] = $resolver();
            }
            public function get(string $name)
            {
                return $this->bindings[$name] ?? null;
            }
            public function has(string $name): bool
            {
                return isset($this->bindings[$name]);
            }
        };
    }
}

class BroadcastingTest extends TestCase
{
    private string $tempLogPath;
    private array $originalInstances = [];
    private $originalLastUsed = null;

    protected function setUp(): void
    {
        $this->tempLogPath = sys_get_temp_dir() . '/broadcasting_test_' . bin2hex(random_bytes(4)) . '.log';
        
        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $this->originalInstances = $ref->getValue();
        $instances = $this->originalInstances;
        $instances['default'] = new TestBroadcastingApp();
        $ref->setValue(null, $instances);

        $refLast = new \ReflectionProperty(\Pramnos\Application\Application::class, 'lastUsedApplication');
        $this->originalLastUsed = $refLast->getValue();
        $refLast->setValue(null, 'default');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLogPath)) {
            unlink($this->tempLogPath);
        }

        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $ref->setValue(null, $this->originalInstances);

        $refLast = new \ReflectionProperty(\Pramnos\Application\Application::class, 'lastUsedApplication');
        $refLast->setValue(null, $this->originalLastUsed);
    }

    public function testNullDriverNameAndBroadcast(): void
    {
        $driver = new NullDriver();
        $this->assertSame('null', $driver->name());
        
        // This should run without throwing exceptions
        $driver->broadcast('test-channel', 'test-event', ['foo' => 'bar']);
    }

    public function testLogDriverBroadcastAndReading(): void
    {
        $driver = new LogDriver($this->tempLogPath);
        $this->assertSame('log', $driver->name());
        $this->assertSame($this->tempLogPath, $driver->getLogPath());

        $this->assertEmpty($driver->getEntries());

        $payload = ['foo' => 'bar'];
        $driver->broadcast('test-channel', 'test-event', $payload);

        $entries = $driver->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('test-channel', $entries[0]['channel']);
        $this->assertSame('test-event', $entries[0]['event']);
        $this->assertSame($payload, $entries[0]['payload']);

        $driver->clear();
        $this->assertEmpty($driver->getEntries());
    }

    public function testBroadcastingManagerRegistrationAndDefault(): void
    {
        $manager = new BroadcastingManager();
        $this->assertContains('null', $manager->getDriverNames());

        $logDriver = new LogDriver($this->tempLogPath);
        $manager->addDriver($logDriver);
        $this->assertContains('log', $manager->getDriverNames());

        $manager->setDefault('log');
        $this->assertSame($logDriver, $manager->driver());

        $this->expectException(\InvalidArgumentException::class);
        $manager->setDefault('non-existent');
    }

    public function testBroadcastingViaAndBroadcast(): void
    {
        $manager = new BroadcastingManager();
        $logDriver = new LogDriver($this->tempLogPath);
        $manager->addDriver($logDriver);
        
        $manager->via('log', 'channel-via', 'event-via', ['data' => 1]);
        $entries = $logDriver->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('channel-via', $entries[0]['channel']);
    }

    public function testPusherDriverConstructionWithPusherInstalled(): void
    {
        $driver = new PusherDriver(['app_id' => '123', 'app_key' => 'key', 'app_secret' => 'secret']);
        $this->assertSame('pusher', $driver->name());
    }

    public function testBroadcastableTrait(): void
    {
        $app = \Pramnos\Application\Application::getInstance();
        $manager = new BroadcastingManager();
        $logDriver = new LogDriver($this->tempLogPath);
        $manager->addDriver($logDriver);
        $manager->setDefault('log');

        $app->container->singleton('broadcasting', fn() => $manager);

        $model = new DummyModel();
        
        // Test auto snake-case model name
        $model->broadcastCreated();
        $model->broadcastUpdated();
        $model->broadcastDeleted();

        $entries = $logDriver->getEntries();
        $this->assertCount(3, $entries);
        
        $this->assertSame('dummy_model.created', $entries[0]['event']);
        $this->assertSame('dummy_model.updated', $entries[1]['event']);
        $this->assertSame('dummy_model.deleted', $entries[2]['event']);
    }

    /**
     * broadcastCreated/Updated/Deleted must silently do nothing when the
     * application container has no 'broadcasting' binding.
     * Covers resolveBroadcastingManager() returning null and the early-return
     * branch inside broadcastEvent() at lines 99-101.
     */
    public function testBroadcastableDoesNothingWhenManagerNotRegistered(): void
    {
        // Arrange — app container has no 'broadcasting' binding
        $app = \Pramnos\Application\Application::getInstance();
        // Ensure 'broadcasting' is absent (fresh TestBroadcastingApp has empty bindings)
        $model = new DummyModel();

        // Act + Assert — none of these must throw
        $model->broadcastCreated();
        $model->broadcastUpdated();
        $model->broadcastDeleted();

        // If we reach here without exceptions, the null manager path is correct
        $this->assertTrue(true, 'No exception expected when broadcasting is not registered');
    }

    /**
     * When a model using Broadcastable does NOT implement toArray(), broadcastEvent()
     * must use an empty array as payload base (the ternary `[] else` branch at line 108).
     * The manager still receives the broadcast — only _model and $extra appear in payload.
     */
    public function testBroadcastableUsesEmptyArrayPayloadWhenModelLacksToArray(): void
    {
        // Arrange — model WITHOUT toArray()
        $modelWithoutToArray = new class {
            use Broadcastable;
        };

        $app = \Pramnos\Application\Application::getInstance();
        $manager = new BroadcastingManager();
        $logDriver = new LogDriver($this->tempLogPath);
        $manager->addDriver($logDriver);
        $manager->setDefault('log');
        $app->container->singleton('broadcasting', fn() => $manager);

        // Act
        $modelWithoutToArray->broadcastEvent('ping', ['extra_key' => 'val']);

        // Assert — payload contains only _model and extra_key (no model fields)
        $entries = $logDriver->getEntries();
        $this->assertCount(1, $entries);
        $this->assertArrayHasKey('_model', $entries[0]['payload'],
            '_model key must always be present in broadcast payload');
        $this->assertSame('val', $entries[0]['payload']['extra_key'],
            '$extra keys must appear in the payload');
        $this->assertArrayNotHasKey('id', $entries[0]['payload'],
            'payload must NOT contain id when model has no toArray()');
    }

    /**
     * broadcastEvent() with an explicit broadcastChannel set must use that
     * channel name instead of the auto-derived snake_case model name.
     * Covers the `$this->broadcastChannel ?: $shortName` ternary true branch.
     */
    public function testBroadcastEventUsesExplicitChannelWhenSet(): void
    {
        // Arrange
        $app = \Pramnos\Application\Application::getInstance();
        $manager = new BroadcastingManager();
        $logDriver = new LogDriver($this->tempLogPath);
        $manager->addDriver($logDriver);
        $manager->setDefault('log');
        $app->container->singleton('broadcasting', fn() => $manager);

        $model = new DummyModel();
        // Force a specific channel name via reflection
        $ref = new \ReflectionProperty($model, 'broadcastChannel');
        $ref->setValue($model, 'my_custom_channel');

        // Act
        $model->broadcastCreated();

        // Assert — the explicit channel was used, not the class-derived name
        $entries = $logDriver->getEntries();
        $this->assertSame('my_custom_channel', $entries[0]['channel'],
            'broadcastChannel property must override the auto-derived model name');
    }

    /**
     * broadcastCreated/Updated/Deleted must not broadcast when the respective
     * broadcastOn* flag is false. Covers the false branch of each guard if.
     */
    public function testBroadcastableDoesNotBroadcastWhenFlagIsFalse(): void
    {
        // Arrange
        $app = \Pramnos\Application\Application::getInstance();
        $manager = new BroadcastingManager();
        $logDriver = new LogDriver($this->tempLogPath);
        $manager->addDriver($logDriver);
        $manager->setDefault('log');
        $app->container->singleton('broadcasting', fn() => $manager);

        $model = new DummyModel();
        $ref = new \ReflectionClass($model);
        $ref->getProperty('broadcastOnCreate')->setValue($model, false);
        $ref->getProperty('broadcastOnUpdate')->setValue($model, false);
        $ref->getProperty('broadcastOnDelete')->setValue($model, false);

        // Act — none of these should fire a broadcast
        $model->broadcastCreated();
        $model->broadcastUpdated();
        $model->broadcastDeleted();

        // Assert — log driver received no entries
        $this->assertEmpty($logDriver->getEntries(),
            'No events must be broadcast when all broadcastOn* flags are false');
    }

    public function testBroadcastingServiceProviderRegistration(): void
    {
        $app = \Pramnos\Application\Application::getInstance();
        $originalInfo = $app->applicationInfo;
        $app->applicationInfo['broadcasting'] = [
            'default' => 'log',
            'log_path' => $this->tempLogPath,
        ];

        $provider = new BroadcastingServiceProvider($app);
        $provider->register();

        $this->assertTrue($app->container->has('broadcasting'));
        $manager = $app->container->get('broadcasting');
        $this->assertInstanceOf(BroadcastingManager::class, $manager);
        $this->assertSame('log', $manager->driver()->name());

        $app->applicationInfo = $originalInfo;
    }
}
