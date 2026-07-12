<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Media\Thumbnail;

#[CoversClass(Thumbnail::class)]
class ThumbnailCharacterizationTest extends TestCase
{
    public function testDefaults(): void
    {
        $thumb = new Thumbnail();

        $this->assertSame('', $thumb->filename);
        $this->assertSame(0, $thumb->x);
        $this->assertSame(0, $thumb->y);
        $this->assertSame(0, $thumb->views);
        $this->assertSame(0, $thumb->filesize);
        $this->assertSame('', $thumb->reason);
        $this->assertSame('', $thumb->url);
        $this->assertSame(0, $thumb->createdTxt);
    }

    public function testPropertiesAreMutable(): void
    {
        $thumb = new Thumbnail();

        $thumb->filename = '/tmp/pic.jpg';
        $thumb->x = 320;
        $thumb->y = 240;
        $thumb->views = 12;
        $thumb->filesize = 1024;
        $thumb->reason = 'preview';
        $thumb->url = '/media/preview.jpg';
        $thumb->createdTxt = '2026-01-01 10:00:00';

        $this->assertSame('/tmp/pic.jpg', $thumb->filename);
        $this->assertSame(320, $thumb->x);
        $this->assertSame(240, $thumb->y);
        $this->assertSame(12, $thumb->views);
        $this->assertSame(1024, $thumb->filesize);
        $this->assertSame('preview', $thumb->reason);
        $this->assertSame('/media/preview.jpg', $thumb->url);
        $this->assertSame('2026-01-01 10:00:00', $thumb->createdTxt);
    }
}
