<?php

namespace Pramnos\Tests\Unit\Theme;

use Pramnos\Theme\Theme;
use Pramnos\Application\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Theme class
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Theme\Theme::class)]
class ThemeTest extends TestCase
{
    private Theme $theme;
    
    protected function setUp(): void
    {
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $this->theme = new Theme('default', '', $app);
    }
    
    public function testConstructorInitializesDefaultTheme(): void
    {
        $this->assertSame('default', $this->theme->theme);
        $this->assertSame('Default', $this->theme->title);
        $this->assertInstanceOf(Application::class, $this->theme->application);
    }
    
    public function testSetAndGetContentType(): void
    {
        $this->assertSame('index', $this->theme->getContentType());
        
        $this->assertSame($this->theme, $this->theme->setContentType('page'));
        $this->assertSame('page', $this->theme->getContentType());
    }
    
    public function testAllowsViewOverridesReturnsBoolean(): void
    {
        $this->assertFalse($this->theme->allowsViewOverrides());
    }
    
    public function testRegisterWidgetAreaAddsWidgetAndReturnsSelf(): void
    {
        $this->assertFalse($this->theme->hasWidgetAreas());
        
        $this->assertSame($this->theme, $this->theme->registerWidgetArea('Sidebar 1', 'Main Sidebar', 'sidebar1'));
        
        $this->assertTrue($this->theme->hasWidgetAreas());
        $areas = $this->theme->getWidgetAreas();
        $this->assertArrayHasKey('sidebar1', $areas);
        $this->assertSame('Sidebar 1', $areas['sidebar1']['name']);
        $this->assertSame('Main Sidebar', $areas['sidebar1']['description']);
    }
    
    public function testRegisterNavMenuAddsMenuAndReturnsSelf(): void
    {
        $this->assertFalse($this->theme->hasMenuAreas());
        
        $this->assertSame($this->theme, $this->theme->register_nav_menu('primary', 'Primary Menu'));
        
        $this->assertTrue($this->theme->hasMenuAreas());
        $areas = $this->theme->getMenuAreas();
        $this->assertArrayHasKey('primary', $areas);
        $this->assertSame('Primary Menu', $areas['primary']['description']);
    }
    
    public function testRemoveMenuAreaRemovesMenu(): void
    {
        $this->theme->register_nav_menu('footer', 'Footer Menu');
        $this->assertTrue($this->theme->hasMenuAreas());
        
        $this->assertSame($this->theme, $this->theme->removeMenuArea('footer'));
        $this->assertFalse($this->theme->hasMenuAreas());
    }
    
    public function testRegisterBannerLocationAddsLocation(): void
    {
        $this->assertFalse($this->theme->hasBannerLocations());
        
        $this->assertSame($this->theme, $this->theme->registerBannerLocation('top', 'Top Banner'));
        
        $this->assertTrue($this->theme->hasBannerLocations());
        $locations = $this->theme->getbannerLocations();
        $this->assertArrayHasKey('top', $locations);
        $this->assertSame('Top Banner', $locations['top']['description']);
    }
    
    public function testGetThemeReturnsThemeInstance(): void
    {
        $theme = Theme::getTheme('default');
        $this->assertInstanceOf(Theme::class, $theme);
        $this->assertSame('default', $theme->theme);
    }
    
    public function testAddThemeSupportReturnsSelf(): void
    {
        $this->assertSame($this->theme, $this->theme->add_theme_support('post-thumbnails'));
    }
    
    public function testRegisterSidebarIsAliasForRegisterWidgetArea(): void
    {
        $this->assertSame($this->theme, $this->theme->register_sidebar([
            'name' => 'Footer Area',
            'id' => 'footer1',
            'description' => 'Footer widget area'
        ]));
        
        $areas = $this->theme->getWidgetAreas();
        $this->assertArrayHasKey('footer1', $areas);
        $this->assertSame('Footer Area', $areas['footer1']['name']);
    }

    public function testSetAndGetMenu(): void
    {
        $this->theme->register_nav_menu('header', 'Header Menu');
        
        // setMenu should set it and return false if position doesn't exist.
        // It saves to Settings, but in unit tests Settings is mocked or DB is mocked.
        // We'll just test that it sets it in memory.
        $this->theme->setMenu('header', 5);
        $this->assertSame(5, $this->theme->getMenu('header'));
        
        // Setting to non-existent position returns false, but it is stored in menus array
        $this->assertFalse($this->theme->setMenu('doesnt_exist', 5));
        $this->assertSame(5, $this->theme->getMenu('doesnt_exist'));
    }

    public function testRegisterMultipleWidgetAreasWithSameName(): void
    {
        $this->theme->registerWidgetArea('Sidebar');
        $this->theme->registerWidgetArea('Sidebar');
        $this->theme->registerWidgetArea('Sidebar');
        
        $areas = $this->theme->getWidgetAreas();
        $names = array_column($areas, 'name');
        
        $this->assertContains('Sidebar', $names);
        $this->assertContains('Sidebar 1', $names);
        $this->assertContains('Sidebar 2', $names);
    }
    
    public function testSetAndGetBannerLocation(): void
    {
        $this->theme->registerBannerLocation('top', 'Top Banner');
        
        $this->theme->setBannerLocation('top', 10);
        $locations = $this->theme->getbannerLocations();
        
        $this->assertSame(10, $locations['top']['locationid']);
        
        // Non-existent location
        $this->assertFalse($this->theme->setBannerLocation('missing', 10));
    }
}
