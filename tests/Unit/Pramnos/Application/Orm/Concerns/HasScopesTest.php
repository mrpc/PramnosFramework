<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Orm\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Orm\Concerns\HasScopes;

/**
 * Unit tests for Pramnos\Application\Orm\Concerns\HasScopes.
 *
 * HasScopes provides local + global query scope support for ORM models.
 * Global scopes are registered statically per class and applied automatically
 * to every query via applyGlobalScopes().  Local scopes are methods named
 * scopeXxx() and are accumulated via applyScope() then flushed via
 * applyPendingScopes().  The appendCondition() helper builds an AND-chain.
 *
 * Tests use an anonymous fixture class that:
 *   - uses HasScopes
 *   - declares $this->_pendingScopes (consumed by applyScope / applyPendingScopes)
 *   - exposes protected methods as public for assertion
 *   - defines a few local scope methods (scopeActive, scopeOlderThan)
 *
 * Static state isolation: $globalScopes is reset via Reflection in setUp/tearDown
 * because multiple instances of the same anonymous class share the same static bag.
 *
 * Tests verify:
 *   - addGlobalScope() registers a callback that applyGlobalScopes() invokes.
 *   - removeGlobalScope() removes a callback; applyGlobalScopes() skips it.
 *   - withoutGlobalScope() suppresses a scope for the next call only.
 *   - applyScope() accumulates local scope calls for later execution.
 *   - applyPendingScopes() flushes the accumulated calls and clears the list.
 *   - applyScope() throws BadMethodCallException for an undefined scope.
 *   - appendCondition() returns the condition alone when filter is empty.
 *   - appendCondition() wraps both sides in parentheses when filter is non-empty.
 *   - appendCondition() returns $filter unchanged when condition is empty.
 */
#[CoversClass(HasScopes::class)]
class HasScopesTest extends TestCase
{
    /** Anonymous class name — used to reset static state between tests. */
    private string $modelClass;

    protected function setUp(): void
    {
        $instance          = $this->makeModel();
        $this->modelClass  = get_class($instance);
        $this->resetGlobalScopes();
    }

    protected function tearDown(): void
    {
        $this->resetGlobalScopes();
    }

    // =========================================================================
    // Fixture factory
    // =========================================================================

    private function makeModel(): object
    {
        return new class {
            use HasScopes;

            /** Required by applyScope() / applyPendingScopes(). */
            public array $_pendingScopes = [];

            // ----- expose protected methods as public for assertions -----

            public function exposedApplyGlobal(string $filter): string
            {
                return $this->applyGlobalScopes($filter);
            }

            public function exposedApplyPending(string $filter): string
            {
                return $this->applyPendingScopes($filter);
            }

            public function exposedAppendCondition(string $filter, string $cond): string
            {
                return $this->appendCondition($filter, $cond);
            }

            // ----- local scope methods (named scopeXxx) -----

            public function scopeActive(string $filter): string
            {
                return $this->appendCondition($filter, 'active = 1');
            }

            public function scopeOlderThan(string $filter, int $days): string
            {
                return $this->appendCondition($filter, "created_days < {$days}");
            }
        };
    }

    /** Reset $globalScopes static bag to avoid cross-test contamination. */
    private function resetGlobalScopes(): void
    {
        $ref = new \ReflectionProperty($this->modelClass, 'globalScopes');
        $ref->setValue(null, []);
    }

    // =========================================================================
    // addGlobalScope / applyGlobalScopes
    // =========================================================================

    /**
     * A scope registered via addGlobalScope() is applied by applyGlobalScopes(),
     * which appends the scope's result to the filter string.
     */
    public function testAddGlobalScopeIsAppliedByApplyGlobalScopes(): void
    {
        // Arrange
        $m = $this->makeModel();
        $m::addGlobalScope('tenant', fn(string $f) => $m->exposedAppendCondition($f, 'tenant_id = 1'));

        // Act
        $result = $m->exposedApplyGlobal('');

        // Assert — scope appended its condition
        $this->assertSame('tenant_id = 1', $result);
    }

    /**
     * Multiple global scopes are applied in registration order and combined
     * with AND-chaining through successive appendCondition() calls.
     */
    public function testMultipleGlobalScopesAreChainedInOrder(): void
    {
        // Arrange
        $m = $this->makeModel();
        $m::addGlobalScope('active', fn(string $f) => $m->exposedAppendCondition($f, 'active = 1'));
        $m::addGlobalScope('tenant', fn(string $f) => $m->exposedAppendCondition($f, 'tenant_id = 5'));

        // Act
        $result = $m->exposedApplyGlobal('');

        // Assert — both conditions present, AND-chained
        $this->assertStringContainsString('active = 1', $result);
        $this->assertStringContainsString('tenant_id = 5', $result);
        $this->assertStringContainsString('AND', $result);
    }

    /**
     * applyGlobalScopes() with no registered scopes returns the original filter
     * string unchanged (possibly an empty string — no side-effects).
     */
    public function testApplyGlobalScopesWithNoScopesReturnsFilterUnchanged(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->exposedApplyGlobal('existing = 1');

        // Assert
        $this->assertSame('existing = 1', $result);
    }

    // =========================================================================
    // removeGlobalScope
    // =========================================================================

    /**
     * removeGlobalScope() unregisters a scope; subsequent applyGlobalScopes()
     * calls do not include that scope's condition.
     */
    public function testRemoveGlobalScopePreventsScopeFromBeingApplied(): void
    {
        // Arrange
        $m = $this->makeModel();
        $m::addGlobalScope('tenant', fn(string $f) => $m->exposedAppendCondition($f, 'tenant_id = 1'));
        $m::addGlobalScope('active', fn(string $f) => $m->exposedAppendCondition($f, 'active = 1'));

        // Act — remove 'tenant', keep 'active'
        $m::removeGlobalScope('tenant');
        $result = $m->exposedApplyGlobal('');

        // Assert — only 'active' remains
        $this->assertSame('active = 1', $result);
    }

    // =========================================================================
    // withoutGlobalScope
    // =========================================================================

    /**
     * withoutGlobalScope() returns $this for fluent use.
     */
    public function testWithoutGlobalScopeReturnsSelf(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->withoutGlobalScope('tenant');

        // Assert
        $this->assertSame($m, $result);
    }

    /**
     * A scope listed via withoutGlobalScope() is skipped for the next
     * applyGlobalScopes() call.  After that call the suppression list is cleared,
     * so the scope fires again on the following applyGlobalScopes() call.
     */
    public function testWithoutGlobalScopeSuppressesScopeForOneCallOnly(): void
    {
        // Arrange
        $m = $this->makeModel();
        $m::addGlobalScope('tenant', fn(string $f) => $m->exposedAppendCondition($f, 'tenant_id = 1'));

        // Act — suppress for first call
        $m->withoutGlobalScope('tenant');
        $firstResult  = $m->exposedApplyGlobal('');

        // Act — second call: suppression is gone, scope fires
        $secondResult = $m->exposedApplyGlobal('');

        // Assert — first call: condition absent
        $this->assertSame('', $firstResult);

        // Assert — second call: condition present
        $this->assertSame('tenant_id = 1', $secondResult);
    }

    // =========================================================================
    // applyScope / applyPendingScopes
    // =========================================================================

    /**
     * applyScope() returns $this for fluent chaining and accumulates the scope
     * in the pending list without invoking it immediately.
     */
    public function testApplyScopeReturnsSelfAndAccumulatesScope(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->applyScope('active');

        // Assert — returns $this (fluent)
        $this->assertSame($m, $result);

        // Assert — scope queued but filter not yet modified
        $this->assertCount(1, $m->_pendingScopes);
    }

    /**
     * applyPendingScopes() executes all queued local scopes against the filter
     * string and clears the pending list.
     */
    public function testApplyPendingScopesExecutesQueuedScopesAndClearsList(): void
    {
        // Arrange — queue two local scopes
        $m = $this->makeModel();
        $m->applyScope('active');
        $m->applyScope('olderThan', 30);

        // Act
        $result = $m->exposedApplyPending('');

        // Assert — both scope conditions are present
        $this->assertStringContainsString('active = 1', $result);
        $this->assertStringContainsString('created_days < 30', $result);

        // Assert — pending list cleared
        $this->assertEmpty($m->_pendingScopes);
    }

    /**
     * applyScope() throws BadMethodCallException when the named scope method
     * does not exist on the model.
     */
    public function testApplyScopeThrowsForUndefinedScope(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Assert — exception before any pending modification
        $this->expectException(\BadMethodCallException::class);

        // Act
        $m->applyScope('nonExistent');
    }

    // =========================================================================
    // appendCondition
    // =========================================================================

    /**
     * appendCondition() returns the condition alone when the existing filter
     * is an empty string — no parentheses, no AND keyword.
     */
    public function testAppendConditionReturnsConditionAloneWhenFilterIsEmpty(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->exposedAppendCondition('', 'status = 1');

        // Assert
        $this->assertSame('status = 1', $result);
    }

    /**
     * appendCondition() wraps both sides in parentheses and joins them with AND
     * when the existing filter is non-empty.
     */
    public function testAppendConditionWrapsAndJoinsWhenFilterIsNonEmpty(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->exposedAppendCondition('type = "post"', 'status = 1');

        // Assert — exact parenthesized form
        $this->assertSame('(type = "post") AND (status = 1)', $result);
    }

    /**
     * appendCondition() returns the existing filter unchanged when the condition
     * to append is an empty string — avoids injecting spurious AND clauses.
     */
    public function testAppendConditionReturnsFilterUnchangedWhenConditionIsEmpty(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->exposedAppendCondition('type = "post"', '');

        // Assert — filter unchanged, no trailing AND
        $this->assertSame('type = "post"', $result);
    }

    /**
     * appendCondition() with both filter and condition empty returns an empty
     * string — the condition guard short-circuits before the join.
     */
    public function testAppendConditionBothEmptyReturnsEmpty(): void
    {
        // Arrange
        $m = $this->makeModel();

        // Act
        $result = $m->exposedAppendCondition('', '');

        // Assert
        $this->assertSame('', $result);
    }
}
