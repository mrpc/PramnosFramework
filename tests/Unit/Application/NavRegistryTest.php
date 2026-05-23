<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\NavItem;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;

/**
 * Unit tests for NavRegistry, NavItem, and NavSection.
 *
 * All tests use NavRegistry::reset() in tearDown() to prevent state leakage
 * between tests (the registry is global static state).
 *
 * The user-visibility rules tested here mirror the documented spec in NavRegistry:
 *   1. requireAuth=true  + guest          → hidden
 *   2. minUserType > 0   + low usertype   → hidden
 *   3. feature gate      + missing feature → hidden
 *   4. RBAC              + no PermissionEngine class → fallback to minUserType only
 *   5. Both minUserType and RBAC must pass when set
 */
#[CoversClass(NavRegistry::class)]
#[CoversClass(NavItem::class)]
#[CoversClass(NavSection::class)]
class NavRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        // Ensure each test starts with an empty registry
        NavRegistry::reset();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // register / remove / reset
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registering an item makes it retrievable via getIds().
     */
    public function testRegisterAddsItem(): void
    {
        // Arrange
        $item = new NavItem('main.home', 'Home', 'http://example.com/', NavSection::Main);

        // Act
        NavRegistry::register($item);

        // Assert
        $this->assertContains('main.home', NavRegistry::getIds());
    }

    /**
     * Registering an item with the same id replaces the previous one —
     * applications can override framework defaults by id.
     */
    public function testRegisterOverridesExistingId(): void
    {
        // Arrange
        $first  = new NavItem('main.home', 'Home',        'http://example.com/', NavSection::Main);
        $second = new NavItem('main.home', 'Custom Home', 'http://example.com/custom/', NavSection::Main);

        // Act
        NavRegistry::register($first);
        NavRegistry::register($second);
        $nav = NavRegistry::getForUser(null);

        // Assert — only one item, with the label from the second registration
        $mainItems = $nav[NavSection::Main->value] ?? [];
        $this->assertCount(1, $mainItems, 'Duplicate id must replace, not accumulate');
        $this->assertSame('Custom Home', $mainItems[0]->label);
    }

    /**
     * remove() deletes a registered item; getForUser() no longer returns it.
     */
    public function testRemoveDeletesItem(): void
    {
        // Arrange
        NavRegistry::register(new NavItem('admin.logs', 'Logs', '/logs', NavSection::Admin));

        // Act
        NavRegistry::remove('admin.logs');

        // Assert — registry no longer contains the id
        $this->assertNotContains('admin.logs', NavRegistry::getIds());
        $nav = NavRegistry::getForUser(null);
        $this->assertEmpty($nav[NavSection::Admin->value] ?? []);
    }

    /**
     * remove() on a non-existent id is a silent no-op — must not throw.
     */
    public function testRemoveNonExistentIdIsNoop(): void
    {
        // Act & Assert — no exception
        NavRegistry::remove('does.not.exist');
        $this->addToAssertionCount(1);
    }

    /**
     * reset() empties the registry completely — subsequent getForUser() returns [].
     */
    public function testResetClearsAllItems(): void
    {
        // Arrange — register two items
        NavRegistry::register(new NavItem('a', 'A', '/a', NavSection::Main));
        NavRegistry::register(new NavItem('b', 'B', '/b', NavSection::Admin));

        // Act
        NavRegistry::reset();

        // Assert
        $this->assertEmpty(NavRegistry::getIds());
        $this->assertEmpty(NavRegistry::getForUser(null));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guest visibility (no user)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A guest user sees only requireAuth=false items.
     *
     * Items with requireAuth=true must be completely absent from the result —
     * leaking admin/account links to guests is a security defect.
     */
    public function testGuestSeesOnlyPublicItems(): void
    {
        // Arrange
        NavRegistry::register(new NavItem('main.home',   'Home',    '/',        NavSection::Main,  0, requireAuth: false));
        NavRegistry::register(new NavItem('user.login',  'Login',   '/login',   NavSection::User,  0, requireAuth: false));
        NavRegistry::register(new NavItem('user.account','Account', '/account', NavSection::User, 10, requireAuth: true));
        NavRegistry::register(new NavItem('admin.logs',  'Logs',    '/logs',    NavSection::Admin, 10, requireAuth: true));

        // Act — null user = guest
        $nav = NavRegistry::getForUser(null);

        // Assert — Home and Login are visible
        $this->assertCount(1, $nav[NavSection::Main->value] ?? [], 'Guest must see Home');
        $this->assertCount(1, $nav[NavSection::User->value] ?? [], 'Guest must see Login only');
        $this->assertSame('Login', ($nav[NavSection::User->value][0])->label);

        // Assert — Account and Logs are hidden
        $this->assertEmpty($nav[NavSection::Admin->value] ?? [], 'Guest must not see Admin items');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // usertype filtering
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A user with usertype=50 does NOT see items with minUserType=80.
     *
     * The usertype gate protects admin-only links from regular users even when
     * they are authenticated.
     */
    public function testLowUsertypeDoesNotSeeHighMinUserTypeItems(): void
    {
        // Arrange — user with usertype 50
        $user = $this->makeUser(50);
        NavRegistry::register(new NavItem(
            'admin.logs', 'Logs', '/logs', NavSection::Admin,
            10, requireAuth: true, minUserType: 80,
        ));

        // Act
        $nav = NavRegistry::getForUser($user);

        // Assert
        $this->assertEmpty($nav[NavSection::Admin->value] ?? [],
            'usertype=50 must not see minUserType=80 items');
    }

    /**
     * A user with usertype=90 sees items with minUserType=80.
     */
    public function testHighUsertypeSeesMinUserTypeItems(): void
    {
        // Arrange — admin user with usertype 90
        $user = $this->makeUser(90);
        NavRegistry::register(new NavItem(
            'admin.logs', 'Logs', '/logs', NavSection::Admin,
            10, requireAuth: true, minUserType: 80,
        ));

        // Act
        $nav = NavRegistry::getForUser($user);

        // Assert
        $this->assertCount(1, $nav[NavSection::Admin->value] ?? [],
            'usertype=90 must see minUserType=80 items');
    }

    /**
     * A guest never sees items with minUserType > 0, even if requireAuth=false
     * (which would be unusual config, but the rules must be independent).
     */
    public function testGuestDoesNotSeeMinUsertypeItemEvenIfAuthNotRequired(): void
    {
        // Arrange
        NavRegistry::register(new NavItem(
            'edge.case', 'Edge', '/edge', NavSection::Main,
            0, requireAuth: false, minUserType: 1,
        ));

        // Act
        $nav = NavRegistry::getForUser(null);

        // Assert — minUserType > 0 requires an actual logged-in user
        $this->assertEmpty($nav[NavSection::Main->value] ?? []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Feature gate
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * An item with feature='queue' is hidden when 'queue' is not in $enabledFeatures.
     */
    public function testFeatureGateHidesItemWhenFeatureDisabled(): void
    {
        // Arrange
        $user = $this->makeUser(90);
        NavRegistry::register(new NavItem(
            'admin.queue', 'Queue', '/queue', NavSection::Admin,
            30, requireAuth: true, minUserType: 80, feature: 'queue',
        ));

        // Act — features list does NOT contain 'queue'
        $nav = NavRegistry::getForUser($user, ['auth', 'messaging']);

        // Assert
        $this->assertEmpty($nav[NavSection::Admin->value] ?? [],
            'queue item must be hidden when queue feature is not enabled');
    }

    /**
     * An item with feature='queue' is visible when 'queue' IS in $enabledFeatures.
     */
    public function testFeatureGateShowsItemWhenFeatureEnabled(): void
    {
        // Arrange
        $user = $this->makeUser(90);
        NavRegistry::register(new NavItem(
            'admin.queue', 'Queue', '/queue', NavSection::Admin,
            30, requireAuth: true, minUserType: 80, feature: 'queue',
        ));

        // Act
        $nav = NavRegistry::getForUser($user, ['auth', 'queue']);

        // Assert
        $this->assertCount(1, $nav[NavSection::Admin->value] ?? []);
        $this->assertSame('Queue', ($nav[NavSection::Admin->value][0])->label);
    }

    /**
     * An item with feature=null is always shown regardless of $enabledFeatures.
     */
    public function testNullFeatureIsAlwaysShown(): void
    {
        // Arrange
        NavRegistry::register(new NavItem(
            'main.home', 'Home', '/', NavSection::Main, 0, feature: null,
        ));

        // Act
        $nav = NavRegistry::getForUser(null, []);

        // Assert
        $this->assertCount(1, $nav[NavSection::Main->value] ?? []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Position / ordering
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Items within a section are sorted ascending by position.
     *
     * Registration order must not determine display order — only position does.
     */
    public function testItemsAreSortedByPositionWithinSection(): void
    {
        // Arrange — register in reverse order
        NavRegistry::register(new NavItem('a3', 'Third',  '/c', NavSection::Main, 30));
        NavRegistry::register(new NavItem('a1', 'First',  '/a', NavSection::Main, 10));
        NavRegistry::register(new NavItem('a2', 'Second', '/b', NavSection::Main, 20));

        // Act
        $nav = NavRegistry::getForUser(null);

        // Assert — must appear as First, Second, Third regardless of registration order
        $labels = array_map(fn($i) => $i->label, $nav[NavSection::Main->value] ?? []);
        $this->assertSame(['First', 'Second', 'Third'], $labels,
            'Items must be sorted by position, not registration order');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section grouping
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Items from different sections are returned under their respective section keys.
     *
     * The template relies on this grouping to render Main/User/Admin clusters
     * in distinct HTML elements.
     */
    public function testItemsAreGroupedBySection(): void
    {
        // Arrange
        $user = $this->makeUser(90);
        NavRegistry::register(new NavItem('main.home',  'Home',    '/',       NavSection::Main,  0));
        NavRegistry::register(new NavItem('user.login', 'Login',   '/login',  NavSection::User,  0));
        NavRegistry::register(new NavItem('admin.logs', 'Logs',    '/logs',   NavSection::Admin, 0, requireAuth: true, minUserType: 80));

        // Act
        $nav = NavRegistry::getForUser($user);

        // Assert — each section has exactly one item
        $this->assertCount(1, $nav[NavSection::Main->value]  ?? []);
        $this->assertCount(1, $nav[NavSection::User->value]  ?? []);
        $this->assertCount(1, $nav[NavSection::Admin->value] ?? []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Application::registerDefaultNavItems integration
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * registerDefaultNavItems() with no features registers Home, Login, Logs
     * but NOT OAuth Apps (which requires 'authserver' feature).
     */
    public function testRegisterDefaultNavItemsWithNoFeatures(): void
    {
        // Arrange
        $app = new \Pramnos\Application\Application();

        // Act
        $app->registerDefaultNavItems([]);

        // Assert — Home registered in Main section
        $this->assertContains('main.home', NavRegistry::getIds());

        // Assert — Login/Account/Logout registered
        $this->assertContains('user.login',   NavRegistry::getIds());
        $this->assertContains('user.account', NavRegistry::getIds());
        $this->assertContains('user.logout',  NavRegistry::getIds());

        // Assert — always-on admin items registered
        $this->assertContains('admin.logs',     NavRegistry::getIds());
        $this->assertContains('admin.users',    NavRegistry::getIds());
        $this->assertContains('admin.settings', NavRegistry::getIds());

        // Assert — OAuth Apps NOT registered (no authserver feature)
        $this->assertNotContains('admin.oauth', NavRegistry::getIds());
    }

    /**
     * registerDefaultNavItems() with 'authserver' also registers OAuth Apps.
     */
    public function testRegisterDefaultNavItemsWithAuthserver(): void
    {
        // Arrange
        $app = new \Pramnos\Application\Application();

        // Act
        $app->registerDefaultNavItems(['auth', 'authserver']);

        // Assert — OAuth Apps item is registered
        $this->assertContains('admin.oauth', NavRegistry::getIds());
    }

    /**
     * Calling registerDefaultNavItems() twice (e.g. re-init) replaces items
     * rather than duplicating them — duplicate id protection is critical.
     */
    public function testRegisterDefaultNavItemsIsIdempotent(): void
    {
        // Arrange
        $app = new \Pramnos\Application\Application();

        // Act — call twice
        $app->registerDefaultNavItems([]);
        $app->registerDefaultNavItems([]);

        // Assert — exactly one 'main.home' entry (no duplicates)
        $mainItems = count(array_filter(
            NavRegistry::getForUser(null)[NavSection::Main->value] ?? [],
            fn($i) => $i->id === 'main.home'
        ));
        $this->assertSame(1, $mainItems, 'registerDefaultNavItems must be idempotent');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Creates a minimal User stub with the given usertype.
     * The stub has a valid userid so Session::staticIsLogged() returns true.
     */
    private function makeUser(int $usertype): \Pramnos\User\User
    {
        $user           = new \Pramnos\User\User();
        $user->userid   = 42;
        $user->usertype = $usertype;
        // Simulate logged-in session for permission checks
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 42;
        return $user;
    }
}
