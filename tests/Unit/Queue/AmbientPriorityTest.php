<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Pramnos\Queue\AmbientPriority;

/**
 * {@see AmbientPriority} on its own: nothing set reads null, a worker's own set() is read back
 * exactly, and reset() forgets it — the three operations `QueueManager::resolvePriority()` and
 * `Worker::processNextTask()` build the inheritance feature out of.
 */
class AmbientPriorityTest extends TestCase
{
    protected function tearDown(): void
    {
        AmbientPriority::reset();
        parent::tearDown();
    }

    public function testSetReadBackAndReset(): void
    {
        // Act / Assert
        $this->assertNull(AmbientPriority::current(), 'nothing running yet');

        AmbientPriority::set(3);
        $this->assertSame(3, AmbientPriority::current());

        AmbientPriority::set(null);
        $this->assertNull(AmbientPriority::current(), 'a worker clears it the same way it set it');

        AmbientPriority::set(7);
        AmbientPriority::reset();
        $this->assertNull(AmbientPriority::current());
    }
}
