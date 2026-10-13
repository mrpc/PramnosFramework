<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Llm\Chat\Web\Stamp;

/**
 * A message's time on the conversation page: an instant in UTC for `<time datetime>`, whatever
 * shape the database read it back in — with an offset from PostgreSQL, without one from MySQL.
 */
#[CoversClass(Stamp::class)]
class StampTest extends TestCase
{
    /** Without an offset it is UTC; with one it is converted; nothing is no time. */
    public function testEveryShapeIsOneInstant(): void
    {
        // Act / Assert
        $this->assertSame('2026-10-13T19:50:00Z', Stamp::iso('2026-10-13 19:50:00'), 'MySQL: written in UTC');
        $this->assertSame('2026-10-13T19:50:00Z', Stamp::iso('2026-10-13 22:50:00+03:00'), 'PostgreSQL: with its offset');
        $this->assertSame('', Stamp::iso(''));
        $this->assertSame('', Stamp::iso('not a time'));
    }

    /** The words a page without JavaScript shows, which say they are UTC. */
    public function testTheTextSaysItsZone(): void
    {
        // Act / Assert
        $this->assertSame('13 Oct 2026 19:50 UTC', Stamp::text('2026-10-13T19:50:00Z'));
        $this->assertSame('', Stamp::text(''));
    }
}
