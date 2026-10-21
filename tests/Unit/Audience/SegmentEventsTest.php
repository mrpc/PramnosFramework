<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\SegmentEntered;
use Pramnos\Audience\SegmentLeft;

/**
 * The segment events travel to a queued listener as plain data and are built again in the worker
 * with the same segment, person and time.
 */
#[CoversClass(SegmentEntered::class)]
#[CoversClass(SegmentLeft::class)]
class SegmentEventsTest extends TestCase
{
    /**
     * Each event writes ids and a time, builds itself again from them, names its person, and is
     * fired under its own name — what a rule listens to.
     */
    public function testAnEventSurvivesTheQueue(): void
    {
        foreach ([SegmentEntered::class => 'segment.entered', SegmentLeft::class => 'segment.left'] as $class => $name) {
            // Arrange
            $event = new $class(3, 41, 1_760_000_000);

            // Act
            $again = $class::fromQueue(json_decode((string) json_encode($event), true));

            // Assert
            $this->assertSame(['segment' => 3, 'person' => 41, 'at' => 1_760_000_000], $event->jsonSerialize());
            $this->assertEquals($event, $again);
            $this->assertSame(41, $again->personId());
            $this->assertSame($name, $class::EVENT);
        }
    }

    /** A task missing its data builds an event about nobody rather than failing in the worker. */
    public function testMissingDataIsZero(): void
    {
        // Arrange
        $data = [];

        // Act
        $event = SegmentLeft::fromQueue($data);

        // Assert
        $this->assertSame(['segment' => 0, 'person' => 0, 'at' => 0], $event->jsonSerialize());
    }
}
