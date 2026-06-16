<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Debug\DebugBar;
use Pramnos\Debug\DebugBarServiceProvider;

/**
 * Unit tests for DebugBarServiceProvider.
 *
 * Strategy:
 * - isDebugEnabled() is private and reads env vars and Settings, so tests
 *   control its output by setting those real inputs rather than subclassing.
 * - boot() in a CLI context (PHPUnit) returns before ob_start / set_error_handler
 *   (the PHP_SAPI === 'cli' guard), so those lines are intentionally untestable
 *   as unit tests — they are covered by integration/manual tests.
 * - Tests that need DEVELOPMENT to be undefined must run in a separate process
 *   to avoid constant pollution from earlier tests in the suite.
 * - Always reset DebugBar state in tearDown to prevent singleton leakage.
 */
class DebugBarServiceProviderTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        DebugBar::reset();
        Settings::clearSettings();
        $this->app = new Application('test_debug_provider');
    }

    protected function tearDown(): void
    {
        DebugBar::reset();
        Settings::clearSettings();
        putenv('APP_DEBUG=');

        // Remove test app instance from the global registry.
        $ref  = new \ReflectionClass(Application::class);
        $prop = $ref->getProperty('appInstances');
        $cur  = $prop->getValue();
        unset($cur['test_debug_provider']);
        $prop->setValue(null, $cur);
    }

    // ── register() ────────────────────────────────────────────────────────────

    /**
     * register() is intentionally empty — DebugBar is a native singleton and
     * needs no container binding.  The method must be callable without side
     * effects or exceptions.
     */
    public function testRegisterIsCallableWithoutSideEffects(): void
    {
        // Arrange
        $provider = new DebugBarServiceProvider($this->app);

        // Act / Assert — no exception thrown
        $provider->register();
        $this->assertTrue(true, 'register() must be callable without errors');
    }

    // ── boot() — debug enabled via Settings ───────────────────────────────────

    /**
     * boot() must add core collectors to the DebugBar when debug is enabled.
     *
     * Since tests run under CLI (PHP_SAPI === 'cli'), boot() returns before
     * ob_start() and set_error_handler(), so we only assert that collectors
     * were added — the output-buffer injection is not exercised in unit tests.
     */
    public function testBootAddsCollectorsWhenDebugEnabledViaSettings(): void
    {
        // Arrange — enable debug via Settings so isDebugEnabled() returns true
        putenv('APP_DEBUG=');
        Settings::setSetting('debug', true, false);
        $provider = new DebugBarServiceProvider($this->app);

        // Act
        $provider->boot();

        // Assert — bar has received at least the core collectors
        $bar        = DebugBar::getInstance();
        $collectors = $bar->getCollectors();
        $this->assertNotEmpty($collectors,
            'boot() must register collectors when debug is enabled');

        $names = array_keys($collectors);
        $this->assertContains('timers', $names, 'TimeCollector must be registered (key: timers)');
        $this->assertContains('memory', $names, 'MemoryCollector must be registered');
        $this->assertContains('logs',   $names, 'LogCollector must be registered (key: logs)');
    }

    /**
     * boot() must add collectors when debug is enabled via APP_DEBUG env var.
     * This covers the first branch in isDebugEnabled().
     */
    public function testBootAddsCollectorsWhenDebugEnabledViaEnv(): void
    {
        // Arrange — activate debug via environment variable
        putenv('APP_DEBUG=1');
        Settings::setSetting('debug', false, false);
        $provider = new DebugBarServiceProvider($this->app);

        try {
            // Act
            $provider->boot();

            // Assert — collectors were added
            $collectors = DebugBar::getInstance()->getCollectors();
            $this->assertNotEmpty($collectors,
                'boot() must register collectors when APP_DEBUG=1');
        } finally {
            putenv('APP_DEBUG=');
        }
    }

    /**
     * boot() must return early without touching DebugBar when debug is disabled.
     *
     * This test runs in an isolated process so that the DEVELOPMENT constant
     * (defined by DevPanelControllerTest earlier in the suite) cannot pollute
     * the result of isDebugEnabled().
     *
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootDoesNothingWhenDebugIsDisabled(): void
    {
        // Arrange — all debug signals off
        putenv('APP_DEBUG=');
        // In the isolated process Settings start clean; explicitly set to false.
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('debug', false, false);
        \Pramnos\Application\Settings::setSetting('development', false, false);

        $app      = new \Pramnos\Application\Application('test_iso_provider');
        $provider = new \Pramnos\Debug\DebugBarServiceProvider($app);

        // Act
        $provider->boot();

        // Assert — DebugBar has no collectors (boot returned early)
        $bar = \Pramnos\Debug\DebugBar::getInstance();
        $this->assertEmpty($bar->getCollectors(),
            'boot() must not add collectors when debug is disabled');
    }

    // ── isDebugEnabled() — private method tested via Reflection ──────────────

    /**
     * isDebugEnabled() must return true when APP_DEBUG env var is a truthy string.
     * Environment-based debug activation is the primary mechanism for server config.
     */
    public function testIsDebugEnabledReturnsTrueWhenAppDebugEnvIsOne(): void
    {
        // Arrange
        putenv('APP_DEBUG=1');
        Settings::setSetting('debug', false, false);
        $provider = new DebugBarServiceProvider($this->app);

        try {
            // Act
            $result = $this->callIsDebugEnabled($provider);

            // Assert
            $this->assertTrue($result,
                'isDebugEnabled() must return true when APP_DEBUG=1');
        } finally {
            putenv('APP_DEBUG=');
        }
    }

    /**
     * isDebugEnabled() must return false when APP_DEBUG is "0".
     *
     * Runs in isolation so that the DEVELOPMENT constant (set by earlier tests)
     * cannot bleed into this process and cause a false positive.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsDebugEnabledReturnsFalseWhenAppDebugIsZero(): void
    {
        // Arrange
        putenv('APP_DEBUG=0');
        Settings::setSetting('debug', false, false);
        Settings::setSetting('development', false, false);
        $provider = new DebugBarServiceProvider($this->app);

        try {
            // Act
            $result = $this->callIsDebugEnabled($provider);

            // Assert
            $this->assertFalse($result,
                'isDebugEnabled() must return false when APP_DEBUG=0');
        } finally {
            putenv('APP_DEBUG=');
        }
    }

    /**
     * isDebugEnabled() must return false when APP_DEBUG is "false".
     *
     * Runs in isolation so that the DEVELOPMENT constant (set by earlier tests)
     * cannot bleed into this process and cause a false positive.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsDebugEnabledReturnsFalseWhenAppDebugIsFalseString(): void
    {
        // Arrange
        putenv('APP_DEBUG=false');
        Settings::setSetting('debug', false, false);
        Settings::setSetting('development', false, false);
        $provider = new DebugBarServiceProvider($this->app);

        try {
            // Act
            $result = $this->callIsDebugEnabled($provider);

            // Assert
            $this->assertFalse($result);
        } finally {
            putenv('APP_DEBUG=');
        }
    }

    /**
     * isDebugEnabled() must return true when Settings has debug="true" (string).
     * This covers the `$debug === 'true'` branch inside isDebugEnabled().
     */
    public function testIsDebugEnabledReturnsTrueWhenSettingsDebugIsStringTrue(): void
    {
        // Arrange — no env, Settings debug = 'true'
        putenv('APP_DEBUG=');
        Settings::setSetting('debug', 'true', false);
        $provider = new DebugBarServiceProvider($this->app);

        // Act
        $result = $this->callIsDebugEnabled($provider);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * isDebugEnabled() must return true when Settings has debug="yes" (string).
     * This covers the `$debug === 'yes'` branch inside isDebugEnabled().
     */
    public function testIsDebugEnabledReturnsTrueWhenSettingsDebugIsYes(): void
    {
        // Arrange
        putenv('APP_DEBUG=');
        Settings::setSetting('debug', 'yes', false);
        $provider = new DebugBarServiceProvider($this->app);

        // Act
        $result = $this->callIsDebugEnabled($provider);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * isDebugEnabled() must return true when Settings has development=true.
     * Covers the fallback `$dev === true` branch.
     */
    public function testIsDebugEnabledReturnsTrueWhenSettingsDevelopmentIsTrue(): void
    {
        // Arrange
        putenv('APP_DEBUG=');
        Settings::setSetting('debug', false, false);
        Settings::setSetting('development', true, false);
        $provider = new DebugBarServiceProvider($this->app);

        // Act
        $result = $this->callIsDebugEnabled($provider);

        // Assert
        $this->assertTrue($result,
            'isDebugEnabled() must return true when Settings development=true');
    }

    /**
     * isDebugEnabled() must return true when Settings has development="yes".
     */
    public function testIsDebugEnabledReturnsTrueWhenSettingsDevelopmentIsYes(): void
    {
        // Arrange
        putenv('APP_DEBUG=');
        Settings::setSetting('debug', false, false);
        Settings::setSetting('development', 'yes', false);
        $provider = new DebugBarServiceProvider($this->app);

        // Act
        $result = $this->callIsDebugEnabled($provider);

        // Assert
        $this->assertTrue($result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Invoke the private isDebugEnabled() method via Reflection.
     */
    private function callIsDebugEnabled(DebugBarServiceProvider $provider): bool
    {
        $ref    = new \ReflectionClass($provider);
        $method = $ref->getMethod('isDebugEnabled');
        return (bool) $method->invoke($provider);
    }
}
