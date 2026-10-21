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
        $this->assertSame([['id' => $this->groups['Press'], 'name' => 'Press'], ['id' => $this->groups['Volunteers'], 'name' => 'Volunteers']], $card);
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
}
