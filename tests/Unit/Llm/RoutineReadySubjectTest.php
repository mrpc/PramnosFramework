<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Chat\RoutineReady;

/**
 * A report's email subject carries the day it ran, so a mailbox of daily reports with one title
 * tells them apart; without a day it is the title alone, as for a notification class written for six
 * arguments.
 */
#[CoversClass(RoutineReady::class)]
class RoutineReadySubjectTest extends LlmTestCase
{
    public function testTheSubjectCarriesTheDay(): void
    {
        // Arrange
        $dated   = new RoutineReady('Daily figures', true, '', '/r/1', 'Body', true, '28/10/2026');
        $undated = new RoutineReady('Daily figures', true, '', '/r/1', 'Body', true);

        // Act / Assert
        $this->assertSame('Daily figures — 28/10/2026', $dated->toMail(null)['subject']);
        $this->assertSame('Daily figures', $undated->toMail(null)['subject']);
    }
}
