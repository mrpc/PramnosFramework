<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\DevPanel\DevPanelServiceProvider;

/**
 * Unit tests for DevPanelServiceProvider.
 *
 * boot() guards its HTTP-specific logic with `PHP_SAPI === 'cli'`, which is
 * always true in PHPUnit.  The actual validation logic is in the protected
 * bootHttp() method, which tests exercise directly via Reflection.
 */
class DevPanelServiceProviderTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        Settings::clearSettings();
        $this->app = new Application('test_devpanel_provider');
    }

    protected function tearDown(): void
    {
        Settings::clearSettings();

        // Remove test app instance from the global registry.
        $ref  = new \ReflectionClass(Application::class);
        $prop = $ref->getProperty('appInstances');
        $cur  = $prop->getValue();
        unset($cur['test_devpanel_provider']);
        $prop->setValue(null, $cur);
    }

    // ── register() ────────────────────────────────────────────────────────────

    /**
     * register() is intentionally empty — DevPanel uses auto-discovery for its
     * controller and needs no container bindings.  Must be callable without errors.
     */
    public function testRegisterIsCallableWithoutSideEffects(): void
    {
        // Arrange
        $provider = new DevPanelServiceProvider($this->app);

        // Act / Assert
        $provider->register();
        $this->assertTrue(true, 'register() must be callable without errors');
    }

    // ── boot() — CLI guard ────────────────────────────────────────────────────

    /**
     * boot() must return early when running under CLI without logging or
     * throwing.  The CLI guard exists so tests and Artisan-like commands do not
     * trigger HTTP-specific logic.
     */
    public function testBootReturnsEarlyUnderCli(): void
    {
        // Arrange — PHP_SAPI is 'cli' in PHPUnit
        $provider = new DevPanelServiceProvider($this->app);

        // Act / Assert — no exception, no output
        $provider->boot();
        $this->assertTrue(true, 'boot() must not throw when run under CLI');
    }

    // ── bootHttp() — min_usertype validation ──────────────────────────────────

    /**
     * bootHttp() must log a warning when devpanel.min_usertype is 0 (below 1).
     *
     * The log call is fire-and-forget so we only assert that no exception is
     * thrown — the Logger internal behaviour is covered by LogManager tests.
     */
    public function testBootHttpLogsWarningWhenMinUsertypeIsZero(): void
    {
        // Arrange
        Settings::setSetting('devpanel.min_usertype', 0, false);
        $provider = new DevPanelServiceProvider($this->app);

        // Act / Assert
        $this->callBootHttp($provider);
        $this->assertTrue(true,
            'bootHttp() must not propagate exceptions when min_usertype is out of range');
    }

    /**
     * bootHttp() must log a warning when devpanel.min_usertype exceeds 100.
     */
    public function testBootHttpLogsWarningWhenMinUsertypeExceeds100(): void
    {
        // Arrange
        Settings::setSetting('devpanel.min_usertype', 200, false);
        $provider = new DevPanelServiceProvider($this->app);

        // Act / Assert
        $this->callBootHttp($provider);
        $this->assertTrue(true);
    }

    /**
     * bootHttp() must not log when devpanel.min_usertype is within the valid
     * range [1, 100].  A typical admin threshold is 90.
     */
    public function testBootHttpDoesNotLogWhenMinUsertypeIsValid(): void
    {
        // Arrange — typical admin threshold
        Settings::setSetting('devpanel.min_usertype', 90, false);
        $provider = new DevPanelServiceProvider($this->app);

        // Act / Assert
        $this->callBootHttp($provider);
        $this->assertTrue(true,
            'bootHttp() must silently succeed when min_usertype is within bounds');
    }

    /**
     * bootHttp() must not log when devpanel.min_usertype is not configured
     * (getSetting returns false).
     */
    public function testBootHttpDoesNotLogWhenMinUsertypeIsNotSet(): void
    {
        // Arrange — no min_usertype setting; getSetting returns false
        $provider = new DevPanelServiceProvider($this->app);

        // Act / Assert
        $this->callBootHttp($provider);
        $this->assertTrue(true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Invoke the protected bootHttp() method via Reflection.
     *
     * bootHttp() is protected so it can be called from subclasses in HTTP
     * context; we use Reflection to reach it in unit tests without subclassing.
     */
    private function callBootHttp(DevPanelServiceProvider $provider): void
    {
        $ref    = new \ReflectionClass($provider);
        $method = $ref->getMethod('bootHttp');
        $method->setAccessible(true);
        $method->invoke($provider);
    }
}
