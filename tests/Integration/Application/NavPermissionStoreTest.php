<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\NavItem;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;
use Pramnos\Application\Settings;
use Pramnos\Auth\Permissions;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Menu visibility decided by the framework's own permission store.
 *
 * {@see \Pramnos\Tests\Unit\Application\NavRegistryTest} covers the filtering rules and the case
 * where the application brings its own scheme — a `User` with a `hasPermission()` method, which
 * `userHasPermission()` asks first because it governs the rest of that application.
 *
 * The other branch is the one that runs on an installation that has *not* written its own: the
 * item's permission name goes to `Pramnos\Auth\Permissions`, and that had never happened in a
 * test. It matters because of what the code before it did — it asked an optional
 * `PermissionEngine` addon that exists nowhere, found it absent, and skipped the check. Every
 * declared permission was skipped on every installation, and every item was shown to every
 * signed-in user.
 *
 * Three answers, and the third is the one that keeps a menu usable:
 *
 *   - an explicit **deny** hides the item;
 *   - an explicit **allow** shows it;
 *   - **no rule at all** shows it. An application that declares permission names and has granted
 *     nothing to anybody would otherwise have an empty menu on first run, and hiding navigation
 *     is not access control — the action behind the item enforces its own.
 *
 * Both backends: {@see NavPermissionStorePostgreSQLTest} re-runs it. The lookup behind
 * `isAllowed()` is a multi-column `WHERE` against the permissions table, and "no row" is the
 * answer the third rule turns on.
 */
#[CoversClass(NavRegistry::class)]
class NavPermissionStoreTest extends BaseTestCase
{
    private $db;

    /** A user id no fixture would collide with, and above the `< 2` floor. */
    private const USER_ID = 9971;

    private const RESOURCE = 'navprobe.reports';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        Permissions::setupDb(false);

        NavRegistry::reset();
        $this->clearRules();

        $_SESSION = ['logged' => true, 'uid' => self::USER_ID];
    }

    protected function tearDown(): void
    {
        $this->clearRules();
        NavRegistry::reset();
        $_SESSION = [];

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /**
     * An explicit deny in the store hides the item.
     *
     * The assertion the old code could not make: it consulted an addon that does not exist and
     * carried on, so a denied permission showed the item anyway.
     */
    public function testAnExplicitDenyHidesTheItem(): void
    {
        // Arrange
        $this->registerGatedItem();
        Permissions::getInstance()->deny(self::USER_ID, self::RESOURCE, 'view');

        // Act & Assert
        $this->assertNotContains(
            'navprobe',
            $this->visibleIds(),
            'a denied permission still showed its menu item'
        );
    }

    /** An explicit allow shows it. */
    public function testAnExplicitAllowShowsTheItem(): void
    {
        // Arrange
        $this->registerGatedItem();
        Permissions::getInstance()->allow(self::USER_ID, self::RESOURCE, 'view');

        // Act & Assert
        $this->assertContains('navprobe', $this->visibleIds());
    }

    /**
     * A permission nobody has said anything about shows the item.
     *
     * Silence is not a deny. This is the rule that makes the check safe to switch on for
     * installations that already declare permission names and have granted none of them — the
     * alternative is every menu emptying itself on upgrade.
     */
    public function testSilenceInTheStoreShowsTheItem(): void
    {
        // Arrange — no rule of any kind for this name.
        $this->registerGatedItem();

        // Act & Assert
        $this->assertContains(
            'navprobe',
            $this->visibleIds(),
            'an unmentioned permission emptied the menu'
        );
    }

    /**
     * A revoked rule goes back to silence rather than to a deny.
     *
     * `removePermission()` deletes the row, and the row is the only difference between "denied"
     * and "never mentioned". An operator taking a grant away from somebody expects the default,
     * which for navigation is visible.
     */
    public function testRemovingARuleReturnsToSilence(): void
    {
        // Arrange
        $this->registerGatedItem();
        $permissions = Permissions::getInstance();
        $permissions->deny(self::USER_ID, self::RESOURCE, 'view');
        $this->assertNotContains('navprobe', $this->visibleIds(), 'precondition: denied');

        // Act
        $permissions->removePermission(self::USER_ID, self::RESOURCE, 'view');

        // Assert
        $this->assertContains('navprobe', $this->visibleIds());
    }

    /**
     * A gated item is hidden from anything that is not a real account.
     *
     * `userid < 2` is the guest and the system account. Neither is somebody a permission can be
     * granted to, so a permission-gated item is not theirs to see — and unlike the silence rule
     * above, this one hides, because there is no account for the store to have an opinion about.
     */
    public function testAGatedItemIsHiddenFromANonAccount(): void
    {
        // Arrange
        $this->registerGatedItem();
        Permissions::getInstance()->allow(1, self::RESOURCE, 'view');
        $_SESSION['uid'] = 1;

        // Act & Assert
        $this->assertNotContains(
            'navprobe',
            $this->visibleIds($this->user(1)),
            'a permission-gated item was shown to the system account'
        );
    }

    /**
     * An application whose own `hasPermission()` raises does not lose its menu.
     *
     * The application's scheme is asked first, and anything can be behind it — a lookup against a
     * service that is down, a table that has not been migrated yet. Hiding navigation is not
     * access control, so a scheme that cannot answer is no opinion, and the action behind the
     * item still enforces its own.
     */
    public function testAnApplicationSchemeThatRaisesIsNoOpinion(): void
    {
        // Arrange
        $this->registerGatedItem();

        $user = new class () extends \Pramnos\User\User {
            public function __construct()
            {
                parent::__construct();
                $this->userid   = NavPermissionStoreTest::userId();
                $this->usertype = 10;
            }

            public function hasPermission(string $name): bool
            {
                throw new \RuntimeException('the permission service is down');
            }
        };

        // Act & Assert
        $this->assertContains(
            'navprobe',
            $this->visibleIds($user),
            'a permission scheme that could not answer emptied the menu'
        );
    }

    /** `getIds()` names what is registered, which is how a theme asks what it has. */
    public function testGetIdsNamesWhatIsRegistered(): void
    {
        // Arrange
        $this->registerGatedItem();
        NavRegistry::register(new NavItem('navprobe.two', 'Two', '/two', NavSection::Main, 1));

        // Act & Assert
        $this->assertSame(['navprobe', 'navprobe.two'], NavRegistry::getIds());

        NavRegistry::remove('navprobe');
        $this->assertSame(['navprobe.two'], NavRegistry::getIds());
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** The probe user id, reachable from the anonymous class above. */
    public static function userId(): int
    {
        return self::USER_ID;
    }

    private function registerGatedItem(): void
    {
        NavRegistry::register(new NavItem(
            'navprobe',
            'Reports',
            '/reports',
            NavSection::Main,
            0,
            requireAuth: true,
            permission: self::RESOURCE,
        ));
    }

    /**
     * A signed-in account with no scheme of its own, so the store is asked.
     *
     * Extends `User` because `getForUser()` is typed `?User`, and that type is part of the
     * contract rather than an accident.
     */
    private function user(int $userId = self::USER_ID): \Pramnos\User\User
    {
        return new class ($userId) extends \Pramnos\User\User {
            public function __construct(int $userId)
            {
                parent::__construct();
                $this->userid   = $userId;
                $this->usertype = 10;
            }
        };
    }

    /** @return list<string> */
    private function visibleIds(?\Pramnos\User\User $user = null): array
    {
        $nav = NavRegistry::getForUser($user ?? $this->user());

        return array_column($nav[NavSection::Main->value] ?? [], 'id');
    }

    /** Every rule this test could have written, and nothing else. */
    private function clearRules(): void
    {
        $permissions = Permissions::getInstance();

        foreach ([self::USER_ID, 1] as $subject) {
            try {
                $permissions->removePermission($subject, self::RESOURCE, 'view');
            } catch (\Throwable $exception) {
                // Nothing to remove is not a failure of this test.
            }
        }
    }
}
