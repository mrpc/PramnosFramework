<?php

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\ServiceProvider;

// ---------------------------------------------------------------------------
// Test helpers: concrete providers outside the test class
// ---------------------------------------------------------------------------

/**
 * Concrete ServiceProvider that records lifecycle calls into a shared
 * static log — cleared between tests via clearLog().
 */
class RecordingServiceProvider extends ServiceProvider
{
    /** @var string[] */
    public static array $log = [];

    public static function clearLog(): void
    {
        self::$log = [];
    }

    public function register(): void
    {
        self::$log[] = 'register';
    }

    public function boot(): void
    {
        self::$log[] = 'boot';
    }
}

/**
 * Concrete ServiceProvider with no overrides — validates that the default
 * no-op implementations compile and run without throwing.
 */
class SilentServiceProvider extends ServiceProvider {}

// ---------------------------------------------------------------------------
// Test case
// ---------------------------------------------------------------------------

/**
 * Unit tests for the ServiceProvider abstract class.
 *
 * Application::init() requires a live database connection, so these tests
 * exercise the lifecycle contract and FeatureRegistry integration in isolation
 * via mocks and direct provider instantiation.
 */
class ServiceProviderUnitTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::reset();
        RecordingServiceProvider::clearLog();
    }

    // =========================================================================
    // ServiceProvider contract
    // =========================================================================

    /**
     * ServiceProvider must be abstract — it cannot be instantiated directly.
     * Only concrete subclasses that extend it are valid providers.
     */
    public function testServiceProviderIsAbstract(): void
    {
        // Arrange & Act
        $ref = new \ReflectionClass(ServiceProvider::class);

        // Assert
        $this->assertTrue($ref->isAbstract(), 'ServiceProvider must be abstract');
    }

    /**
     * The default register() and boot() implementations are empty no-ops.
     * Subclasses that only use one phase must not be forced to implement both,
     * and calling the defaults must never throw.
     */
    public function testDefaultRegisterAndBootAreNoOps(): void
    {
        // Arrange
        $provider = new SilentServiceProvider($this->makeStubApp());

        // Act & Assert — neither method throws
        $provider->register();
        $provider->boot();
        $this->assertTrue(true);
    }

    /**
     * The Application instance passed to the constructor must be accessible as
     * $this->app inside both register() and boot(), allowing providers to
     * interact with the application object.
     */
    public function testAppPropertyIsAccessibleInsideProvider(): void
    {
        // Arrange
        $app = $this->makeStubApp();

        $provider = new class($app) extends ServiceProvider {
            public ?\Pramnos\Application\Application $capturedApp = null;

            public function register(): void
            {
                $this->capturedApp = $this->app;
            }
        };

        // Act
        $provider->register();

        // Assert — $this->app inside register() is the instance we passed in
        $this->assertSame($app, $provider->capturedApp);
    }

    // =========================================================================
    // Bootstrap order invariant
    // =========================================================================

    /**
     * The two-phase contract: ALL providers' register() must complete before
     * ANY provider's boot() runs.  This allows a provider to rely in its
     * boot() on bindings that another provider set up in register().
     *
     * The test simulates the loop inside Application::bootServiceProviders().
     */
    public function testAllRegisterCallsBeforeAnyBootCall(): void
    {
        // Arrange — use ArrayObject as a shared mutable log (objects are
        // always passed by identity, so there is no need for PHP references)
        $log = new \ArrayObject();
        $app = $this->makeStubApp();

        $makeProvider = function (string $name) use ($app, $log): ServiceProvider {
            return new class($app, $name, $log) extends ServiceProvider {
                private string $n;
                private \ArrayObject $log;

                public function __construct($a, string $n, \ArrayObject $log)
                {
                    parent::__construct($a);
                    $this->n   = $n;
                    $this->log = $log;
                }

                public function register(): void { $this->log[] = "register:{$this->n}"; }
                public function boot(): void     { $this->log[] = "boot:{$this->n}";     }
            };
        };

        $providers = [$makeProvider('A'), $makeProvider('B'), $makeProvider('C')];

        // Act — replicate the Application::bootServiceProviders() loop
        foreach ($providers as $p) { $p->register(); }
        foreach ($providers as $p) { $p->boot(); }

        // Assert — all three register() calls, then all three boot() calls
        $this->assertSame(
            ['register:A', 'register:B', 'register:C', 'boot:A', 'boot:B', 'boot:C'],
            $log->getArrayCopy(),
            'register() for all providers must precede boot() for any provider'
        );
    }

    // =========================================================================
    // FeatureRegistry provider lookup
    // =========================================================================

    /**
     * getProvider() must return null for a feature that was registered without
     * a 'provider' key — the expected state for all built-in features during
     * Phase 4 before Phase 2 backports are wired up.  The bootstrap loop skips
     * null providers silently.
     */
    public function testNullProviderIsSkippedSilently(): void
    {
        // Arrange
        FeatureRegistry::register('headless_feature'); // no provider key

        // Act
        $provider = FeatureRegistry::getProvider('headless_feature');

        // Assert
        $this->assertNull($provider);
        // Confirm that the skip condition used in bootServiceProviders() holds
        $this->assertTrue($provider === null || !class_exists((string) $provider));
    }

    /**
     * When a feature is registered with a concrete ServiceProvider FQCN,
     * getProvider() must return that FQCN and the class must be instantiable
     * so that the bootstrap loop can call register() and boot() on it.
     */
    public function testProviderFqcnCanBeInstantiatedAndBooted(): void
    {
        // Arrange
        FeatureRegistry::register('recording_feature', [
            'provider' => RecordingServiceProvider::class,
        ]);
        FeatureRegistry::loadFromConfig(['recording_feature']);

        $app = $this->makeStubApp();

        // Act — simulate one iteration of Application::bootServiceProviders()
        $class = FeatureRegistry::getProvider('recording_feature');
        $this->assertSame(RecordingServiceProvider::class, $class);
        $this->assertTrue(class_exists($class));

        $provider = new $class($app);
        $provider->register();
        $provider->boot();

        // Assert — both lifecycle methods ran
        $this->assertSame(['register', 'boot'], RecordingServiceProvider::$log);
    }

    /**
     * Providers added manually (without a feature key) must be bootstrapped in
     * the same two-phase order as feature-registry providers.  This test
     * simulates Application::addProvider() by directly adding to the providers
     * array and running the bootstrap loop.
     */
    public function testManuallyAddedProviderIsBooted(): void
    {
        // Arrange
        $app      = $this->makeStubApp();
        $provider = new RecordingServiceProvider($app);

        // Act — simulate bootServiceProviders() with one manually-added provider
        $providers = [$provider];
        foreach ($providers as $p) { $p->register(); }
        foreach ($providers as $p) { $p->boot(); }

        // Assert
        $this->assertSame(['register', 'boot'], RecordingServiceProvider::$log);
    }

    /**
     * With multiple providers, each must receive exactly one register() call
     * and one boot() call, in phase order (all register first, then all boot).
     */
    public function testMultipleProvidersBootInPhaseOrder(): void
    {
        // Arrange
        $log = new \ArrayObject();
        $app = $this->makeStubApp();

        $makeProvider = function (string $name) use ($app, $log): ServiceProvider {
            return new class($app, $name, $log) extends ServiceProvider {
                private string $n;
                private \ArrayObject $log;

                public function __construct($a, string $n, \ArrayObject $l)
                {
                    parent::__construct($a);
                    $this->n   = $n;
                    $this->log = $l;
                }

                public function register(): void { $this->log[] = "register:{$this->n}"; }
                public function boot(): void     { $this->log[] = "boot:{$this->n}"; }
            };
        };

        $providers = [$makeProvider('A'), $makeProvider('B')];

        // Act
        foreach ($providers as $p) { $p->register(); }
        foreach ($providers as $p) { $p->boot(); }

        // Assert
        $this->assertSame(
            ['register:A', 'register:B', 'boot:A', 'boot:B'],
            $log->getArrayCopy()
        );
    }

    // =========================================================================
    // Class structure
    // =========================================================================

    /**
     * The $app property must be protected (accessible to subclasses) and typed
     * as Application so providers can call application methods without casting.
     */
    public function testAppPropertyIsProtectedAndTypedAsApplication(): void
    {
        // Arrange
        $ref      = new \ReflectionClass(ServiceProvider::class);
        $property = $ref->getProperty('app');

        // Assert — protected visibility
        $this->assertTrue(
            $property->isProtected(),
            '$app must be protected so subclasses can read it'
        );

        // Assert — typed as Application
        $type = $property->getType();
        $this->assertNotNull($type, '$app must be typed');
        $this->assertSame(\Pramnos\Application\Application::class, $type->getName());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Returns a mock Application that satisfies the ServiceProvider constructor
     * type hint without requiring a database connection.
     */
    private function makeStubApp(): \Pramnos\Application\Application
    {
        return $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
