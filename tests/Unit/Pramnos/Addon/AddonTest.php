<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Addon;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Addon\Addon;

/**
 * Unit tests for Pramnos\Addon\Addon.
 *
 * Addon provides a WordPress-style hooks system (addAction / doAction /
 * addFilter / applyFilters) plus an addon registry (registerAddon, getaddons).
 *
 * Tests cover:
 *   - addAction(): returns true on first register, false on duplicate.
 *   - removeAction(): false when not registered, true when found.
 *   - doAction(): returns false when no hook is registered for the tag;
 *     executes the callback when a valid function is registered.
 *   - addFilter(): same duplicate-detection logic as addAction.
 *   - applyFilters(): returns value unchanged when no filter is registered;
 *     applies the callback when one is registered.
 *   - isActive() / getAddon() / getaddons(): addon registry basics.
 *   - triger(): returns empty array when no addons are registered.
 *   - Constructor: default property values.
 *
 * Static state is reset between tests via reflection so no test bleeds into
 * the next one.
 */
#[CoversClass(Addon::class)]
class AddonTest extends TestCase
{
    // =========================================================================
    // Static callback helpers (used by doAction / applyFilters tests)
    // =========================================================================

    /** Increments the shared counter — used as an action callback. */
    public static int $callCount = 0;

    public static function countingCallback(): void
    {
        self::$callCount++;
    }

    public static function uppercaseFilter(string $value): string
    {
        return strtoupper($value);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Reset all three private static stores between tests so one test cannot
     * affect the next through shared state.
     */
    private function resetStaticState(): void
    {
        foreach (['_actions', '_filters', '_addons'] as $prop) {
            $rp = new \ReflectionProperty(Addon::class, $prop);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
    }

    protected function setUp(): void
    {
        $this->resetStaticState();
    }

    protected function tearDown(): void
    {
        $this->resetStaticState();
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * Addon can be instantiated and has sensible property defaults.
     */
    public function testConstructorSetsDefaultProperties(): void
    {
        // Arrange / Act
        $addon = new Addon();

        // Assert
        $this->assertSame('addon',  $addon->name);
        $this->assertSame('system', $addon->type);
    }

    // =========================================================================
    // addAction() / removeAction()
    // =========================================================================

    /**
     * addAction() returns true the first time a function is hooked to a tag.
     */
    public function testAddActionReturnsTrueOnFirstRegister(): void
    {
        // Arrange / Act
        $result = Addon::addAction('init', 'strlen');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * addAction() returns false when the same function is hooked to the same
     * tag twice — prevents duplicate registrations.
     */
    public function testAddActionReturnsFalseOnDuplicate(): void
    {
        // Arrange
        Addon::addAction('init', 'strlen');

        // Act — second registration for the same tag + function
        $result = Addon::addAction('init', 'strlen');

        // Assert — duplicate detected, not added again
        $this->assertFalse($result);
    }

    /**
     * Adding two different functions to the same tag both succeed.
     */
    public function testAddActionAllowsDifferentFunctionsOnSameTag(): void
    {
        // Arrange / Act
        $r1 = Addon::addAction('save', 'strlen');
        $r2 = Addon::addAction('save', 'strtolower');

        // Assert — both accepted
        $this->assertTrue($r1);
        $this->assertTrue($r2);
    }

    /**
     * Adding the same function to different tags both succeed.
     */
    public function testAddActionAllowsSameFunctionOnDifferentTags(): void
    {
        // Arrange / Act
        $r1 = Addon::addAction('init',  'strlen');
        $r2 = Addon::addAction('ready', 'strlen');

        // Assert — different tags are independent
        $this->assertTrue($r1);
        $this->assertTrue($r2);
    }

    /**
     * removeAction() returns false when the tag has no registered actions.
     */
    public function testRemoveActionReturnsFalseWhenTagNotRegistered(): void
    {
        // Arrange — nothing registered

        // Assert
        $addon = new Addon();
        $this->assertFalse($addon->removeAction('nonexistent', 'strlen'));
    }

    /**
     * removeAction() returns true when the function is found and removed.
     */
    public function testRemoveActionReturnsTrueWhenFound(): void
    {
        // Arrange
        Addon::addAction('init', 'strlen', 10);
        $addon = new Addon();

        // Act
        $result = $addon->removeAction('init', 'strlen', 10);

        // Assert — the function was found and removed
        $this->assertTrue($result);
    }

    // =========================================================================
    // doAction()
    // =========================================================================

    /**
     * doAction() returns false when no action is registered for the given tag.
     * This is the "tag not found" short-circuit path.
     */
    public function testDoActionReturnsFalseWhenNoActionsRegistered(): void
    {
        // Arrange — no actions registered at all

        // Assert
        $this->assertFalse(Addon::doAction('unregistered_tag'));
    }

    /**
     * doAction() invokes the registered callback when the tag matches a
     * real callable. We register a static method on this test class (the
     * array-form callback) so method_exists() can verify it, and track
     * invocation via a static counter.
     */
    public function testDoActionInvokesRegisteredCallback(): void
    {
        // Arrange — reset counter and register the class-method callback
        self::$callCount = 0;
        Addon::addAction('tick', [self::class, 'countingCallback']);

        // Act
        Addon::doAction('tick');

        // Assert — callback was executed exactly once
        $this->assertSame(1, self::$callCount);
    }

    // =========================================================================
    // addFilter() / removeFilter()
    // =========================================================================

    /**
     * addFilter() returns true on the first registration for a tag.
     */
    public function testAddFilterReturnsTrueOnFirstRegister(): void
    {
        // Arrange / Act
        $result = Addon::addFilter('content', 'strtolower');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * addFilter() returns false when the same function is already registered.
     */
    public function testAddFilterReturnsFalseOnDuplicate(): void
    {
        // Arrange
        Addon::addFilter('content', 'strtolower');

        // Act
        $result = Addon::addFilter('content', 'strtolower');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * removeFilter() returns false when the tag has no registered filters.
     */
    public function testRemoveFilterReturnsFalseWhenTagNotRegistered(): void
    {
        // Arrange — nothing registered
        $addon = new Addon();

        // Assert
        $this->assertFalse($addon->removeFilter('nonexistent', 'strtolower'));
    }

    // =========================================================================
    // applyFilters()
    // =========================================================================

    /**
     * applyFilters() returns the original value unchanged when no filter is
     * registered for the given tag.
     */
    public function testApplyFiltersReturnsOriginalValueWhenNoFilterRegistered(): void
    {
        // Arrange — no filters registered
        $original = 'Hello World';

        // Act
        $result = Addon::applyFilters('content', $original);

        // Assert — value unchanged
        $this->assertSame($original, $result);
    }

    /**
     * applyFilters() applies a registered valid callable and returns the
     * transformed value. We use a static method on this class so the
     * method_exists() path is exercised.
     */
    public function testApplyFiltersAppliesRegisteredCallback(): void
    {
        // Arrange — register class-method filter
        Addon::addFilter('title', [self::class, 'uppercaseFilter']);

        // Act
        $result = Addon::applyFilters('title', 'hello world');

        // Assert — uppercaseFilter was applied
        $this->assertSame('HELLO WORLD', $result);
    }

    /**
     * applyFilters() with more than 3 arguments treats additional arguments as
     * the $arg array (the variadic branch).
     */
    public function testApplyFiltersWithExtraArgsUsesVariadicBranch(): void
    {
        // Arrange — no filters registered for this tag
        $result = Addon::applyFilters('trim_filter', '  hello  ', [], 'extra');

        // Assert — value unchanged (no filter), variadic branch taken
        $this->assertSame('  hello  ', $result);
    }

    // =========================================================================
    // getaddons() / isActive() / getAddon()
    // =========================================================================

    /**
     * getaddons() returns an empty array when no addons have been registered.
     */
    public function testGetAddonsReturnsEmptyArrayInitially(): void
    {
        // Assert
        $this->assertSame([], Addon::getaddons());
    }

    /**
     * getaddons() with a specific type returns an empty array when no addons
     * of that type exist.
     */
    public function testGetAddonsWithTypeReturnsEmptyForUnknownType(): void
    {
        // Assert
        $this->assertSame([], Addon::getaddons('system'));
    }

    /**
     * isActive() returns false when the type or addon name is not registered.
     */
    public function testIsActiveReturnsFalseWhenAddonNotRegistered(): void
    {
        // Assert
        $this->assertFalse(Addon::isActive('system', 'myAddon'));
    }

    /**
     * getAddon() returns false when the requested addon is not registered.
     */
    public function testGetAddonReturnsFalseWhenNotRegistered(): void
    {
        // Assert
        $this->assertFalse(Addon::getAddon('system', 'nonexistent'));
    }

    // =========================================================================
    // triger()
    // =========================================================================

    /**
     * triger() returns an empty array when no addons are registered for the
     * given type (the "type not found" short-circuit path).
     */
    public function testTrigerReturnsEmptyArrayWhenNoAddonsRegistered(): void
    {
        // Assert — no addons, result is always []
        $this->assertSame([], Addon::triger('init', 'system'));
    }

    /**
     * triger() with no type argument iterates all addons and returns [] when
     * none implement the action method.
     */
    public function testTrigerWithNoTypeReturnsEmptyArray(): void
    {
        // Assert
        $this->assertSame([], Addon::triger('nonexistent_action'));
    }

    // =========================================================================
    // filter()
    // =========================================================================

    /**
     * filter() with an empty type must return the original content unchanged
     * when no addons with a matching method are registered.
     *
     * This covers the `else` branch of filter() (lines ~342-350): iterating all
     * addons when $type == ''.
     */
    public function testFilterWithEmptyTypeReturnsContentUnchanged(): void
    {
        // Arrange — no addons registered
        // Act
        $result = Addon::filter('title', '', 'My Title');

        // Assert — no addon to transform, original content returned
        $this->assertSame('My Title', $result,
            'filter() must return the original content when no matching addon exists');
    }

    /**
     * filter() with a type that has no registered addons must return the
     * original content.
     *
     * This covers the `if ($type !== '')` branch (lines ~332-341) where the
     * type is not in $_addons — the foreach body is never entered.
     */
    public function testFilterWithUnknownTypeReturnsContentUnchanged(): void
    {
        // Act
        $result = Addon::filter('title', 'content', 'My Title');

        // Assert
        $this->assertSame('My Title', $result);
    }

    // =========================================================================
    // trigerAddon()
    // =========================================================================

    /**
     * trigerAddon() must return false when the specified type/addon is not
     * registered.
     *
     * This covers the else-path of the outer `if (isset(...))` guard in
     * trigerAddon() (lines ~419-428).
     */
    public function testTrigerAddonReturnsFalseWhenAddonNotRegistered(): void
    {
        // Act — neither type nor addon exists
        $result = Addon::trigerAddon('init', 'system', 'myaddon');

        // Assert
        $this->assertFalse($result,
            'trigerAddon() must return false when the addon is not registered');
    }

    // =========================================================================
    // unload()
    // =========================================================================

    /**
     * unload() must return false when the addon is not registered.
     *
     * This covers the else-branch of unload() (lines ~493-495).
     */
    public function testUnloadReturnsFalseWhenAddonNotRegistered(): void
    {
        // Act
        $result = Addon::unload('nonexistent', 'system');

        // Assert
        $this->assertFalse($result, 'unload() must return false for an unknown addon');
    }

    /**
     * unload() must return true and remove the addon when it is registered.
     *
     * This covers the if-branch of unload() (lines ~490-492): the addon is
     * present, so it is unset from the registry and true is returned.
     */
    public function testUnloadReturnsTrueAndRemovesAddonWhenRegistered(): void
    {
        // Arrange — inject an addon directly into the static registry
        $addonObj = new Addon();
        $rp = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setAccessible(true);
        $rp->setValue(null, ['system' => ['myaddon' => $addonObj]]);

        // Act
        $result = Addon::unload('myaddon', 'system');

        // Assert — returned true
        $this->assertTrue($result, 'unload() must return true when the addon was present');

        // Assert — addon is gone from the registry
        $this->assertFalse(Addon::isActive('system', 'myaddon'),
            'unload() must remove the addon from the registry');
    }

    // =========================================================================
    // load()
    // =========================================================================

    /**
     * load() must return false when the file and class do not exist.
     *
     * This covers the else-branch of load() (lines ~535-537): neither the class
     * exists nor the addon file is found — return false.
     */
    public function testLoadReturnsFalseWhenClassAndFileNotFound(): void
    {
        // Act — class and file both absent
        $result = Addon::load('Pramnos_Nonexistent_Addon_XYZ_' . bin2hex(random_bytes(4)), 'system');

        // Assert
        $this->assertFalse($result,
            'load() must return false when neither class nor file exists');
    }

    /**
     * load() with an already-loaded class must instantiate it and return true.
     *
     * This covers the `if (class_exists($addon))` true branch of load()
     * (lines ~506-514): register the addon without a file and return true.
     */
    public function testLoadReturnsTrueWhenClassExists(): void
    {
        // Arrange — use Addon itself as the class to load (it definitely exists)
        // The class name must be a fully-qualified name or autoload-reachable
        $className = Addon::class; // 'Pramnos\Addon\Addon'

        // Act
        $result = Addon::load($className, 'test_load');

        // Assert — class was found, true returned
        $this->assertTrue($result,
            'load() must return true when the class already exists via autoload');

        // Assert — the addon is now registered
        $this->assertTrue(Addon::isActive('test_load', $className),
            'load() must register the instantiated addon in the registry');
    }

    // =========================================================================
    // getProperty()
    // =========================================================================

    /**
     * getProperty() must return the addon's own property value when no
     * multilanguage override exists for the current language.
     *
     * This covers the `return $this->$property` else-branch of getProperty()
     * (lines ~558-561): when no multilanguage field is set, fall back to the
     * addon's public property.
     */
    public function testGetPropertyReturnsDynamicPropertyWhenNoMultilanguage(): void
    {
        // Arrange — a fresh addon with a public property
        $addon = new Addon();
        $addon->title = 'My Addon Title';

        // Act
        $result = $addon->getProperty('title');

        // Assert
        $this->assertSame('My Addon Title', $result,
            'getProperty() must return the direct property when no multilanguage override is set');
    }
}
