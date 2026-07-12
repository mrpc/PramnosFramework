<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Theme\Theme;

/**
 * Characterization tests for Theme methods that do not require full
 * application bootstrapping.
 *
 * Widget management tests verify:
 * - addWidget / getWidgets core contract (parse_str → store in $widgets array)
 * - Guard clauses: unknown area, missing widgetId
 * - Filtered vs. unfiltered getWidgets
 * - Debug output mode
 */
#[CoversClass(Theme::class)]
class ThemeCharacterizationTest extends TestCase
{
    /**
     * Build a Theme instance bypassing the constructor so we can test
     * individual methods without an Application, Database, or theme files.
     * Sets widgetAreas, menuAreas, menus, widgets, and theme to known values.
     */
    private function newThemeWithoutConstructor(): Theme
    {
        $ref = new \ReflectionClass(Theme::class);
        /** @var Theme $theme */
        $theme = $ref->newInstanceWithoutConstructor();

        $widgetAreasProp = new \ReflectionProperty($theme, 'widgetAreas');
        $widgetAreasProp->setValue($theme, []);

        $menuAreasProp = new \ReflectionProperty($theme, 'menuAreas');
        $menuAreasProp->setValue($theme, []);

        $menusProp = new \ReflectionProperty($theme, 'menus');
        $menusProp->setValue($theme, []);

        // widgets array must be initialised (constructor loads it from Settings)
        $widgetsProp = new \ReflectionProperty($theme, 'widgets');
        $widgetsProp->setValue($theme, []);

        // theme name is used as part of the Settings key when persisting widgets
        $themeProp = new \ReflectionProperty($theme, 'theme');
        $themeProp->setValue($theme, 'test');

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
        $menuAreasProp->setValue($theme, [
            'primary' => ['description' => 'Primary menu', 'menuid' => 5],
        ]);

        $menusProp = new \ReflectionProperty($theme, 'menus');
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

    // -----------------------------------------------------------------------
    // Widget management
    // -----------------------------------------------------------------------

    /**
     * addWidget() parses the widgetData query-string, stores the widget in
     * $widgets keyed by widgetId, and returns true on success.
     *
     * This covers the primary happy path: area exists + widgetId present.
     */
    public function testAddWidgetToRegisteredAreaReturnsTrue(): void
    {
        // Arrange
        $theme = $this->newThemeWithoutConstructor();
        $theme->registerWidgetArea('Sidebar', '', 'sidebar');

        // Act
        $result = $theme->addWidget('sidebar', 'widgetId=text&title=Hello');

        // Assert
        $this->assertTrue($result);
        $widgets = $theme->getWidgets();
        $this->assertArrayHasKey('text', $widgets);
        $this->assertSame('Hello', $widgets['text']['title']);
        $this->assertSame('sidebar', $widgets['text']['widgetArea']);
    }

    /**
     * addWidget() returns false without adding anything when the target
     * widgetAreaID does not exist in the registered areas.
     *
     * Guard clause: prevents silent data corruption when callers pass a typo.
     */
    public function testAddWidgetToNonExistentAreaReturnsFalse(): void
    {
        // Arrange
        $theme = $this->newThemeWithoutConstructor();
        // no widget areas registered

        // Act
        $result = $theme->addWidget('nowhere', 'widgetId=text');

        // Assert — no area registered, must be rejected
        $this->assertFalse($result);
        $this->assertCount(0, $theme->getWidgets());
    }

    /**
     * addWidget() returns false when the widgetData string does not contain
     * a 'widgetId' key, because widgets must be identifiable by ID.
     */
    public function testAddWidgetWithMissingWidgetIdReturnsFalse(): void
    {
        // Arrange
        $theme = $this->newThemeWithoutConstructor();
        $theme->registerWidgetArea('Footer', '', 'footer');

        // Act — query string has no widgetId parameter
        $result = $theme->addWidget('footer', 'title=Hello&type=text');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * getWidgets() with no argument returns every registered widget regardless
     * of which area it belongs to.
     */
    public function testGetWidgetsWithNoFilterReturnsAll(): void
    {
        // Arrange
        $theme = $this->newThemeWithoutConstructor();
        $theme->registerWidgetArea('Sidebar', '', 'sidebar');
        $theme->registerWidgetArea('Footer', '', 'footer');
        $theme->addWidget('sidebar', 'widgetId=w1');
        $theme->addWidget('footer',  'widgetId=w2');

        // Act
        $all = $theme->getWidgets();

        // Assert — both widgets returned
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('w1', $all);
        $this->assertArrayHasKey('w2', $all);
    }

    /**
     * getWidgets($area) returns only the widgets that belong to the given area.
     * Widgets in other areas must not appear in the result.
     */
    public function testGetWidgetsFilteredByAreaReturnsOnlyMatchingWidgets(): void
    {
        // Arrange
        $theme = $this->newThemeWithoutConstructor();
        $theme->registerWidgetArea('Sidebar', '', 'sidebar');
        $theme->registerWidgetArea('Footer',  '', 'footer');
        $theme->addWidget('sidebar', 'widgetId=side1');
        $theme->addWidget('footer',  'widgetId=foot1');
        $theme->addWidget('footer',  'widgetId=foot2');

        // Act
        $footerWidgets = $theme->getWidgets('footer');

        // Assert — only footer widgets returned
        $this->assertCount(2, $footerWidgets);
        $ids = array_column($footerWidgets, 'widgetId');
        $this->assertContains('foot1', $ids);
        $this->assertContains('foot2', $ids);
        $this->assertNotContains('side1', $ids);
    }

    /**
     * addWidget() in debug mode returns a descriptive string instead of true,
     * letting callers log or display the action taken.
     */
    public function testAddWidgetDebugModeReturnsDescriptiveString(): void
    {
        // Arrange
        $theme = $this->newThemeWithoutConstructor();
        $theme->registerWidgetArea('Sidebar', '', 'sidebar');

        // Act
        $result = $theme->addWidget('sidebar', 'widgetId=dbgWidget', true);

        // Assert — debug string contains the widget data and area name
        $this->assertIsString($result);
        $this->assertStringContainsString('dbgWidget', $result);
        $this->assertStringContainsString('sidebar', $result);
    }
}
