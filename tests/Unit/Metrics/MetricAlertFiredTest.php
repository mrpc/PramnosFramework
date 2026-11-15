<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Metrics\MetricAlertFired;

/**
 * An alert firing, as an event a queued listener can be given again (BI phase 7e, FW-516): what
 * it carries, and the round trip through the queue.
 */
#[CoversClass(MetricAlertFired::class)]
class MetricAlertFiredTest extends TestCase
{
    /** Its fields, its event name, what it serialises to and reads back the same from. */
    public function testWhatItCarriesAndTheRoundTrip(): void
    {
        // Arrange
        $event = new MetricAlertFired(4, 7, 'test.payments', 'Payments was 0', 1700000000);

        // Act
        $carried = $event->jsonSerialize();
        $again   = MetricAlertFired::fromQueue($carried);

        // Assert
        $this->assertSame('metric.alert', MetricAlertFired::EVENT);
        $this->assertSame(7, $event->personId(), 'what a rule\'s event trigger reads');
        $this->assertSame(['rule' => 4, 'person' => 7, 'metric' => 'test.payments', 'said' => 'Payments was 0', 'at' => 1700000000], $carried);
        $this->assertEquals($event, $again, 'read back the same from what was carried');
    }
}
