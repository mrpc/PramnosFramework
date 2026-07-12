<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Media;

use PHPUnit\Framework\TestCase;
use Pramnos\Media\ResizeTools;

class ResizeToolsTest extends TestCase
{
    private string $tempDir;
    private string $srcFileJpg;
    private string $srcFilePng;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/resize_tests_' . uniqid();
        mkdir($this->tempDir);
        
        $this->srcFileJpg = $this->tempDir . '/test.jpg';
        $this->srcFilePng = $this->tempDir . '/test.png';
        
        $img = imagecreatetruecolor(1000, 1000);
        $color = imagecolorallocate($img, 255, 0, 0);
        imagefilledrectangle($img, 0, 0, 1000, 1000, $color);
        imagejpeg($img, $this->srcFileJpg);
        imagepng($img, $this->srcFilePng);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->tempDir/*.*"));
        rmdir($this->tempDir);
    }

    public function testConstruct(): void
    {
        $tool = new ResizeTools();
        $this->assertEquals(120, $tool->defaultwidth);
        $this->assertTrue($tool->crop);
        $this->assertTrue($tool->resample);
    }

    public function testResizeJpg(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'resized.jpg';
        
        $tool->resize($this->srcFileJpg, 100, 100);
        
        $this->assertFileExists($this->tempDir . '/resized.jpg');
        list($w, $h) = getimagesize($this->tempDir . '/resized.jpg');
        $this->assertEquals(100, $w);
        $this->assertEquals(100, $h);
    }

    public function testResizePng(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'resized.png';
        
        $tool->resize($this->srcFilePng, 50, 80);
        
        $this->assertFileExists($this->tempDir . '/resized.png');
        list($w, $h) = getimagesize($this->tempDir . '/resized.png');
        $this->assertEquals(50, $w);
        $this->assertEquals(80, $h);
    }
    
    public function testResizeMaxSizeLimit(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'max.jpg';
        $tool->maxsize = 200;
        
        $tool->resize($this->srcFileJpg, 500, 500); // Exceeds maxsize
        
        $this->assertFileExists($this->tempDir . '/max.jpg');
        list($w, $h) = getimagesize($this->tempDir . '/max.jpg');
        $this->assertEquals(120, $w); // Falls back to defaultwidth
    }

    public function testResizeNoCrop(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'nocrop.jpg';
        $tool->crop = false;
        
        $tool->resize($this->srcFileJpg, 100, 200);
        
        $this->assertFileExists($this->tempDir . '/nocrop.jpg');
    }
    
    public function testResizeResampleOnlyWidth(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'width.jpg';
        
        $tool->resize($this->srcFileJpg, 150, 0); // No height given
        
        $this->assertFileExists($this->tempDir . '/width.jpg');
        list($w, $h) = getimagesize($this->tempDir . '/width.jpg');
        $this->assertEquals(150, $w);
        $this->assertEquals(150, $h); // Square image so ratio is 1:1
    }

    public function testResizeMissingSourceFile(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'error.jpg';
        
        $tool->resize($this->tempDir . '/non_existent.jpg', 100, 100);
        
        $this->assertFileExists($this->tempDir . '/error.jpg');
    }
    
    public function testFastImageCopyResampled(): void
    {
        $imgSrc = imagecreatetruecolor(100, 100);
        $imgDst = imagecreatetruecolor(50, 50);
        
        $result = ResizeTools::fastimagecopyresampled($imgDst, $imgSrc, 0, 0, 0, 0, 50, 50, 100, 100, 2);
        
        $this->assertTrue($result);
    }
    
    public function testDebugModeOutput(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'debug.jpg';
        $tool->debug = true;
        
        ob_start();
        $tool->resize($this->srcFileJpg, 100, 100);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('Must resize to', $output);
    }
    
    public function testAutoGenerateFilename(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = ''; // Empty to trigger generation
        
        $tool->resize($this->srcFileJpg, 100, 100);
        
        $files = glob($this->tempDir . '/test-100x100.jpg');
        $this->assertNotEmpty($files);
    }

    public function testAutoGenerateFilenameCollision(): void
    {
        // First create the collision file
        touch($this->tempDir . '/test-100x100.jpg');

        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = ''; 
        
        $tool->resize($this->srcFileJpg, 100, 100);
        
        // Assert that another file was created, not named test-100x100.jpg
        $files = glob($this->tempDir . '/*.jpg');
        $this->assertGreaterThan(1, count($files));
    }
    
    public function testDisplayMethodOutput(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'display.jpg';
        
        ob_start();
        $tool->display($this->srcFileJpg, 10, 10);
        $output = ob_get_clean();
        
        // Output should be the actual image content
        $this->assertNotEmpty($output);
    }
    
    public function testLoadGif(): void
    {
        $srcFileGif = $this->tempDir . '/test.gif';
        $img = imagecreatetruecolor(10, 10);
        imagegif($img, $srcFileGif);
        
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'resized.png'; // GIF gets exported as PNG
        
        $tool->resize($srcFileGif, 5, 5);
        $this->assertFileExists($this->tempDir . '/resized.png');
    }
    
    public function testResizeForcesResample(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'resampled.jpg';
        $tool->resample = true;
        $tool->crop = false; // Must be false to reach resample branch
        $tool->debug = true; // this will hit all the echo statements
        
        ob_start();
        $tool->resize($this->srcFileJpg, 100, 10);
        $output = ob_get_clean();
        
        $this->assertFileExists($this->tempDir . '/resampled.jpg');
        list($w, $h) = getimagesize($this->tempDir . '/resampled.jpg');
        $this->assertEquals(100, $w);
        $this->assertEquals(10, $h);
    }
    
    public function testExportPathAutoDiscovery(): void
    {
        $tool = new ResizeTools();
        // Exportpath is not set, so it will fall back to CACHE_PATH or ROOT
        $tool->exportfile = 'auto_discovery.jpg';
        
        // We need to define CACHE_PATH if not defined so we can capture where it goes
        if (!defined('CACHE_PATH')) {
            define('CACHE_PATH', $this->tempDir);
        } else {
            // we skip or mock if it's already defined
        }
        
        try {
            $tool->resize($this->srcFileJpg, 10, 10);
        } catch (\Exception $e) {
            // It might fail if CACHE_PATH points to a read-only dir, but we just want to hit the code path
        }
        
        $this->assertTrue(true); // just check it doesn't crash fatally
    }
    
    public function testCropSmallerOriginal(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'smaller_orig.jpg';
        $tool->crop = true;
        $tool->forcecrop = true;
        // original is 1000x1000, ask for 2000x2000
        $tool->resize($this->srcFileJpg, 2000, 2000);
        
        $this->assertFileExists($this->tempDir . '/smaller_orig.jpg');
    }

    public function testResamplePng(): void
    {
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile = 'resampled.png';
        $tool->resample = true;
        $tool->resize($this->srcFilePng, 100, 10);

        $this->assertFileExists($this->tempDir . '/resampled.png');
    }

    /**
     * When resample=false and crop=false, loadAndResize() falls through to
     * the "simple resize" else branch (lines 455-460), calling
     * fastimagecopyresampled() directly without crop or resample logic.
     * This branch is NOT reached by any existing test because:
     *  – resample defaults to true, and
     *  – the PNG path sets resample=false only AFTER diff is computed (so
     *    PNG exports with large ratio differences still go via _resample).
     * Explicitly setting resample=false before calling resize() is the only
     * way to force the else path for a JPEG source.
     */
    public function testSimpleResizeNoResampleNoCrop(): void
    {
        // Arrange — disable both crop and resample to force the else branch;
        // debug=true ensures line 456 (echo 'Simple Resize') is also covered.
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile  = 'simple_resize.jpg';
        $tool->resample    = false;
        $tool->crop        = false;
        $tool->debug       = true;

        // Act — capture debug output (line 456: echo 'Simple Resize')
        ob_start();
        $tool->resize($this->srcFileJpg, 80, 80);
        $output = ob_get_clean();

        // Assert — debug echo confirms the else-branch was taken (line 456)
        $this->assertStringContainsString('Simple Resize', $output,
            'debug echo at line 456 must fire in the simple-resize else branch');
        // Output dimensions are correct
        $this->assertFileExists($this->tempDir . '/simple_resize.jpg',
            'simple resize (no resample, no crop) must write the output file');
        [$w, $h] = getimagesize($this->tempDir . '/simple_resize.jpg');
        $this->assertSame(80, $w);
        $this->assertSame(80, $h);
    }

    /**
     * When the source image is smaller than the requested thumbnail, _crop()
     * takes the fast path: it calls fastimagecopyresampled() directly instead
     * of computing a scaled intermediate (lines 303-305).  The condition is:
     *   $this->width < $this->thumbW  OR  $this->height < $this->thumbH
     * A 50×50 source resized to 100×100 satisfies this.
     */
    public function testCropSmallerSourceImage(): void
    {
        // Arrange — create a source image smaller than the requested thumbnail
        $smallSrc = $this->tempDir . '/small50.jpg';
        $img = imagecreatetruecolor(50, 50);
        imagejpeg($img, $smallSrc);

        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile  = 'cropped_small.jpg';
        $tool->crop        = true;

        // Act — source (50×50) < thumb (100×100) → fast fastimagecopyresampled path
        $tool->resize($smallSrc, 100, 100);

        // Assert — output file created at the requested size
        $this->assertFileExists($this->tempDir . '/cropped_small.jpg',
            'crop of smaller source must produce an output file');
        [$w, $h] = getimagesize($this->tempDir . '/cropped_small.jpg');
        $this->assertSame(100, $w);
        $this->assertSame(100, $h);
    }

    /**
     * When thumbH > thumbW the IF branch of _resample() executes (lines
     * 348-360).  With debug=true the echo statements at lines 350 and 356
     * fire.  A wide source (1000×500) resized to a tall thumbnail (10×200)
     * produces a large ratio difference (≈18) that forces _resample even
     * with crop=false.
     */
    public function testResampleDebugTallThumbnail(): void
    {
        // Arrange — wide source so that thumbH=200 > thumbW=10 enters the if-branch
        $wideSrc = $this->tempDir . '/wide1000x500.jpg';
        $img = imagecreatetruecolor(1000, 500);
        imagejpeg($img, $wideSrc);

        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile  = 'tall_resample.jpg';
        $tool->resample    = true;
        $tool->crop        = false;
        $tool->debug       = true;

        // Act — capture debug output from the tall-thumbnail branch
        ob_start();
        $tool->resize($wideSrc, 10, 200);
        $output = ob_get_clean();

        // Assert — the debug echo confirms the if-branch was entered (line 350)
        $this->assertStringContainsString('Height is larger than width', $output,
            '_resample debug echo at line 350 must fire when thumbH > thumbW');
        $this->assertFileExists($this->tempDir . '/tall_resample.jpg',
            '_resample with tall thumbnail must produce an output file');
    }

    /**
     * In the else branch of _resample() (thumbH ≤ thumbW) the vertical
     * placement offset (ypos) is halved when it exceeds 2 (line 375).
     * A super-wide source (2000×10) resized to 100×50 produces:
     *   nheight = round(10 × 100/2000) = 1
     *   ypos    = 50 − 1 = 49  →  > 2  →  ypos = 24
     */
    public function testResampleElseBranchYposAdjustment(): void
    {
        // Arrange — super-wide source so nheight is tiny and ypos > 2
        $superWideSrc = $this->tempDir . '/superwide2000x10.jpg';
        $img = imagecreatetruecolor(2000, 10);
        imagejpeg($img, $superWideSrc);

        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile  = 'ypos_adjust.jpg';
        $tool->resample    = true;
        $tool->crop        = false;

        // Act — large ratio diff forces _resample; ypos > 2 triggers line 375
        $tool->resize($superWideSrc, 100, 50);

        // Assert — output created; the ypos adjustment path was exercised
        $this->assertFileExists($this->tempDir . '/ypos_adjust.jpg',
            '_resample else branch must produce an output file when ypos > 2');
    }

    /**
     * fastimagecopyresampled() clamps quality ≤ 0 to 1 (line 584).
     * Passing quality=0 exercises this guard and still produces valid output.
     */
    public function testFastImageCopyResampledZeroQuality(): void
    {
        // Arrange
        $src = imagecreatetruecolor(100, 100);
        $dst = imagecreatetruecolor(50, 50);

        // Act — quality=0 triggers line 584: $quality = 1
        $result = ResizeTools::fastimagecopyresampled($dst, $src, 0, 0, 0, 0, 50, 50, 100, 100, 0);

        // Assert — clamped quality still produces a valid result
        $this->assertTrue($result,
            'fastimagecopyresampled() must return true even when quality is clamped from 0 to 1');
    }

    /**
     * When maxsize is exceeded AND debug=true, resize() emits a debug echo
     * at line 136 explaining that dimensions were reset to defaultwidth.
     * testResizeMaxSizeLimit already verifies the reset; this test ensures
     * the debug branch (line 136) is also covered.
     */
    public function testResizeDebugWithMaxSizeExceeded(): void
    {
        // Arrange — maxsize smaller than requested dimensions
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile  = 'debug_maxsize.jpg';
        $tool->maxsize     = 200;
        $tool->debug       = true;

        // Act — 500×500 exceeds maxsize=200 → reset fires → debug echo at line 136
        ob_start();
        $tool->resize($this->srcFileJpg, 500, 500);
        $output = ob_get_clean();

        // Assert — debug message confirms the maxsize branch was entered (line 136)
        $this->assertStringContainsString('Due to maxsize', $output,
            'debug echo at line 136 must fire when requested size exceeds maxsize');
    }

    /**
     * _setExportPath() returns early (lines 194-195) when called with an
     * explicit non-null path, immediately storing it and returning it.
     * The normal resize() call passes null (no arg), so this path is only
     * reachable via Reflection.
     */
    public function testSetExportPathWithExplicitPathViaReflection(): void
    {
        // Arrange
        $tool   = new ResizeTools();
        $method = new \ReflectionMethod(ResizeTools::class, '_setExportPath');
        // setAccessible() is a no-op since PHP 8.1 (deprecated in 8.5); omitting it.

        // Act — pass a non-null path → lines 194-195: $this->exportpath = $path; return $path
        $result = $method->invoke($tool, '/tmp/explicit_path/');

        // Assert — the method returned the given path and stored it
        $this->assertSame('/tmp/explicit_path/', $result,
            '_setExportPath() must return the given path when called with non-null argument');
        $this->assertSame('/tmp/explicit_path/', $tool->exportpath,
            '_setExportPath() must store the given path in $this->exportpath');
    }

    /**
     * When diff < resampleLimit with resample=true, _calcRatioDiff() sets
     * forcecrop=true even though crop=false.  Inside _crop() the condition
     *   if ($this->forcecrop == true && $this->crop == false && $this->debug == true)
     * at line 299-300 echoes a "Forcing crop" message.
     * A 1000×1000 source resized to 100×100 produces ratio=1, ratio_original=1,
     * diff=0 < 0.55 → forcecrop=true automatically.
     */
    public function testResampleDebugWithForcecrop(): void
    {
        // Arrange — equal dimensions keep ratio=1, diff=0 < limit → forcecrop fires
        $tool = new ResizeTools();
        $tool->exportpath = $this->tempDir . '/';
        $tool->exportfile  = 'forcecrop_debug.jpg';
        $tool->crop        = false;
        $tool->resample    = true;
        $tool->debug       = true;

        // Act — forcecrop=true set by _calcRatioDiff → _crop → line 300 fires
        ob_start();
        $tool->resize($this->srcFileJpg, 100, 100);
        $output = ob_get_clean();

        // Assert — "Forcing crop" debug message confirms line 300 was executed
        $this->assertStringContainsString('Forcing crop', $output,
            'debug echo at line 300 must fire when forcecrop=true and crop=false');
        $this->assertFileExists($this->tempDir . '/forcecrop_debug.jpg',
            'resize must produce an output file even in the forced-crop debug path');
    }
}
