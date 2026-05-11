<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Pramnos\Media\ResizeTools;

/**
 * Characterization tests for ResizeTools — the image-processing pipeline.
 *
 * Tests are divided into two groups:
 *
 * Group 1 — No GD required:
 *   Default property values and the maxsize / zero-dimension guard logic that
 *   runs BEFORE the GD calls inside resize(). The guard tests call resize()
 *   with a minimal valid PNG so that getimagesize() (which does NOT need GD)
 *   succeeds, then catch the expected GD error at the imagecreatefromtype()
 *   step. This lets us verify the guard contract even when GD is absent.
 *
 * Group 2 — Requires GD:
 *   Full pipeline tests that verify an output file is written, dimensions are
 *   correct, and the exportfile naming convention holds. These are skipped
 *   automatically when the gd extension is absent.
 */
#[CoversClass(ResizeTools::class)]
class ResizeToolsCharacterizationTest extends TestCase
{
    /** Paths of temp files created during a test run — cleaned up in tearDown. */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * Write a minimal 1×1 PNG to a temp file and return its path.
     * Uses only file_put_contents — no GD required. getimagesize() can read
     * this file correctly, which is sufficient for guard-only tests.
     */
    private function makeTempMinimalPng(): string
    {
        // Standard base64-encoded 1×1 grey PNG (no GD, no dependencies)
        $png  = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVQI12NgAAAAAgAB4iG8MwAAAABJRU5ErkJggg==');
        $path = tempnam(sys_get_temp_dir(), 'rt_png_') . '.png';
        file_put_contents($path, $png);
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Create a temp JPEG using GD (only used in GD-requiring tests).
     */
    private function makeTempJpeg(int $width = 200, int $height = 150): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rt_jpg_') . '.jpg';
        $img  = imagecreatetruecolor($width, $height);
        $col  = imagecolorallocate($img, 30, 100, 200);
        imagefill($img, 0, 0, $col);
        imagejpeg($img, $path, 85);
        imagedestroy($img);
        $this->tempFiles[] = $path;
        return $path;
    }

    // -----------------------------------------------------------------------
    // Group 1 — No GD required
    // -----------------------------------------------------------------------

    /**
     * Verify the default public property values that consuming code relies on.
     * Changed defaults break the caller contract even if resize() still works.
     */
    public function testDefaultPropertyValues(): void
    {
        // Arrange + Act
        $rt = new ResizeTools();

        // Assert
        $this->assertFalse($rt->srcFile,    'srcFile must default to false');
        $this->assertFalse($rt->thumbW,     'thumbW must default to false');
        $this->assertFalse($rt->thumbH,     'thumbH must default to false');
        $this->assertFalse($rt->width,      'original width must default to false');
        $this->assertFalse($rt->height,     'original height must default to false');
        $this->assertSame(120,   $rt->defaultwidth);
        $this->assertSame(1024,  $rt->maxsize);
        $this->assertFalse($rt->debug);
        $this->assertSame('FFFFFF', $rt->fillcolor);
        $this->assertTrue($rt->crop,    'crop must be enabled by default');
        $this->assertTrue($rt->resample,'resample must be enabled by default');
        $this->assertSame('', $rt->exportpath);
        $this->assertSame('', $rt->exportfile);
    }

    /**
     * When either requested dimension exceeds maxsize, resize() must clamp
     * thumbW to defaultwidth before the GD call. This protects against
     * runaway memory allocations from untrusted input.
     *
     * The test calls resize() and catches the expected GD error at the
     * imagecreatefromtype step — the guard state is verified after the catch.
     */
    public function testMaxsizeExceededClampsThumbWToDefaultWidth(): void
    {
        // Arrange — minimal PNG for getimagesize(), oversized target dimensions
        $src = $this->makeTempMinimalPng();
        $rt  = new ResizeTools();
        $rt->exportpath = sys_get_temp_dir() . DIRECTORY_SEPARATOR;

        // Act — width and height both exceed maxsize (1024)
        try {
            $rt->resize($src, 2000, 1500);
        } catch (\Throwable $e) {
            // Expected if GD is not available; we only care about the guard state
        }

        // Assert — maxsize guard ran and reset thumbW to defaultwidth
        $this->assertSame(
            120,
            $rt->thumbW,
            'maxsize guard must set thumbW = defaultwidth when input > maxsize'
        );

        if ($rt->exportfile !== '') {
            $this->tempFiles[] = $rt->exportpath . $rt->exportfile;
        }
    }

    /**
     * When width = height = 0 (caller omitted dimensions), the guard path
     * `!thumbW && !thumbH` must set thumbW = defaultwidth so that a 0×0
     * output is never produced.
     */
    public function testZeroDimensionsFallBackToDefaultWidth(): void
    {
        // Arrange
        $src = $this->makeTempMinimalPng();
        $rt  = new ResizeTools();
        $rt->exportpath = sys_get_temp_dir() . DIRECTORY_SEPARATOR;

        // Act
        try {
            $rt->resize($src, 0, 0);
        } catch (\Throwable $e) {
            // Expected if GD is not available
        }

        // Assert
        $this->assertSame(
            120,
            $rt->thumbW,
            'zero-dimensions guard must set thumbW = defaultwidth'
        );

        if ($rt->exportfile !== '') {
            $this->tempFiles[] = $rt->exportpath . $rt->exportfile;
        }
    }

    // -----------------------------------------------------------------------
    // Group 2 — GD required
    // -----------------------------------------------------------------------

    /**
     * resize() must produce an output JPEG file on disk. Verifies the full
     * pipeline: loadInfo → ratio calculation → GD crop/resample → imagejpeg.
     */
    #[RequiresPhpExtension('gd')]
    public function testResizeCreatesOutputFile(): void
    {
        // Arrange — 200×150 source, resize to 100×75
        $src = $this->makeTempJpeg(200, 150);
        $rt  = new ResizeTools();
        $rt->exportpath = sys_get_temp_dir() . DIRECTORY_SEPARATOR;

        // Act
        $rt->resize($src, 100, 75);

        // Assert — output file exists and is a valid JPEG
        $outPath = $rt->exportpath . $rt->exportfile;
        $this->tempFiles[] = $outPath;
        $this->assertFileExists($outPath, 'output JPEG must exist after resize()');
        $this->assertGreaterThan(0, filesize($outPath));

        $info = getimagesize($outPath);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertLessThanOrEqual(100, $info[0], 'output width ≤ requested width');
    }

    /**
     * Width-only resize (height = 0) must compute a proportional height
     * and produce a valid output of the exact requested width.
     */
    #[RequiresPhpExtension('gd')]
    public function testWidthOnlyResizeProducesProportionalOutput(): void
    {
        // Arrange — 300×200 source, width-only 150
        $src = $this->makeTempJpeg(300, 200);
        $rt  = new ResizeTools();
        $rt->exportpath = sys_get_temp_dir() . DIRECTORY_SEPARATOR;

        // Act
        $rt->resize($src, 150, 0);

        // Assert
        $outPath = $rt->exportpath . $rt->exportfile;
        $this->tempFiles[] = $outPath;
        $this->assertFileExists($outPath);

        $info = getimagesize($outPath);
        $this->assertNotFalse($info);
        $this->assertSame(150, $info[0], 'output width must match requested width');
    }

    /**
     * The auto-generated exportfile name must embed the final output
     * dimensions (basename-WxH.ext). Callers rely on this to predict the
     * cached file path without calling resize() first.
     */
    #[RequiresPhpExtension('gd')]
    public function testExportFileNameEmbedsDimensions(): void
    {
        // Arrange
        $src = $this->makeTempJpeg(200, 100);
        $rt  = new ResizeTools();
        $rt->exportpath = sys_get_temp_dir() . DIRECTORY_SEPARATOR;

        // Act
        $rt->resize($src, 80, 40);

        // Assert
        $this->tempFiles[] = $rt->exportpath . $rt->exportfile;
        $this->assertStringContainsString(
            '-80x40.',
            $rt->exportfile,
            'export filename must contain "-WxH." suffix'
        );
    }
}
