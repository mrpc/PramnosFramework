<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;

/**
 * Every administration screen the framework ships declares the ability that opens it.
 *
 * Under `admin_access = permissions` a screen is opened by its ability, named after its menu item.
 * A screen that declares none falls back to its usertype floor — so it would be open to every
 * administrator above it while the menu hid its link, which is the one combination this was built
 * to make impossible. It would not fail anything; it would just be the screen nobody can take away.
 */
class AdminScreensDeclareAnAbilityTest extends TestCase
{
    /** @return array<string, string> file => declared ability */
    private function declarations(): array
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match("/protected string \\\$adminAbility = '([^']+)';/", $source, $m)) {
                $found[substr($file->getPathname(), strlen($root))] = $m[1];
            }
        }

        return $found;
    }

    /** @return list<string> The Admin menu items the framework registers. */
    private function adminMenuIds(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php');
        preg_match_all("/new NavItem\\(\\s*'(admin\\.[a-z]+)'[^;]*?NavSection::Admin/s", $source, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Every Admin menu item has a screen declaring its id, and every declared ability names an
     * item — so the menu and the screen cannot disagree about what opens it.
     */
    public function testEveryAdminMenuItemHasAScreenDeclaringIt(): void
    {
        // Arrange
        $menu     = $this->adminMenuIds();
        $declared = $this->declarations();

        // Act
        $this->assertNotEmpty($menu, 'the sweep found nothing to check');
        $undeclared = array_values(array_diff($menu, $declared));
        $unknown    = array_values(array_diff($declared, $menu));

        // Assert
        $this->assertSame([], $undeclared, 'Admin menu items no screen declares as its $adminAbility');
        $this->assertSame([], $unknown, 'declared abilities with no Admin menu item to grant them by');
    }

    /**
     * Every controller that guards itself with requireMinUserType() is an administration screen and
     * declares an ability — the guard is what the ability switches from a floor to a grant.
     */
    public function testEveryFloorGuardedControllerDeclaresAnAbility(): void
    {
        // Arrange
        $root = dirname(__DIR__, 3) . '/src/';
        $missing = [];
        $scanned = 0;

        // Act
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'Application/Controller.php')
                || str_contains($file->getPathname(), '/Console/')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'requireMinUserType(')) {
                continue;
            }
            $scanned++;
            if (!str_contains($source, 'protected string $adminAbility =')
                && !str_contains($source, 'trait AdminScreenGrants')) {
                $missing[] = substr($file->getPathname(), strlen($root));
            }
        }

        // Assert
        $this->assertGreaterThan(10, $scanned, 'the sweep found nothing to check');
        $this->assertSame([], $missing);
    }

    /**
     * The Invitations screen is registered with the auth feature, at 98 — inviting ends in an
     * account, a superuser's decision under usertype — and not without the feature.
     */
    public function testTheInvitationsScreenIsRegisteredWithTheAuthFeature(): void
    {
        // Arrange
        $saved = \Pramnos\Application\NavRegistry::all();
        \Pramnos\Application\NavRegistry::reset();

        try {
            // Act
            (new \Pramnos\Application\Application())->registerDefaultNavItems(['auth']);
            $with = array_column(\Pramnos\Application\NavRegistry::all(), null, 'id');
            \Pramnos\Application\NavRegistry::reset();
            (new \Pramnos\Application\Application())->registerDefaultNavItems([]);
            $without = array_column(\Pramnos\Application\NavRegistry::all(), 'id');
        } finally {
            \Pramnos\Application\NavRegistry::reset();
            foreach ($saved as $item) {
                \Pramnos\Application\NavRegistry::register($item);
            }
        }

        // Assert
        $this->assertArrayHasKey('admin.invitations', $with);
        $this->assertSame(98, $with['admin.invitations']->minUserType);
        $this->assertSame('auth', $with['admin.invitations']->feature);
        $this->assertNotContains('admin.invitations', $without);
    }
}
