<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\UnknownFeatureException;

/**
 * Unit tests for Pramnos\Application\FeatureRegistry and
 * Pramnos\Application\UnknownFeatureException.
 *
 * FeatureRegistry is a static registry of framework features.  Built-in
 * features (core, auth, authserver, messaging, queue) are registered lazily
 * on first access.  Tests call FeatureRegistry::reset() in setUp/tearDown to
 * guarantee full isolation between test methods.
 *
 * Tests verify:
 *   - initDefaults() registers the 5 built-in features.
 *   - register() adds a feature definition.
 *   - loadFromConfig() enables listed features.
 *   - loadFromConfig() throws UnknownFeatureException for an unknown key.
 *   - isEnabled() returns true for 'core' regardless of config.
 *   - isEnabled() returns true for enabled features and false for disabled ones.
 *   - getEnabled() always includes 'core'.
 *   - getKnown() returns all registered keys.
 *   - getProvider() returns the FQCN or null.
 *   - getMigrationPaths() returns the migration directory array.
 *   - getDefinition() returns the full definition or null for unknown keys.
 *   - reset() clears all state so the next call re-triggers initDefaults().
 *   - UnknownFeatureException stores the unknown key and includes it in the message.
 */
#[CoversClass(FeatureRegistry::class)]
#[CoversClass(UnknownFeatureException::class)]
class FeatureRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::reset();
    }

    protected function tearDown(): void
    {
        FeatureRegistry::reset();
    }

    // =========================================================================
    // initDefaults
    // =========================================================================

    /**
     * initDefaults() registers the 5 framework built-in feature keys: core,
     * auth, authserver, messaging, queue.
     */
    public function testInitDefaultsRegistersBuiltInFeatures(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act
        $known = FeatureRegistry::getKnown();

        // Assert — all five built-in keys are present
        foreach (['core', 'auth', 'authserver', 'messaging', 'queue'] as $key) {
            $this->assertContains($key, $known, "Built-in feature '{$key}' should be registered");
        }
    }

    /**
     * initDefaults() is also triggered lazily on the first public method call,
     * so the registry is never empty for a normal consumer.
     */
    public function testLazyInitDefaultsTriggeredOnFirstAccess(): void
    {
        // Arrange — registry is reset (no defaults loaded yet)

        // Act — calling isEnabled triggers ensureDefaults
        $coreEnabled = FeatureRegistry::isEnabled('core');

        // Assert — core is always enabled and defaults were loaded
        $this->assertTrue($coreEnabled);
        $this->assertContains('core', FeatureRegistry::getKnown());
    }

    // =========================================================================
    // register
    // =========================================================================

    /**
     * register() adds a custom feature to the known map with the given config.
     */
    public function testRegisterAddsCustomFeatureDefinition(): void
    {
        // Arrange
        FeatureRegistry::reset();

        // Act
        FeatureRegistry::register('myfeature', [
            'description' => 'My custom feature',
            'provider'    => 'MyApp\\MyProvider',
            'migrations'  => ['/migrations/myfeature'],
        ]);

        // Assert — feature is known
        $this->assertContains('myfeature', FeatureRegistry::getKnown());

        // Assert — definition matches
        $def = FeatureRegistry::getDefinition('myfeature');
        $this->assertSame('My custom feature',    $def['description']);
        $this->assertSame('MyApp\\MyProvider',    $def['provider']);
        $this->assertSame(['/migrations/myfeature'], $def['migrations']);
    }

    /**
     * register() with no config applies sensible defaults: empty description,
     * null provider, empty migrations array.
     */
    public function testRegisterWithNoConfigUsesDefaults(): void
    {
        // Arrange
        FeatureRegistry::reset();

        // Act
        FeatureRegistry::register('barebones');

        // Assert
        $def = FeatureRegistry::getDefinition('barebones');
        $this->assertSame('',   $def['description']);
        $this->assertNull($def['provider']);
        $this->assertSame([], $def['migrations']);
    }

    // =========================================================================
    // loadFromConfig
    // =========================================================================

    /**
     * loadFromConfig() enables each feature key in the given array.
     */
    public function testLoadFromConfigEnablesListedFeatures(): void
    {
        // Arrange — initDefaults registers auth and queue
        FeatureRegistry::initDefaults();

        // Act
        FeatureRegistry::loadFromConfig(['auth', 'queue']);

        // Assert — both are now enabled
        $this->assertTrue(FeatureRegistry::isEnabled('auth'));
        $this->assertTrue(FeatureRegistry::isEnabled('queue'));
    }

    /**
     * loadFromConfig() throws UnknownFeatureException when a key in the array
     * has not been registered.  The exception message includes the unknown key
     * and a list of known keys.
     */
    public function testLoadFromConfigThrowsForUnknownKey(): void
    {
        // Arrange — fresh defaults
        FeatureRegistry::initDefaults();

        // Assert — exception expected
        $this->expectException(UnknownFeatureException::class);

        // Act
        FeatureRegistry::loadFromConfig(['phantom_feature']);
    }

    /**
     * loadFromConfig() is additive: calling it a second time adds to the
     * existing enabled set rather than replacing it.
     */
    public function testLoadFromConfigIsAdditive(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act — enable in two separate calls
        FeatureRegistry::loadFromConfig(['auth']);
        FeatureRegistry::loadFromConfig(['queue']);

        // Assert — both are enabled
        $this->assertTrue(FeatureRegistry::isEnabled('auth'));
        $this->assertTrue(FeatureRegistry::isEnabled('queue'));
    }

    // =========================================================================
    // isEnabled
    // =========================================================================

    /**
     * 'core' is always enabled — isEnabled('core') returns true even before
     * any loadFromConfig() call and even after an explicit reset.
     */
    public function testIsEnabledReturnsTrueForCoreAlways(): void
    {
        // Arrange / Act / Assert — true immediately after reset
        $this->assertTrue(FeatureRegistry::isEnabled('core'));
    }

    /**
     * A feature that has NOT been loaded returns false from isEnabled().
     */
    public function testIsEnabledReturnsFalseForNotEnabledFeature(): void
    {
        // Arrange — defaults loaded but auth not enabled
        FeatureRegistry::initDefaults();

        // Assert — messaging not explicitly enabled
        $this->assertFalse(FeatureRegistry::isEnabled('messaging'));
    }

    // =========================================================================
    // getEnabled
    // =========================================================================

    /**
     * getEnabled() always returns at least ['core'] even when nothing else is
     * enabled.
     */
    public function testGetEnabledAlwaysIncludesCore(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act
        $enabled = FeatureRegistry::getEnabled();

        // Assert
        $this->assertContains('core', $enabled);
    }

    /**
     * getEnabled() returns the enabled set plus 'core'.
     */
    public function testGetEnabledReturnsEnabledFeaturesPlusCore(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();
        FeatureRegistry::loadFromConfig(['auth', 'messaging']);

        // Act
        $enabled = FeatureRegistry::getEnabled();

        // Assert — core + auth + messaging
        $this->assertContains('core',      $enabled);
        $this->assertContains('auth',      $enabled);
        $this->assertContains('messaging', $enabled);

        // Assert — queue was not enabled
        $this->assertNotContains('queue', $enabled);
    }

    // =========================================================================
    // getKnown
    // =========================================================================

    /**
     * getKnown() returns only the registered feature keys.
     */
    public function testGetKnownReturnsAllRegisteredKeys(): void
    {
        // Arrange — register two custom features only
        FeatureRegistry::reset();
        FeatureRegistry::register('alpha');
        FeatureRegistry::register('beta');

        // Act
        $known = FeatureRegistry::getKnown();

        // Assert — both present; built-ins are NOT present after a reset + manual register
        // (initDefaults was NOT called; ensureDefaults sees $defaultsLoaded=false and calls it,
        // which happens BEFORE our manual registers... so the built-ins ARE also present)
        // Testing just our custom ones are there:
        $this->assertContains('alpha', $known);
        $this->assertContains('beta', $known);
    }

    // =========================================================================
    // getProvider
    // =========================================================================

    /**
     * getProvider() returns the FQCN registered for a feature.
     */
    public function testGetProviderReturnsProviderFqcn(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act
        $provider = FeatureRegistry::getProvider('authserver');

        // Assert — the OAuth2 service provider class
        $this->assertSame(\Pramnos\Auth\AuthServerServiceProvider::class, $provider);
    }

    /**
     * getProvider() returns null when no provider has been registered for a feature.
     */
    public function testGetProviderReturnsNullWhenNoProvider(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act — 'auth' does not have a provider (null by design for basic auth)
        $provider = FeatureRegistry::getProvider('auth');

        // Assert
        $this->assertNull($provider);
    }

    /**
     * getProvider() returns null for an unknown key (not registered).
     */
    public function testGetProviderReturnsNullForUnknownKey(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act
        $provider = FeatureRegistry::getProvider('nonexistent');

        // Assert
        $this->assertNull($provider);
    }

    // =========================================================================
    // getMigrationPaths
    // =========================================================================

    /**
     * getMigrationPaths() returns the migration directory array for a feature.
     */
    public function testGetMigrationPathsReturnsPathsForKnownFeature(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act
        $paths = FeatureRegistry::getMigrationPaths('core');

        // Assert — at least one migration directory registered for core
        $this->assertNotEmpty($paths);
        $this->assertIsArray($paths);
    }

    /**
     * getMigrationPaths() returns an empty array for an unknown key.
     */
    public function testGetMigrationPathsReturnsEmptyArrayForUnknownKey(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act
        $paths = FeatureRegistry::getMigrationPaths('nonexistent');

        // Assert
        $this->assertSame([], $paths);
    }

    // =========================================================================
    // getDefinition
    // =========================================================================

    /**
     * getDefinition() returns the full definition array for a registered feature.
     */
    public function testGetDefinitionReturnsFullDefinitionForKnownKey(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act
        $def = FeatureRegistry::getDefinition('queue');

        // Assert — all three keys present
        $this->assertIsArray($def);
        $this->assertArrayHasKey('description', $def);
        $this->assertArrayHasKey('provider',    $def);
        $this->assertArrayHasKey('migrations',  $def);
    }

    /**
     * getDefinition() returns null for a key that has not been registered.
     */
    public function testGetDefinitionReturnsNullForUnknownKey(): void
    {
        // Arrange
        FeatureRegistry::initDefaults();

        // Act
        $def = FeatureRegistry::getDefinition('does_not_exist');

        // Assert
        $this->assertNull($def);
    }

    // =========================================================================
    // reset
    // =========================================================================

    /**
     * reset() clears all state — after a reset, the next call to isEnabled()
     * re-triggers initDefaults() (lazy init), re-registering built-ins.
     */
    public function testResetClearsAllState(): void
    {
        // Arrange — enable auth
        FeatureRegistry::initDefaults();
        FeatureRegistry::loadFromConfig(['auth']);
        $this->assertTrue(FeatureRegistry::isEnabled('auth'));

        // Act
        FeatureRegistry::reset();

        // Assert — auth is no longer enabled (defaults re-loaded on next access,
        // but loadFromConfig has not been called again)
        $this->assertFalse(FeatureRegistry::isEnabled('auth'));
    }

    // =========================================================================
    // UnknownFeatureException
    // =========================================================================

    /**
     * UnknownFeatureException stores the unknown key and includes it in the
     * exception message together with the list of known keys.
     */
    public function testUnknownFeatureExceptionStoresKey(): void
    {
        // Arrange / Act
        $e = new UnknownFeatureException('phantom', ['core', 'auth', 'queue']);

        // Assert — accessor returns the unknown key
        $this->assertSame('phantom', $e->getFeatureKey());
    }

    /**
     * UnknownFeatureException message includes the unknown key and known keys.
     */
    public function testUnknownFeatureExceptionMessageContainsKeyAndKnownList(): void
    {
        // Arrange / Act
        $e = new UnknownFeatureException('phantom', ['core', 'auth', 'queue']);

        // Assert — unknown key appears in the message
        $this->assertStringContainsString('phantom', $e->getMessage());

        // Assert — known keys listed in the message
        $this->assertStringContainsString('core',  $e->getMessage());
        $this->assertStringContainsString('auth',  $e->getMessage());
        $this->assertStringContainsString('queue', $e->getMessage());
    }

    /**
     * UnknownFeatureException with an empty known list uses the "no features
     * are currently registered" fallback message.
     */
    public function testUnknownFeatureExceptionWithEmptyKnownList(): void
    {
        // Arrange / Act
        $e = new UnknownFeatureException('ghost', []);

        // Assert — fallback message present
        $this->assertStringContainsString('No features', $e->getMessage());
    }
}
