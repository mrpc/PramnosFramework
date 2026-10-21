<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Filter;
use Pramnos\Audience\RefreshPersonSegments;
use Pramnos\Audience\SegmentEntered;
use Pramnos\Audience\SegmentLeft;
use Pramnos\Audience\Segments;
use Pramnos\Console\Commands\SegmentsRecompute;
use Pramnos\Event\Event;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Segments on MySQL and PostgreSQL: a filter with a name, worked out in full and per person, its
 * history kept as stays, each change an event — except the first recomputation, which only
 * records who is in.
 */
#[CoversClass(Segments::class)]
#[CoversClass(SegmentEntered::class)]
#[CoversClass(SegmentLeft::class)]
#[CoversClass(RefreshPersonSegments::class)]
#[CoversClass(SegmentsRecompute::class)]
#[CoversClass(Filter::class)]
class SegmentsTest extends TestCase
{
    use AudienceProbe;

    /** @var list<string> "entered:segment:person" / "left:…", in the order dispatched */
    private array $heard = [];

    /** Record every segment event the request hears. */
    private function listen(): void
    {
        Event::listen(SegmentEntered::EVENT, function (SegmentEntered $e): void {
            $this->heard[] = 'entered:' . $e->segmentId . ':' . $e->personId;
        });
        Event::listen(SegmentLeft::EVENT, function (SegmentLeft $e): void {
            $this->heard[] = 'left:' . $e->segmentId . ':' . $e->personId;
        });
    }

    /** "Greek speakers": anna and cara. */
    private function greek(): int
    {
        return Segments::create('Greek speakers', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]], owner: 7, description: 'For news in Greek');
    }

    /** Change a column of one account. */
    private function set(string $name, array $row): void
    {
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids[$name])->update($row);
    }

    /**
     * A segment's first recomputation records everybody in it without an event — they did not
     * just arrive — and stores the count and when it was done.
     */
    #[DataProvider('databases')]
    public function testTheFirstRecomputationRecordsMembersSilently(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->listen();
        $id = $this->greek();

        // Act
        $result  = Segments::recompute($id);
        $segment = Segments::get($id);

        // Assert
        $this->assertSame(['entered' => 2, 'left' => 0, 'members' => 2], $result);
        $this->assertSame([], $this->heard, 'no event for the people already there');
        $this->assertSame(2, $segment['members']);
        $this->assertNotNull($segment['recomputedAt']);
        $this->assertSame('For news in Greek', $segment['description']);
        $this->assertSame(7, $segment['owner']);
        $this->assertTrue(Segments::isMember($id, $this->ids['anna']));
        $this->assertFalse(Segments::isMember($id, $this->ids['bob']));
    }

    /**
     * Later recomputations announce each change: who entered and who left, as event classes,
     * with the stay closed rather than deleted — the history is what "+in / −out over 7 days"
     * counts.
     */
    #[DataProvider('databases')]
    public function testALaterRecomputationAnnouncesWhoEnteredAndWhoLeft(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = $this->greek();
        Segments::recompute($id);
        $this->listen();
        $this->set('anna', ['language' => 'en']);
        $this->set('bob', ['language' => 'el']);

        // Act
        $result = Segments::recompute($id);

        // Assert
        $this->assertSame(['entered' => 1, 'left' => 1, 'members' => 2], $result);
        $this->assertSame(['entered:' . $id . ':' . $this->ids['bob'], 'left:' . $id . ':' . $this->ids['anna']], $this->heard);
        $this->assertSame(['entered' => 3, 'left' => 1], Segments::movement($id), 'two first stays and bob; anna left');
        $stays = $this->db->queryBuilder()->table('segment_members')->where('userid', $this->ids['anna'])->getAll();
        $this->assertCount(1, $stays);
        $this->assertNotNull($stays[0]['left_at'], 'closed, not deleted');
    }

    /**
     * One person is worked out against every segment that has been computed once: they enter or
     * leave at once, with the events; a segment never computed is left for its first run.
     */
    #[DataProvider('databases')]
    public function testOnePersonIsRecomputedAgainstEverySegment(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = $this->greek();
        Segments::recompute($greek);
        $fresh = Segments::create('Professionals', ['conditions' => [['attribute' => 'usertype', 'op' => 'is', 'value' => 2]]]);
        $this->listen();
        $this->set('bob', ['language' => 'el']);
        $this->set('anna', ['language' => 'en']);

        // Act
        $bob  = Segments::recomputePerson($this->ids['bob']);
        $anna = Segments::recomputePerson($this->ids['anna']);
        $same = Segments::recomputePerson($this->ids['cara']);

        // Assert
        $this->assertSame(['entered' => [$greek], 'left' => []], $bob, 'not the never-computed one');
        $this->assertSame(['entered' => [], 'left' => [$greek]], $anna);
        $this->assertSame(['entered' => [], 'left' => []], $same, 'no change, no event');
        $this->assertSame(['entered:' . $greek . ':' . $this->ids['bob'], 'left:' . $greek . ':' . $this->ids['anna']], $this->heard);
        $this->assertSame(2, Segments::get($greek)['members']);
        $this->assertFalse(Segments::isMember($fresh, $this->ids['bob']));
    }

    /**
     * A filter can ask "is in segment X": the full run computes X first, so the segment built on
     * it reads X's members as they are now; explain() names the segment and says whether the
     * person is in it.
     */
    #[DataProvider('databases')]
    public function testASegmentCanBeBuiltOnAnother(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        // Made first, so an unordered run would compute it before the segment it reads.
        $built = Segments::create('Greek professionals', ['conditions' => [
            ['segment' => 999999, 'op' => 'in'],
        ]]);
        $greek = $this->greek();
        Segments::update($built, ['filter' => ['conditions' => [
            ['segment' => $greek, 'op' => 'in'],
            ['attribute' => 'usertype', 'op' => 'is', 'value' => 2],
        ]]]);
        $outside = Segments::create('Not Greek', ['conditions' => [['segment' => $greek, 'op' => 'not_in']]]);

        // Act
        $results = Segments::recomputeAll();
        $why     = Segments::filter($built)->explain($this->ids['anna']);

        // Assert
        $this->assertSame([$greek, $built, $outside], array_keys($results), 'the read segment first');
        $this->assertSame(1, $results[$built]['members'], 'cara: Greek and a professional');
        $this->assertTrue(Segments::isMember($built, $this->ids['cara']));
        $this->assertTrue(Segments::isMember($outside, $this->ids['bob']));
        $this->assertSame('Greek speakers', $why['conditions'][0]['label']);
        $this->assertTrue($why['conditions'][0]['actual']);
        $this->assertTrue($why['conditions'][0]['ok']);
        $this->assertFalse($why['conditions'][1]['ok']);
        $panel = Segments::of($this->ids['cara']);
        $this->assertSame([$built, $greek], array_column($panel, 'id'), 'the person card: by name');
        $this->assertSame(['Greek professionals', 'Greek speakers'], array_column($panel, 'name'));
        $this->assertGreaterThan(0, $panel[0]['since']);
    }

    /**
     * A segment cannot be defined by its own members, directly or through another segment: the
     * filter is refused when it is saved, so a recomputation never chases its tail.
     */
    #[DataProvider('databases')]
    public function testASegmentCannotNameItself(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $a = $this->greek();
        $b = Segments::create('B', ['conditions' => [['segment' => $a, 'op' => 'in']]]);

        // Act
        $refusals = [];
        foreach ([$a, $b] as $id) {
            try {
                Segments::update($a, ['filter' => ['conditions' => [['segment' => $id, 'op' => 'in']]]]);
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }

        // A diamond — two segments over one — is not a cycle and is saved.
        $c = Segments::create('C', ['conditions' => [['segment' => $b, 'op' => 'in']]]);
        $d = Segments::create('D', ['conditions' => [['segment' => $b, 'op' => 'in']]]);
        Segments::update($c, ['filter' => ['conditions' => [['segment' => $b, 'op' => 'in'], ['segment' => $d, 'op' => 'in']]]]);

        // Assert
        $this->assertSame(['A segment cannot be defined by its own members.', 'A segment cannot be defined by its own members.'], $refusals);
        $this->assertSame([$b, $d], Filter::segmentsIn(Segments::get($c)['filter']));
        $this->assertSame('language', Segments::get($a)['filter']['conditions'][0]['attribute'], 'unchanged');
    }

    /**
     * A segment in use — by another segment's filter, or by whatever an application reports
     * through usage() — is not deleted; one in no use is deleted with its history.
     */
    #[DataProvider('databases')]
    public function testASegmentInUseIsNotDeleted(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = $this->greek();
        Segments::recompute($greek);
        $built = Segments::create('Built', ['conditions' => [['segment' => $greek, 'op' => 'in']]]);
        Segments::usage('rules', fn (int $id): array => $id === $built ? ['rule Welcome'] : []);

        // Act
        $refused = [];
        foreach ([$greek, $built] as $id) {
            try {
                Segments::delete($id);
            } catch (\RuntimeException $e) {
                $refused[] = $e->getMessage();
            }
        }
        Segments::reset();
        Segments::delete($built);
        Segments::delete($greek);

        // Assert
        $this->assertSame(['This segment is in use: segment Built', 'This segment is in use: rule Welcome'], $refused);
        $this->assertSame([], Segments::all());
        $this->assertSame([], $this->db->queryBuilder()->table('segment_members')->getAll(), 'the history goes with it');
    }

    /**
     * A name is required and trimmed of markup; a filter that does not check is refused before
     * anything is written.
     */
    #[DataProvider('databases')]
    public function testANameAndAValidFilterAreRequired(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $refusals = [];

        // Act
        foreach ([['  <b></b> ', []], ['Bad', ['conditions' => [['attribute' => 'nope', 'op' => 'is', 'value' => 1]]]]] as [$name, $filter]) {
            try {
                Segments::create($name, $filter);
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }
        $id = Segments::create(' <i>Everyone</i> ', '');
        Segments::update($id, ['name' => 'All', 'description' => 'Everybody in scope']);

        // Assert
        $this->assertSame(['A segment needs a name.', 'filter.0: no attribute nope.'], $refusals);
        $this->assertSame('All', Segments::get($id)['name']);
        $this->assertSame('Everybody in scope', Segments::get($id)['description']);
        $this->assertNull(Segments::get($id + 1000));
        $this->expectException(\InvalidArgumentException::class);
        Segments::recompute($id + 1000);
    }

    /**
     * refreshOn() puts a folded queued listener on the events, and that listener, run as the worker
     * runs it, recomputes the person the event names — an id or an object with personId().
     */
    #[DataProvider('databases')]
    public function testAnEventRecomputesItsPersonThroughTheListener(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = $this->greek();
        Segments::recompute($greek);
        Segments::refreshOn('profile.saved');
        $this->set('bob', ['language' => 'el']);
        $listener = new RefreshPersonSegments();

        // Act
        $changes = $listener->handle(new SegmentLeft(0, $this->ids['bob'], 0));
        $nobody  = $listener->handle(['not', 'a', 'person']);

        // Assert
        $this->assertContains(RefreshPersonSegments::class, Event::getListeners('profile.saved'));
        $this->assertSame(['entered' => [$greek], 'left' => []], $changes);
        $this->assertNull($nobody);
        $this->assertSame('segments:person:12', $listener->debounceKey('12'));
        $this->assertSame('segments:person:5', $listener->debounceKey(5));
        $this->assertNull($listener->debounceKey(0));
    }

    /**
     * The scheduled command works out every segment, or one, and says what changed; with no
     * table it reports that and succeeds, since it runs on every installation.
     */
    #[DataProvider('databases')]
    public function testTheCommandRecomputesAndReports(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek  = $this->greek();
        $tester = new CommandTester(new SegmentsRecompute());

        // Act
        $all  = $tester->execute([]);
        $out  = $tester->getDisplay();
        $one  = $tester->execute(['--segment' => (string) $greek]);
        $bad  = $tester->execute(['--segment' => '424242']);
        $this->db->schema()->dropTableIfExists('segment_members');
        $this->db->schema()->dropTableIfExists('segments');
        $this->probeChanged($this->db->type);
        $none = $tester->execute([]);

        // Assert
        $this->assertSame(0, $all);
        $this->assertStringContainsString("Segment {$greek}: 2 in, +2 entered, -0 left", $out);
        $this->assertSame(0, $one);
        $this->assertSame(1, $bad, 'an unknown segment is a failure');
        $this->assertSame(0, $none);
        $this->assertStringContainsString('No segments here', $tester->getDisplay());
    }
}
