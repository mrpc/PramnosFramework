<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Framework\Migrations\Todos\CreateTodosTable;
use Pramnos\Todos\TodoNotification;
use Pramnos\Todos\Todos;

/**
 * The to-dos told (FW-363, step 2), against MySQL with and without a prefix and PostgreSQL: an
 * item given to someone, its reminder, the "due today" notice, the notices settled, an account's
 * items handed to a successor, and what an export carries.
 */
#[CoversClass(Todos::class)]
#[CoversClass(TodoNotification::class)]
#[CoversClass(\Pramnos\Console\Commands\TodosRemind::class)]
class TodosToldTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The application's info before the test. */
    private array $info = [];

    /** The probe with the to-do table, and settings the test then changes. */
    private function ready(string $type, string $host, int $port, string $user, array $todos = []): void
    {
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/todos/2026_11_09_000011_create_todos_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([CreateTodosTable::class], $this->db);
        $this->db->queryBuilder()->table(Todos::TABLE)->delete();
        $this->db->queryBuilder()->table('notifications')->delete();
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC'];
        Application::getInstance()->applicationInfo['todos'] = $todos;
        \Pramnos\Conversations\SystemPost::reset();   // what an earlier data set posted, with the same ids
        Todos::forgetSources();
    }

    /** The settings back, the sources forgotten, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        if ($this->info !== []) {
            Application::getInstance()->applicationInfo = $this->info;
        }
        Todos::forgetSources();
        $this->probeTearDown();
    }

    /**
     * The messages in a person's bell, by whether they are read.
     *
     * @return list<array{message: string, read: bool, about: list<string>}>
     */
    private function bell(int $person): array
    {
        $out = [];
        foreach ($this->db->queryBuilder()->table('notifications')->where('notifiable_id', $person)->orderBy('created_at')->getAllOrFail() as $row) {
            $data  = (array) json_decode((string) $row['data'], true);
            $out[] = ['message' => (string) ($data['message'] ?? ''), 'read' => $row['read_at'] !== null, 'about' => (array) ($data['about'] ?? [])];
        }

        return $out;
    }

    /**
     * Given to someone: "A to-do for you · from X" in their bell — never for one's own act; a
     * change of assignee tells the new one; a source's new item tells its assignee unless the
     * source says it did (`tell: false`). Closing an item settles its notice; deleting one too;
     * seeing the list settles the rest.
     */
    #[DataProvider('databases')]
    public function testAnItemGivenIsToldAndSettled(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];

        // Act
        $call = Todos::add(['title' => 'Call the supplier', 'assigned_to' => $maria], $eleni);
        Todos::add(['title' => 'My own'], $eleni);                                   // her own act: nobody told
        $mail = Todos::add(['title' => 'Answer the mail', 'assigned_to' => $maria], $eleni);
        Todos::update($call, ['assigned_to' => $nikos], $eleni);                     // to Nikos now
        Todos::source('ticket', static fn (): array => [
            'ticket:1' => ['title' => 'Ticket one', 'assigned_to' => $nikos],
            'ticket:2' => ['title' => 'Ticket two', 'assigned_to' => $nikos, 'tell' => false],
        ]);
        Todos::sync(0);
        $beforeClose = $this->bell($maria);
        Todos::update($call, ['status' => Todos::DONE], $nikos);
        $nikosBell   = $this->bell($nikos);
        Todos::delete($mail, $eleni);
        $mariaBell   = $this->bell($maria);
        $seen        = Todos::seen($nikos);

        // Assert
        $this->assertSame(['Νίκος Π. closed: Call the supplier'], array_column($this->bell($eleni), 'message'),
            'her own acts tell her nothing; her own item she gave away, closed by another, does (FW-487)');
        $this->assertCount(2, $beforeClose);
        $about = array_column($beforeClose, 'about', 'message');
        $this->assertSame(['todo:' . $call], $about['A to-do for you · from Ελένη Σ.: Call the supplier'] ?? null, 'about the item: what settles it');
        $byMessage = array_column($nikosBell, 'read', 'message');
        ksort($byMessage);
        $this->assertSame(['A to-do for you · from Ελένη Σ.: Call the supplier' => true, 'A to-do for you: Ticket one' => false], $byMessage,
            'the new assignee told, the closed item\'s notice settled; the source that told already not told again');
        $this->assertSame([true, true], array_column($mariaBell, 'read'), 'one closed, one deleted: both settled');
        $this->assertSame(1, $seen, 'seeing the list settles what is left');
    }

    /**
     * For an application moving onto the list (FW-487): a source gives an exact time and a
     * reminder, and keeps them in step; a notice leads each recipient where `todos.url` says for
     * them; "to-dos about this" by a path finds an item linked with a query on it; and each piece
     * is turned off on its own (`todos.tools`, `menu`, `dashboard`, `rules`, `chat`).
     */
    #[DataProvider('databases')]
    public function testWhatAnApplicationMovingOntoTheListNeeds(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user, ['url' => static fn (array $item, ?int $to): string => '/to/' . $to . '#' . $item['id'],
            'tools' => false, 'menu' => false, 'dashboard' => false, 'rules' => false, 'chat' => false]);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $at   = (int) (time() / 60) * 60 + 3600;
        $when = $at;
        Todos::source('meeting', static function () use (&$when, $maria): array {
            return ['meeting:1' => ['title' => 'Meeting', 'assigned_to' => $maria, 'due_at' => $when, 'remind_at' => $when - 900]];
        });

        // Act
        Todos::sync(0);
        $first = Todos::list(0, $maria)[0];
        $when  = $at + 1800;
        Todos::sync(0);
        $moved = Todos::list(0, $maria)[0];
        $said  = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $maria)->getAllOrFail();
        $tab   = Todos::add(['title' => 'Check the papers', 'link' => '/admin/users/view/4?tab=validation'], $eleni);
        $about = array_column(Todos::about('/admin/users/view/4', 0, $eleni), 'id');
        $other = array_column(Todos::about('/admin/users/view/4?tab=other', 0, $eleni), 'id');

        // Assert
        $this->assertSame([$at, $at - 900], [$first['due_at'], $first['remind_at']], 'an exact time and a reminder, not the end of the day');
        $this->assertSame([$at + 1800, $at + 900], [$moved['due_at'], $moved['remind_at']], 'kept in step with the source');
        $this->assertSame('/to/' . $maria . '#' . $first['id'], (string) json_decode((string) $said[0]['data'], true)['url'], 'led where the application says for her');
        $this->assertSame([$tab], $about, 'a query on the path is about it');
        $this->assertSame([], $other, 'asked with another query: not');
        $this->assertSame([false, false, false, false, false], [Todos::offers('tools'), Todos::offers('menu'), Todos::offers('dashboard'), Todos::offers('rules'), Todos::offers('chat')]);
        Application::getInstance()->applicationInfo['todos'] = [];
        $this->assertTrue(Todos::offers('chat'), 'on by default, where the table is');
    }

    /**
     * A reminder to the minute: in the bell, and as a line from "Reminders" in the person's
     * conversation with the system. Cleared once sent, so the next minute sends nothing; a closed
     * item's reminder is cleared without a word. Through `todos:remind` too.
     */
    #[DataProvider('databases')]
    public function testAReminderIsSentOnce(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        $maria = $this->ids['maria'];
        $now   = time();
        $due   = Todos::add(['title' => 'Send the invoice', 'remind_at' => $now - 30], $maria);
        $later = Todos::add(['title' => 'Later', 'remind_at' => $now + 3600], $maria);
        $shut  = Todos::add(['title' => 'Closed', 'remind_at' => $now - 30], $maria);
        Todos::update($shut, ['status' => Todos::DONE], $maria);

        // Act
        $sent   = Todos::remind($now);
        $again  = Todos::remind($now + 60);
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\TodosRemind());
        $code   = $tester->execute([]);
        $chat   = Conversations::botFor($maria, 'Reminders');

        // Assert
        $this->assertSame([1, 0, 0], [$sent, $again, $code]);
        $this->assertSame(['Reminder: Send the invoice'], array_column($this->bell($maria), 'message'));
        $this->assertNull(Todos::get($due)['remind_at'], 'cleared once sent');
        $this->assertNull(Todos::get($shut)['remind_at'], 'a closed item\'s reminder cleared, not sent');
        $this->assertSame($now + 3600, Todos::get($later)['remind_at']);
        $this->assertNotNull($chat, 'her conversation with "Reminders"');
        $this->assertStringContainsString('Reminder: Send the invoice', Messages::list((int) $chat)[0]['body']);
    }

    /**
     * Due today, or overdue: once per item, from 08:05 in the organisation's zone, only to an
     * assignee; not where `todos.due_notice` is off, nor to someone who may no longer be given
     * items, nor in an organisation without to-dos. A new day lets it be told again.
     */
    #[DataProvider('databases')]
    public function testDueTodayIsToldOnceAtFiveAfterEight(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $today    = Todos::add(['title' => 'Today', 'due' => '2026-11-10'], $maria);
        $overdue  = Todos::add(['title' => 'Yesterday', 'due' => '2026-11-09'], $maria);
        Todos::add(['title' => 'Tomorrow', 'due' => '2026-11-11'], $maria);
        Todos::add(['title' => 'Nobody\'s', 'due' => '2026-11-10', 'assigned_to' => 0], $maria);
        Todos::add(['title' => 'Nikos\'s', 'due' => '2026-11-10', 'assigned_to' => $nikos], $eleni);
        Todos::add(['title' => 'Other place', 'due' => '2026-11-10', 'tenant' => 5], $maria);
        $this->db->queryBuilder()->table('notifications')->delete();
        $early = (int) strtotime('2026-11-10 08:00 UTC');
        $at    = (int) strtotime('2026-11-10 08:06 UTC');

        // Act
        $off = Todos::dueNotices($at);
        Application::getInstance()->applicationInfo['todos'] = ['due_notice' => true,
            'assignable' => static fn (int $t, int $u): bool => $u !== $nikos, 'allowed' => static fn (int $t): bool => $t !== 5];
        $tooEarly = Todos::dueNotices($early);
        $told     = Todos::dueNotices($at);
        $twice    = Todos::dueNotices($at + 60);
        Todos::update($today, ['due' => '2026-11-11'], $maria);
        $nextDay  = Todos::dueNotices((int) strtotime('2026-11-11 08:05 UTC'));

        // Assert
        $this->assertSame([0, 0, 2, 0, 2], [$off, $tooEarly, $told, $twice, $nextDay], 'the next day: the moved one and tomorrow\'s');
        $messages = array_column($this->bell($maria), 'message');
        $this->assertContains('Due today: Today', $messages);
        $this->assertContains('Overdue: Yesterday', $messages);
        $this->assertSame([], $this->bell($nikos), 'may no longer be given items: not told');
        $this->assertNotNull(Todos::get($overdue)['due_told_at']);
    }

    /**
     * An account going: its open items go to the successor, who is told; without one, to nobody
     * in particular; its private items go with it. The export carries a person's own items, or
     * every own item of an organisation — never a source's.
     */
    #[DataProvider('databases')]
    public function testAnAccountsItemsAreHandedOverAndExported(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $open    = Todos::add(['title' => 'Open', 'assigned_to' => $maria], $eleni);
        $there   = Todos::add(['title' => 'Elsewhere', 'assigned_to' => $maria, 'tenant' => 3], $eleni);
        $private = Todos::add(['title' => 'Private', 'private' => true], $maria);
        $done    = Todos::add(['title' => 'Done'], $maria);
        Todos::update($done, ['status' => Todos::DONE], $maria);
        Todos::source('ticket', static fn (): array => ['ticket:9' => ['title' => 'A ticket', 'assigned_to' => $maria]]);
        Todos::sync(0);
        Application::getInstance()->applicationInfo['todos'] = ['successor' => static fn (int $t, int $u): ?int => $t === 0 ? $nikos : null];
        $this->db->queryBuilder()->table('notifications')->delete();

        // Act
        $mine    = array_column(Todos::exportable(null, $maria), 'title');
        $place   = array_column(Todos::exportable(0), 'title');
        $handed  = Todos::handOver($maria);

        // Assert
        $this->assertSame(['Private', 'Done'], $mine, 'her own items, not a source\'s');
        $this->assertSame(['Open', 'Private', 'Done'], $place, 'the organisation\'s own items');
        $this->assertSame(3, $handed);
        $this->assertSame([$nikos, true], [Todos::get($open)['assigned_to'], Todos::get($open)['assigned_by_hand']]);
        $this->assertNull(Todos::get($there)['assigned_to'], 'no successor there: nobody in particular');
        $this->assertNull(Todos::get($private), 'her private item went with her');
        $this->assertSame($maria, Todos::get($done)['assigned_to'], 'a closed item stays as it was');
        $this->assertCount(2, $this->bell($nikos), 'the successor told of each');
    }

    /** A push replaces the item's last one (its tag), and leads where the bell's notice does. */
    public function testAPushIsTaggedByItsItem(): void
    {
        // Arrange
        $notice = new TodoNotification(42, 'Reminder: Call', '/admin/Todo');

        // Act
        $push = $notice->toPush(null);

        // Assert
        $this->assertSame(['title' => 'To-do', 'body' => 'Reminder: Call', 'url' => '/admin/Todo', 'tag' => 'todo-42'], $push);
    }
}
