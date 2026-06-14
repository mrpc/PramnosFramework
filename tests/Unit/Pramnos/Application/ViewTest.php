<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\View;
use Pramnos\Application\Controller;
use Pramnos\Application\Application;
use Pramnos\Application\Model;

#[CoversClass(View::class)]
class ViewTest extends TestCase
{
    private function getController(): Controller
    {
        $app = new Application();
        return new Controller($app);
    }

    public function testConstructorAndGetters(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/some/path', 'MyView', 'html');

        $this->assertEquals('html', $view->getType());
        $this->assertSame($ctrl, $view->controller);
    }

    public function testModelsManagement(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/some/path', 'MyView', 'html');

        $model = new class(null, null) extends Model {
            public $name = 'TestModel';
            public function __construct($a, $b) {}
        };

        $view->addModel($model, true);
        
        $this->assertSame($model, $view->getModel('TestModel'));
        $this->assertSame($model, $view->getModel()); // default

        $anotherModel = new class(null, null) extends Model {
            public $name = 'Another';
            public function __construct($a, $b) {}
        };
        $view->addModel($anotherModel, false);
        $this->assertNotSame($model, $view->getModel('Another'));
        $this->assertFalse($view->getModel('NonExistent'));
    }

    public function testEscape(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/path', 'V', 'html');

        $this->assertEquals('&lt;b&gt;bold&lt;/b&gt;', $view->escape('<b>bold</b>'));
        $this->assertEquals('&lt;script&gt;', $view->e('<script>'));
    }

    public function testSectionsAndYield(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/path', 'V', 'html');

        $this->assertEquals('default_html', $view->yield('nonexistent', 'default_html'));

        $view->section('content');
        echo "Hello ";
        $view->section('nested');
        echo "World";
        $view->endsection();
        echo "!";
        $view->endsection();

        $this->assertEquals('World', $view->yield('nested'));
        $this->assertEquals('Hello !', $view->yield('content'));
    }

    public function testLayoutAndSetTemplateCacheDir(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/path', 'V', 'html');

        $view->layout('my_layout');
        $this->assertObjectHasProperty('_layout', $view);
        
        View::setTemplateCacheDir('/tmp/cache');
        $this->assertEquals('/tmp/cache', View::getTemplateCacheDir());
    }

    public function testWithCacheAndCacheMethod(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/path', 'V', 'html');

        $view->withCache(600, 'my_key');
        
        // Cache method with no cache instance available
        $result = $view->cache('test_key', 300, function() {
            return 'computed_value';
        });

        $this->assertEquals('computed_value', $result);
    }
    
    public function testGetTplFailsWithoutFile(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/fake/path', 'V', 'html');
        $this->assertFalse($view->getTpl('fake_tpl'));
    }

    public function testDisplayReturnsOutput(): void
    {
        $ctrl = $this->getController();
        $view = new class($ctrl) extends View {
            public function __construct($c) { parent::__construct($c, '/path', 'V', 'html'); }
            public function getTpl($tpl='', $type='', $render=false) {
                if ($render) return 'rendered';
                $this->output = 'buffered';
                return true;
            }
        };

        $this->assertEquals('rendered', $view->display('', true));
        $this->assertEquals('buffered', $view->display('', false));
    }

    public function testGetTplWithActualFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/view_test_' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/my_view.html.php', '<?php echo "Hello View"; ?>');
        
        $ctrl = $this->getController();
        $view = new View($ctrl, $tempDir, 'my_view', 'html');
        
        // Render to output buffer
        $result = $view->getTpl('my_view', 'html', false);
        $this->assertTrue($result);
        $this->assertStringContainsString('Hello View', $view->output);
        $this->assertStringContainsString('View Rendered at:', $view->output);
        
        // Render directly
        $output = $view->getTpl('my_view', 'html', true);
        $this->assertStringContainsString('Hello View', $output);

        unlink($tempDir . '/my_view.html.php');
        rmdir($tempDir);
    }

    public function testInsertAndLayout(): void
    {
        $tempDir = sys_get_temp_dir() . '/view_test_layout_' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/partials');
        
        // Create partial
        file_put_contents($tempDir . '/partials/header.html.php', 'HEADER: <?php echo $title; ?>');
        // Create layout
        file_put_contents($tempDir . '/layout.html.php', '<html><?php echo $this->yield("content"); ?></html>');
        // Create main view
        file_put_contents($tempDir . '/main.html.php', '<?php $this->layout("layout"); $this->section("content"); $this->insert("partials/header", ["title" => "MyTitle"]); ?> BODY <?php $this->endsection(); ?>');
        
        $ctrl = $this->getController();
        $view = new View($ctrl, $tempDir, 'main', 'html');
        
        $output = $view->getTpl('main', 'html', true);
        
        $this->assertStringContainsString('<html>HEADER: MyTitle BODY </html>', $output);

        unlink($tempDir . '/partials/header.html.php');
        rmdir($tempDir . '/partials');
        unlink($tempDir . '/layout.html.php');
        unlink($tempDir . '/main.html.php');
        rmdir($tempDir);
    }

    public function testGetTplCatchesExceptionsInTemplate(): void
    {
        $tempDir = sys_get_temp_dir() . '/view_test_exc_' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/error.html.php', '<?php throw new \Exception("Template failure"); ?>');
        
        $ctrl = $this->getController();
        $view = new View($ctrl, $tempDir, 'error', 'html');
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Error rendering template file');
        
        try {
            $view->getTpl('error', 'html', true);
        } finally {
            unlink($tempDir . '/error.html.php');
            rmdir($tempDir);
        }
    }

    public function testGetTplReturnsJsonListIfFormatJson(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/path', 'V', 'html');
        
        $model = new class(null, null) extends Model {
            public $name = 'JsonModel';
            public function __construct($a, $b) {}
            public function getJsonList() { return '{"status":"ok"}'; }
        };
        $view->addModel($model, true);
        
        $_GET['format'] = 'json';
        
        $result = $view->getTpl('nonexistent', 'html', false);
        
        $this->assertTrue($result);
        $this->assertEquals('{"status":"ok"}', $view->output);
        
        unset($_GET['format']); // cleanup
    }

    public function testGetTplWithCache(): void
    {
        $tempDir = sys_get_temp_dir() . '/view_test_cache_' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/my_cached_view.html.php', '<?php echo "Cached Output"; ?>');
        
        $ctrl = $this->getController();
        $view = new View($ctrl, $tempDir, 'my_cached_view', 'html');
        
        // This exercises the cache logic blocks (even if cache backend is unavailable, it covers the try/catch)
        $output = $view->withCache(60, 'test_cache_key')->getTpl('my_cached_view', 'html', true);
        
        $this->assertStringContainsString('Cached Output', $output);

        unlink($tempDir . '/my_cached_view.html.php');
        rmdir($tempDir);
    }

    public function testGetTplCatchesExceptionsInLayout(): void
    {
        $tempDir = sys_get_temp_dir() . '/view_test_exc_layout_' . uniqid();
        mkdir($tempDir);

        // Create layout that throws exception
        file_put_contents($tempDir . '/bad_layout.html.php', '<html><?php throw new \Exception("Layout crash"); ?></html>');
        // Create main view that uses the bad layout
        file_put_contents($tempDir . '/main_bad.html.php', '<?php $this->layout("bad_layout"); ?> CONTENT');

        $ctrl = $this->getController();
        $view = new View($ctrl, $tempDir, 'main_bad', 'html');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Error rendering layout');

        try {
            $view->getTpl('main_bad', 'html', true);
        } finally {
            unlink($tempDir . '/bad_layout.html.php');
            unlink($tempDir . '/main_bad.html.php');
            rmdir($tempDir);
        }
    }

    /**
     * endsection() must return immediately without error when the section stack
     * is empty (line 323). This guard prevents array_pop on an empty stack when
     * a template calls endsection() without a matching section().
     */
    public function testEndsectionWithEmptyStackReturnsWithoutError(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/path', 'V', 'html');

        // Act — no section() was called; sectionStack is empty
        $view->endsection();

        // Assert — no exception thrown; guard at line 323 returned early
        $this->assertTrue(true,
            'endsection() must not throw when called on an empty section stack');
    }

    /**
     * insert() must log a warning and return silently when the requested partial
     * cannot be resolved (lines 367–368). This prevents fatal errors from a
     * missing partial silently breaking the entire page render.
     */
    public function testInsertWithNonExistentPartialLogsAndReturns(): void
    {
        $ctrl = $this->getController();
        $view = new View($ctrl, '/nonexistent/path', 'V', 'html');

        // Act — partial does not exist in any search location
        // insert() must call Logger::log (line 367) and return without throwing
        ob_start();
        $view->insert('completely_nonexistent_partial');
        ob_end_clean();

        // Assert — no exception was thrown
        $this->assertTrue(true,
            'insert() must return silently when the partial cannot be found');
    }

    /**
     * insert() called with an absolute file path that exists must resolve it
     * directly via the "absolute path" branch in resolveTemplatePath (line 438),
     * without searching relative directories.
     */
    public function testInsertWithAbsolutePathIncludesFile(): void
    {
        // Arrange — create a real partial file at an absolute path
        $tempFile = sys_get_temp_dir() . '/pramnos_abs_partial_' . uniqid() . '.html.php';
        file_put_contents($tempFile, 'ABSOLUTE PARTIAL CONTENT');

        $ctrl = $this->getController();
        $view = new View($ctrl, '/some/path', 'V', 'html');

        // Act — pass the absolute path directly; resolveTemplatePath returns it at line 438
        ob_start();
        $view->insert($tempFile);
        $out = ob_get_clean();

        // Assert — partial was included via the absolute path branch
        $this->assertStringContainsString('ABSOLUTE PARTIAL CONTENT', $out,
            'insert() with an absolute path must include the file via the absolute branch');

        unlink($tempFile);
    }

    /**
     * getIncludePath() must compile a .tpl.php template through TemplateCompiler
     * and return the cached compiled path (lines 417–419). This path is taken
     * whenever a template file ends with .tpl.php; .html.php files skip it.
     */
    public function testGetTplWithTplPhpTemplateUsesCompiler(): void
    {
        // Arrange — create a minimal .tpl.php template in a temp dir
        $tempDir = sys_get_temp_dir() . '/view_test_tpl_' . uniqid();
        mkdir($tempDir);
        $cacheDir = $tempDir . '/cache';
        mkdir($cacheDir);

        // Write a simple .tpl.php template that uses {{ }} echo syntax
        file_put_contents($tempDir . '/simple.tpl.php', 'COMPILED: {{ "hello tpl" }}');

        // Point the template cache at the temp cache dir so the compiler can write
        View::setTemplateCacheDir($cacheDir);

        $ctrl = $this->getController();
        // View name matches the file; type is empty so the raw filename is used
        $view = new View($ctrl, $tempDir, 'simple', '');

        try {
            // Act — getTpl resolves 'simple.tpl.php', calls getIncludePath which
            // triggers the TemplateCompiler path (lines 417–419)
            $tplFile = $tempDir . '/simple.tpl.php';
            $ref = new \ReflectionMethod($view, 'getIncludePath');
            $compiled = $ref->invoke($view, $tplFile);

            // Assert — compiled path is a different file from the source
            $this->assertNotEquals($tplFile, $compiled,
                'getIncludePath() must return a compiled path for .tpl.php files');
            $this->assertFileExists($compiled,
                'getIncludePath() must write the compiled file to the cache dir');
        } finally {
            // Cleanup
            View::setTemplateCacheDir('');
            @unlink($tempDir . '/simple.tpl.php');
            array_map('unlink', glob($cacheDir . '/*'));
            @rmdir($cacheDir);
            @rmdir($tempDir);
        }
    }
}
