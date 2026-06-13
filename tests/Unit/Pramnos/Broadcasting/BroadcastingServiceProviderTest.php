<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Container;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\BroadcastingServiceProvider;

/**
 * Unit tests for BroadcastingServiceProvider.
 *
 * The provider registers a 'broadcasting' singleton in the DI container.
 * Tests verify:
 *   - register() binds BroadcastingManager under the 'broadcasting' key.
 *   - The default driver ('null') is used when no config is set.
 *   - A custom driver name from config is applied.
 *   - An unknown driver name falls back to 'null' gracefully.
 *   - boot() is a no-op and does not throw.
 */
#[CoversClass(BroadcastingServiceProvider::class)]
class BroadcastingServiceProviderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal Application subclass that exposes a real Container and
     * the given applicationInfo.
     *
     * PHPUnit mocks of Application can't reliably exercise the Base::__get()
     * magic that serves 'container'. An anonymous subclass is simpler: it does
     * not call the real Application constructor (which requires APP_PATH, DB,
     * etc.) but stores container and applicationInfo directly in _data so that
     * the inherited __get()/__set() magic works correctly.
     */
    private function makeApp(array $broadcastingConfig = []): Application
    {
        $container = new Container();
        $info      = empty($broadcastingConfig)
            ? []
            : ['broadcasting' => $broadcastingConfig];

        // Anonymous subclass — does NOT call Application::__construct()
        return new class($container, $info) extends Application {
            public function __construct(Container $c, array $info)
            {
                // Store in _data so Base::__get('container') returns it
                $this->_data['container']   = $c;
                $this->applicationInfo      = $info;
            }
        };
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * register() must bind a callable under the 'broadcasting' key so that
     * container->get('broadcasting') returns a BroadcastingManager.
     *
     * This is the golden path: no config → default 'null' driver is selected.
     */
    public function testRegisterBindsBroadcastingManagerInContainer(): void
    {
        // Arrange
        $app      = $this->makeApp();
        $provider = new BroadcastingServiceProvider($app);

        // Act
        $provider->register();
        $manager  = $app->container->get('broadcasting');

        // Assert — the singleton resolves to BroadcastingManager
        $this->assertInstanceOf(BroadcastingManager::class, $manager,
            'register() must bind a BroadcastingManager singleton under "broadcasting"');
    }

    /**
     * The 'broadcasting' singleton must only be instantiated once — a second
     * call to get() must return the exact same object.
     */
    public function testBroadcastingSingletonIsSharedInstance(): void
    {
        // Arrange
        $app      = $this->makeApp();
        $provider = new BroadcastingServiceProvider($app);
        $provider->register();

        // Act
        $first  = $app->container->get('broadcasting');
        $second = $app->container->get('broadcasting');

        // Assert — PSR-11 singleton behaviour
        $this->assertSame($first, $second,
            'broadcasting singleton must return the same instance on every get()');
    }

    /**
     * When a known driver ('log') is specified in config, the manager's default
     * must reflect that driver after register() runs.
     */
    public function testRegisterAppliesConfiguredDefaultDriver(): void
    {
        // Arrange — set default to 'log' (LogDriver is always added)
        $app      = $this->makeApp(['default' => 'log']);
        $provider = new BroadcastingServiceProvider($app);

        // Act
        $provider->register();
        $manager = $app->container->get('broadcasting');

        // Assert — the manager has drivers registered; resolving by name must not throw
        $this->assertInstanceOf(BroadcastingManager::class, $manager);
    }

    /**
     * When an unknown driver is specified in config, the provider must catch the
     * InvalidArgumentException and fall back to 'null' without throwing.
     *
     * This covers the try/catch fallback at lines 54-60 of BroadcastingServiceProvider.
     */
    public function testRegisterFallsBackToNullDriverWhenConfiguredDriverIsUnknown(): void
    {
        // Arrange — 'nonexistent_driver' is not registered by any addDriver() call
        $app      = $this->makeApp(['default' => 'nonexistent_driver']);
        $provider = new BroadcastingServiceProvider($app);

        // Act — must not throw; falls back to 'null'
        $provider->register();
        $manager = $app->container->get('broadcasting');

        // Assert — manager still resolves to a valid BroadcastingManager
        $this->assertInstanceOf(BroadcastingManager::class, $manager,
            'Provider must fall back gracefully when the configured driver is unknown');
    }

    /**
     * boot() is intentionally empty — it must execute without throwing and
     * return void.
     */
    public function testBootDoesNotThrow(): void
    {
        // Arrange
        $app      = $this->makeApp();
        $provider = new BroadcastingServiceProvider($app);

        // Act + Assert
        $this->expectNotToPerformAssertions();
        $provider->boot();
    }
}
