<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Theme\Theme;

/**
 * Characterization tests for Theme methods that do not require full
 * application bootstrapping.
 */
#[CoversClass(Theme::class)]
class ThemeCharacterizationTest extends TestCase
{
    private function newThemeWithoutConstructor(): Theme
    {
        $ref = new \ReflectionClass(Theme::class);
        /** @var Theme $theme */
        $theme = $ref->newInstanceWithoutConstructor();

        $widgetAreasProp = new \ReflectionProperty($theme, 'widgetAreas');
        $widgetAreasProp->setAccessible(true);
        $widgetAreasProp->setValue($theme, []);

        $menuAreasProp = new \ReflectionProperty($theme, 'menuAreas');
        $menuAreasProp->setAccessible(true);
        $menuAreasProp->setValue($theme, []);

        $menusProp = new \ReflectionProperty($theme, 'menus');
        $menusProp->setAccessible(true);
        $menusProp->setValue($theme, []);

        return $theme;
    }

    public function testSetAndGetContentTypeRoundTrip(): void
    {
        $theme = $this->newThemeWithoutConstructor();
        $result = $theme->setContentType('single');

        $this->assertSame($theme, $result);
        $this->assertSame('single', $theme->getContentType());
    }

    public function testAllowsViewOverridesDefaultIsFalse(): void
    {
        $theme = $this->newThemeWithoutConstructor();
        $this->assertFalse($theme->allowsViewOverrides());
    }

    public function testRegisterWidgetAreaCreatesAreaAndReturnsSelf(): void
    {
        $theme = $this->newThemeWithoutConstructor();
        $result = $theme->registerWidgetArea('Sidebar', 'Main sidebar', 'sidebar-main');

        $this->assertSame($theme, $result);
        $areas = $theme->getWidgetAreas();
        $this->assertArrayHasKey('sidebar-main', $areas);
        $this->assertSame('Sidebar', $areas['sidebar-main']['name']);
        $this->assertSame('Main sidebar', $areas['sidebar-main']['description']);
    }

    public function testRegisterWidgetAreaAutoGeneratesUniqueName(): void
    {
        $theme = $this->newThemeWithoutConstructor();

        $theme->registerWidgetArea('Sidebar', 'First', 'a1');
        $theme->registerWidgetArea('Sidebar', 'Second', 'a2');

        $areas = $theme->getWidgetAreas();
        $this->assertSame('Sidebar', $areas['a1']['name']);
        $this->assertSame('Sidebar 1', $areas['a2']['name']);
    }

    public function testHasWidgetAreasReflectsState(): void
    {
        $theme = $this->newThemeWithoutConstructor();
        $this->assertFalse($theme->hasWidgetAreas());

        $theme->registerWidgetArea('Footer', '', 'footer');
        $this->assertTrue($theme->hasWidgetAreas());
    }

    public function testRemoveMenuAreaRemovesFromMenuAreasAndMenus(): void
    {
        $theme = $this->newThemeWithoutConstructor();

        $menuAreasProp = new \ReflectionProperty($theme, 'menuAreas');
        $menuAreasProp->setAccessible(true);
        $menuAreasProp->setValue($theme, [
            'primary' => ['description' => 'Primary menu', 'menuid' => 5],
        ]);

        $menusProp = new \ReflectionProperty($theme, 'menus');
        $menusProp->setAccessible(true);
        $menusProp->setValue($theme, ['primary' => 5]);

        $result = $theme->removeMenuArea('primary');

        $this->assertSame($theme, $result);

        /** @var array<string,mixed> $menuAreas */
        $menuAreas = $menuAreasProp->getValue($theme);
        /** @var array<string,mixed> $menus */
        $menus = $menusProp->getValue($theme);

        $this->assertArrayNotHasKey('primary', $menuAreas);
        $this->assertArrayNotHasKey('primary', $menus);
    }
}
