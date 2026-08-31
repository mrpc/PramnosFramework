<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;

/**
 * The roles screen has an address in a project this framework generates.
 *
 * `Application::registerAdminNav()` registers `admin.roles` unconditionally, and the
 * administration area resolves `src/Admin/Controllers` before the framework's own. So a project
 * with no class there gets a menu entry that answers **404** — the navigation offers a screen
 * nobody can open.
 *
 * Found by an application's own test, not by this suite: `/admin/Roles => 404` in a check that
 * walks every link the nav offers. Worth pinning here so the next framework screen does not
 * repeat it.
 */
class RolesScreenIsReachableTest extends TestCase
{
    /**
     * The nav registers it.
     */
    public function testTheNavigationOffersIt(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php'
        );

        // Assert
        $this->assertStringContainsString("'admin.roles'", $source);
    }

    /**
     * And a scaffolded project gets the wrapper that puts it on that address.
     *
     * Registering the link and not scaffolding the controller is the whole bug: each half looks
     * finished on its own.
     */
    public function testAScaffoldedProjectGetsTheWrapper(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );

        // Assert
        $this->assertStringContainsString('src/Admin/Controllers/Roles.php', $source);
        $this->assertStringContainsString('class Roles extends FrameworkRolesController', $source);
    }

    /**
     * Every framework admin controller the nav points at is scaffolded.
     *
     * The general form of the same mistake. A screen added to the nav without a wrapper is a
     * 404 on a link the menu draws, and it is invisible to whoever added it because the
     * framework's own tests never route through a project.
     */
    public function testEveryAdminNavTargetIsScaffolded(): void
    {
        // Arrange
        $init = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );
        $app  = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php'
        );

        preg_match_all("~\\\$admin\\('([A-Za-z]+)'\\)~", $app, $matches);

        $missing = [];

        foreach (array_unique($matches[1] ?? []) as $screen) {
            /*
             * Case-insensitive, and either directory.
             *
             * The nav names a screen the way a URL does — `$admin('users')` — and the scaffolded
             * file is `Users.php`; the area's resolver does not care. And `Health` lives in
             * `src/Controllers` on purpose, because `/health/check` is what a monitor calls and
             * it must answer without a session. A check that demanded the admin directory would
             * report that deliberate decision as a fault.
             */
            $inAdmin  = 'src/Admin/Controllers/' . $screen . '.php';
            $inPublic = 'src/Controllers/' . $screen . '.php';

            if (stripos($init, $inAdmin) === false && stripos($init, $inPublic) === false) {
                $missing[] = $screen;
            }
        }

        // Assert
        $this->assertSame([], $missing,
            'the navigation points at screens no generated project has a controller for: '
            . implode(', ', $missing));
    }
}
