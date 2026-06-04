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

    public function testAddAndGetWidgets(): void
    {
        $this->theme->registerWidgetArea('Sidebar 1', 'Main Sidebar', 'sidebar1');
        $this->theme->addWidget('sidebar1', 'widgetId=123&class=TestWidget');
        
        $widgets = $this->theme->getWidgets('sidebar1');
        $this->assertCount(1, $widgets);
        $this->assertSame('TestWidget', $widgets[0]['class']);
        
        $this->theme->addWidget('sidebar1', 'widgetId=124&class=TestWidget2');
        $widgets = $this->theme->getWidgets('sidebar1');
        $this->assertCount(2, $widgets);
        
        // Reset widgets
        $this->theme->resetWidgets('sidebar1');
        $this->assertCount(0, $this->theme->getWidgets('sidebar1'));
    }

    public function testRenderWidgetArea(): void
    {
        $this->theme->registerWidgetArea('Test Sidebar', 'Desc', 'test_sidebar');
        
        // Output should be buffered
        ob_start();
        $result = $this->theme->renderWidgetArea('test_sidebar');
        $output = ob_get_clean();
        
        $this->assertIsString($result);
        
        // No widgets added, so shouldn't output much except empty or wrappers, but we don't know exactly what without DB.
        
        // Test non-existent widget area
        $result = $this->theme->renderWidgetArea('missing');
        $this->assertSame('', $result);
    }
    
    public function testGetElementFunctions(): void
    {
        $app = $this->createMock(\Pramnos\Application\Application::class);
        
        $theme = new Theme('default', '', $app);
        
        ob_start();
        $theme->getElement('header');
        $theme->getElement('footer');
        $theme->get_header();
        $theme->get_footer();
        $theme->get_sidebar();
        $theme->get_search_form();
        $output = ob_get_clean();
        
        $this->assertEmpty($output); // Since getView is empty
    }
    
    public function testCmsLocationFunctions(): void
    {
        $this->assertEmpty($this->theme->getCmsLocation('missing'));
    }

    public function testRemoveBannerLocation(): void
    {
        $this->theme->registerBannerLocation('top', 'Top Banner');
        $this->assertTrue($this->theme->hasBannerLocations());
        
        $this->theme->removeBannerLocation('top');
        $this->assertFalse($this->theme->hasBannerLocations());
        
        $this->assertSame($this->theme, $this->theme->removeBannerLocation('missing'));
    }
    
    public function testGetHeadAndFoot(): void
    {
        $ref = new \ReflectionProperty($this->theme, 'body');
        $ref->setValue($this->theme, "<html><head>test</head><body>start[MODULE]end</body></html>");
        
        $this->assertStringContainsString('start', $this->theme->gethead());
        $this->assertStringContainsString('end', $this->theme->getfoot());
    }

    public function testGetThemesReturnsArray(): void
    {
        // Should return an array even if path is invalid or empty
        $themes = Theme::getThemes('/invalid/path/that/does/not/exist');
        $this->assertIsArray($themes);
    }
    
    public function testSettingsFunctions(): void
    {
        // Mock the form since Settings relies on form object internally
        $mockForm = new class {
            public array $_fields = [];
            public function addField($name, $title, $type, $options, $description, $required, $default, $value) {
                $field = new \stdClass();
                $field->value = $value;
                $this->_fields[$name] = $field;
            }
        };
        
        $ref = new \ReflectionProperty($this->theme, '_form');
        $ref->setValue($this->theme, $mockForm);
        
        $this->assertFalse($this->theme->hasSettings());
        
        $this->theme->addSetting('test_setting', 'Test', 'textfield', null, 'Desc', false, 'def', 'val');
        $this->assertTrue($this->theme->hasSettings());
        $this->assertSame('val', $this->theme->getSetting('test_setting'));
        $this->assertNull($this->theme->getSetting('missing'));
    }

    public function testGetheader(): void
    {
        $ref = new \ReflectionProperty($this->theme, 'contents');
        $ref->setValue($this->theme, "<html><head>test_header</head><body>start[MODULE]end</body></html>");
        
        $this->assertSame('test_header', $this->theme->getheader());
        
        $ref->setValue($this->theme, "<html><body>start[MODULE]end</body></html>");
        $this->assertSame('', $this->theme->getheader());
    }

    public function testDisplayMenuWithMockClass(): void
    {
        $this->theme->register_nav_menu('primary', 'Primary Menu', 1);
        
        ob_start();
        try {
            $this->theme->displayMenu('primary', ['menu' => 1]);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        
        $this->assertSame('rendered_menu', $output);
        
        // Test with echo = false
        $result = $this->theme->displayMenu('primary', ['echo' => false]);
        $this->assertSame('rendered_menu', $result);
    }
}

if (!class_exists('Pramnos\Theme\pramnoscms_menu')) {
    eval("namespace Pramnos\Theme { class pramnoscms_menu {
        public \$options;
        public function __construct(\$id) {}
        public function render() { return 'rendered_menu'; }
    } }");
}
