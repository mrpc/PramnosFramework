<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Todos;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Todos\CreateTodosTable;
use Pramnos\Todos\Todos;

/**
 * The to-do list against the real database — MySQL here, PostgreSQL in the sibling: an item's
 * life (added from a line, changed, closed, opened again, deleted), who sees what and in which
 * order, and keeping the list in step with the application's sources.
 */
#[CoversClass(Todos::class)]
#[CoversClass(CreateTodosTable::class)]
#[CoversClass(\Pramnos\Console\Commands\TodosSync::class)]
class TodosMySQLTest extends TestCase
{
    protected Database $db;

    /** The application's info before the test. */
    private array $info;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        require_once ROOT . '/database/migrations/framework/todos/2026_11_09_000011_create_todos_table.php';
        require_once ROOT . '/database/migrations/framework/todos/2026_11_12_000001_add_repeat_to_todos.php';
        \Pramnos\Framework\Testing\Schema::ensure([CreateTodosTable::class, \Pramnos\Framework\Migrations\Todos\AddRepeatToTodos::class], $this->db);
        $this->db->queryBuilder()->table(Todos::TABLE)->where('tenant', '>=', 900)->delete();
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['todos'] = [];
        Todos::forgetSources();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table(Todos::TABLE)->where('tenant', '>=', 900)->delete();
        Application::getInstance()->applicationInfo = $this->info;
        Todos::forgetSources();
    }

    /** The connection under test. */
    protected function connect(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(false);
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL is not reachable.');
        }

        return $db;
    }

    /**
     * A repeating item (FW-487): done, it closes and the next opens at once — same title, link,
     * assignee, privacy and repeat, its reminder at the next occurrence, its day moved as many
     * days, nobody told it is theirs; done again, no second next; dismissed, none. A repeat with
     * no time to count from, or not known, is refused; a reminder sent keeps its time, so a
     * reminder-only item still repeats from it.
     */
    public function testARepeatingItemOpensItsNextWhenDone(): void
    {
        // Arrange
        $zone   = \Pramnos\Metrics\Window::zoneOf(900);
        $remind = (new \DateTimeImmutable('today 09:00', $zone))->getTimestamp() - 86400;   // yesterday 09:00
        $day    = (new \DateTimeImmutable('@' . $remind))->setTimezone($zone)->format('Y-m-d');
        $refused = [];
        foreach ([['repeat' => 'daily'], ['repeat' => 'hourly', 'due' => $day]] as $bad) {
            try {
                Todos::add(['tenant' => 900, 'title' => 'x'] + $bad, 7);
            } catch (\InvalidArgumentException $e) {
                $refused[] = $e->getMessage();
            }
        }
        $id = Todos::add(['tenant' => 900, 'title' => 'Water the plants https://example.test/p', 'due' => $day, 'remind_at' => $remind, 'repeat' => 'daily', 'private' => true], 7);
        $once = Todos::add(['tenant' => 900, 'title' => 'Once', 'remind_at' => $remind], 7);
        $mine = static fn (): array => array_values(array_filter(Todos::list(900, 7), static fn (array $i): bool => str_starts_with($i['title'], 'Water')));

        // Act
        Todos::remind($remind + 60);
        $sent = Todos::get($id);
        Todos::update($id, ['status' => Todos::DONE], 7);
        Todos::update($id, ['status' => Todos::DONE], 7);
        $after = $mine();
        $next  = array_values(array_filter($after, static fn (array $i): bool => $i['id'] !== $id))[0];
        Todos::update($next['id'], ['status' => Todos::DISMISSED], 7);
        Todos::update($once, ['repeat' => 'weekly'], 7);       // a reminder sent: it counts from it
        $weekly = Todos::get($once);
        try {
            Todos::update(Todos::add(['tenant' => 900, 'title' => 'No time'], 7), ['repeat' => 'daily'], 7);
        } catch (\InvalidArgumentException $e) {
            $refused[] = $e->getMessage();
        }

        // Assert
        $this->assertSame(['A repeating to-do needs a day or a reminder.', 'A to-do repeats daily, on weekdays, weekly, monthly or yearly.',
            'A repeating to-do needs a day or a reminder.'], $refused);
        $this->assertNull($sent['remind_at'], 'the reminder sent');
        $this->assertSame($remind, $sent['reminded_at'], 'its time kept');
        $this->assertCount(2, $after, 'the done one and its next: done twice opens one next');
        $this->assertSame(['Water the plants', 'https://example.test/p', 7, true, 'daily'], [$next['title'], $next['link'], $next['assigned_to'], $next['private'], $next['repeat']]);
        $this->assertGreaterThan(time(), $next['remind_at'], 'its reminder at the next occurrence, still to come');
        $this->assertSame('09:00', (new \DateTimeImmutable('@' . $next['remind_at']))->setTimezone($zone)->format('H:i'), 'at the same time of day');
        $moved = (int) round(($next['remind_at'] - $remind) / 86400);
        $this->assertSame((new \DateTimeImmutable($day, $zone))->modify('+' . $moved . ' days')->format('Y-m-d'),
            (new \DateTimeImmutable('@' . $next['due_at']))->setTimezone($zone)->format('Y-m-d'), 'its day moved as many days');
        $this->assertCount(2, $mine(), 'dismissed: no next');
        $this->assertSame('weekly', $weekly['repeat']);
    }

    /**
     * An item from a line: its web address becomes the link, the rest the title — or the address
     * the title when nothing else is left; the author's by default; a day kept as its end. Done
     * closes it, open again clears that; only the author retitles, makes private, or deletes; a
     * bad link, an empty title and a person who may not be given items are refused.
     */
    public function testAnItemsLife(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['todos'] = ['assignable' => static fn (int $tenant, int $user): bool => $user !== 13];
        $refused = [];
        foreach ([['title' => ''], ['title' => 'x', 'link' => 'javascript:alert(1)'], ['title' => 'x', 'assigned_to' => 13], ['title' => 'x', 'due' => '2026-13-01']] as $bad) {
            try {
                Todos::add($bad + ['tenant' => 900], 7);
            } catch (\InvalidArgumentException $e) {
                $refused[] = $e->getMessage();
            }
        }

        // Act
        $id      = Todos::add(['tenant' => 900, 'title' => 'Call the supplier https://example.test/s/4', 'due' => '2026-11-12'], 7);
        $bare    = Todos::get(Todos::add(['tenant' => 900, 'title' => 'https://example.test/only'], 7));
        $added   = Todos::get($id);
        Todos::update($id, ['status' => Todos::DONE], 8);
        $done    = Todos::get($id);
        Todos::update($id, ['status' => Todos::OPEN, 'assigned_to' => 8, 'due' => ''], 8);
        $again   = Todos::get($id);
        $denied  = [];
        foreach ([fn () => Todos::update($id, ['title' => 'Mine now'], 8), fn () => Todos::update($id, ['private' => true], 8), fn () => Todos::delete($id, 8)] as $act) {
            try {
                $act();
            } catch (\InvalidArgumentException $e) {
                $denied[] = $e->getMessage();
            }
        }
        Todos::update($id, ['title' => 'Call the supplier today'], 7);
        Todos::delete($id, 7);

        // Assert
        $this->assertCount(4, $refused, 'empty title, a script link, an unassignable person, a day that is not one');
        $this->assertSame(['Call the supplier', 'https://example.test/s/4', 7], [$added['title'], $added['link'], $added['assigned_to']]);
        $this->assertSame(['https://example.test/only', 'https://example.test/only'], [$bare['title'], $bare['link']], 'nothing else left: the address is the title');
        $zone = \Pramnos\Metrics\Window::zoneOf(900);
        $this->assertSame('2026-11-12 23:59:59', (new \DateTimeImmutable('@' . $added['due_at']))->setTimezone($zone)->format('Y-m-d H:i:s'), 'a day kept as its end');
        $this->assertSame(Todos::DONE, $done['status']);
        $this->assertNotNull($done['closed_at']);
        $this->assertSame([Todos::OPEN, null, 8, true, null], [$again['status'], $again['closed_at'], $again['assigned_to'], $again['assigned_by_hand'], $again['due_at']]);
        $this->assertCount(3, $denied, 'another person neither retitles, nor makes private, nor deletes');
        $this->assertNull(Todos::get($id), 'deleted by its author');
    }

    /**
     * The list: everyone's public items and only one's own private ones; open first, then by hand,
     * then by day, oldest first within a day — newest with `newest_first`; a closed item for seven
     * days, then gone from it.
     */
    public function testWhoSeesWhatAndInWhichOrder(): void
    {
        // Arrange
        $later   = Todos::add(['tenant' => 900, 'title' => 'Later', 'due' => '2026-12-01'], 7);
        $soon    = Todos::add(['tenant' => 900, 'title' => 'Soon', 'due' => '2026-11-10'], 7);
        $soonToo = Todos::add(['tenant' => 900, 'title' => 'Soon too', 'due' => '2026-11-10'], 7);
        $mine    = Todos::add(['tenant' => 900, 'title' => 'Private', 'private' => true], 8);
        $closed  = Todos::add(['tenant' => 900, 'title' => 'Closed'], 7);
        $old     = Todos::add(['tenant' => 900, 'title' => 'Closed long ago'], 7);
        Todos::update($closed, ['status' => Todos::DISMISSED], 7);
        $this->db->queryBuilder()->table(Todos::TABLE)->where('todoid', $soonToo)->update(['created_at' => time() + 10]);
        $this->db->queryBuilder()->table(Todos::TABLE)->where('todoid', $old)->update(['status' => Todos::DONE, 'closed_at' => time() - 8 * 86400]);

        // Act
        $seven  = array_column(Todos::list(900, 7), 'id');
        $eight  = array_column(Todos::list(900, 8), 'id');
        Application::getInstance()->applicationInfo['todos'] = ['newest_first' => true];
        $newest = array_column(Todos::list(900, 7), 'id');

        // Assert
        $this->assertSame([$soon, $soonToo, $later, $closed], $seven, 'by day, oldest first; closed last; the private and the long-closed not there');
        $this->assertSame([$soon, $soonToo, $later, $mine, $closed], $eight, 'one\'s own private item, no day: after the dated ones');
        $this->assertSame([$soonToo, $soon, $later, $closed], $newest, 'newest first within a day');
    }

    /**
     * Sources: a new key opens an item; the title, day and assignee follow the source, but not an
     * assignee chosen by hand; a done item still wanted opens again, a dismissed one stays
     * dismissed; one no longer wanted is closed as done — a dismissed one quietly, so a new reason
     * opens it again; a source that fails is skipped and the rest still run — through `todos:sync` too.
     */
    public function testTheListKeepsInStepWithItsSources(): void
    {
        // Arrange
        $wants = ['a' => ['title' => 'Answer A', 'assigned_to' => 4], 'b' => ['title' => 'Answer B'], 'c' => ['title' => 'Answer C'], 'd' => ['title' => 'Answer D']];
        Application::getInstance()->applicationInfo['todos'] = ['tenants' => static fn (): array => [900]];
        Todos::source('ticket', function (int $tenant) use (&$wants): array {
            return $tenant === 900 ? $wants : [];
        });
        Todos::source('broken', static fn (): array => throw new \RuntimeException('down'));
        $first = Todos::sync();
        $ids   = array_column(array_filter(Todos::list(900, 1), static fn (array $i): bool => $i['kind'] === 'ticket'), 'id', 'source');
        Todos::update($ids['b'], ['assigned_to' => 9], 1);        // chosen by hand
        Todos::update($ids['c'], ['status' => Todos::DONE], 1);
        Todos::update($ids['d'], ['status' => Todos::DISMISSED], 1);

        // Act — A retitled and reassigned, B's assignee changed by the source, C and D still wanted, A gone next
        $wants = ['a' => ['title' => 'Answer A again', 'assigned_to' => 5, 'due' => '2026-11-20'], 'b' => ['title' => 'Answer B', 'assigned_to' => 6],
            'c' => ['title' => 'Answer C'], 'd' => ['title' => 'Answer D']];
        $second = Todos::sync(900);
        $a = Todos::get($ids['a']);
        $wants = ['b' => ['title' => 'Answer B'], 'c' => ['title' => 'Answer C'], 'd' => ['title' => 'Answer D']];
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\TodosSync());
        $code   = $tester->execute([]);
        $stays   = Todos::get($ids['d']);
        $wants   = ['b' => ['title' => 'Answer B'], 'c' => ['title' => 'Answer C']];
        $gone    = Todos::sync(900);
        $quietly = Todos::get($ids['d']);
        $wants   = ['b' => ['title' => 'Answer B'], 'c' => ['title' => 'Answer C'], 'd' => ['title' => 'Answer D']];
        Todos::sync(900);
        $back    = Todos::get($ids['d']);

        // Assert
        $this->assertSame([4, 0, ['broken']], [$first['opened'], $first['closed'], $first['failed']], 'four opened; the broken source skipped');
        $this->assertSame(['Answer A again', 5, false], [$a['title'], $a['assigned_to'], $a['assigned_by_hand']], 'the title and assignee follow the source');
        $this->assertNotNull($a['due_at']);
        $this->assertSame(9, Todos::get($ids['b'])['assigned_to'], 'an assignee chosen by hand stays');
        $this->assertSame(Todos::OPEN, Todos::get($ids['c'])['status'], 'done but still wanted: open again');
        $this->assertSame(Todos::DISMISSED, $stays['status'], 'dismissed and still wanted: stays dismissed');
        $this->assertSame(Todos::DONE, Todos::get($ids['a'])['status'], 'no longer wanted: done');
        $this->assertSame(1, $code, 'a failed source makes the command fail');
        $this->assertStringContainsString('broken', $tester->getDisplay());
        $this->assertSame(0, $second['opened']);
        $this->assertSame([Todos::DONE, 0], [$quietly['status'], $gone['closed']], 'dismissed, its reason gone: done quietly, not counted');
        $this->assertSame(Todos::OPEN, $back['status'], 'a new reason: open again, not hidden for good');
    }

    /** A goal that turns syncs its organisation's list; `touch()` makes the next read sync. */
    public function testAGoalThatTurnsAndATouchSync(): void
    {
        // Arrange
        $wanted = [];
        Todos::source('goal', function (int $tenant) use (&$wanted): array {
            return $tenant === 901 ? $wanted : [];
        });
        $wanted = ['goal:1' => ['title' => 'Revenue is behind']];

        // Act
        \Pramnos\Event\Event::fire(\Pramnos\Metrics\GoalTurned::EVENT, ['id' => 1, 'tenant' => 901], 'on_track', 'behind');
        $after = array_column(Todos::list(901, 1), 'title');
        $wanted = [];
        Todos::touch(901);
        $read = array_column(array_filter(Todos::list(901, 1), static fn (array $i): bool => $i['status'] === Todos::OPEN), 'title');

        // Assert
        $this->assertSame(['Revenue is behind'], $after, 'the turn opened it');
        $this->assertSame([], $read, 'touched, the next read synced: closed');
    }

    /**
     * An application that has not adopted the to-dos — no `pramnos.todos` — gets nothing from the
     * framework's schedule: `todos:remind` and `todos:sync` end in success, not a failure a minute.
     */
    public function testTheCommandsDoNothingWithoutTheTable(): void
    {
        // Arrange — the same connection, a prefix under which there is no to-do table (MySQL names it by prefix)
        if ($this->db->type !== 'mysql') {
            $this->markTestSkipped('A prefix hides a table on MySQL only.');
        }
        $bare         = clone $this->db;
        $bare->prefix = 'pf_none_';
        $previous     = Database::getInstance();
        $reference    = &Database::getInstance();
        $reference    = $bare;
        $installed    = new \ReflectionProperty(Todos::class, 'installed');
        $installed->setValue(null, null);

        try {
            // Act
            $remind = (new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\TodosRemind()))->execute([]);
            $sync   = (new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\TodosSync()))->execute([]);
            $there  = Todos::installed();
        } finally {
            $reference = &Database::getInstance();
            $reference = $previous;
            $installed->setValue(null, null);
        }

        // Assert
        $this->assertFalse($there, 'no table under that prefix');
        $this->assertSame([0, 0], [$remind, $sync], 'success, not a failure every minute');
    }
}
