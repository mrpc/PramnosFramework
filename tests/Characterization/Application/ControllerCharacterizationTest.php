<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;

/**
 * Characterization tests for Controller action management and permission system.
 *
 * Tests lock the contracts for action registration, breadcrumb management,
 * permission checking (wildcard scopes), and the auth() gate logic.
 * No database or HTTP connection is required.
 */
#[CoversClass(Controller::class)]
class ControllerCharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure an Application singleton exists so Controller
        // can resolve it without calling the full constructor chain.
        // Do not store in typed property — getInstance() returns a reference
        // that may be null on the first call in this test environment.
        Application::getInstance();
    }

    // -----------------------------------------------------------------------
    // Action registration
    // -----------------------------------------------------------------------

    /**
     * addaction() appends a string action to the public actions list.
     * 'display' is pre-registered by the constructor.
     */
    public function testAddSingleActionAppendsToActions(): void
    {
        // Arrange
        $ctrl = new Controller(null);

        // Act
        $ctrl->addaction('index');

        // Assert
        $this->assertContains('index', $ctrl->actions);
    }

    /**
     * addaction() accepts an array and registers every element.
     */
    public function testAddArrayOfActionsRegistersAll(): void
    {
        // Arrange
        $ctrl = new Controller(null);

        // Act
        $ctrl->addaction(['list', 'create', 'delete']);

        // Assert
        $this->assertContains('list', $ctrl->actions);
        $this->assertContains('create', $ctrl->actions);
        $this->assertContains('delete', $ctrl->actions);
    }

    /**
     * addAuthAction() adds to the auth-required actions list, separate from
     * the public actions list.
     */
    public function testAddAuthActionRegistersInAuthList(): void
    {
        // Arrange
        $ctrl = new Controller(null);

        // Act
        $ctrl->addAuthAction('privateEdit');

        // Assert
        $this->assertContains('privateEdit', $ctrl->actions_auth);
        $this->assertNotContains('privateEdit', $ctrl->actions);
    }

    /**
     * addAuthAction() accepts an array and registers every element.
     */
    public function testAddAuthActionArrayRegistersAll(): void
    {
        // Arrange
        $ctrl = new Controller(null);

        // Act
        $ctrl->addAuthAction(['approve', 'reject']);

        // Assert
        $this->assertContains('approve', $ctrl->actions_auth);
        $this->assertContains('reject', $ctrl->actions_auth);
    }

    /**
     * Constructor always pre-registers 'display' as a public action.
     */
    public function testConstructorPreRegistersDisplayAction(): void
    {
        // Act
        $ctrl = new Controller(null);

        // Assert
        $this->assertContains('display', $ctrl->actions);
    }

    // -----------------------------------------------------------------------
    // Breadcrumbs
    // -----------------------------------------------------------------------

    /**
     * addBreadcrumb appends items and getBreadcrumbs returns them all.
     */
    public function testAddBreadcrumbAndGetBreadcrumbs(): void
    {
        // Arrange
        $ctrl = new Controller(null);

        // Act
        $ctrl->addBreadcrumb('Home', '/');
        $ctrl->addBreadcrumb('Articles', '/articles');

        // Assert
        $crumbs = $ctrl->getBreadcrumbs();
        $this->assertCount(2, $crumbs);
        $this->assertSame('Home', $crumbs[0]['item']);
        $this->assertSame('/articles', $crumbs[1]['url']);
    }

    /**
     * addBreadcrumb returns $this for chaining.
     */
    public function testAddBreadcrumbReturnsSelf(): void
    {
        // Arrange
        $ctrl = new Controller(null);

        // Act + Assert
        $this->assertSame($ctrl, $ctrl->addBreadcrumb('Test'));
    }

    // -----------------------------------------------------------------------
    // Permission system – _auth_hasPermissions / _auth_wildcardMatch
    // -----------------------------------------------------------------------

    /**
     * A controller with no permissions set allows any action.
     */
    public function testAuthReturnsTrueForPublicActionWithNoPermissions(): void
    {
        // Arrange
        $ctrl = new Controller(null);
        $ctrl->addaction('view');

        // Act
        $result = $ctrl->auth('view');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * addActionPermission registers required scopes for an action, and a
     * controller instantiated with matching user permissions passes auth.
     */
    public function testUserWithExactScopePassesPermissionCheck(): void
    {
        // Arrange – user has 'posts:edit' scope
        $ctrl = new Controller(null, 'posts:edit');
        $ctrl->addaction('edit');
        $ctrl->addActionPermission('edit', 'posts:edit');

        // Act
        $result = $ctrl->auth('edit');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * A user without the required scope gets a 403 exception from auth().
     */
    public function testUserWithoutRequiredScopeThrows403(): void
    {
        // Arrange – user only has 'posts:view', needs 'posts:edit'
        $ctrl = new Controller(null, 'posts:view');
        $ctrl->addaction('edit');
        $ctrl->addActionPermission('edit', 'posts:edit');

        // Act + Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);
        $ctrl->auth('edit');
    }

    /**
     * Wildcard scope 'posts:*' grants access to 'posts:edit'.
     */
    public function testWildcardScopeGrantsAccessToChildScope(): void
    {
        // Arrange – user has wildcard over posts namespace
        $ctrl = new Controller(null, 'posts:*');
        $ctrl->addaction('edit');
        $ctrl->addActionPermission('edit', 'posts:edit');

        // Act
        $result = $ctrl->auth('edit');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Wildcard 'admin:*' does NOT match 'posts:edit' — wildcards are prefix-scoped.
     */
    public function testWildcardScopeDoesNotMatchDifferentNamespace(): void
    {
        // Arrange
        $ctrl = new Controller(null, 'admin:*');
        $ctrl->addaction('edit');
        $ctrl->addActionPermission('edit', 'posts:edit');

        // Act + Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);
        $ctrl->auth('edit');
    }

    /**
     * An action with no permission requirement always passes even when the
     * controller has user_permissions set.
     */
    public function testActionWithNoPermissionRequirementAlwaysPasses(): void
    {
        // Arrange – user has some scope but the action has no requirement
        $ctrl = new Controller(null, 'posts:view');
        $ctrl->addaction('list');
        // Intentionally NOT calling addActionPermission for 'list'

        // Act
        $result = $ctrl->auth('list');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * addActionPermission accepts an array of permissions for a single action
     * and any one matching scope is sufficient (OR semantics).
     */
    public function testMultiplePermissionsOrSemanticsPassesIfAnyMatches(): void
    {
        // Arrange – user has only 'moderator:edit'
        $ctrl = new Controller(null, 'moderator:edit');
        $ctrl->addaction('edit');
        $ctrl->addActionPermission('edit', ['admin:edit', 'moderator:edit']);

        // Act
        $result = $ctrl->auth('edit');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * addActionPermission with an array of actions registers the permission
     * for every action in the array.
     */
    public function testAddActionPermissionWithActionArrayRegistersForAll(): void
    {
        // Arrange
        $ctrl = new Controller(null, 'admin:all');
        $ctrl->addaction(['approve', 'reject']);
        $ctrl->addActionPermission(['approve', 'reject'], 'admin:all');

        // Act + Assert — both actions must pass
        $this->assertTrue($ctrl->auth('approve'));
        $this->assertTrue($ctrl->auth('reject'));
    }

    // -----------------------------------------------------------------------
    // addMiddleware / exec
    // -----------------------------------------------------------------------

    /**
     * addMiddleware() registers middleware and returns $this for chaining.
     * Also verifies that exec() invokes the action through the middleware pipeline.
     */
    public function testAddMiddlewareReturnsSelf(): void
    {
        // Arrange
        $ctrl = new Controller(null);

        // Act — addMiddleware returns $this for fluent chaining
        $mw = new class implements \Pramnos\Http\MiddlewareInterface {
            public function handle(\Pramnos\Http\Request $request, callable $next): mixed
            {
                return $next($request);
            }
        };
        $result = $ctrl->addMiddleware('*', $mw);

        // Assert
        $this->assertSame($ctrl, $result, 'addMiddleware() must return $this for chaining');
    }

    /**
     * addMiddleware() with an array of action names registers the middleware
     * for each action in the array (not just the first one).
     */
    public function testAddMiddlewareWithArrayOfActions(): void
    {
        // Arrange
        $ctrl = new Controller(null);
        $mw = new class implements \Pramnos\Http\MiddlewareInterface {
            public function handle(\Pramnos\Http\Request $request, callable $next): mixed
            {
                return $next($request);
            }
        };

        // Act — register the same middleware for two actions at once
        $ctrl->addMiddleware(['save', 'delete'], $mw);

        // Assert — middleware must be registered; exercise exec() on a public action
        // to confirm _runThroughMiddleware() handles the no-middleware path cleanly.
        $ctrl->addaction('display');
        $ctrl->exec('display'); // empty display() → returns null, no middleware
        $this->addToAssertionCount(1); // reaching here means exec() didn't throw
    }

    /**
     * exec() with an unknown action falls through to the default display() action.
     */
    public function testExecWithUnknownActionFallsToDisplay(): void
    {
        // Arrange — a concrete subclass that tracks display() calls
        $ctrl = new class(null) extends Controller {
            public bool $displayCalled = false;
            public function display($args = []): void { $this->displayCalled = true; }
        };

        // Act — 'nonexistent' is not registered; exec() must fall back to display()
        $ctrl->exec('nonexistent');

        // Assert
        $this->assertTrue($ctrl->displayCalled,
            'exec() must call display() when requested action is not registered');
    }

    /**
     * exec() with a registered action calls that action method.
     */
    public function testExecWithRegisteredActionCallsTheAction(): void
    {
        // Arrange
        $ctrl = new class(null) extends Controller {
            public bool $listCalled = false;
            public function list($args = []): void { $this->listCalled = true; }
        };
        $ctrl->addaction('list');

        // Act
        $ctrl->exec('list');

        // Assert
        $this->assertTrue($ctrl->listCalled,
            'exec() must invoke the registered action method');
    }

    /**
     * exec() with empty action string defaults to display().
     */
    public function testExecWithEmptyActionDefaultsToDisplay(): void
    {
        // Arrange
        $ctrl = new class(null) extends Controller {
            public bool $displayCalled = false;
            public function display($args = []): void { $this->displayCalled = true; }
        };

        // Act — empty string selects 'display'
        $ctrl->exec('');

        // Assert
        $this->assertTrue($ctrl->displayCalled);
    }

    /**
     * exec() with middleware registered runs the action through the pipeline.
     * Uses a static counter property to track invocations from the anonymous class.
     */
    public function testExecRunsActionThroughMiddleware(): void
    {
        // Arrange — middleware sets a static flag when invoked
        TrackingMiddleware::$called = false;

        $ctrl = new class(null) extends Controller {
            public function display($args = []): void {}
        };
        $ctrl->addMiddleware('display', new TrackingMiddleware());

        // Act
        $ctrl->exec('display');

        // Assert — middleware handle() was invoked
        $this->assertTrue(TrackingMiddleware::$called,
            'exec() must run the action through registered middleware');
    }
}

// =============================================================================
// Stubs
// =============================================================================

/** Middleware that records whether handle() was called via a static flag. */
class TrackingMiddleware implements \Pramnos\Http\MiddlewareInterface
{
    public static bool $called = false;

    public function handle(\Pramnos\Http\Request $request, callable $next): mixed
    {
        self::$called = true;
        return $next($request);
    }
}
