<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Email\Unsubscribe;
use Pramnos\Llm\Chat\RoutineReady;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/AnalystTasksTest.php';

/**
 * A report's own unsubscribe link — on MySQL and PostgreSQL.
 *
 * What holds: a report's email belongs to the list `askreport-{id}`; following its unsubscribe
 * link stops that report's email — for every account with the address — and no other report's,
 * and leaves no opt-out row behind, so the subscription's own switch stays the one truth and
 * turning the email back on works at once; and an application's handler may cover a whole family of
 * lists with a name ending in `*`.
 */
#[CoversClass(Unsubscribe::class)]
#[CoversClass(RoutineReady::class)]
class ReportUnsubscribeTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function tearDown(): void
    {
        Unsubscribe::reset();
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    #[DataProvider('databases')]
    public function testTheLinkStopsThatReportsEmailOnly(string $type, string $host, int $port): void
    {
        // Arrange — one person, two reports both emailed to them
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class], $this->db);
        $mark = 'ru' . bin2hex(random_bytes(3));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@Example.test', 'password' => 'x', 'regdate' => time()]);
        $userId = (int) $this->db->getInsertId();
        $this->users[] = $userId;
        $tasks = new Routines(new \Pramnos\Llm\Chat\Conversations(new SchedulingAnalyst()));
        $one   = $tasks->create(new Scope($userId, null), ['kind' => 'report', 'title' => 'One', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $two   = $tasks->create(new Scope($userId, null), ['kind' => 'report', 'title' => 'Two', 'prompt' => 'q', 'cadence' => 'daily', 'at' => '09:00']);
        $tasks->subscribe($userId, $one['routine_id'], true);
        $tasks->subscribe($userId, $two['routine_id'], true);
        $notice = new RoutineReady('One', true, '', '/r', 'x', true, '2026-10-28', (int) $one['routine_id']);

        // Act — the link in report One's email
        Unsubscribe::optOut($mark . '@example.test', $notice->unsubscribeList(), 'link');

        // Assert
        $this->assertSame('askreport-' . $one['routine_id'], $notice->unsubscribeList());
        $this->assertSame('', (new RoutineReady('Q', true, '', '/r'))->unsubscribeList(), 'not a report\'s: no list of its own');
        $this->assertSame([], $tasks->emailSubscribers($one['routine_id']), 'that report is no longer emailed');
        $this->assertSame([$userId], $tasks->emailSubscribers($two['routine_id']), 'the other one still is');
        $this->assertSame(0, $this->db->queryBuilder()->table('pramnos.emailoptouts')->where('list', 'askreport-' . $one['routine_id'])->count(),
            'no opt-out row: the subscription is the one switch');

        // Act — turned back on from the page
        $tasks->subscribe($userId, $one['routine_id'], true);

        // Assert
        $this->assertSame([$userId], $tasks->emailSubscribers($one['routine_id']), 'back on at once');
    }

    /** A handler named with `*` covers every list it prefixes, unless a list has its own. */
    #[DataProvider('databases')]
    public function testAHandlerForAFamilyOfLists(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Messaging\CreateEmailoptoutsTable::class], $this->db);
        $seen = [];
        Unsubscribe::handle('invoices-*', function (string $email, string $list) use (&$seen): void {
            $seen[] = 'family:' . $list;
        });
        Unsubscribe::handle('invoices-7', function (string $email, string $list) use (&$seen): void {
            $seen[] = 'own:' . $list;
        });

        // Act
        Unsubscribe::optOut('a@example.test', 'invoices-3', 'link');
        Unsubscribe::optOut('a@example.test', 'invoices-7', 'link');
        Unsubscribe::optOut('a@example.test', 'news', 'link');

        // Assert
        $this->assertSame(['family:invoices-3', 'own:invoices-7'], $seen);
    }
}
