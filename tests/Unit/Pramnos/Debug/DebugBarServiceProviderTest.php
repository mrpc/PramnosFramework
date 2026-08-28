<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Pramnos\Debug\RequestId;
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
        // Request ids are process-wide and change Logger's output shape while
        // active. A test that activated them must not decide how another test's
        // log lines are written — which is exactly what happened once.
        RequestId::reset();
        DebugBar::reset();
        Settings::clearSettings();
        $this->app = new Application('test_debug_provider');
    }

    protected function tearDown(): void
    {
        RequestId::reset();
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
    public function testBootAddsCollectorsWhenDebugEnabled(): void
    {
        // Arrange — `APP_DEBUG`, which is one of the three signals that remain. It used to be
        // the `debug` *setting*: a row in the settings table, editable from the settings
        // screen, that turned the toolbar on for every visitor of the site rather than for
        // the person who set it. See `isDebugEnabled()` for why that is no longer a signal.
        putenv('APP_DEBUG=1');
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
     * A settings row does not turn the toolbar on. It used to, and that was the hole.
     *
     * `debug` and `development` are rows in the settings table, editable from
     * `/admin/Settings`. Flipping either one turned the toolbar on **for every visitor of
     * the site** — and what it carries is every query with its bindings, the session's keys,
     * the request's authentication state, the resolved route and middleware. A row in a
     * table nobody thinks of as dangerous is not the right lock for that.
     *
     * They were redundant as well as risky: a development environment says so through
     * `APP_DEBUG` or the `DEVELOPMENT` constant, and a developer on a live server has
     * `debug:token`, which is signed, single-use and expires by itself.
     *
     * The settings still mean what they always meant elsewhere — error display, the
     * DevPanel, the debug log. Only this decision stopped reading them.
     */
    public function testASettingsRowDoesNotOpenTheToolbar(): void
    {
        // Arrange — no environment signal, and both settings saying yes as loudly as they can
        putenv('APP_DEBUG=');

        foreach ([true, '1', 'true', 'yes'] as $value) {
            Settings::setSetting('debug', $value, false);
            Settings::setSetting('development', $value, false);
            $provider = new DebugBarServiceProvider($this->app);

            // Act & Assert
            $this->assertFalse(
                $this->callIsDebugEnabled($provider),
                'a settings value of ' . var_export($value, true) . ' must not open the toolbar'
            );
        }
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

    /**
     * A page carries no developer internals because of a settings row either.
     *
     * The same hole one step along: `View` wrote the view's file path into an HTML comment on
     * every rendered page, gated on `isDebugMode()` — which reads the `debug` and
     * `development` settings. So a row editable from the settings screen decided whether
     * every visitor's page source, and every crawler's copy of it, said where the
     * application's files live.
     *
     * Asserted through the shared method rather than by rendering a view: what matters is
     * that the *decision* no longer consults the settings, and that is where it lives.
     */
    public function testASettingsRowDoesNotPutInternalsInAPage(): void
    {
        // Arrange
        putenv('APP_DEBUG=');
        Settings::setSetting('debug', 'yes', false);
        Settings::setSetting('development', 'yes', false);

        // Act & Assert
        $this->assertFalse(
            \Pramnos\Application\Application::isDeveloperEnvironment(),
            'the settings must not make a request a developer request'
        );

        // …and the environment does, which is what a development checkout has.
        putenv('APP_DEBUG=1');
        $this->assertTrue(\Pramnos\Application\Application::isDeveloperEnvironment());
        putenv('APP_DEBUG=');
    }
}
