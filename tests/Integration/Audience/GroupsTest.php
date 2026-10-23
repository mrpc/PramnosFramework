<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Filter;
use Pramnos\Audience\GroupJoined;
use Pramnos\Audience\GroupLeft;
use Pramnos\Audience\Groups;
use Pramnos\Event\Event;

/**
 * Group membership through one path — the screen, a bulk action over a filter, a rule — on MySQL,
 * prefixed MySQL and PostgreSQL: only real changes are written and announced.
 */
#[CoversClass(Groups::class)]
#[CoversClass(GroupJoined::class)]
#[CoversClass(GroupLeft::class)]
#[CoversClass(\Pramnos\Framework\Migrations\UserGroups\AddMembershipHistoryToUsergroups::class)]
class GroupsTest extends TestCase
{
    use AudienceProbe;

    /** @var list<string> "joined:group:person:source" / "left:…" */
    private array $heard = [];

    /** Record every group event the request hears. */
    private function listen(): void
    {
        Event::listen(GroupJoined::EVENT, function (GroupJoined $e): void {
            $this->heard[] = 'joined:' . $e->groupId . ':' . $e->personId . ':' . $e->source;
        });
        Event::listen(GroupLeft::EVENT, function (GroupLeft $e): void {
            $this->heard[] = 'left:' . $e->groupId . ':' . $e->personId . ':' . $e->source;
        });
    }

    /** The usernames in a group. */
    private function members(int $group): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['userid'],
            $this->db->queryBuilder()->table('userstogroups')->where('groupid', $group)->getAll());

        return $this->names($ids);
    }

    /**
     * Adding writes only the people not yet in, announces only them, and says how many; removing
     * likewise. A repeated call is a no-op, not a duplicate key.
     */
    #[DataProvider('databases')]
    public function testOnlyRealChangesAreWrittenAndAnnounced(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->listen();
        $press = $this->groups['Press'];

        // Act
        $added   = Groups::add($press, [$this->ids['anna'], $this->ids['bob'], $this->ids['anna'], 0], by: 1);
        $again   = Groups::add($press, $this->ids['anna']);
        $removed = Groups::remove($press, [$this->ids['bob'], $this->ids['cara']], source: 'rule');
        $none    = Groups::remove($press, []);
        $nobody  = Groups::add($press, []);

        // Assert
        $this->assertSame(1, $added, 'bob was already in; the duplicate and 0 are dropped');
        $this->assertSame(0, $again);
        $this->assertSame(1, $removed, 'cara was never in');
        $this->assertSame(0, $none);
        $this->assertSame(0, $nobody);
        $this->assertSame(['anna'], $this->members($press));
        $this->assertSame([
            'joined:' . $press . ':' . $this->ids['anna'] . ':admin',
            'left:' . $press . ':' . $this->ids['bob'] . ':rule',
        ], $this->heard);
    }

    /**
     * The bulk action puts everybody a filter matches in a group and takes them out again —
     * paged, so an audience of any size is one call.
     */
    #[DataProvider('databases')]
    public function testABulkActionFollowsAFilter(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->listen();
        $press  = $this->groups['Press'];
        $greek  = Filter::fromArray(['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);

        // Act
        $added   = Groups::addMatching($press, $greek, by: 1);
        $after   = $this->members($press);
        $removed = Groups::removeMatching($press, $greek);

        // Assert
        $this->assertSame(2, $added, 'anna and cara; bob was in already and is not Greek');
        $this->assertSame(['anna', 'bob', 'cara'], $after);
        $this->assertSame(2, $removed);
        $this->assertSame(['bob'], $this->members($press));
        $this->assertSame('joined:' . $press . ':' . $this->ids['anna'] . ':bulk', $this->heard[0]);
    }

    /**
     * A change in a group that holds a role is a change of the members' permissions; the change
     * goes through either way, and the person card lists the groups by name.
     */
    #[DataProvider('databases')]
    public function testAGroupWithARoleStillChangesAndThePersonCardListsGroups(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->db->queryBuilder()->table('authserver.roles')->insert(['role_name' => 'Editors', 'is_active' => true]);
        $role = (int) $this->db->queryBuilder()->table('authserver.roles')->where('role_name', 'Editors')->first()->fields['roleid'];
        $this->db->queryBuilder()->table('authserver.group_roles')
            ->insert(['groupid' => $this->groups['Press'], 'roleid' => $role, 'is_active' => true]);

        // Act
        $added = Groups::add($this->groups['Press'], $this->ids['cara']);
        $card  = Groups::of($this->ids['bob']);

        // Assert
        $this->assertSame(1, $added);
        $older = ['addedAt' => null, 'addedBy' => null, 'source' => null];
        $this->assertSame([['id' => $this->groups['Press'], 'name' => 'Press'] + $older, ['id' => $this->groups['Volunteers'], 'name' => 'Volunteers'] + $older], $card,
            'memberships older than their record say nothing of when');
        $this->assertSame([], Groups::of($this->ids['dan']));
    }

    /** The events travel to a queued listener and come back the same. */
    public function testGroupEventsSurviveTheQueue(): void
    {
        foreach ([GroupJoined::class, GroupLeft::class] as $class) {
            // Arrange
            $event = new $class(4, 9, 2, 'rule');

            // Act
            $again = $class::fromQueue(json_decode((string) json_encode($event), true));
            $empty = $class::fromQueue([]);

            // Assert
            $this->assertEquals($event, $again);
            $this->assertSame(9, $again->personId());
            $this->assertNull($empty->by);
            $this->assertSame('admin', $empty->source);
        }
    }

    /**
     * A membership keeps when it began, by whom and how; joining and leaving go to the changelog
     * as `group.joined` / `group.left` on the group, which is what counts who entered and who
     * left over a week — only real changes, and a membership older than its record is not
     * counted as entering.
     */
    #[DataProvider('databases')]
    public function testMembershipsKeepTheirHistory(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $press = $this->groups['Press'];
        $volunteers = $this->groups['Volunteers'];
        $events = fn (int $group): array => array_column($this->db->queryBuilder()->table(\Pramnos\Changelog\ChangelogWriter::EVENTS_TABLE)
            ->where('entity', Groups::ENTITY)->where('itemid', (string) $group)->orderBy('eventid')->getAllOrFail(), 'event');

        // Act
        $before = time();
        Groups::add($press, [$this->ids['anna'], $this->ids['bob']], 7, 'coupon');
        Groups::remove($press, [$this->ids['anna'], $this->ids['dan']], null, 'rule');
        Groups::add($volunteers, $this->ids['cara'], null, str_repeat('x', 30));
        \Pramnos\Changelog\ChangelogWriter::event(Groups::ENTITY, $volunteers, 'group.joined', ['userid' => $this->ids['dan']], by: 0, at: time() - 9 * 86400);
        $moves = Groups::movements();
        $card  = array_column(Groups::of($this->ids['cara']), null, 'name');
        $bob   = array_column(Groups::of($this->ids['bob']), null, 'name');

        // Assert
        $this->assertSame(['entered' => 1, 'left' => 1], $moves[$press], 'anna in and out; bob was in already; dan never was');
        $this->assertSame(['entered' => 1, 'left' => 0], $moves[$volunteers], 'nine days ago is not this week');
        $this->assertSame(['group.joined', 'group.left'], $events($press), 'anna in, anna out — in the changelog, not a table of its own');
        $this->assertGreaterThanOrEqual($before, $card['Volunteers']['addedAt']);
        $this->assertSame([null, str_repeat('x', 20)], [$card['Volunteers']['addedBy'], $card['Volunteers']['source']], 'how, cut to its column');
        $this->assertNull($bob['Press']['addedAt'], 'bob was in before the record: adding him again changes nothing');
    }

    /**
     * The migration only adds three nullable columns on the memberships — the ones already there
     * keep their rows, with nothing said of when. Without it groups work as before, with nothing
     * said of when; it runs twice without harm, and comes off again. Without the changelog there
     * is no movement to count.
     */
    #[DataProvider('databases')]
    public function testTheHistoryMigrationAddsAndComesOff(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $application = (new \ReflectionClass(\Pramnos\Application\Application::class))->newInstanceWithoutConstructor();
        $application->database = $this->db;
        $migration = new \Pramnos\Framework\Migrations\UserGroups\AddMembershipHistoryToUsergroups($application);
        $schema    = $this->db->schema();

        // Act
        $migration->down();
        Groups::reset();
        $without = [$schema->hasColumn('userstogroups', 'addedat'), Groups::keepsHistory()];
        $added   = Groups::add($this->groups['Press'], $this->ids['cara'], 2);
        $plain   = array_column(Groups::of($this->ids['cara']), 'addedAt');
        $migration->up();
        $migration->up();
        Groups::reset();
        $with = [$schema->hasColumn('userstogroups', 'addedsource'), Groups::keepsHistory()];
        // As where the changelog feature is off: its tables are not there.
        (new \ReflectionProperty(Groups::class, 'changelog'))->setValue(null, false);
        $noChangelog = [Groups::add($this->groups['Volunteers'], $this->ids['dan'], 2), Groups::movements()];
        Groups::reset();

        // Assert
        $this->assertSame([false, false], $without);
        $this->assertSame(1, $added, 'groups work without it');
        $this->assertSame([null], $plain, 'with nothing said of when');
        $this->assertSame([true, true], $with);
        $this->assertSame(4, (int) $this->db->queryBuilder()->table('userstogroups')->whereNull('addedat')->count(), 'the three rows of the probe and cara\'s, kept, with nothing said of when');
        $this->assertSame([1, []], $noChangelog, 'no changelog: the membership changes, and there is no movement to count');
    }
}
