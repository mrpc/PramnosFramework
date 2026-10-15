<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\User\Roles;

/**
 * The roles every bundled screen and tool asks for, in place of a usertype number of its own.
 *
 * An installation that moved its administrators to 97 and made 99 a single root account has to be
 * able to say so once, in `app.php`, and have every screen follow — a number written into one
 * controller is a screen that silently keeps the old line.
 */
#[CoversClass(Roles::class)]
#[CoversClass(\Pramnos\DevPanel\DevPanelController::class)]
#[CoversClass(\Pramnos\Application\Controllers\Adminer::class)]
#[CoversClass(\Pramnos\Debug\DebugGrantController::class)]
#[CoversClass(\Pramnos\Auth\AdminAccess::class)]
class RolesTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $app = Application::getInstance();
        $app->makeCurrentInstance();
        $this->saved = $app->applicationInfo;
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = (array) $this->saved;
        parent::tearDown();
    }

    /** Set `app.php`'s sections for the test. */
    private function config(array $sections): void
    {
        $app = Application::getInstance();
        $app->applicationInfo = $sections + $app->applicationInfo;
        unset($app->applicationInfo['devpanel'], $app->applicationInfo['debug']);
        $app->applicationInfo = $sections + $app->applicationInfo;
    }

    /**
     * Each role's default, an installation's own level for one, and a role nobody defined —
     * refused loudly, because a typo answering 0 would open a screen to everybody.
     */
    public function testEachRolesLevel(): void
    {
        // Arrange
        $this->config(['roles' => ['admin' => 97, 'manager' => 'nonsense']]);

        // Act / Assert
        $this->assertSame(97, Roles::level(Roles::ADMIN), 'the installation\'s');
        $this->assertSame(80, Roles::level(Roles::MANAGER), 'a value that is no number is the default');
        $this->assertSame([98, 99], [Roles::level(Roles::SUPER), Roles::level(Roles::ROOT)]);
        $this->expectException(\InvalidArgumentException::class);
        Roles::level('adminn');
    }

    /** A person holds a role at its level and above; nobody holds none. */
    public function testWhoHoldsARole(): void
    {
        // Arrange
        $this->config(['roles' => ['admin' => 97]]);

        // Act / Assert
        $this->assertTrue(Roles::atLeast((object) ['userid' => 5, 'usertype' => 97], Roles::ADMIN));
        $this->assertFalse(Roles::atLeast((object) ['userid' => 5, 'usertype' => 96], Roles::ADMIN), '90 was the line before; 97 is now');
        $this->assertFalse(Roles::atLeast(null, Roles::MANAGER));
    }

    /**
     * The developer tools — the DevPanel, Adminer, the debug bar — follow the `root` role, the
     * administration area's floor and superuser the `super` one — and
     * a key a tool already had still wins over its role.
     */
    public function testTheToolsFollowTheRoles(): void
    {
        // Arrange — one root account, as an installation sets it
        $this->config(['roles' => ['root' => 100, 'super' => 97]]);
        $rootFloor = new \ReflectionMethod(\Pramnos\Application\Controllers\Adminer::class, 'rootFloor');

        // Act / Assert — by role
        $this->assertSame(100, \Pramnos\DevPanel\DevPanelController::developerFloor());
        $this->assertSame(100, $rootFloor->invoke(null));
        \Pramnos\Application\Settings::setSetting(\Pramnos\Auth\AdminAccess::DEFAULT_FLOOR_SETTING, 0, false);
        \Pramnos\Application\Settings::setSetting(\Pramnos\Auth\AdminAccess::SUPERUSER_SETTING, 0, false);
        $this->assertSame([97, 97], [\Pramnos\Auth\AdminAccess::defaultUsertype(), \Pramnos\Auth\AdminAccess::superuserUsertype()]);

        // A tool's own key wins
        $this->config(['roles' => ['root' => 100], 'devpanel' => ['min_usertype' => 95, 'adminer_min_usertype' => 96]]);
        $this->assertSame(95, \Pramnos\DevPanel\DevPanelController::developerFloor());
        $this->assertSame(96, $rootFloor->invoke(null));
    }

    /**
     * No bundled screen or tool writes a usertype number of its own: a comparison of a usertype
     * with a literal, or a controller's `$requiredUserType` set to one, is a screen that does not
     * follow `roles`. The only numbers allowed are where they are defined, or where they are the
     * value an account is created with rather than a line it must cross.
     */
    public function testNoBundledCodeWritesAUsertypeNumber(): void
    {
        // Arrange
        $allowed = [
            'src/Pramnos/User/Roles.php',      // the defaults themselves
            'src/Pramnos/User/UserTypes.php',  // labels for bands, not gates
        ];
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT . '/src', \FilesystemIterator::SKIP_DOTS));

        // Act
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen(ROOT) + 1);
            if ($file->getExtension() !== 'php' || in_array($relative, $allowed, true)) {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                $code = trim($line);
                if (str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '//')) {
                    continue;
                }
                $compares = preg_match('/usertype[^;]{0,40}(?:>=|<=|<|(?<!=)>)\s*\d{2}\b|\b\d{2}\s*(?:>=|<=|<|>)[^;]{0,30}usertype/i', $code) === 1;
                $requires = preg_match('/\$(?:requiredUserType|perUserUserType|minUserType)\s*=\s*[1-9]\d*\s*;/', $code) === 1;
                if ($compares || $requires) {
                    $found[] = $relative . ':' . ($number + 1);
                }
            }
        }

        // Assert
        $this->assertSame([], $found, "Ask \\Pramnos\\User\\Roles for a role instead of writing a usertype number:\n  " . implode("\n  ", $found));
    }
}
