<?php

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\UnknownFeatureException;

/**
 * Unit tests for FeatureRegistry and UnknownFeatureException.
 *
 * FeatureRegistry is a static-state class, so each test must call reset()
 * in setUp() to ensure complete isolation.
 */
class FeatureRegistryUnitTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::reset();
    }

    // =========================================================================
    // isEnabled
    // =========================================================================

    /**
     * 'core' is always enabled regardless of whether loadFromConfig() has been
     * called — it is a special built-in that cannot be disabled.
     */
    public function testCoreIsAlwaysEnabled(): void
    {
        // Arrange — registry freshly reset, no loadFromConfig() call
        // Act & Assert
        $this->assertTrue(
            FeatureRegistry::isEnabled('core'),
            "'core' must be enabled even without calling loadFromConfig()"
        );
    }

    /**
     * A feature that has been registered and then listed in loadFromConfig()
     * must be reported as enabled.
     */
    public function testEnabledFeatureIsReported(): void
    {
        // Arrange
        FeatureRegistry::register('auth');
        FeatureRegistry::loadFromConfig(['auth']);

        // Act & Assert
        $this->assertTrue(FeatureRegistry::isEnabled('auth'));
    }

    /**
     * A feature that is registered but NOT listed in loadFromConfig() must
     * not be considered enabled.
     */
    public function testRegisteredButNotEnabledFeatureIsNotEnabled(): void
    {
        // Arrange
        FeatureRegistry::register('auth');
        FeatureRegistry::register('queue');
        FeatureRegistry::loadFromConfig(['auth']); // queue not listed

        // Act & Assert
        $this->assertFalse(
            FeatureRegistry::isEnabled('queue'),
            "A registered-but-not-configured feature must not be enabled"
        );
    }

    /**
     * isEnabled() must return false for a key that was never registered and
     * never enabled — it should not throw.
     */
    public function testUnknownFeatureIsNotEnabled(): void
    {
        // Arrange — empty registry after reset

        // Act & Assert — must not throw, must return false
        $this->assertFalse(FeatureRegistry::isEnabled('nonexistent'));
    }

    // =========================================================================
    // loadFromConfig
    // =========================================================================

    /**
     * Providing a feature key that was never registered must throw
     * UnknownFeatureException with the offending key accessible via
     * getFeatureKey().
     */
    public function testLoadFromConfigThrowsForUnknownFeature(): void
    {
        // Arrange — no features registered (reset() was called in setUp)

        // Act & Assert
        $this->expectException(UnknownFeatureException::class);
        FeatureRegistry::loadFromConfig(['nosuchfeature']);
    }

    /**
     * The exception message must name the unknown key AND list the known ones
     * so developers can diagnose mismatches immediately.
     */
    public function testUnknownFeatureExceptionContainsKeyAndKnownList(): void
    {
        // Arrange
        FeatureRegistry::register('auth');
        FeatureRegistry::register('queue');

        try {
            // Act
            FeatureRegistry::loadFromConfig(['typo_key']);
            $this->fail('Expected UnknownFeatureException was not thrown');
        } catch (UnknownFeatureException $e) {
            // Assert — message must mention the bad key
            $this->assertStringContainsString('typo_key', $e->getMessage());
            // Assert — message must list the known features
            $this->assertStringContainsString('auth', $e->getMessage());
            $this->assertStringContainsString('queue', $e->getMessage());
            // Assert — getFeatureKey() returns the offending key exactly
            $this->assertSame('typo_key', $e->getFeatureKey());
        }
    }

    /**
     * Calling loadFromConfig() multiple times must accumulate enabled features
     * rather than replacing them — the method is safe to call repeatedly.
     */
    public function testLoadFromConfigAccumulates(): void
    {
        // Arrange
        FeatureRegistry::register('auth');
        FeatureRegistry::register('queue');

        // Act — two separate calls
        FeatureRegistry::loadFromConfig(['auth']);
        FeatureRegistry::loadFromConfig(['queue']);

        // Assert — both must be enabled
        $this->assertTrue(FeatureRegistry::isEnabled('auth'));
        $this->assertTrue(FeatureRegistry::isEnabled('queue'));
    }

    /**
     * An empty features array must be accepted silently — applications that
     * use only the core feature do not need to list anything.
     */
    public function testLoadFromConfigWithEmptyArrayIsNoop(): void
    {
        // Arrange & Act — no exception expected
        FeatureRegistry::loadFromConfig([]);

        // Assert — core still enabled, nothing else
        $this->assertTrue(FeatureRegistry::isEnabled('core'));
        $enabled = FeatureRegistry::getEnabled();
        $this->assertSame(['core'], $enabled);
    }

    // =========================================================================
    // getEnabled
    // =========================================================================

    /**
     * getEnabled() must always include 'core', even when it was not explicitly
     * listed in loadFromConfig(), because core is implicitly always active.
     */
    public function testGetEnabledAlwaysIncludesCore(): void
    {
        // Arrange
        FeatureRegistry::register('auth');
        FeatureRegistry::loadFromConfig(['auth']);

        // Act
        $enabled = FeatureRegistry::getEnabled();

        // Assert — core must be present
        $this->assertContains('core', $enabled);
        $this->assertContains('auth', $enabled);
    }

    /**
     * When no features are enabled by config, getEnabled() must return exactly
     * ['core'].
     */
    public function testGetEnabledReturnsCoreOnlyByDefault(): void
    {
        // Arrange — no loadFromConfig() call
        // Act
        $enabled = FeatureRegistry::getEnabled();
        // Assert
        $this->assertSame(['core'], $enabled);
    }

    // =========================================================================
    // getKnown / register
    // =========================================================================

    /**
     * getKnown() must return all registered keys including built-in defaults,
     * in the order they were registered.
     */
    public function testGetKnownReturnsAllRegisteredKeys(): void
    {
        // Arrange — after reset(), initDefaults() runs lazily on first call
        // Act
        $known = FeatureRegistry::getKnown();

        // Assert — built-in defaults must always be present
        $this->assertContains('core', $known);
        $this->assertContains('auth', $known);
        $this->assertContains('authserver', $known);
        $this->assertContains('messaging', $known);
        $this->assertContains('queue', $known);
    }

    /**
     * register() must allow custom feature keys that are then discoverable
     * via getKnown() and can be enabled via loadFromConfig().
     */
    public function testRegisterCustomFeature(): void
    {
        // Arrange
        FeatureRegistry::register('my_plugin', [
            'description' => 'My custom plugin',
        ]);

        // Act & Assert — custom key appears in known list
        $this->assertContains('my_plugin', FeatureRegistry::getKnown());

        // Act & Assert — can be enabled without throwing
        FeatureRegistry::loadFromConfig(['my_plugin']);
        $this->assertTrue(FeatureRegistry::isEnabled('my_plugin'));
    }

    /**
     * Re-registering an existing key must overwrite its definition rather than
     * accumulating duplicate entries.
     */
    public function testRegisterOverwritesExistingDefinition(): void
    {
        // Arrange
        FeatureRegistry::register('auth', ['description' => 'Original']);
        FeatureRegistry::register('auth', ['description' => 'Updated']);

        // Act
        $def = FeatureRegistry::getDefinition('auth');

        // Assert — last registration wins
        $this->assertSame('Updated', $def['description']);
        // And there is still only one 'auth' entry
        $this->assertSame(1, array_count_values(FeatureRegistry::getKnown())['auth']);
    }

    // =========================================================================
    // getProvider / getMigrationPaths / getDefinition
    // =========================================================================

    /**
     * getProvider() must return the FQCN string when a provider is registered,
     * or null when no provider has been set (the expected state during Phase 4
     * before Phase 2 backports are implemented).
     */
    public function testGetProviderReturnsNullWhenNotSet(): void
    {
        // Arrange
        FeatureRegistry::register('auth'); // no provider key

        // Act & Assert
        $this->assertNull(FeatureRegistry::getProvider('auth'));
    }

    /**
     * When a provider FQCN is registered it must be returned verbatim by
     * getProvider().
     */
    public function testGetProviderReturnsFqcnWhenSet(): void
    {
        // Arrange
        FeatureRegistry::register('auth', [
            'provider' => 'Pramnos\\Auth\\AuthServiceProvider',
        ]);

        // Act & Assert
        $this->assertSame(
            'Pramnos\\Auth\\AuthServiceProvider',
            FeatureRegistry::getProvider('auth')
        );
    }

    /**
     * getMigrationPaths() must return an empty array for features registered
     * without migration paths — callers must not have to guard against null.
     */
    public function testGetMigrationPathsReturnsEmptyArrayWhenNotSet(): void
    {
        // Arrange
        FeatureRegistry::register('auth');

        // Act & Assert
        $this->assertSame([], FeatureRegistry::getMigrationPaths('auth'));
    }

    /**
     * getMigrationPaths() must return the exact paths array that was supplied
     * during registration.
     */
    public function testGetMigrationPathsReturnsPaths(): void
    {
        // Arrange
        $paths = ['/db/migrations/auth', '/db/migrations/auth_v2'];
        FeatureRegistry::register('auth', ['migrations' => $paths]);

        // Act & Assert
        $this->assertSame($paths, FeatureRegistry::getMigrationPaths('auth'));
    }

    /**
     * getDefinition() must return null for an unregistered key rather than
     * throwing, so callers can safely use it for existence checks.
     */
    public function testGetDefinitionReturnsNullForUnknownKey(): void
    {
        // Arrange — empty registry after reset + lazy defaults
        // Act & Assert
        $this->assertNull(FeatureRegistry::getDefinition('never_registered'));
    }

    /**
     * getDefinition() must return the full definition array with all three
     * canonical keys (description, provider, migrations) present.
     */
    public function testGetDefinitionReturnsCompleteDefinition(): void
    {
        // Arrange
        FeatureRegistry::register('queue', [
            'description' => 'Background Job Queue',
            'provider'    => 'Pramnos\\Queue\\QueueServiceProvider',
            'migrations'  => ['/db/migrations/queue'],
        ]);

        // Act
        $def = FeatureRegistry::getDefinition('queue');

        // Assert — all fields present
        $this->assertSame('Background Job Queue', $def['description']);
        $this->assertSame('Pramnos\\Queue\\QueueServiceProvider', $def['provider']);
        $this->assertSame(['/db/migrations/queue'], $def['migrations']);
    }

    // =========================================================================
    // initDefaults
    // =========================================================================

    /**
     * After a reset(), calling initDefaults() explicitly must re-register all
     * five built-in features and must not leave the registry in a partially
     * initialised state.
     */
    public function testInitDefaultsRegistersAllBuiltins(): void
    {
        // Arrange — reset clears everything
        FeatureRegistry::reset();

        // Act
        FeatureRegistry::initDefaults();

        // Assert — all five built-ins present
        $known = FeatureRegistry::getKnown();
        foreach (['core', 'auth', 'authserver', 'messaging', 'queue'] as $key) {
            $this->assertContains($key, $known, "Built-in '{$key}' missing after initDefaults()");
        }
    }

    /**
     * The built-in defaults must be loaded lazily: calling any public method
     * after reset() without an explicit initDefaults() call must still expose
     * the five built-in keys.
     */
    public function testDefaultsLoadedLazily(): void
    {
        // Arrange — reset() was already called in setUp()
        // Act — trigger lazy load via getKnown()
        $known = FeatureRegistry::getKnown();

        // Assert
        $this->assertContains('core', $known);
        $this->assertContains('queue', $known);
    }

    // =========================================================================
    // reset
    // =========================================================================

    /**
     * reset() must clear enabled features and known features so that the
     * registry is indistinguishable from a freshly-bootstrapped state.
     */
    public function testResetClearsState(): void
    {
        // Arrange — populate registry
        FeatureRegistry::register('auth');
        FeatureRegistry::loadFromConfig(['auth']);
        $this->assertTrue(FeatureRegistry::isEnabled('auth'));

        // Act
        FeatureRegistry::reset();

        // Assert — auth is no longer known (defaults not reloaded yet)
        // We verify by checking that getKnown() after reset+explicit-check
        // does NOT include 'auth' when we re-register only 'core'
        // (The lazy initDefaults will re-register the built-ins, including auth —
        //  so we confirm the reset took effect by verifying the enabled set is empty)
        $enabled = FeatureRegistry::getEnabled();
        $this->assertSame(['core'], $enabled, "After reset(), only core must be enabled");
    }

    // =========================================================================
    // UnknownFeatureException standalone
    // =========================================================================

    /**
     * When knownKeys is empty, the exception message must use the fallback
     * "No features are currently registered." hint.
     */
    public function testUnknownFeatureExceptionWithNoKnownKeys(): void
    {
        // Arrange & Act
        $ex = new UnknownFeatureException('badkey', []);

        // Assert
        $this->assertSame('badkey', $ex->getFeatureKey());
        $this->assertStringContainsString(
            'No features are currently registered.',
            $ex->getMessage()
        );
    }

    /**
     * When knownKeys is provided, the exception message must enumerate them
     * all so the developer can spot the intended key.
     */
    public function testUnknownFeatureExceptionWithKnownKeys(): void
    {
        // Arrange & Act
        $ex = new UnknownFeatureException('typo', ['auth', 'queue', 'messaging']);

        // Assert
        $this->assertSame('typo', $ex->getFeatureKey());
        $this->assertStringContainsString('auth', $ex->getMessage());
        $this->assertStringContainsString('queue', $ex->getMessage());
        $this->assertStringContainsString('messaging', $ex->getMessage());
    }

    /**
     * UnknownFeatureException must extend \RuntimeException so it can be
     * caught by callers using a standard exception hierarchy.
     */
    public function testUnknownFeatureExceptionExtendsRuntimeException(): void
    {
        // Arrange & Act
        $ex = new UnknownFeatureException('x');

        // Assert
        $this->assertInstanceOf(\RuntimeException::class, $ex);
    }
}
