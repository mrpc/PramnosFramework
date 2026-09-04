<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Media\MediaObject;
use Pramnos\Media\Thumbnail;

/**
 * A stored rendition is matched by the request that produced it.
 *
 * WHAT: `thumbnailMatchesRequest()` compares against the **requested** box when
 *       the entry records one, and against the result when it does not.
 * WHY:  the lookup compared the request to `x`/`y`, which is what the rendition
 *       came out as, while the write stored the clamped result in those same
 *       fields. Every source smaller than the requested box therefore missed its
 *       own entry for ever — rebuilt, appended and saved on every call, with a
 *       random filename prefix so neither the files nor the entries were bounded.
 *
 * The fallback is the half that cannot be got wrong quietly, and is why this is a
 * unit test rather than only the integration one. The `thumbnails` column holds
 * JSON now, but rows written before that are `serialize()` output, and
 * `readLegacyThumbnails()` deliberately hands those back **as they are** rather
 * than converting them — so an entry can be an object of an application's own
 * thumbnail class, which does not declare these fields at all. Reading an
 * undeclared property off one of those is a warning and a null, so the matcher
 * reads through `isset` exactly as `encodeThumbnails()` does.
 */
#[CoversClass(MediaObject::class)]
#[CoversClass(Thumbnail::class)]
class ThumbnailRequestMatchingTest extends TestCase
{
    /**
     * Call the private matcher.
     *
     * @param object $thumb
     */
    private function findsEntry($thumb, int $width, int $height): bool
    {
        return (bool) (new \ReflectionMethod(MediaObject::class, 'thumbnailMatchesRequest'))
            ->invoke(null, $thumb, $width, $height);
    }

    /**
     * An entry that recorded the requested box is found by that box.
     *
     * The numbers here are the reported case: 177×222 asked of a 150×150 source
     * produces 120×150.
     */
    public function testAnEntryIsFoundByTheBoxThatWasAskedFor(): void
    {
        // Arrange
        $thumb = new Thumbnail();
        $thumb->x = 120;
        $thumb->y = 150;
        $thumb->requestedX = 177;
        $thumb->requestedY = 222;

        // Act + Assert
        $this->assertTrue($this->findsEntry($thumb, 177, 222),
            'the request that produced it must find it');
        $this->assertFalse($this->findsEntry($thumb, 120, 150),
            'the clamped result is not a request anybody made');
        $this->assertFalse($this->findsEntry($thumb, 200, 222));
    }

    /**
     * An entry with no requested box falls back to the result, as before.
     *
     * Every entry in every existing installation is this shape. A matcher that
     * only compared the requested box would stop finding all of them, and would
     * rebuild every rendition once — which is the bug it was fixing, arriving
     * from the other direction.
     */
    public function testAnEntryWithoutARequestedBoxIsMatchedOnItsResult(): void
    {
        // Arrange — what a pre-existing JSON row hydrates to: class defaults of 0
        $thumb = new Thumbnail();
        $thumb->x = 120;
        $thumb->y = 150;

        // Act + Assert
        $this->assertSame(0, $thumb->requestedX, 'precondition: not recorded');
        $this->assertTrue($this->findsEntry($thumb, 120, 150),
            'the old comparison has to keep working for old entries');
        $this->assertFalse($this->findsEntry($thumb, 177, 222));
    }

    /**
     * A legacy entry of an application's own class does not warn, and still matches.
     *
     * `readLegacyThumbnails()` returns these unconverted on purpose, so the
     * matcher meets objects that never heard of `requestedX`. Under PHP 8 an
     * undeclared property read is a warning; this test fails on it, because the
     * suite turns warnings into failures, which is the point of asserting it.
     */
    public function testALegacyForeignThumbnailNeitherWarnsNorStopsMatching(): void
    {
        // Arrange — the shape an application's own thumbnail class has: the
        // fields this framework reads, and nothing newer
        $foreign = new class {
            public $filename = '/tmp/x.jpg';
            public $x = 120;
            public $y = 150;
            public $reason = 'custom';
        };

        // Act + Assert
        $this->assertTrue($this->findsEntry($foreign, 120, 150));
        $this->assertFalse($this->findsEntry($foreign, 177, 222));
    }

    /**
     * A half-recorded entry is treated as not recorded.
     *
     * Both dimensions or neither: one of them alone cannot identify a box, and a
     * `requestedX` with no `requestedY` would otherwise match every height.
     */
    public function testAHalfRecordedRequestFallsBackToTheResult(): void
    {
        // Arrange
        $thumb = new Thumbnail();
        $thumb->x = 120;
        $thumb->y = 150;
        $thumb->requestedX = 177;
        $thumb->requestedY = 0;

        // Act + Assert
        $this->assertFalse($this->findsEntry($thumb, 177, 222),
            'half a box is not a box');
        $this->assertTrue($this->findsEntry($thumb, 120, 150),
            'so it is matched the old way');
    }

    /**
     * The new fields survive the column's JSON round trip.
     *
     * They have to be in `THUMBNAIL_FIELDS` to be written at all — the encoder
     * copies that list and nothing else, and the hydrator ignores keys outside
     * it — so a field added to the class and not to the list would be silently
     * dropped on every save.
     */
    public function testTheRequestedBoxSurvivesTheColumnRoundTrip(): void
    {
        // Arrange
        $thumb = new Thumbnail();
        $thumb->filename   = '/tmp/x.jpg';
        $thumb->x          = 120;
        $thumb->y          = 150;
        $thumb->requestedX = 177;
        $thumb->requestedY = 222;
        $thumb->reason     = 'custom';

        $encode = new \ReflectionMethod(MediaObject::class, 'encodeThumbnails');
        $decode = new \ReflectionMethod(MediaObject::class, 'usableThumbnails');

        // Act
        $stored  = $encode->invoke(null, array($thumb));
        $reread  = $decode->invoke(null, $stored);

        // Assert
        $this->assertStringStartsWith('[', $stored, 'the column holds JSON');
        $this->assertCount(1, $reread);
        $this->assertSame(177, (int) $reread[0]->requestedX);
        $this->assertSame(222, (int) $reread[0]->requestedY);
        $this->assertTrue($this->findsEntry($reread[0], 177, 222),
            'and it is findable after the round trip, which is the only thing that matters');
    }

    /**
     * A JSON row written before these fields existed reads back as not recorded.
     *
     * This is what every stored row looks like on the day this ships, so it is
     * the case that decides whether the change is safe to deploy.
     */
    public function testAJsonRowWrittenBeforeTheseFieldsReadsBackAsNotRecorded(): void
    {
        // Arrange — the old payload, with the eight fields that existed then
        $old = json_encode(array(array(
            'filename'   => '/tmp/x.jpg',
            'x'          => 120,
            'y'          => 150,
            'views'      => 0,
            'filesize'   => 1234,
            'reason'     => 'custom',
            'url'        => 'media/x.jpg',
            'createdTxt' => '01/01/2026 00:00:00',
        )));

        // Act
        $reread = (new \ReflectionMethod(MediaObject::class, 'usableThumbnails'))
            ->invoke(null, $old);

        // Assert
        $this->assertCount(1, $reread);
        $this->assertSame(0, (int) $reread[0]->requestedX,
            'absent from the payload, so the class default stands');
        $this->assertTrue($this->findsEntry($reread[0], 120, 150),
            'and it keeps being found the way it always was');
    }
}
