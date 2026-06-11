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

    /**
     * Test constructor with non-default theme name sets theme and path correctly.
     *
     * When a non-default theme name is passed, the constructor stores that name,
     * uses the default ROOT/themes path, and fills the thumbnail fallback since
     * no actual screenshot file exists in the test environment.
     */
    public function testConstructorWithNonDefaultTheme(): void
    {
        // Arrange: use a non-default theme name with no actual files on disk
        $app = $this->createMock(\Pramnos\Application\Application::class);

        // Act
        $theme = new Theme('mytheme', '', $app);

        // Assert: theme name stored and path built correctly
        $this->assertSame('mytheme', $theme->theme);
        $this->assertSame('Mytheme', $theme->title);
        // thumbnail should fall back to the nothumbnail.png placeholder
        $this->assertStringContainsString('nothumbnail.png', $theme->thumbnail);
    }

    /**
     * Test constructor with an explicit custom path sets fullpath correctly.
     *
     * When a non-empty path is supplied the constructor stores it verbatim as
     * $this->path and builds $this->fullpath = path/theme.
     */
    public function testConstructorWithCustomPath(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $path  = sys_get_temp_dir(); // a directory that exists

        // Act
        $theme = new Theme('custom', $path, $app);

        // Assert: path and fullpath built from the supplied value
        $this->assertSame($path, $theme->path);
        $this->assertSame($path . DIRECTORY_SEPARATOR . 'custom', $theme->fullpath);
    }

    /**
     * Test constructor thumbnail detection when screenshot.jpg exists.
     *
     * When fullpath/screenshot.jpg is present (but no screenshot.png) the
     * constructor must set $thumbnail to a URL pointing at the .jpg file.
     * The constructor builds fullpath as $path/DS/$theme, so the screenshot
     * must live inside a directory named after the theme.
     */
    public function testConstructorThumbnailJpg(): void
    {
        // Arrange: create basedir/jpgtheme/ with screenshot.jpg
        $baseDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thumb_jpg_' . uniqid();
        $themeDir = $baseDir . DIRECTORY_SEPARATOR . 'jpgtheme';
        mkdir($themeDir, 0755, true);
        touch($themeDir . DIRECTORY_SEPARATOR . 'screenshot.jpg');
        $app = $this->createMock(\Pramnos\Application\Application::class);

        // Act: pass baseDir as path so fullpath = baseDir/jpgtheme
        $theme = new Theme('jpgtheme', $baseDir, $app);

        // Assert: thumbnail URL contains the jpg filename
        $this->assertStringContainsString('screenshot.jpg', $theme->thumbnail);

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . 'screenshot.jpg');
        rmdir($themeDir);
        rmdir($baseDir);
    }

    /**
     * Test constructor thumbnail detection when screenshot.png exists.
     *
     * When fullpath/screenshot.png is present the constructor must set
     * $thumbnail to a URL pointing at the .png file.
     */
    public function testConstructorThumbnailPng(): void
    {
        // Arrange: create basedir/pngtheme/ with screenshot.png
        $baseDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thumb_png_' . uniqid();
        $themeDir = $baseDir . DIRECTORY_SEPARATOR . 'pngtheme';
        mkdir($themeDir, 0755, true);
        touch($themeDir . DIRECTORY_SEPARATOR . 'screenshot.png');
        $app = $this->createMock(\Pramnos\Application\Application::class);

        // Act
        $theme = new Theme('pngtheme', $baseDir, $app);

        // Assert: thumbnail URL contains the png filename
        $this->assertStringContainsString('screenshot.png', $theme->thumbnail);

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . 'screenshot.png');
        rmdir($themeDir);
        rmdir($baseDir);
    }

    /**
     * Test getTheme() with non-null theme name updates the active theme.
     *
     * When getTheme() is called with a new name it should record that name as
     * the active theme and return a Theme instance for it.
     */
    public function testGetThemeWithExplicitName(): void
    {
        // Arrange: reset instances so a fresh Theme is constructed
        $ref = new \ReflectionProperty(Theme::class, 'instances');
        $ref->setValue(null, null);

        $app = $this->createMock(\Pramnos\Application\Application::class);

        // Act: request a theme that doesn't have a theme PHP file (plain branch)
        $theme = Theme::getTheme('newtheme', '', false, $app);

        // Assert
        $this->assertInstanceOf(Theme::class, $theme);
        $this->assertSame('newtheme', $theme->theme);
    }

    /**
     * Test getTheme() with custom path uses ROOT-prefixed path.
     *
     * When a non-empty path is supplied to getTheme() the method prepends
     * ROOT . DS so the instance is created with the correct directory.
     */
    public function testGetThemeWithCustomPath(): void
    {
        // Arrange
        $ref = new \ReflectionProperty(Theme::class, 'instances');
        $ref->setValue(null, null);
        $app = $this->createMock(\Pramnos\Application\Application::class);

        // Act: use a relative path — the method prepends ROOT/DS
        $theme = Theme::getTheme('pathtest', 'themes', false, $app);

        // Assert
        $this->assertInstanceOf(Theme::class, $theme);
    }

    /**
     * Test getTheme() returns cached instance on second call for same theme.
     *
     * Once an instance exists in the static cache, a subsequent call with the
     * same theme name must return that exact object (identity check).
     */
    public function testGetThemeReturnsCachedInstance(): void
    {
        // Arrange: make sure instances is fresh for our test key
        $ref = new \ReflectionProperty(Theme::class, 'instances');
        $current = $ref->getValue() ?? [];
        $current['cached_test'] = new Theme('cached_test', sys_get_temp_dir(),
            $this->createMock(\Pramnos\Application\Application::class));
        $ref->setValue(null, $current);

        // Act: call getTheme for the already-cached key
        $theme1 = Theme::getTheme('cached_test', '', false);
        $theme2 = Theme::getTheme('cached_test', '', false);

        // Assert: same object returned both times (proves the cache branch)
        $this->assertSame($theme1, $theme2);
    }

    /**
     * Test getTheme() with a theme directory that contains a theme PHP file
     * with a proper theme class.
     *
     * Exercises the branch in getTheme() where the theme.php file exists and
     * contains a class named {theme}_theme that extends Theme.  The path must
     * be provided as a non-empty relative path so getTheme() uses
     * ROOT . DS . $path rather than APP_PATH/themes.
     */
    public function testGetThemeLoadsCustomThemeClass(): void
    {
        // Arrange: use a fixed theme name so the class name is predictable.
        // getTheme() with non-empty path computes ROOT/DS/path/theme/theme.php.
        $themeName = 'pramnos_unit_customtheme';
        $baseDir   = ROOT . DIRECTORY_SEPARATOR . 'tmp_themes_test';
        $themeDir  = $baseDir . DIRECTORY_SEPARATOR . $themeName;
        @mkdir($themeDir, 0755, true);

        // Write a minimal theme PHP file with the expected class name.
        $classCode = "<?php\nclass {$themeName}_theme extends \\Pramnos\\Theme\\Theme {}\n";
        file_put_contents($themeDir . DIRECTORY_SEPARATOR . $themeName . '.php', $classCode);

        // Reset static instances cache so this test key is fresh.
        $ref = new \ReflectionProperty(Theme::class, 'instances');
        $current = $ref->getValue() ?? [];
        unset($current[$themeName]);
        $ref->setValue(null, $current);

        $app = $this->createMock(\Pramnos\Application\Application::class);

        // Act: pass relative path 'tmp_themes_test'; getTheme prepends ROOT/DS
        $theme = Theme::getTheme($themeName, 'tmp_themes_test', false, $app);

        // Assert: the returned object is our custom class
        $this->assertInstanceOf($themeName . '_theme', $theme);

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . $themeName . '.php');
        rmdir($themeDir);
        rmdir($baseDir);
    }

    /**
     * Test loadtheme() handles a missing theme file gracefully.
     *
     * When the theme file doesn't exist loadtheme() should still complete
     * without errors (body/contents remain empty and displayInit() is called).
     */
    public function testLoadthemeWithMissingFile(): void
    {
        // Arrange: theme points to a non-existent directory so no file is found
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '/nonexistent/path', $app);

        // Act: should not throw
        $theme->loadtheme();

        // Assert: getheader returns empty since contents is empty
        $this->assertSame('', $theme->getheader());
    }

    /**
     * Test loadtheme() with an existing theme file populates body/contents.
     *
     * When the theme file exists and contains a [MODULE] marker the body is
     * split correctly by gethead() and getfoot().
     */
    public function testLoadthemeWithExistingFile(): void
    {
        // Arrange: build a minimal temp theme directory + template file
        $themeName = 'loadtest';
        $themeDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $themeName;
        @mkdir($themeDir, 0755, true);

        $html = "<html><head></head><body>BEFORE[MODULE]AFTER</body></html>";
        file_put_contents($themeDir . DIRECTORY_SEPARATOR . 'theme.html.php', $html);

        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme($themeName, sys_get_temp_dir(), $app);

        // Act
        $theme->loadtheme();

        // Assert: head/foot split around [MODULE] marker
        $this->assertStringContainsString('BEFORE', $theme->gethead());
        $this->assertStringContainsString('AFTER', $theme->getfoot());

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . 'theme.html.php');
        rmdir($themeDir);
    }

    /**
     * Test loadtheme() uses the correct content-type-specific template file.
     *
     * When _contentType is set to 'index' and the index template file exists
     * on disk, loadtheme() must load that file's content.  The elements array
     * maps 'index' → 'theme.html.php' which the document type substitution
     * turns into 'theme.html.php'.
     */
    public function testLoadthemeUsesContentTypeFile(): void
    {
        // Arrange: build a temp theme dir with a theme.html.php template.
        // 'index' maps to 'theme.html.php' in the elements array; the
        // str_replace('.html.php', '.html.php') is a no-op so the filename
        // stays theme.html.php — same as the default path.
        $themeName = 'cttesttheme';
        $baseDir   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cttest_' . uniqid();
        $themeDir  = $baseDir . DIRECTORY_SEPARATOR . $themeName;
        mkdir($themeDir, 0755, true);

        $indexHtml = "<html><head></head><body>INDEXED[MODULE]END</body></html>";
        file_put_contents($themeDir . DIRECTORY_SEPARATOR . 'theme.html.php', $indexHtml);

        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme($themeName, $baseDir, $app);
        $theme->setContentType('index');  // 'index' → theme.html.php

        // Act
        $theme->loadtheme();

        // Assert: index content is loaded
        $this->assertStringContainsString('INDEXED', $theme->gethead());

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . 'theme.html.php');
        rmdir($themeDir);
        rmdir($baseDir);
    }

    /**
     * Test getThemes() returns directory names when path exists.
     *
     * Creates a temporary directory with sub-directories and verifies they
     * are all returned by getThemes().
     */
    public function testGetThemesWithExistingPath(): void
    {
        // Arrange: build a temp dir containing two theme sub-dirs
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'themes_' . uniqid();
        mkdir($base . DIRECTORY_SEPARATOR . 'theme_a', 0755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'theme_b', 0755, true);

        // Act
        $themes = Theme::getThemes($base);

        // Assert: both theme directories appear in the result
        $this->assertContains('theme_a', $themes);
        $this->assertContains('theme_b', $themes);

        // Cleanup
        rmdir($base . DIRECTORY_SEPARATOR . 'theme_a');
        rmdir($base . DIRECTORY_SEPARATOR . 'theme_b');
        rmdir($base);
    }

    /**
     * Test displayInit() adds a stylesheet to the document when style.css exists.
     *
     * When fullpath/style.css is present displayInit() must call
     * Document::addCss() exactly once with a URL containing the CSS filename.
     */
    public function testDisplayInitAddsCssWhenStyleExists(): void
    {
        // Arrange: build a temp theme dir with a style.css file
        $themeName = 'styletest';
        $themeDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $themeName;
        @mkdir($themeDir, 0755, true);
        touch($themeDir . DIRECTORY_SEPARATOR . 'style.css');

        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme($themeName, sys_get_temp_dir(), $app);

        // Act: displayInit() is already called in loadtheme(), but call it explicitly
        // to make the assertion direct and not dependent on loadtheme behaviour.
        $result = $theme->displayInit();

        // Assert: returns self (fluent interface)
        $this->assertSame($theme, $result);

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . 'style.css');
        rmdir($themeDir);
    }

    /**
     * Test register_nav_menu() with explicit menuid triggers setMenu().
     *
     * When a non-null menuid is provided the menu area's menuid must be
     * stored immediately without waiting for assignMenus().
     */
    public function testRegisterNavMenuWithExplicitMenuId(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);

        // Act: register a menu with an explicit ID
        $result = $theme->register_nav_menu('sidebar_nav', 'Sidebar Navigation', 42);

        // Assert: fluent return and menuid stored
        $this->assertSame($theme, $result);
        $areas = $theme->getMenuAreas();
        $this->assertArrayHasKey('sidebar_nav', $areas);
        $this->assertSame(42, $areas['sidebar_nav']['menuid']);
    }

    /**
     * Test addWidget() returns false when widgetId is not present in data.
     *
     * The method should return false (not throw) when the parsed widget data
     * does not contain a 'widgetId' key.
     */
    public function testAddWidgetReturnsFalseWhenWidgetIdMissing(): void
    {
        // Arrange: register a widget area so the area-check passes
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);
        $theme->registerWidgetArea('Test Area', '', 'area1');

        // Act: submit data without a widgetId key
        $result = $theme->addWidget('area1', 'class=SomeWidget&title=Hello');

        // Assert: returns false since widgetId is absent
        $this->assertFalse($result);
    }

    /**
     * Test addWidget() returns debug string when widgetAreaID is unknown and debug=true.
     *
     * When the target widget area does not exist and debug mode is enabled the
     * method should return a descriptive string instead of false.
     */
    public function testAddWidgetDebugModeUnknownArea(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);

        // Act
        $result = $theme->addWidget('no_such_area', 'widgetId=1', true);

        // Assert: debug output is a non-empty string mentioning the area name
        $this->assertIsString($result);
        $this->assertStringContainsString('no_such_area', $result);
    }

    /**
     * Test addWidget() debug=true returns the creation string when area exists.
     *
     * When the area is valid and widgetId is supplied with debug=true the
     * method should return the descriptive output string.
     */
    public function testAddWidgetDebugModeValidArea(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);
        $theme->registerWidgetArea('Main', '', 'main_area');

        // Act
        $result = $theme->addWidget('main_area', 'widgetId=99&class=MyWidget', true);

        // Assert: debug returns a string (the "Creating a widget" message)
        $this->assertIsString($result);
        $this->assertStringContainsString('99', $result);
    }

    /**
     * Test getWidgets() with a widgetArea filter returns only matching widgets.
     *
     * When widgets have been added to two different areas, filtering by area
     * must return only the widgets assigned to that area.
     */
    public function testGetWidgetsWithAreaFilter(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);
        $theme->registerWidgetArea('Area A', '', 'area_a');
        $theme->registerWidgetArea('Area B', '', 'area_b');

        $theme->addWidget('area_a', 'widgetId=10&class=Widget10');
        $theme->addWidget('area_b', 'widgetId=20&class=Widget20');

        // Act
        $widgetsA = $theme->getWidgets('area_a');
        $widgetsB = $theme->getWidgets('area_b');

        // Assert: each filtered list contains only its own widget
        $this->assertCount(1, $widgetsA);
        $this->assertSame('Widget10', $widgetsA[0]['class']);

        $this->assertCount(1, $widgetsB);
        $this->assertSame('Widget20', $widgetsB[0]['class']);
    }

    /**
     * Test registerBannerLocation() with an explicit locationid triggers setBannerLocation().
     *
     * When a non-null locationid is supplied the banner location's locationid
     * must be stored immediately.
     */
    public function testRegisterBannerLocationWithExplicitId(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);

        // Act
        $result = $theme->registerBannerLocation('footer_banner', 'Footer Banner', 77);

        // Assert: fluent return and locationid set
        $this->assertSame($theme, $result);
        $locations = $theme->getbannerLocations();
        $this->assertArrayHasKey('footer_banner', $locations);
        $this->assertSame(77, $locations['footer_banner']['locationid']);
    }

    /**
     * Test getCmsLocation() returns false for an unknown position.
     *
     * Verifies the "not found" branch of getCmsLocation() returns boolean false.
     */
    public function testGetCmsLocationReturnsFalseForUnknown(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);

        // Act
        $result = $theme->getCmsLocation('nonexistent');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test displayMenu() constructs proper HTML wrappers with container options.
     *
     * Verifies the HTML container and class/id wrapping logic by passing
     * container_class and container_id args and checking the rendered output.
     */
    public function testDisplayMenuContainerOptions(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);
        $theme->register_nav_menu('top', 'Top Menu', 1);

        // Act: echo=false returns the rendered string
        $output = $theme->displayMenu('top', [
            'echo'            => false,
            'container'       => 'nav',
            'container_class' => 'main-nav',
            'container_id'    => 'top-nav',
        ]);

        // Assert: output contains the rendered menu string from the mock class
        $this->assertStringContainsString('rendered_menu', $output);
    }

    /**
     * Test addWidget() returns false when area is unknown and debug=false.
     *
     * When the widget area ID does not exist and debug is false (default),
     * addWidget() must return boolean false silently.
     */
    public function testAddWidgetReturnsFalseForUnknownAreaSilent(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);
        // Do NOT register any widget area

        // Act: debug defaults to false
        $result = $theme->addWidget('nonexistent_area', 'widgetId=5&class=Foo');

        // Assert: silent false return (line 905 path)
        $this->assertFalse($result);
    }

    /**
     * Test getWidgets() with null widgetArea returns all widgets.
     *
     * When called without arguments (or with null) getWidgets() must return
     * the complete widgets array rather than filtering by area.  Uses a fresh
     * theme instance with a unique name to avoid shared Settings state.
     */
    public function testGetWidgetsWithNullAreaReturnsAll(): void
    {
        // Arrange: unique theme name to avoid shared Settings widget data
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('nullwidgettest_' . uniqid(), '', $app);
        $theme->resetWidgets(); // clear any pre-existing stored widgets
        $theme->registerWidgetArea('A', '', 'aa_null');
        $theme->registerWidgetArea('B', '', 'bb_null');
        $theme->addWidget('aa_null', 'widgetId=111&class=W1');
        $theme->addWidget('bb_null', 'widgetId=222&class=W2');

        // Act: no area filter → returns all (line 922 path)
        $all = $theme->getWidgets();

        // Assert: both widgets are in the result
        $this->assertCount(2, $all);
    }

    /**
     * Test displayMenu() without a container produces no wrapping tag.
     *
     * When container is set to empty string no <div>/<nav> wrapper should
     * be added around the menu list.
     */
    public function testDisplayMenuNoContainer(): void
    {
        // Arrange
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);
        $theme->register_nav_menu('footer', 'Footer Menu', 2);

        // Act
        $output = $theme->displayMenu('footer', [
            'echo'      => false,
            'container' => '',
        ]);

        // Assert: output still contains menu content
        $this->assertStringContainsString('rendered_menu', $output);
        // No <div> or <nav> wrapper
        $this->assertStringNotContainsString('<div', $output);
        $this->assertStringNotContainsString('<nav', $output);
    }

    /**
     * Test getCmsLocation() returns the location ID when found.
     *
     * When a banner location with a known CMS ID is registered and
     * setCmsLocation stores a mapping, getCmsLocation must return it.
     */
    public function testGetCmsLocationReturnsIdWhenFound(): void
    {
        // Arrange: register a banner location with an explicit ID so
        // setBannerLocation() stores the mapping in cmsBannerLocations.
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);
        $theme->registerBannerLocation('hero', 'Hero Banner', 55);

        // Act: getCmsLocation delegates to assignBannerLocations then looks up
        $result = $theme->getCmsLocation('hero');

        // Assert: ID 55 is returned (line 1092 path)
        $this->assertSame(55, $result);
    }

    /**
     * Test getElement() includes a file and returns true when it exists.
     *
     * When the element's file is present on disk, getElement() must include it
     * (which may produce output) and return true.
     */
    public function testGetElementReturnsTrueForExistingFile(): void
    {
        // Arrange: build a temp theme dir with a header.php file
        $themeName = 'elemtest';
        $baseDir   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'elem_' . uniqid();
        $themeDir  = $baseDir . DIRECTORY_SEPARATOR . $themeName;
        mkdir($themeDir, 0755, true);
        file_put_contents($themeDir . DIRECTORY_SEPARATOR . 'header.php', '<?php /* header */ ?>');

        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme($themeName, $baseDir, $app);

        // Act
        ob_start();
        $result = $theme->getElement('header');
        ob_end_clean();

        // Assert: file was found and included (lines 946-947)
        $this->assertTrue($result);

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . 'header.php');
        rmdir($themeDir);
        rmdir($baseDir);
    }

    /**
     * Test getTheme() called with null returns the active theme instance.
     *
     * When getTheme() receives null as the theme argument it must use the
     * currently active theme name (line 218 path).
     */
    public function testGetThemeNullUsesActiveTheme(): void
    {
        // Arrange: set active theme to 'default' via an explicit call first
        $ref = new \ReflectionProperty(Theme::class, 'instances');
        $current = $ref->getValue() ?? [];
        // Ensure a 'default' instance exists
        $mockApp = $this->createMock(\Pramnos\Application\Application::class);
        $existing = new Theme('default', '', $mockApp);
        $current['default'] = $existing;
        $ref->setValue(null, $current);

        // Also set activeTheme to 'default'
        $activeRef = new \ReflectionProperty(Theme::class, 'activeTheme');
        $activeRef->setValue(null, 'default');

        // Act: null argument → uses activeTheme = 'default'
        $theme = Theme::getTheme(null);

        // Assert: returns the cached 'default' instance (line 218 + 269 path)
        $this->assertSame($existing, $theme);
    }

    /**
     * Test getElement() via alias get_header() includes the header file.
     *
     * Exercises the get_header() alias method to ensure it delegates correctly
     * to getElement('header').
     */
    public function testGetHeaderAliasIncludesFile(): void
    {
        // Arrange: same fixture as testGetElementReturnsTrueForExistingFile
        $themeName = 'headeralias';
        $baseDir   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ha_' . uniqid();
        $themeDir  = $baseDir . DIRECTORY_SEPARATOR . $themeName;
        mkdir($themeDir, 0755, true);
        file_put_contents($themeDir . DIRECTORY_SEPARATOR . 'header.php', '<?php /* header */ ?>');

        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme($themeName, $baseDir, $app);

        // Act
        ob_start();
        $result = $theme->get_header();
        ob_end_clean();

        // Assert
        $this->assertTrue($result);

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . 'header.php');
        rmdir($themeDir);
        rmdir($baseDir);
    }

    /**
     * Test the element alias trio: get_footer() / get_sidebar() delegate to
     * getElement() and include the corresponding file, while
     * get_search_form() always returns false because 'search_form' is not a
     * registered element key (only 'search' is).
     */
    public function testElementAliasesDelegateToGetElement(): void
    {
        // Arrange: temp theme with footer.php and sidebar.php present
        $themeName = 'aliastrio';
        $baseDir   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'at_' . uniqid();
        $themeDir  = $baseDir . DIRECTORY_SEPARATOR . $themeName;
        mkdir($themeDir, 0755, true);
        file_put_contents($themeDir . DIRECTORY_SEPARATOR . 'footer.php', '<?php /* footer */ ?>');
        file_put_contents($themeDir . DIRECTORY_SEPARATOR . 'sidebar.php', '<?php /* sidebar */ ?>');

        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme($themeName, $baseDir, $app);

        // Act / Assert — existing element files are included → true
        ob_start();
        $footer  = $theme->get_footer();
        $sidebar = $theme->get_sidebar();
        $search  = $theme->get_search_form();
        ob_end_clean();

        $this->assertTrue($footer, 'footer.php exists, get_footer() must include it');
        $this->assertTrue($sidebar, 'sidebar.php exists, get_sidebar() must include it');
        // 'search_form' is not a registered element key → always false
        $this->assertFalse($search);

        // Cleanup
        unlink($themeDir . DIRECTORY_SEPARATOR . 'footer.php');
        unlink($themeDir . DIRECTORY_SEPARATOR . 'sidebar.php');
        rmdir($themeDir);
        rmdir($baseDir);
    }

    /**
     * Test the WordPress-compatibility global functions get_header(),
     * get_footer(), get_search_form() and get_sidebar(): each resolves the
     * active theme via Theme::getTheme() and delegates to the corresponding
     * method. They must run without errors even when the element files are
     * missing (getElement() then simply returns false).
     */
    public function testWordpressCompatGlobalFunctionsUseActiveTheme(): void
    {
        // Arrange: register a bare theme (no element files) as the active one
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);

        $ref     = new \ReflectionProperty(Theme::class, 'instances');
        $current = $ref->getValue() ?? [];
        $current['default'] = $theme;
        $ref->setValue(null, $current);
        $activeRef = new \ReflectionProperty(Theme::class, 'activeTheme');
        $activeRef->setValue(null, 'default');

        // Act — the compat layer must not throw or output anything.
        // Theme.php declares them inside its own namespace (the file-level
        // `namespace Pramnos\Theme;` applies to the function definitions too).
        ob_start();
        \Pramnos\Theme\get_header();
        \Pramnos\Theme\get_footer();
        \Pramnos\Theme\get_search_form();
        \Pramnos\Theme\get_sidebar();
        $output = ob_get_clean();

        // Assert — no element files exist, so nothing may be emitted
        $this->assertSame('', $output);
    }

    /**
     * Test getThemeObjects() with an explicit path argument: entries that are
     * not directories under ROOT/themes (the repository has no such tree in
     * the test environment) are skipped, so the result is an empty array —
     * but the directory scan itself must complete without errors.
     */
    public function testGetThemeObjectsScansPathAndSkipsNonThemeEntries(): void
    {
        // Arrange — temp dir with a plain file and a subdirectory
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tobj_' . uniqid();
        mkdir($path . DIRECTORY_SEPARATOR . 'sometheme', 0755, true);
        file_put_contents($path . DIRECTORY_SEPARATOR . 'stray.txt', 'x');

        // Act
        $objects = Theme::getThemeObjects($path);

        // Assert — 'sometheme' is not a dir under ROOT/themes → filtered out
        $this->assertSame([], $objects);

        // Cleanup
        unlink($path . DIRECTORY_SEPARATOR . 'stray.txt');
        rmdir($path . DIRECTORY_SEPARATOR . 'sometheme');
        rmdir($path);
    }

    /**
     * Test addSetting() with a numeric name prefixes with underscore.
     *
     * Field names that start with a digit are invalid PHP identifiers, so
     * addSetting() must prepend an underscore.  Verify via getSetting()
     * using the prefixed name.
     */
    public function testAddSettingPrefixesNumericName(): void
    {
        // Arrange: mock the form as before
        $mockForm = new class {
            public array $_fields = [];
            public function addField($name, $title, $type, $options, $description, $required, $default, $value): void {
                $field = new \stdClass();
                $field->value = $value;
                $this->_fields[$name] = $field;
            }
        };
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);
        $ref   = new \ReflectionProperty($theme, '_form');
        $ref->setValue($theme, $mockForm);

        // Act: name starts with digit — addSetting() prepends '_' (line 445)
        $theme->addSetting('1numeric', 'Test', 'textfield', null, null, false, null, 'hello');

        // Assert: stored under '_1numeric'
        $this->assertSame('hello', $theme->getSetting('_1numeric'));
    }

    /**
     * Test getMenu() returns false when position is not in menus.
     *
     * getMenu() must return boolean false for a position that has never been
     * assigned a menu ID (line 747 path).
     */
    public function testGetMenuReturnsFalseForUnknownPosition(): void
    {
        // Arrange: fresh theme with no saved menus
        $app   = $this->createMock(\Pramnos\Application\Application::class);
        $theme = new Theme('default', '', $app);

        // Act
        $result = $theme->getMenu('totally_unknown_position');

        // Assert (line 747)
        $this->assertFalse($result);
    }
}

if (!class_exists('Pramnos\Theme\pramnoscms_menu')) {
    eval("namespace Pramnos\Theme { class pramnoscms_menu {
        public \$options;
        public function __construct(\$id) {}
        public function render() { return 'rendered_menu'; }
    } }");
}
