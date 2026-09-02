<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Auth\Gate;

/**
 * `can()` and `cannot()` — the rule layer a controller asks before it acts.
 *
 * Four statements between them, never executed, in the pair every guard clause in an application
 * is written with. Both are thin by design: the decision belongs to the `Gate`, and a controller
 * that reimplemented any of it would have a second opinion about the same ability.
 *
 * `cannot()` is defined as `!$this->can(…)` rather than as its own `Gate` call, which is the detail
 * worth pinning: the two can never disagree, and a rule registered after the controller was
 * constructed is seen by both. An independent implementation is how a codebase ends up allowing
 * something in one guard clause and refusing it in another.
 *
 * The arguments matter as much as the answer. A rule is `fn($user, $post) => …`, so an ability
 * asked without its subject is a different question — and one that would be answered `true` by a
 * rule expecting to compare an owner.
 */
#[CoversClass(Controller::class)]
class ControllerAbilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Gate::reset();
        parent::tearDown();
    }

    private function controller(): Controller
    {
        return new class extends Controller {
            public function __construct() {}
        };
    }

    /**
     * An allowed ability is `true` from `can()` and `false` from `cannot()`.
     *
     * Both, in one test, because the pair's only real contract is that they are opposites.
     */
    public function testAnAllowedAbilityIsTheOppositeInBoth(): void
    {
        // Arrange
        Gate::define('publish', static fn (): bool => true);
        $controller = $this->controller();

        // Act + Assert
        $this->assertTrue($controller->can('publish'));
        $this->assertFalse($controller->cannot('publish'));
    }

    /**
     * A refused ability is the other way round.
     */
    public function testARefusedAbilityIsTheOppositeInBoth(): void
    {
        // Arrange
        Gate::define('publish', static fn (): bool => false);
        $controller = $this->controller();

        // Act + Assert
        $this->assertFalse($controller->can('publish'));
        $this->assertTrue($controller->cannot('publish'));
    }

    /**
     * An ability nobody defined is refused, not allowed.
     *
     * The direction that matters. A controller asking about an ability whose rule was never
     * registered — a typo, a provider that did not boot — must not be told yes.
     */
    public function testAnUndefinedAbilityIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();

        // Act + Assert
        $this->assertFalse($controller->can('never-registered'));
        $this->assertTrue($controller->cannot('never-registered'));
    }

    /**
     * The arguments reach the rule, in order.
     *
     * A rule is `fn($user, $subject) => …`, so an ability asked without its subject is a different
     * question — and a `can()` that dropped the arguments would ask a rule comparing an owner to
     * compare it against nothing.
     */
    public function testTheArgumentsReachTheRuleInOrder(): void
    {
        // Arrange
        $seen = [];
        Gate::define('edit', static function ($user, ...$arguments) use (&$seen): bool {
            $seen = $arguments;

            return true;
        });

        // Act
        $this->controller()->can('edit', 'first', 'second');

        // Assert
        $this->assertSame(['first', 'second'], $seen);
    }

    /**
     * `cannot()` passes its arguments through as well.
     *
     * Separately asserted because it delegates: a `cannot()` that called `can($ability)` without
     * the rest would negate the answer to a different question, and every negated guard clause in
     * an application would be wrong in the same invisible way.
     */
    public function testCannotPassesItsArgumentsThroughToo(): void
    {
        // Arrange
        $seen = [];
        Gate::define('edit', static function ($user, ...$arguments) use (&$seen): bool {
            $seen = $arguments;

            return false;
        });

        // Act
        $refused = $this->controller()->cannot('edit', 'first', 'second');

        // Assert
        $this->assertTrue($refused);
        $this->assertSame(['first', 'second'], $seen);
    }
}
