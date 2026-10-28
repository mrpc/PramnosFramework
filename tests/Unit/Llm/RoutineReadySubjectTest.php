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

    /**
     * A chart in a report reaches the email as a table of its numbers — a mail client runs no
     * script, and the JSON a page draws from would show raw and widen the message.
     */
    public function testAChartBecomesATableInTheEmail(): void
    {
        // Arrange
        $content = "Bookings:\n\n```chart\n" . '{"type":"bar","title":"Week","labels":["Mon","Tue"],"series":[{"name":"2026","data":[3,null]}]}' . "\n```\n\nDone.";
        $notice  = new RoutineReady('Weekly', true, '', '/r/1', $content, true);

        // Act
        $body = $notice->toMail(null)['body'];
        $page = \Pramnos\Llm\Chat\Markdown::toHtml($content);

        // Assert
        $this->assertStringNotContainsString('data-pf-chart', $body);
        $this->assertStringNotContainsString('"series"', $body, 'no JSON in the email');
        $this->assertStringContainsString('<caption style="text-align:left;font-weight:bold">Week</caption>', $body);
        $this->assertStringContainsString('>2026</th>', $body);
        $this->assertMatchesRegularExpression('#>Mon</td><td[^>]*>3</td>#', $body);
        $this->assertMatchesRegularExpression('#>Tue</td><td[^>]*></td>#', $body, 'a missing value is an empty cell');
        $this->assertStringContainsString('Done.', $body);
        $this->assertStringContainsString('data-pf-chart', $page, 'a page still draws the chart');
    }
}
