<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Media\IccProfile;
use Pramnos\Media\Imaging;

/**
 * Pictures GD cannot read, where Imagick is installed (FW-402): what a file is by its content, what
 * this server can read, a phone's HEIC converted to JPEG (PNG when transparent) with its metadata
 * left out, a Display P3 photograph converted to sRGB — and a PDF, an SVG or a script named as a
 * picture never handed to Imagick.
 *
 * Imagick is never required. The tests that need it are skipped without it; those that hold the
 * refusals run either way.
 */
#[CoversClass(Imaging::class)]
#[CoversClass(IccProfile::class)]
final class ImagingTest extends TestCase
{
    /** @var list<string> Files to remove */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /** A scratch file with these bytes, named as given. */
    private function file(string $name, string $bytes): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pf_imaging_' . getmypid() . '_' . $name;
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }

    /** A fixture copied to a scratch file, so a conversion does not consume it. */
    private function fixture(string $name, string $as): string
    {
        return $this->file($as, (string) file_get_contents(ROOT . '/tests/fixtures/images/' . $name));
    }

    /** Imagick with HEIC here, or the test is skipped. */
    private function needsImagick(): void
    {
        if (!Imaging::imagickReads('heic')) {
            $this->markTestSkipped('Imagick with HEIC is not installed here.');
        }
    }

    /**
     * The format is read from the first bytes, never the name: a JPEG, a PNG, a GIF, a WebP and a
     * HEIC are each known; a PDF, an SVG, a PHP script and something too short are none, whatever
     * they are called — so none of them is ever handed to Imagick.
     */
    public function testTheFormatIsReadFromTheContent(): void
    {
        // Arrange
        $gd = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($gd);
        $jpeg = (string) ob_get_clean();
        ob_start();
        imagepng($gd);
        $png = (string) ob_get_clean();
        ob_start();
        imagegif($gd);
        $gif = (string) ob_get_clean();
        ob_start();
        imagewebp($gd);
        $webp = (string) ob_get_clean();

        // Act
        $formats = [
            Imaging::format($this->file('a.png', $jpeg)),
            Imaging::format($this->file('b.jpg', $png)),
            Imaging::format($this->file('c.jpg', $gif)),
            Imaging::format($this->file('d.jpg', $webp)),
            Imaging::format($this->fixture('gps.heic', 'e.jpg')),
            Imaging::format($this->file('f.jpg', "%PDF-1.4\n%âãÏÓ\n1 0 obj << >> endobj\n")),
            Imaging::format($this->file('g.jpg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>')),
            Imaging::format($this->file('h.heic', "<?php echo 'hello'; ?>\n")),
            Imaging::format($this->file('i.jpg', "\xFF\xD8")),
            Imaging::format('/no/such/file.jpg'),
        ];

        // Assert
        $this->assertSame(['jpeg', 'png', 'gif', 'webp', 'heic', null, null, null, null, null], $formats);
        $this->assertFalse(Imaging::readable($this->file('j.heic', "%PDF-1.4\n" . str_repeat('x', 40))), 'a PDF named .heic is not readable');
    }

    /**
     * Neither conversion touches what it is not for: a picture GD reads needs no converting, and
     * a PDF or an SVG named .heic is refused, left as it was, never opened by Imagick.
     */
    public function testWhatGdReadsAndWhatIsNoPictureAreLeftAlone(): void
    {
        // Arrange
        $gd = imagecreatetruecolor(4, 4);
        $jpeg = $this->file('k.jpg', '');
        imagejpeg($gd, $jpeg);
        $pdf = $this->file('l.heic', "%PDF-1.4\n" . str_repeat('x', 40));
        $svg = $this->file('m.heic', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $before = (string) file_get_contents($jpeg);

        // Act
        $results = [Imaging::toWebImage($jpeg), Imaging::toWebImage($pdf), Imaging::toWebImage($svg), Imaging::normalize($jpeg)];

        // Assert
        $this->assertSame([null, null, null, false], $results);
        $this->assertSame($before, file_get_contents($jpeg), 'a JPEG with no colour profile is not rewritten');
        $this->assertFileExists($pdf, 'refused, not removed');
        $this->assertFileExists($svg);
        $this->assertContains('jpg', Imaging::uploadExtensions());
        $this->assertContains('webp', Imaging::uploadExtensions());
    }

    /**
     * A phone's HEIC, where Imagick reads it: a JPEG beside it, the same picture (left red, right
     * blue, 40×20), its metadata gone — GPS among it — and the HEIC removed. It is then a format
     * this server takes for upload.
     */
    public function testAHeicBecomesAJpegWithoutItsMetadata(): void
    {
        // Arrange
        $this->needsImagick();
        $heic = $this->fixture('gps.heic', 'photo.heic');
        $this->assertNotEmpty((new \Imagick($heic))->getImageProperties('exif:GPS*'), 'the fixture carries GPS');

        // Act
        $converted = Imaging::toWebImage($heic);
        $this->files[] = (string) ($converted['file'] ?? '');
        $jpeg = new \Imagick($converted['file']);

        // Assert
        $this->assertSame('jpg', $converted['ext']);
        $this->assertSame('image/jpeg', $converted['mime']);
        $this->assertStringEndsWith('photo.jpg', $converted['file']);
        $this->assertSame([40, 20], [$jpeg->getImageWidth(), $jpeg->getImageHeight()]);
        $left  = $jpeg->getImagePixelColor(5, 10)->getColor();
        $right = $jpeg->getImagePixelColor(35, 10)->getColor();
        $this->assertGreaterThan(200, $left['r'], 'left stays red');
        $this->assertGreaterThan(200, $right['b'], 'right stays blue');
        $this->assertSame([], $jpeg->getImageProperties('exif:*'), 'no metadata, no GPS');
        $this->assertFileDoesNotExist($heic, 'the HEIC removed');
        $this->assertSame(['heic', 'heif', 'avif'], array_values(array_intersect(['heic', 'heif', 'avif'], Imaging::uploadExtensions())));
    }

    /** A transparent HEIC becomes a PNG, its transparency kept. */
    public function testATransparentHeicBecomesAPng(): void
    {
        // Arrange
        $this->needsImagick();
        $heic = $this->fixture('transparent.heic', 'logo.heic');

        // Act
        $converted = Imaging::toWebImage($heic);
        $this->files[] = (string) ($converted['file'] ?? '');
        $png = imagecreatefrompng($converted['file']);

        // Assert
        $this->assertSame(['png', 'image/png'], [$converted['ext'], $converted['mime']]);
        // By the colour's own alpha: a PNG may be palette-based, whose pixel is an index.
        $alpha = static fn (int $x, int $y): int => imagecolorsforindex($png, imagecolorat($png, $x, $y))['alpha'];
        $this->assertSame(127, $alpha(25, 5), 'the right half still transparent');
        $this->assertSame(0, $alpha(5, 5), 'the left half opaque');
    }

    /**
     * An iPhone's Display P3 JPEG, which GD reads but whose profile it drops: rewritten in place in
     * sRGB, its colour converted rather than reinterpreted, its GPS gone; and every Imagick call
     * here takes one thread, not every core.
     */
    public function testADisplayP3PhotographIsConvertedToSrgb(): void
    {
        // Arrange — a P3 JPEG, rgb(200,100,50) in P3, with a GPS block
        $this->needsImagick();
        $image = new \Imagick();
        $image->newImage(8, 8, new \ImagickPixel('rgb(200,100,50)'));
        $image->setImageFormat('JPEG');
        $image->setImageCompressionQuality(100);
        $image->setImageProfile('icc', IccProfile::displayP3());
        $file = $this->file('iphone.jpg', $image->getImagesBlob());

        // Act
        $changed = Imaging::normalize($file);
        $again   = Imaging::normalize($file);
        $after   = new \Imagick($file);
        $colour  = $after->getImagePixelColor(4, 4)->getColor();

        // Assert
        $this->assertTrue($changed);
        $this->assertFalse($again, 'sRGB now: nothing more to do');
        $this->assertSame('jpeg', Imaging::format($file), 'still a JPEG, in place');
        // P3's rgb(200,100,50) is sRGB's rgb(215,93,31), give or take the JPEG.
        $this->assertEqualsWithDelta(215, $colour['r'], 4);
        $this->assertEqualsWithDelta(93, $colour['g'], 4);
        $this->assertEqualsWithDelta(31, $colour['b'], 4);
        $this->assertArrayNotHasKey('icc', $after->getImageProfiles('icc', false), 'no profile left: sRGB, as a browser assumes');
        if (defined(\Imagick::class . '::RESOURCETYPE_THREAD')) {
            $this->assertSame(1, (int) \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_THREAD), 'one thread per picture');
        }
    }

    /** The two profiles are what an ICC reader takes: sRGB and Display P3 by name, of the size they say. */
    public function testTheProfilesAreWellFormed(): void
    {
        // Act
        $srgb = IccProfile::srgb();
        $p3   = IccProfile::displayP3();

        // Assert
        $this->assertSame(strlen($srgb), unpack('N', substr($srgb, 0, 4))[1], 'the size it declares');
        $this->assertSame('acsp', substr($srgb, 36, 4));
        $this->assertStringContainsString('sRGB', $srgb);
        $this->assertStringContainsString('Display P3', $p3);
        $this->assertStringNotContainsString('sRGB', $p3, 'so it is not taken for sRGB');
        $this->assertSame($srgb, IccProfile::srgb(), 'built once');
    }
}
