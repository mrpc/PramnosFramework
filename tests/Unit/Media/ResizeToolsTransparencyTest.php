<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Media\ResizeTools;

/**
 * Drawing a transparent image without flattening it.
 *
 * WHAT: `blend()` fades a PNG without turning its clear pixels grey, and `silhouette()`
 *       returns the image's own shape rather than a rectangle.
 *
 * WHY:  GD's compositing calls divide into ones that respect the source's alpha
 *       (`imagecopy`, `imagecopyresampled`) and ones that do not (`imagecopymerge`,
 *       `imagecopymergegray`) — and the second group is the one whose signature takes a
 *       **percentage**. So "draw this logo at 60%" leads straight to the wrong call, and
 *       produces a grey rectangle with the logo faintly on it.
 *
 *       Three bugs in one afternoon in one application, all this shape: a logo at 60% as a
 *       grey rectangle, a glow behind it as a coloured card, and scratch canvases starting
 *       opaque black so anything not overwriting every pixel gained a background. A logo
 *       is the single most likely image in a CMS to have a transparent background, and the
 *       framework's media system is what a CMS uses for logos.
 *
 * **Every assertion is about a corner** — inside the drawn box, outside the artwork. That
 * is where all three bugs showed, and it is the pixel a rectangle fills and a silhouette
 * does not.
 */
#[CoversClass(ResizeTools::class)]
class ResizeToolsTransparencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD is not available.');
        }
    }

    /**
     * A 40×40 canvas, fully transparent, with an opaque red disc in the middle.
     *
     * A disc rather than a square, so there is a corner that is *artwork-free* to assert
     * on — which is the whole method of this class.
     */
    private function logo(): \GdImage
    {
        $image = imagecreatetruecolor(40, 40);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        imagealphablending($image, true);
        imagefilledellipse($image, 20, 20, 24, 24, imagecolorallocate($image, 255, 0, 0));
        imagealphablending($image, false);

        return $image;
    }

    /** A transparent destination of the given size. */
    private function canvas(int $size = 60): \GdImage
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    /** @return array{r: int, g: int, b: int, a: int} */
    private function pixel(\GdImage $image, int $x, int $y): array
    {
        $colour = imagecolorat($image, $x, $y);

        return [
            'r' => ($colour >> 16) & 0xFF,
            'g' => ($colour >> 8) & 0xFF,
            'b' => $colour & 0xFF,
            'a' => ($colour >> 24) & 0x7F,
        ];
    }

    /**
     * A faded logo leaves the corner of its box transparent.
     *
     * **The assertion the whole class is for.** `imagecopymerge()` — the call with the
     * opacity argument — makes that corner opaque grey, and the result is a rectangle with
     * a logo faintly on it. The corner is inside the drawn area and outside the disc, so
     * nothing but the compositing decides what is there.
     */
    public function testAFadedLogoLeavesTheCornerOfItsBoxTransparent(): void
    {
        // Arrange
        $canvas = $this->canvas();
        $logo   = $this->logo();

        // Act — the box is 10,10 to 50,50; its corner is 11,11 and the disc is not there
        ResizeTools::blend($canvas, $logo, 10, 10, 40, 40, 0.6);

        // Assert
        $this->assertSame(
            127,
            $this->pixel($canvas, 11, 11)['a'],
            'the corner of the box is not transparent, so the fade flattened the alpha'
        );
    }

    /**
     * And the artwork itself is drawn, and faded.
     *
     * The other half: a `blend()` that drew nothing at all would pass the assertion above
     * perfectly. The centre must carry the colour, and carry it translucently.
     */
    public function testTheArtworkIsDrawnAndFaded(): void
    {
        // Arrange
        $canvas = $this->canvas();

        // Act
        ResizeTools::blend($canvas, $this->logo(), 10, 10, 40, 40, 0.5);

        // Assert — the centre of the disc
        $centre = $this->pixel($canvas, 30, 30);
        $this->assertGreaterThan(100, $centre['r'], 'the artwork was not drawn');
        $this->assertGreaterThan(0, $centre['a'], 'a 50% fade left the pixel fully opaque');
        $this->assertLessThan(127, $centre['a'], 'a 50% fade left the pixel invisible');
    }

    /**
     * At full opacity the artwork is opaque, and the corner is still clear.
     *
     * The boundary. A fade of 1.0 must be the identity, or every caller drawing a logo
     * plainly pays for a filter pass that changes the image slightly.
     */
    public function testFullOpacityDrawsTheArtworkUnchanged(): void
    {
        // Arrange
        $canvas = $this->canvas();

        // Act
        ResizeTools::blend($canvas, $this->logo(), 10, 10, 40, 40, 1.0);

        // Assert
        $this->assertSame(0, $this->pixel($canvas, 30, 30)['a'], 'a full-opacity draw is translucent');
        $this->assertSame(127, $this->pixel($canvas, 11, 11)['a']);
    }

    /**
     * The source is not modified, so drawing it twice is not drawing it twice as faint.
     *
     * The fade happens on a copy. Filtering the source in place is the obvious
     * implementation and it makes a second `blend()` of the same logo silently darker,
     * which surfaces as "the second card looks wrong" long after the code that did it.
     */
    public function testTheSourceIsLeftAlone(): void
    {
        // Arrange
        $logo   = $this->logo();
        $before = $this->pixel($logo, 20, 20);

        // Act
        ResizeTools::blend($this->canvas(), $logo, 10, 10, 40, 40, 0.3);

        // Assert
        $this->assertSame($before, $this->pixel($logo, 20, 20), 'the source was faded in place');
    }

    /**
     * A silhouette keeps the shape and leaves the clear pixels clear.
     *
     * The second of the three bugs: the obvious way to draw a halo behind a picture is a
     * filled rectangle, and behind a transparent PNG that is a coloured card. The corner
     * again — a rectangle fills it, a silhouette does not.
     */
    public function testASilhouetteIsTheShapeAndNotARectangle(): void
    {
        // Act
        $shape = ResizeTools::silhouette($this->logo(), 0xFFFFFF);

        // Assert
        $this->assertSame(127, $this->pixel($shape, 1, 1)['a'], 'the silhouette filled the corner');

        $centre = $this->pixel($shape, 20, 20);
        $this->assertSame(255, $centre['r'], 'the shape was not recoloured');
        $this->assertSame(255, $centre['g']);
        $this->assertSame(255, $centre['b']);
        $this->assertSame(0, $centre['a']);
    }

    /**
     * A dark source recoloured light comes out light.
     *
     * `IMG_FILTER_COLORIZE` *adds* to the colour rather than replacing it, so the
     * one-call implementation turns a red logo colorised white into pink. This is why
     * `silhouette()` walks the pixels: a filter that is fast and wrong is worse than a
     * loop that is correct on images this size.
     */
    public function testARecolouredSilhouetteReplacesTheColourRatherThanAddingToIt(): void
    {
        // Act
        $shape = ResizeTools::silhouette($this->logo(), 0x0000FF);

        // Assert — the red disc is now pure blue, not magenta
        $centre = $this->pixel($shape, 20, 20);
        $this->assertSame(0, $centre['r'], 'the original colour was added to rather than replaced');
        $this->assertSame(255, $centre['b']);
    }

    /**
     * The requested alpha is a floor, and a soft edge stays soft.
     *
     * A glow is drawn translucent, and a logo with an anti-aliased edge has pixels that
     * are already partly clear. Replacing their alpha with a single value gives the glow a
     * hard edge — which is the artefact that makes a halo look like a sticker.
     */
    public function testTheRequestedAlphaIsAFloorRatherThanTheWholeAnswer(): void
    {
        // Act
        $shape = ResizeTools::silhouette($this->logo(), 0x000000, 60);

        // Assert
        $this->assertSame(60, $this->pixel($shape, 20, 20)['a'], 'an opaque pixel ignored the alpha');
        $this->assertSame(127, $this->pixel($shape, 1, 1)['a'], 'a clear pixel was given a colour');
    }

    /**
     * A zero-opacity or zero-sized draw does nothing, rather than raising.
     *
     * Both arrive from arithmetic — a computed width that rounds to zero, an animation at
     * its first frame — and an exception there is a page that fails over a pixel.
     */
    public function testADrawWithNothingToDoIsHarmless(): void
    {
        // Arrange
        $canvas = $this->canvas();

        // Act + Assert
        $this->assertTrue(ResizeTools::blend($canvas, $this->logo(), 0, 0, 40, 40, 0.0));
        $this->assertTrue(ResizeTools::blend($canvas, $this->logo(), 0, 0, 0, 0, 1.0));
        $this->assertSame(127, $this->pixel($canvas, 5, 5)['a'], 'something was drawn anyway');
    }

}
