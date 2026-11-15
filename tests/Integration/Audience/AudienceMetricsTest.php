<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Audience\AudienceMetrics;
use Pramnos\Audience\PersonEvents;
use Pramnos\Audience\Segments;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * The audience as metrics, with nothing declared: a segment's size at the end of each period, who
 * entered it — its first working out not counted, as the library does not count it — and who left;
 * a person event's occurrences and the people who did it. Left out with `'audience' => false`.
 *
 * Also BI phase 7d: a metric of its own, over its own table, broken down or filtered by a segment
 * or an audience attribute once it names its person column ({@see Metric::person()}).
 */
#[CoversClass(AudienceMetrics::class)]
#[CoversClass(Metrics::class)]
#[CoversClass(Metric::class)]
class AudienceMetricsTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }

    /** @var mixed The application's metrics settings, put back */
    private mixed $metrics = null;

    /** The metrics forgotten and their settings put back, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo['metrics'] = $this->metrics;
        Metrics::reset();
        PersonEvents::reset();
        $this->probeTearDown();
    }

    /**
     * September: anna was in the segment from its first working out (1 September) and left on the
     * 20th, cara entered on the 10th, bob on the 25th. At the end of September two are in it; two
     * entered — anna's first stay is the segment's start — and one left; at the end of August
     * nobody was. Paid twice by anna and once by bob in September: three payments, two people.
     */
    #[DataProvider('databases')]
    public function testSegmentsAndEventsAreMetrics(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->metrics = Application::getInstance()->applicationInfo['metrics'] ?? null;
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC'];
        $at  = static fn (string $when): int => (int) strtotime($when . ' UTC');
        $id  = Segments::create('Greek speakers', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        $this->db->queryBuilder()->table('#PREFIX#segments')->where('segmentid', $id)->update(['firstcomputedat' => $at('2026-09-01 00:00')]);
        $stays = $this->db->queryBuilder()->table('pramnos.segment_members');
        $stays->where('segmentid', $id)->delete();
        foreach ([['anna', '2026-09-01 00:00', '2026-09-20 10:00'], ['cara', '2026-09-10 10:00', null], ['bob', '2026-09-25 10:00', null]] as [$name, $in, $out]) {
            $this->db->queryBuilder()->table('pramnos.segment_members')->insert(['segmentid' => $id, 'userid' => $this->ids[$name],
                'entered_at' => $at($in), 'left_at' => $out === null ? null : $at($out)]);
        }
        PersonEvents::define('test.paid', 'Paid');
        PersonEvents::backfill('test.paid', [[$this->ids['anna'], $at('2026-09-05 10:00')], [$this->ids['anna'], $at('2026-09-06 10:00')], [$this->ids['bob'], $at('2026-09-07 10:00')]]);
        Metrics::reset();

        // Act
        $names = array_keys(Metrics::all());
        $computed = Metrics::compute(['audience.segment.members', 'audience.segment.entered', 'audience.segment.left', 'event.test.paid', 'event.test.paid.people'],
            ['month'], '2026-08-01', '2026-09-30', false, $at('2026-11-06 12:00'));
        $read = static fn (string $metric, string $month, array $where = []): ?float => Metrics::query($metric)->grain('month')->at($month)->where($where)->value();
        $bySegment = Metrics::query('audience.segment.members')->grain('month')->at('2026-09-01')->by('segment')->series()['values'];
        $label     = Metrics::dimensionOf('segment')->labelOf((string) $id);
        $values    = [
            'members' => $read('audience.segment.members', '2026-09-01', ['segment' => (string) $id]), 'before' => $read('audience.segment.members', '2026-08-01'),
            'entered' => $read('audience.segment.entered', '2026-09-01'), 'left' => $read('audience.segment.left', '2026-09-01'),
            'paid' => $read('event.test.paid', '2026-09-01'), 'payers' => $read('event.test.paid.people', '2026-09-01'),
        ];
        // The people behind each number (FW-457): the members, who entered, who paid.
        $members = Metrics::records('audience.segment.members', 'month', '2026-09-01', ['segment' => (string) $id]);
        $entered = Metrics::records('audience.segment.entered', 'month', '2026-09-01');
        $left    = Metrics::records('audience.segment.left', 'month', '2026-09-01');
        $payers  = Metrics::records('event.test.paid.people', 'month', '2026-09-01');
        Application::getInstance()->applicationInfo['metrics']['audience'] = false;
        Metrics::reset();
        $without = array_filter(array_keys(Metrics::all()), static fn (string $n): bool => str_starts_with($n, 'audience.') || str_starts_with($n, 'event.'));

        // Assert
        $this->assertSame([], $computed['failed'], 'computed');
        $this->assertContains('audience.segment.members', $names);
        $this->assertContains('event.test.paid.people', $names);
        $this->assertSame(2.0, $values['members'], 'cara and bob; anna had left');
        $this->assertSame(0.0, $values['before'], 'nobody before it began');
        $this->assertSame([(string) $id => [2.0]], $bySegment);
        $this->assertSame(2.0, $values['entered'], 'cara and bob; anna was its start');
        $this->assertSame(1.0, $values['left']);
        $this->assertSame([3.0, 2.0], [$values['paid'], $values['payers']], 'three payments, two people');
        $this->assertSame('Greek speakers', $label);
        $this->assertEqualsCanonicalizing([$this->ids['cara'], $this->ids['bob']], array_column($members, 'userid'), 'the members as the month ends');
        $this->assertSame('Greek speakers', $members[0]['segment']);
        $this->assertEqualsCanonicalizing([$this->ids['anna'], $this->ids['cara'], $this->ids['bob']], array_column($entered, 'userid'), 'listed, its start too');
        $this->assertSame([$this->ids['anna']], array_column($left, 'userid'));
        $this->assertSame('2026-09-20 10:00', $left[0]['left']);
        $this->assertSame([$this->ids['bob'], $this->ids['anna'], $this->ids['anna']], array_map('intval', array_column($payers, 'userid')), 'each payment, the newest first');
        $this->assertSame([], array_values($without), 'left out where app.php says so');
    }

    /**
     * BI phase 7d: a metric over its own table, not the audience's, is broken down by a segment —
     * read as of the period's end, like `audience.segment.members` above — or by an audience
     * attribute (usertype), the same qualified SQL a filter already uses, once it declares which
     * column names the person (`person('userid')`). Refused on the one-breakdown path without it;
     * the PostgreSQL many-breakdown prefetch skips such a breakdown instead of computing it wrong.
     */
    #[DataProvider('databases')]
    public function testASegmentOrAnAttributeBreaksDownAMetricThatDeclaresItsPerson(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Stats\CreateMetricValuesTable::class], $this->db);
        $at = static fn (string $when): int => (int) strtotime($when . ' UTC');
        $schema = $this->db->schema();
        $schema->dropTableIfExists('metrics_test_payments');
        $schema->createTable('metrics_test_payments', static function ($table): void {
            $table->increments('id');
            $table->integer('userid');
            $table->decimal('amount', 10, 2);
            $table->integer('paid_at');
        });
        try {
            foreach ([['anna', 10], ['anna', 5], ['bob', 7], ['cara', 3]] as [$name, $amount]) {
                $this->db->queryBuilder()->table('metrics_test_payments')->insert(['userid' => $this->ids[$name], 'amount' => $amount, 'paid_at' => $at('2026-09-03 10:00')]);
            }
            // anna and bob are in the segment; cara is not — a sum over it does not add up to the whole.
            $id = Segments::create('Payers probe', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
            $this->db->queryBuilder()->table('pramnos.segment_members')->where('segmentid', $id)->delete();
            foreach (['anna', 'bob'] as $name) {
                $this->db->queryBuilder()->table('pramnos.segment_members')->insert(['segmentid' => $id, 'userid' => $this->ids[$name], 'entered_at' => $at('2026-09-01 00:00'), 'left_at' => null]);
            }
            $withPerson = Metric::flow('test.payments', 'Payments')->from('metrics_test_payments')->at('paid_at', unix: true)->sum('amount')->person('userid')
                ->dimensions(['segment', 'usertype', 'pillar']);
            $withoutOne = Metric::flow('test.payments_noperson', 'Payments, no person()')->from('metrics_test_payments')->at('paid_at', unix: true)->sum('amount');
            Metrics::register($withPerson);

            // Act
            $computed  = Metrics::compute(['test.payments'], ['month'], '2026-09-01', '2026-09-30', false, $at('2026-11-06 12:00'));
            $bySegment = Metrics::query('test.payments')->grain('month')->at('2026-09-01')->by('segment')->series()['values'];
            $byType    = Metrics::query('test.payments')->grain('month')->at('2026-09-01')->by('usertype')->series()['values'];
            // 'pillar' lives on a side table (audtest_profiles) — bob and cara have one, anna does not.
            $byPillar  = Metrics::query('test.payments')->grain('month')->at('2026-09-01')->by('pillar')->series()['values'];
            $total     = Metrics::query('test.payments')->grain('month')->at('2026-09-01')->value();
            $refused   = null;
            try {
                $withoutOne->measure(Window::of('month', '2026-09-01'), ['segment'], $this->db);
            } catch (\LogicException $e) {
                $refused = $e->getMessage();
            }
            $prefetched = $withPerson->prefetchSets(Window::of('month', '2026-09-01'), [['segment'], ['usertype'], []], $this->db);
            $prefetchedAhead = $withPerson->prefetch([Window::of('month', '2026-08-01'), Window::of('month', '2026-09-01')], $this->db);

            // Assert
            $this->assertSame([], $computed['failed'], 'computed');
            $this->assertSame(25.0, $total, 'all four payments, not narrowed by the segment breakdown existing too');
            $this->assertSame(['' => [3.0], (string) $id => [22.0]], $bySegment, 'cara\'s payment kept, under no segment — a LEFT JOIN, not dropped by one in no segment at all');
            $this->assertEqualsCanonicalizing(['1' => [15.0], '2' => [10.0]], $byType, 'usertype 1 (anna) and 2 (bob + cara)');
            $this->assertEqualsCanonicalizing(['' => [15.0], '3' => [7.0], '1' => [3.0]], $byPillar, 'pillar lives on a side table — anna has none, bob\'s is 3, cara\'s is 1');
            $this->assertStringContainsString('person()', (string) $refused, 'refused: no column names the person');
            $this->assertFalse($prefetched, 'the PostgreSQL many-breakdown path does not build the join; one query per breakdown does, in declaredSource()');
            $this->assertFalse($prefetchedAhead, 'the PostgreSQL many-period prefetch does not build it either');
        } finally {
            \Pramnos\Metrics\Store::forget($this->db, 'test.%');
            $schema->dropTableIfExists('metrics_test_payments');
        }
    }
}
