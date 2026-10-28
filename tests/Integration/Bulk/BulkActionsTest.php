<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Bulk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Bulk\BulkActions;
use Pramnos\Bulk\BulkActionTask;
use Pramnos\Bulk\GroupAdd;
use Pramnos\Queue\QueueItem;
use Pramnos\Tests\Integration\Llm\ChatDatabase;
use Pramnos\Tests\Integration\Llm\RecordingQueue;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

// RecordingQueue is declared there, under a name the autoloader cannot map to a file.
require_once dirname(__DIR__) . '/Llm/RoutinesAsksConsentTest.php';

/**
 * Bulk actions on a list of records — on MySQL and PostgreSQL.
 *
 * What holds: an action is offered only to whom it allows, and asked again when it runs, queued
 * chunks included; its required fields are required; a filter narrows the ids; a short list is
 * done in the request and a long one queued in chunks; and the framework's own — adding people to
 * a user group, existing or new — puts them in it through the groups' own path.
 */
#[CoversClass(BulkActions::class)]
#[CoversClass(BulkActionTask::class)]
#[CoversClass(GroupAdd::class)]
class BulkActionsTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        BulkActions::reset();
    }

    protected function tearDown(): void
    {
        // A bulk action writes to the audit log, which remembers that this database had no table for it.
        \Pramnos\Auth\AuditLog::resetTableCache();
        BulkActions::reset();
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A user of this usertype, made for the test. */
    private function user(int $usertype): int
    {
        $mark = 'bk' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time(), 'usertype' => $usertype]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();
        $this->db->cacheflush('userlist');

        return $id;
    }

    /**
     * An action of the application's: offered and run only for whom it allows, its required field
     * required, its filter applied, done in the request with what it said, and written to the
     * audit log; a long list goes to the queue in chunks, and each chunk asks again.
     */
    #[DataProvider('databases')]
    public function testAnApplicationsAction(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $seen = [];
        BulkActions::define('device', 'disable', 'Turn off', [
            'reason' => ['label' => 'Why', 'type' => 'text', 'required' => true],
            'kind'   => ['label' => 'Kind', 'type' => 'select', 'options' => static fn (int $user): array => ['a' => 'A for ' . $user]],
        ], function (array $ids, array $params, int $by) use (&$seen): array {
            $seen[] = [$ids, $params, $by];

            return ['done' => count($ids), 'says' => 'Turned off.'];
        }, allowed: static fn (int $user): bool => $user !== 13, filter: static fn (array $ids, int $by): array => array_values(array_diff($ids, ['C-3'])));
        $queue = new RecordingQueue();
        BulkActions::useQueue($queue);

        // Act
        $offered   = BulkActions::available('device', 12);
        $notOffered = BulkActions::available('device', 13);
        $refused   = BulkActions::run('device', 'disable', ['A-1'], ['reason' => 'x'], 13);
        $missing   = BulkActions::run('device', 'disable', ['A-1'], [], 12);
        $unknown   = BulkActions::run('device', 'explode', ['A-1'], [], 12);
        $empty     = BulkActions::run('device', 'disable', ['C-3', ''], ['reason' => 'x'], 12);
        $tooMany   = BulkActions::run('device', 'disable', range(1, BulkActions::MAX + 1), ['reason' => 'x'], 12);
        $done      = BulkActions::run('device', 'disable', ['A-1', 'B-2', 'C-3', 'A-1'], ['reason' => 'audit'], 12);
        $long      = BulkActions::run('device', 'disable', range(1, BulkActions::CHUNK + 10), ['reason' => 'audit'], 12);
        $guest     = BulkActions::available('device', 1);

        // Assert — offered, its select's options resolved for the person
        $this->assertSame(['label' => 'Turn off', 'params' => [
            'reason' => ['label' => 'Why', 'type' => 'text', 'required' => true],
            'kind'   => ['label' => 'Kind', 'type' => 'select', 'options' => ['a' => 'A for 12']],
        ]], $offered['disable']);
        $this->assertSame([], $notOffered);
        $this->assertSame([], $guest, 'nobody signed in may do anything');
        $this->assertSame('You may not do that.', $refused);
        $this->assertSame('Why is required.', $missing);
        $this->assertSame('There is no such action.', $unknown);
        $this->assertSame('There is nothing here to do that to.', $empty, 'the filter took the only id');
        $this->assertSame('Up to ' . BulkActions::MAX . ' at a time.', $tooMany);
        // Done in the request: once each, the filter applied
        $this->assertSame(['done' => 2, 'queued' => 0, 'says' => 'Turned off.'], $done);
        $this->assertSame([['A-1', 'B-2'], ['reason' => 'audit'], 12], $seen[0]);
        // Queued in chunks
        $this->assertSame(['done' => 0, 'queued' => BulkActions::CHUNK + 10, 'says' => ''], $long);
        $this->assertCount(2, $queue->tasks);
        $this->assertSame([BulkActionTask::TASK, BulkActions::CHUNK], [$queue->tasks[0][0], count($queue->tasks[0][1]['ids'])]);

        // Act — a queued chunk runs, as the person who asked; and one whose asker may no longer
        $task  = new BulkActionTask($this->getMockBuilder(\Pramnos\Application\Controller::class)->disableOriginalConstructor()->getMock());
        $item  = (new \ReflectionClass(QueueItem::class))->newInstanceWithoutConstructor();
        $item->payload = json_encode($queue->tasks[1][1]);
        $ran   = $task->execute($item);
        $item2 = (new \ReflectionClass(QueueItem::class))->newInstanceWithoutConstructor();
        $item2->payload = json_encode(['by' => 13] + $queue->tasks[1][1]);
        $stale = $task->execute($item2);

        // Assert
        $this->assertSame(['message' => '10 done: Turned off.'], $ran);
        $this->assertSame(['message' => '0 done: Not allowed, or no longer defined.'], $stale);
        $this->assertSame('Bulk disable on 10 device', $task->getDescription($item));

        // Act — an application removes an action
        BulkActions::forget('device', 'disable');

        // Assert
        $this->assertSame([], BulkActions::available('device', 12));
    }

    /**
     * The framework's own action on people: offered behind the Groups screen's ability, it puts
     * them in an existing group or a new one by name, says who was already in, and asks for a
     * group when given none.
     */
    #[DataProvider('databases')]
    public function testAddingPeopleToAGroup(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\UserGroups\CreateUsergroupsTables::class,
            \Pramnos\Framework\Migrations\UserGroups\AddMembershipHistoryToUsergroups::class,
            \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class,
        ], $this->db);
        $admin  = $this->user(99);
        $member = $this->user(1);
        $people = [$this->user(1), $this->user(1)];
        $this->db->queryBuilder()->table('#PREFIX#usergroups')->insert(['name' => 'Press ' . $admin, 'description' => '']);
        $press = (int) $this->db->getInsertId();
        \Pramnos\Audience\Groups::add($press, [$people[0]]);

        try {
            // Act
            $offered = BulkActions::available('user', $admin);
            $refused = BulkActions::run('user', 'group_add', $people, ['group' => $press], $member);
            $none    = BulkActions::run('user', 'group_add', $people, [], $admin);
            $added   = BulkActions::run('user', 'group_add', $people, ['group' => (string) $press], $admin);
            $fresh   = BulkActions::run('user', 'group_add', $people, ['new_group' => 'Fresh ' . $admin], $admin);
            $freshId = (int) $this->db->queryBuilder()->table('#PREFIX#usergroups')->where('name', 'Fresh ' . $admin)->first()->fields['groupid'];

            // Assert
            $this->assertSame('Add to a group', $offered['group_add']['label']);
            $this->assertSame('Press ' . $admin, $offered['group_add']['params']['group']['options'][$press], 'the groups to choose from');
            $this->assertSame([], BulkActions::available('user', $member), 'behind the Groups screen\'s ability');
            $this->assertSame('You may not do that.', $refused);
            $this->assertSame('Choose a group, or name a new one.', $none['says']);
            $this->assertSame(['done' => 1, 'queued' => 0, 'says' => '1 added to Press ' . $admin . '; 1 were already in it.'], $added);
            $this->assertSame(2, $fresh['done']);
            $this->assertSame(2, $this->db->queryBuilder()->table('#PREFIX#userstogroups')->where('groupid', $freshId)->count());
            $this->assertSame(2, $this->db->queryBuilder()->table('#PREFIX#userstogroups')->where('groupid', $press)->count());
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#userstogroups')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#usergroups')->where('name', 'like', '%' . $admin)->delete();
        }
    }

    /** Without the groups table there is nothing to choose from. */
    public function testWithoutGroups(): void
    {
        // Act / Assert — the default connection has no usergroups table
        $this->assertSame([], GroupAdd::groups());
    }
}
