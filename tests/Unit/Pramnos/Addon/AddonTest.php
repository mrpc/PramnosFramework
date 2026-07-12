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
    // doAction() — error paths (function/method does not exist)
    // =========================================================================

    /**
     * doAction() must throw an Error (PHP 8 raises Error when instantiating an
     * undefined class name used unqualified inside the Pramnos\Addon namespace)
     * when a registered string function does not exist.
     *
     * The source code uses `throw new Exception(...)` without a leading backslash,
     * so PHP 8 looks for `Pramnos\Addon\Exception` which does not exist — the
     * execution of the branch itself throws an `Error`.
     *
     * Note: The test verifies that reaching this branch causes a throwable so that
     * the branch IS executed (and thus covered). The exact throwable class is an
     * implementation detail of the unqualified `new Exception` in the source.
     */
    public function testDoActionThrowsWhenRegisteredStringFunctionDoesNotExist(): void
    {
        // Arrange — inject an action that references a non-existent function.
        // We bypass addAction() checks by writing directly to static storage.
        $rp = new \ReflectionProperty(Addon::class, '_actions');
        $rp->setValue(null, [
            'broken_action' => [
                10 => [
                    ['function' => 'this_function_definitely_does_not_exist', 'acceptedArgs' => 1, 'counter' => 0]
                ]
            ]
        ]);

        // Act / Assert — doAction() must raise a throwable when the callback is not callable.
        // PHP 8 raises Error (class-not-found) rather than Exception here.
        $this->expectException(\Throwable::class);
        Addon::doAction('broken_action');
    }

    /**
     * doAction() must raise a throwable when a registered array callback
     * references a non-existent method.
     *
     * This covers lines 175-181: the else-branch for an array callback that
     * fails the method_exists() check.
     */
    public function testDoActionThrowsWhenRegisteredArrayCallbackDoesNotExist(): void
    {
        // Arrange — inject an array callback pointing to a non-existent method
        $rp = new \ReflectionProperty(Addon::class, '_actions');
        $rp->setValue(null, [
            'bad_method_action' => [
                10 => [
                    ['function' => ['SomeClass', 'nonExistentMethod'], 'acceptedArgs' => 1, 'counter' => 0]
                ]
            ]
        ]);

        // Act / Assert
        $this->expectException(\Throwable::class);
        Addon::doAction('bad_method_action');
    }

    /**
     * doAction() must use the variadic argument form when called with more than
     * 3 arguments (the func_num_args > 3 branch).
     *
     * This ensures the early arg-normalisation at line 145-147 is exercised.
     */
    public function testDoActionWithMoreThanThreeArgsUsesVariadicBranch(): void
    {
        // Arrange — no actions registered for this tag
        // Act — pass 4 arguments total; the extra arg is collected into $arg slice
        $result = Addon::doAction('no_such_tag_variadic', [], 'extra1', 'extra2');

        // Assert — no actions exist, returns false; the important thing is no error
        $this->assertFalse($result);
    }

    // =========================================================================
    // removeAction() — inner false-return path
    // =========================================================================

    /**
     * removeAction() must return false when the priority exists but the function
     * to remove is NOT found in the list (the inner foreach exhausts without a match).
     *
     * This covers the `return false` at line 220: the priority bucket exists
     * but the requested function is absent.
     */
    public function testRemoveActionReturnsFalseWhenFunctionNotInPriorityBucket(): void
    {
        // Arrange — register 'strlen' but try to remove 'strtolower'
        Addon::addAction('save_post', 'strlen', 10);
        $addon = new Addon();

        // Act — remove a different function at the same priority
        $result = $addon->removeAction('save_post', 'strtolower', 10);

        // Assert — the bucket was found, but the function was not → false
        $this->assertFalse($result,
            'removeAction() must return false when the function is not in the priority bucket');
    }

    // =========================================================================
    // removeFilter() — true-return path
    // =========================================================================

    /**
     * removeFilter() must return true when the filter is registered and removed.
     *
     * This covers lines 248-250: the happy-path of removeFilter() where the
     * function is found and unset from the priority bucket.
     */
    public function testRemoveFilterReturnsTrueWhenFound(): void
    {
        // Arrange
        Addon::addFilter('the_content', 'strtolower', 10);
        $addon = new Addon();

        // Act
        $result = $addon->removeFilter('the_content', 'strtolower', 10);

        // Assert — found and removed
        $this->assertTrue($result,
            'removeFilter() must return true when the filter is found and removed');
    }

    /**
     * removeFilter() must return false when the priority bucket exists but the
     * function is not in it (the inner foreach exhausts without a match).
     *
     * This covers the `return false` at line 252.
     */
    public function testRemoveFilterReturnsFalseWhenFunctionNotInBucket(): void
    {
        // Arrange — register one filter but try to remove a different one
        Addon::addFilter('the_title', 'strtolower', 10);
        $addon = new Addon();

        // Act
        $result = $addon->removeFilter('the_title', 'strtoupper', 10);

        // Assert — bucket found, function absent → false
        $this->assertFalse($result,
            'removeFilter() must return false when the function is absent from the bucket');
    }

    // =========================================================================
    // applyFilters() — error paths (function/method does not exist)
    // =========================================================================

    /**
     * applyFilters() must raise a throwable when a registered string filter
     * function does not exist.
     *
     * This covers lines 304-309: the else-branch for a missing string callback.
     * PHP 8 raises Error (class-not-found) rather than Exception because the
     * unqualified `new Exception` resolves to `Pramnos\Addon\Exception`.
     */
    public function testApplyFiltersThrowsWhenStringFilterDoesNotExist(): void
    {
        // Arrange — inject a broken string filter
        $rp = new \ReflectionProperty(Addon::class, '_filters');
        $rp->setValue(null, [
            'broken_filter' => [
                10 => [
                    ['function' => 'i_do_not_exist_as_a_function', 'acceptedArgs' => 1, 'counter' => 0]
                ]
            ]
        ]);

        // Act / Assert
        $this->expectException(\Throwable::class);
        Addon::applyFilters('broken_filter', 'some value');
    }

    /**
     * applyFilters() must raise a throwable when a registered array callback
     * references a method that does not exist.
     *
     * This covers lines 296-303: the else-branch for a missing array-form callback.
     */
    public function testApplyFiltersThrowsWhenArrayFilterDoesNotExist(): void
    {
        // Arrange — inject an array filter pointing to a non-existent method
        $rp = new \ReflectionProperty(Addon::class, '_filters');
        $rp->setValue(null, [
            'bad_method_filter' => [
                10 => [
                    ['function' => ['NonExistentClass', 'nonExistentMethod'], 'acceptedArgs' => 1, 'counter' => 0]
                ]
            ]
        ]);

        // Act / Assert
        $this->expectException(\Throwable::class);
        Addon::applyFilters('bad_method_filter', 'value');
    }

    /**
     * applyFilters() with a plain string (built-in) callback must apply it and
     * return the modified value.
     *
     * This covers the function_exists() true-branch path for a string callback.
     */
    public function testApplyFiltersAppliesStringCallback(): void
    {
        // Arrange — strtoupper is a valid built-in
        Addon::addFilter('uppercase_tag', 'strtoupper');

        // Act
        $result = Addon::applyFilters('uppercase_tag', 'hello');

        // Assert
        $this->assertSame('HELLO', $result,
            'applyFilters() must invoke the string callback and return the transformed value');
    }

    // =========================================================================
    // filter() — with active addons that implement the filterX method
    // =========================================================================

    /**
     * filter() with a specific type must call filterX() on all addons of that
     * type that implement the method, passing the content and returning the result.
     *
     * This covers lines 333-338: the inner `foreach` that executes the filter method
     * when the addon implements it.
     */
    public function testFilterWithTypeCallsFilterMethodOnMatchingAddon(): void
    {
        // Arrange — build a stub addon with a filterTitle() method
        $stub = new AddonFilterStub();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['content' => ['stub' => $stub]]);

        // Act
        $result = Addon::filter('Title', 'content', 'original content');

        // Assert — filterTitle() was called, content was transformed
        $this->assertSame('[filtered:original content]', $result,
            'filter() must invoke the filterX method on matching addon instances');
    }

    /**
     * filter() with an empty type must iterate ALL registered addons and call
     * the filterX() method on any that implement it.
     *
     * This covers lines 342-347: the else-branch that calls getaddons() with no
     * type and invokes the method when found.
     */
    public function testFilterWithEmptyTypeCallsFilterMethodOnAllAddons(): void
    {
        // Arrange — inject stub into the 'misc' type
        $stub = new AddonFilterStub();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['misc' => ['stub' => $stub]]);

        // Act — empty type → iterate ALL addons
        $result = Addon::filter('Title', '', 'hello');

        // Assert — the stub's filterTitle() was called
        $this->assertSame('[filtered:hello]', $result,
            'filter() with empty type must invoke filterX on any addon that has the method');
    }

    // =========================================================================
    // triger() — with active addons that implement the onX method
    // =========================================================================

    /**
     * triger() with a type must call onX() on all addons of that type that
     * implement the method, and collect the return values into an array.
     *
     * This covers lines 370-374: the inner `foreach` in the typed branch that
     * executes the action method.
     */
    public function testTrigerWithTypeCallsOnMethodAndReturnsResults(): void
    {
        // Arrange — inject a stub addon with an onInit() method
        $stub = new AddonTriggerStub();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['worker' => ['stub' => $stub]]);

        // Act
        $result = Addon::triger('Init', 'worker');

        // Assert — result is an array containing the stub's return value
        $this->assertSame(['triggered'], $result,
            'triger() must call onX() on matching addons and collect the return values');
    }

    /**
     * triger() with no type must iterate ALL addons and call onX() on any
     * that implement the method.
     *
     * This covers lines 379-385: the else-branch of triger() that calls
     * getaddons() with no type filter.
     */
    public function testTrigerWithNoTypeCallsOnMethodOnAllAddons(): void
    {
        // Arrange
        $stub = new AddonTriggerStub();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['system' => ['stub' => $stub]]);

        // Act — no type → iterate all addons
        $result = Addon::triger('Init');

        // Assert
        $this->assertSame(['triggered'], $result,
            'triger() with no type must call onX() on any addon that implements it');
    }

    // =========================================================================
    // trigerAddon() — success path
    // =========================================================================

    /**
     * trigerAddon() must call the onX() method on the specific named addon and
     * return the result when the addon is registered and has the method.
     *
     * This covers lines 419-427 in trigerAddon(): the inner success path that
     * calls call_user_func_array() on the specific addon.
     */
    public function testTrigerAddonCallsMethodOnRegisteredAddon(): void
    {
        // Arrange
        $stub = new AddonTriggerStub();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['system' => ['mystub' => $stub]]);

        // Act
        $result = Addon::trigerAddon('Init', 'system', 'mystub');

        // Assert — onInit() was invoked and returned 'triggered'
        $this->assertSame('triggered', $result,
            'trigerAddon() must call the onX() method and return its result');
    }

    /**
     * trigerAddon() must return false when the type exists but the specific
     * addon is NOT registered.
     *
     * This covers the inner `if (isset(self::$_addons[$type][$addon]))` false
     * branch at line 419.
     */
    public function testTrigerAddonReturnsFalseWhenAddonNameNotRegistered(): void
    {
        // Arrange — type exists but the specific addon does not
        $stub = new AddonTriggerStub();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['system' => ['otherstub' => $stub]]);

        // Act
        $result = Addon::trigerAddon('Init', 'system', 'nonexistent');

        // Assert
        $this->assertFalse($result,
            'trigerAddon() must return false when the specific addon name is not registered');
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

    // =========================================================================
    // getaddons() — all types merged
    // =========================================================================

    /**
     * getaddons() with no argument must merge addons from all registered types
     * into a flat array keyed by addon name.
     *
     * This covers lines 438-442: the else-branch of getaddons() that iterates
     * all $_addons entries and merges them into a single return array.
     */
    public function testGetAddonsReturnsAllAddonsAcrossTypes(): void
    {
        // Arrange — inject addons of two different types
        $stub1 = new Addon();
        $stub1->name = 'alpha';
        $stub2 = new AddonTriggerStub();
        $stub2->name = 'beta';
        $rp = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, [
            'typeA' => ['alpha' => $stub1],
            'typeB' => ['beta'  => $stub2],
        ]);

        // Act — call with no type argument
        $result = Addon::getaddons();

        // Assert — both addons appear in the merged result
        $this->assertArrayHasKey('alpha', $result, 'alpha addon must be present');
        $this->assertArrayHasKey('beta',  $result, 'beta addon must be present');
        $this->assertCount(2, $result);
    }

    /**
     * getaddons() with a specific type must return only the addons of that type.
     *
     * This covers line 446-448: the isset branch that returns $_addons[$type].
     */
    public function testGetAddonsWithTypeReturnsOnlyThatType(): void
    {
        // Arrange
        $stub = new Addon();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['auth' => ['myaddon' => $stub]]);

        // Act
        $result = Addon::getaddons('auth');

        // Assert — only the auth addon
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('myaddon', $result);
    }

    /**
     * getAddon() must return the registered addon object when the type and name
     * are both present.
     *
     * This covers lines 404-406: the isset-true path that returns the addon object.
     */
    public function testGetAddonReturnsObjectWhenRegistered(): void
    {
        // Arrange
        $stub = new Addon();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['system' => ['myobj' => $stub]]);

        // Act
        $result = Addon::getAddon('system', 'myobj');

        // Assert
        $this->assertSame($stub, $result,
            'getAddon() must return the exact registered addon object');
    }

    /**
     * isActive() must return true when the addon is present in the registry.
     *
     * This covers lines 394-396: the isset-true path that returns true.
     */
    public function testIsActiveReturnsTrueWhenAddonRegistered(): void
    {
        // Arrange
        $stub = new Addon();
        $rp   = new \ReflectionProperty(Addon::class, '_addons');
        $rp->setValue(null, ['system' => ['present' => $stub]]);

        // Act / Assert
        $this->assertTrue(Addon::isActive('system', 'present'),
            'isActive() must return true when the addon is registered');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Stub addons used by filter() and triger() tests
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Stub addon with a filterTitle() method so that Addon::filter('Title', ...) can
 * invoke it via call_user_func_array().
 */
class AddonFilterStub extends Addon
{
    public function filterTitle(string $content): string
    {
        return '[filtered:' . $content . ']';
    }
}

/**
 * Stub addon with an onInit() method so that Addon::triger('Init', ...) and
 * Addon::trigerAddon('Init', ...) can invoke it.
 */
class AddonTriggerStub extends Addon
{
    public function onInit(): string
    {
        return 'triggered';
    }
}
