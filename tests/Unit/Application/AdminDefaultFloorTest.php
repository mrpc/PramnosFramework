<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\SettingsController;
use Pramnos\Application\Settings;

/** A framework screen as an application extends it without touching the floor. */
class UntouchedSettingsScreen extends SettingsController
{
    public function floor(): int
    {
        return $this->requiredUserType;
    }

    public function checkedFloor(): int
    {
        return $this->adminFloor();
    }
}

/** An application that redeclares the floor keeps it. */
class RedeclaredSettingsScreen extends UntouchedSettingsScreen
{
    protected int $requiredUserType = 50;
}

/** An application that sets the floor before the framework's constructor runs keeps it. */
class ConstructedSettingsScreen extends UntouchedSettingsScreen
{
    public function __construct()
    {
        $this->requiredUserType = 60;
        parent::__construct(null);
    }
}

/** A screen with an ability and no floor of its own, as Health and Logs are. */
class FloorlessScreen extends \Pramnos\Application\Controller
{
    protected string $adminAbility = 'admin.floorless';

    public function checkedFloor(): int
    {
        return $this->adminFloor();
    }
}

/**
 * Every framework administration screen opens at one floor, and an application's own stands.
 *
 * The framework's controllers declared 80 or 90 each, and the menu items their own copy; the
 * floor is now `AdminAccess::defaultUsertype()` — 98, or `admin_default_usertype` — applied to
 * the property every action reads. What an application said about a screen it extends has to
 * survive that, or an upgrade would silently raise a floor somebody chose.
 */
class AdminDefaultFloorTest extends TestCase
{
    protected function tearDown(): void
    {
        Settings::setSetting('admin_default_usertype', null, false);
    }

    /**
     * A framework screen that nobody changed opens at 98.
     */
    public function testAFrameworkScreenOpensAtTheDefaultFloor(): void
    {
        // Act
        $screen = new UntouchedSettingsScreen(null);

        // Assert
        $this->assertSame(98, $screen->floor());
        $this->assertSame(98, $screen->checkedFloor(), 'exec() and the actions must agree');
    }

    /**
     * `admin_default_usertype` moves it.
     */
    public function testTheSettingMovesTheFloor(): void
    {
        // Arrange
        Settings::setSetting('admin_default_usertype', 80, false);

        // Act
        $screen = new UntouchedSettingsScreen(null);

        // Assert
        $this->assertSame(80, $screen->floor());
    }

    /**
     * An application that redeclares the property, or sets it, keeps its value.
     */
    public function testAnApplicationsOwnFloorStands(): void
    {
        // Act & Assert
        $this->assertSame(50, (new RedeclaredSettingsScreen(null))->floor(), 'a redeclared floor');
        $this->assertSame(60, (new ConstructedSettingsScreen())->floor(), 'a floor set in a constructor');
    }

    /**
     * A screen with an ability and no floor property is at the default, not at 0.
     *
     * 0 under `admin_access = usertype` would open it to every account, and the area's own
     * floor was the only thing in the way.
     */
    public function testAScreenWithoutAFloorIsAtTheDefault(): void
    {
        // Act & Assert
        $this->assertSame(98, (new FloorlessScreen(null))->checkedFloor());
    }
}
