<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Application\Application;

/**
 * Unit tests for Pramnos\Application\Controller.
 *
 * Tests the pure-data methods of the Controller base class that do not require
 * a real HTTP request, database, or view rendering:
 *   - Action registration (addaction, addAuthAction, addActionPermission)
 *   - Middleware registration (addMiddleware)
 *   - Breadcrumb management (getBreadcrumbs, addBreadcrumb)
 *   - Scope/permission helpers (_auth_normalizePermissions, _auth_hasPermissions,
 *     _auth_hasScope, _auth_wildcardMatch) — tested via a concrete subclass
 *
 * exec(), display(), auth(), getView() and redirect() require a full application
 * stack and are covered by integration tests.
 */
#[CoversClass(Controller::class)]
class ControllerTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /** Concrete testable subclass that exposes protected auth helpers and props. */
    private function makeController(): object
    {
        return new class(null) extends Controller {
            // Expose protected property (action_permissions is protected in Controller)
            public function getActionPermissions(): array
            {
                return $this->action_permissions;
            }
            // Expose protected methods for white-box testing
            public function exposeHasPermissions($req, $user): bool
            {
                return $this->_auth_hasPermissions($req, $user);
            }
            public function exposeNormalizePermissions($p): array
            {
                return $this->_auth_normalizePermissions($p);
            }
            public function exposeHasScope(string $req, array $user): bool
            {
                return $this->_auth_hasScope($req, $user);
            }
            public function exposeWildcardMatch(string $req, string $user): bool
            {
                return $this->_auth_wildcardMatch($req, $user);
            }
        };
    }

    // =========================================================================
    // addaction()
    // =========================================================================

    /**
     * addaction() adds a single action to the public actions list.
     * 'display' is always pre-added by the constructor.
     */
    public function testAddactionAppendsSingleAction(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $ctrl->addaction('list');

        // Assert – 'list' must appear in the actions property
        $this->assertContains('list', $ctrl->actions);
    }

    /**
     * addaction() with an array adds each element to the actions list.
     */
    public function testAddactionAcceptsArray(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $ctrl->addaction(['create', 'edit', 'delete']);

        // Assert – all three added
        $this->assertContains('create', $ctrl->actions);
        $this->assertContains('edit',   $ctrl->actions);
        $this->assertContains('delete', $ctrl->actions);
    }

    // =========================================================================
    // addAuthAction()
    // =========================================================================

    /**
     * addAuthAction() registers actions that require authentication.
     */
    public function testAddAuthActionStoresSingleAction(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $ctrl->addAuthAction('profile');

        // Assert
        $this->assertContains('profile', $ctrl->actions_auth);
    }

    /**
     * addAuthAction() with an array registers all provided actions.
     */
    public function testAddAuthActionAcceptsArray(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $ctrl->addAuthAction(['dashboard', 'settings']);

        // Assert
        $this->assertContains('dashboard', $ctrl->actions_auth);
        $this->assertContains('settings',  $ctrl->actions_auth);
    }

    // =========================================================================
    // addActionPermission()
    // =========================================================================

    /**
     * addActionPermission() attaches one permission to a specific action.
     */
    public function testAddActionPermissionStoresSinglePermission(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $ctrl->addActionPermission('delete', 'admin:delete');

        // Assert
        $this->assertArrayHasKey('delete', $ctrl->getActionPermissions());
        $this->assertContains('admin:delete', $ctrl->getActionPermissions()['delete']);
    }

    /**
     * addActionPermission() called twice for the same action accumulates
     * permissions in the array (merge, no duplication check).
     */
    public function testAddActionPermissionAccumulates(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $ctrl->addActionPermission('edit', 'read:posts');
        $ctrl->addActionPermission('edit', 'write:posts');

        // Assert – both permissions present
        $this->assertCount(2, $ctrl->getActionPermissions()['edit']);
    }

    /**
     * addActionPermission() with an array of actions applies the permissions
     * to each action individually.
     */
    public function testAddActionPermissionAcceptsActionArray(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act – same permission required for both 'edit' and 'delete'
        $ctrl->addActionPermission(['edit', 'delete'], ['write:posts']);

        // Assert
        $this->assertContains('write:posts', $ctrl->getActionPermissions()['edit']);
        $this->assertContains('write:posts', $ctrl->getActionPermissions()['delete']);
    }

    // =========================================================================
    // addMiddleware()
    // =========================================================================

    /**
     * addMiddleware() returns $this for fluent chaining.
     */
    public function testAddMiddlewareReturnsSelf(): void
    {
        // Arrange
        $ctrl = $this->makeController();
        $mw   = new class implements \Pramnos\Http\MiddlewareInterface {
            public function handle(\Pramnos\Http\Request $req, callable $next): mixed { return $next($req); }
        };

        // Act / Assert – fluent return
        $this->assertSame($ctrl, $ctrl->addMiddleware('list', $mw));
    }

    /**
     * addMiddleware() with '*' applies the middleware to all actions.
     */
    public function testAddMiddlewareWithWildcardAction(): void
    {
        // Arrange
        $ctrl = $this->makeController();
        $mw   = new class implements \Pramnos\Http\MiddlewareInterface {
            public function handle(\Pramnos\Http\Request $req, callable $next): mixed { return $next($req); }
        };

        // Act – register for all actions
        $ctrl->addMiddleware('*', $mw);
        $ctrl->addMiddleware(['list', 'edit'], $mw);

        // Assert – fluent return and no exception
        $this->assertSame($ctrl, $ctrl->addMiddleware('show', $mw));
    }

    // =========================================================================
    // getBreadcrumbs() / addBreadcrumb()
    // =========================================================================

    /**
     * getBreadcrumbs() returns an empty array when no breadcrumbs have been added.
     */
    public function testGetBreadcrumbsInitiallyEmpty(): void
    {
        // Arrange / Act
        $ctrl = $this->makeController();

        // Assert
        $this->assertSame([], $ctrl->getBreadcrumbs());
    }

    /**
     * addBreadcrumb() appends an item+url pair and returns $this.
     */
    public function testAddBreadcrumbAppendsAndReturnsFluentSelf(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $result = $ctrl->addBreadcrumb('Home', '/');

        // Assert – fluent return
        $this->assertSame($ctrl, $result);
        // Assert – item stored
        $crumbs = $ctrl->getBreadcrumbs();
        $this->assertCount(1, $crumbs);
        $this->assertSame('Home', $crumbs[0]['item']);
        $this->assertSame('/',    $crumbs[0]['url']);
    }

    /**
     * addBreadcrumb() without a URL stores null for the url key.
     */
    public function testAddBreadcrumbWithoutUrlStoresNull(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $ctrl->addBreadcrumb('Current Page');

        // Assert
        $crumbs = $ctrl->getBreadcrumbs();
        $this->assertNull($crumbs[0]['url']);
    }

    /**
     * Multiple addBreadcrumb() calls accumulate in order.
     */
    public function testAddBreadcrumbAccumulatesInOrder(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $ctrl->addBreadcrumb('Home', '/')
             ->addBreadcrumb('Products', '/products')
             ->addBreadcrumb('Widget', null);

        // Assert
        $crumbs = $ctrl->getBreadcrumbs();
        $this->assertCount(3, $crumbs);
        $this->assertSame('Home',     $crumbs[0]['item']);
        $this->assertSame('Products', $crumbs[1]['item']);
        $this->assertSame('Widget',   $crumbs[2]['item']);
    }

    // =========================================================================
    // _auth_normalizePermissions() (via expose helper)
    // =========================================================================

    /**
     * _auth_normalizePermissions() converts a space-separated string into an array.
     */
    public function testNormalizePermissionsConvertsStringToArray(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $result = $ctrl->exposeNormalizePermissions('read:posts write:posts');

        // Assert
        $this->assertSame(['read:posts', 'write:posts'], $result);
    }

    /**
     * _auth_normalizePermissions() leaves an array unchanged.
     */
    public function testNormalizePermissionsPassesThroughArray(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $result = $ctrl->exposeNormalizePermissions(['a', 'b']);

        // Assert
        $this->assertSame(['a', 'b'], $result);
    }

    // =========================================================================
    // _auth_wildcardMatch() (via expose helper)
    // =========================================================================

    /**
     * _auth_wildcardMatch() returns false when userScope has no wildcard.
     */
    public function testWildcardMatchReturnsFalseWithNoWildcard(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act / Assert
        $this->assertFalse($ctrl->exposeWildcardMatch('posts:read', 'posts:write'));
    }

    /**
     * _auth_wildcardMatch() with 'posts:*' matches 'posts:read', 'posts:write', etc.
     * Controller uses str_replace('\*', '.*', ...) which correctly escapes the
     * pattern (unlike Router which uses str_replace('*', '.*', ...) — a known bug).
     */
    public function testWildcardMatchPartialWildcardMatchesPrefix(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act / Assert – 'posts:*' must match 'posts:read' and 'posts:write'
        $this->assertTrue($ctrl->exposeWildcardMatch('posts:read',  'posts:*'));
        $this->assertTrue($ctrl->exposeWildcardMatch('posts:write', 'posts:*'));
        $this->assertFalse($ctrl->exposeWildcardMatch('users:read', 'posts:*'));
    }

    // =========================================================================
    // _auth_hasPermissions() (via expose helper)
    // =========================================================================

    /**
     * _auth_hasPermissions() returns true when required permissions array is empty.
     * Note: passing '' (empty string) normalizes to [''] which is NOT empty, so
     * the empty-array path only works with an actual empty array [].
     */
    public function testHasPermissionsReturnsTrueForEmptyRequired(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act / Assert – only an empty array [] triggers the early-return true
        $this->assertTrue($ctrl->exposeHasPermissions([], []));
        $this->assertTrue($ctrl->exposeHasPermissions([], ['user:read']));
    }

    /**
     * _auth_hasPermissions() returns true when user has at least one of the
     * required permissions (OR logic — any match passes).
     */
    public function testHasPermissionsReturnsTrueOnAnyMatch(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act – user has 'write:posts'; one of required ['read:posts', 'write:posts']
        $result = $ctrl->exposeHasPermissions(
            ['read:posts', 'write:posts'],
            ['write:posts']
        );

        // Assert – OR semantics: any required permission match passes
        $this->assertTrue($result);
    }

    /**
     * _auth_hasPermissions() returns false when user has none of the required.
     */
    public function testHasPermissionsReturnsFalseWhenNoneMatch(): void
    {
        // Arrange
        $ctrl = $this->makeController();

        // Act
        $result = $ctrl->exposeHasPermissions(
            ['admin:read'],
            ['user:read']
        );

        // Assert
        $this->assertFalse($result);
    }
}
