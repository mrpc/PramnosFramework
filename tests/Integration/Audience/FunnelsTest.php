<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Filter;
use Pramnos\Audience\Funnels;
use Pramnos\Audience\PersonEvents;
use Pramnos\Audience\Segments;
use Pramnos\Metrics\Widgets\FunnelWidget;

/**
 * Funnels over person events (BI phase 7g, FW-518): on MySQL, prefixed MySQL and PostgreSQL.
 *
 * anna starts, then reaches the middle 100 seconds later, then the end 50 seconds after that; bob
 * starts, then reaches the middle 200 seconds later, and never the end; cara only starts.
 */
#[CoversClass(Funnels::class)]
#[CoversClass(PersonEvents::class)]
#[CoversClass(Filter::class)]
#[CoversClass(Segments::class)]
#[CoversClass(FunnelWidget::class)]
class FunnelsTest extends TestCase
{
    use AudienceProbe;

    /** Records anna, bob and cara's own steps, at the given start moment. */
    private function steps(int $start): void
    {
        PersonEvents::record($this->ids['anna'], 'start', [], $start);
        PersonEvents::record($this->ids['bob'], 'start', [], $start);
        PersonEvents::record($this->ids['cara'], 'start', [], $start);
        PersonEvents::record($this->ids['anna'], 'middle', [], $start + 100);
        PersonEvents::record($this->ids['bob'], 'middle', [], $start + 200);
        PersonEvents::record($this->ids['anna'], 'end', [], $start + 150);
    }

    /**
     * Each step's own people, narrowing from the one before — never cara past the first, never
     * bob past the second — their share of the first, and the median time from the step before;
     * fewer than two steps, or steps that are not events at all, are refused.
     */
    #[DataProvider('databases')]
    public function testEachStepNarrowsFromTheOneBefore(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $start = time() - 3600;
        $this->steps($start);

        // Act
        $funnel = Funnels::run(['start', 'middle', 'end']);
        $refused = 0;
        foreach ([['start'], ['start', 'start']] as $bad) {
            try {
                Funnels::run($bad);
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }

        // Assert
        $this->assertSame(['start', 'middle', 'end'], array_column($funnel['steps'], 'event'));
        $this->assertSame([3, 2, 1], array_column($funnel['steps'], 'people'), 'cara never reaches the middle; bob never the end');
        $this->assertSame([1.0, 2 / 3, 1 / 3], array_column($funnel['steps'], 'share'));
        $this->assertNull($funnel['steps'][0]['median_seconds'], 'nothing to measure before the first step');
        $this->assertSame(100, $funnel['steps'][1]['median_seconds'], 'the middle of anna\'s 100s and bob\'s 200s gap');
        $this->assertSame(50, $funnel['steps'][2]['median_seconds'], 'anna alone: 50s from the middle to the end');
        $this->assertSame([], $funnel['by'], 'nothing asked for');
        $this->assertSame(2, $refused, 'one step, or the same step twice, is not a funnel');
    }

    /**
     * Broken down by a segment (member or not, among the first step's own people) and by an
     * audience attribute's own values — the same count every other breakdown in the audience
     * module goes through, restricted to this funnel's own cohort.
     */
    #[DataProvider('databases')]
    public function testBrokenDownByASegmentOrAnAttribute(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->steps(time() - 3600);
        // bob's own pillar is 3, cara's 1, anna has no profile row at all (the seed, AudienceProbe).
        $segment = Segments::create('Pillar three', ['conditions' => [['attribute' => 'pillar', 'op' => 'is', 'value' => 3]]]);
        Segments::recompute($segment);

        // Act
        $bySegment  = Funnels::run(['start', 'middle'], ['by' => 'segment:' . $segment]);
        $byPillar   = Funnels::run(['start', 'middle'], ['by' => 'pillar']);
        $noCohort   = Funnels::run(['start', 'middle'], ['from' => time() + 86400, 'by' => 'segment:' . $segment]);

        // Assert
        $this->assertSame(['Member' => 1, 'Not a member' => 2], $bySegment['by'], 'bob alone is in the segment');
        // Tied counts: the order ties break in is the database's own, so each value is checked on its own.
        $this->assertCount(3, $byPillar['by']);
        $this->assertSame(1, $byPillar['by'][3] ?? null, 'bob\'s own pillar');
        $this->assertSame(1, $byPillar['by'][1] ?? null, 'cara\'s own pillar');
        $this->assertSame(1, $byPillar['by']['(empty)'] ?? null, 'anna has no profile row at all');
        $this->assertSame([], $noCohort['by'], 'nobody in a window with nothing in it');
    }

    /**
     * The report card ({@see FunnelWidget}): each step's own row, a breakdown table beside it
     * where one is chosen; events that are not declared are dropped, and fewer than two steps
     * left is a note instead of a table.
     */
    #[DataProvider('databases')]
    public function testTheReportCardShowsEachStep(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->steps(time() - 3600);
        PersonEvents::record($this->ids['anna'], 'long.start', [], time() - 10000);
        PersonEvents::record($this->ids['anna'], 'long.end', [], time() - 10000 + 5000);
        PersonEvents::define('start', 'Started');
        PersonEvents::define('middle', 'Reached the middle');
        PersonEvents::define('end', 'Finished');
        PersonEvents::define('long.start', 'Long start');
        PersonEvents::define('long.end', 'Long end');

        // Act
        $card    = (new FunnelWidget())->configure(['events' => 'start,middle,unknown.event,end', 'by' => 'pillar'])->render();
        $none    = (new FunnelWidget())->configure(['events' => 'start'])->render();
        $refused = (new FunnelWidget())->configure(['events' => 'start,start'])->render();
        $hours   = (new FunnelWidget())->configure(['events' => 'long.start,long.end'])->render();
        $options = (new FunnelWidget())->options();

        // Assert
        $this->assertStringContainsString('Reached the middle', $card);
        $this->assertStringContainsString('Finished', $card);
        $this->assertStringNotContainsString('unknown.event', $card, 'an event nobody declared is dropped, not shown as a step');
        $this->assertStringContainsString('(empty)', $card, 'the breakdown table, by the pillar chosen in settings');
        $this->assertStringContainsString('settings', $none, 'one known step left: a note, not a table');
        $this->assertStringContainsString('two steps', $refused, 'the same step twice, deduplicated to one, is not a funnel either');
        $this->assertStringContainsString(' h', $hours, 'an hour or more: hours, not minutes');
        $this->assertArrayHasKey('by', $options);
    }
}
