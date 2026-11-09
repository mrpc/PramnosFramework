<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Framework\Migrations\Todos\CreateTodosTable;
use Pramnos\Todos\Todos;

/**
 * The to-dos' screens (FW-363, step 4), against MySQL with and without a prefix and PostgreSQL:
 * `/admin/Todo` with its views, groups, changes in place and calendar; the header's urgent five;
 * "To-dos about this"; and the dashboard's card. Every theme's template drawn.
 */
#[CoversClass(Todos::class)]
#[CoversClass(\Pramnos\Application\Controllers\Todo::class)]
#[CoversClass(\Pramnos\Todos\Html::class)]
#[CoversClass(\Pramnos\Todos\TodosWidget::class)]
class TodosScreenTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }
    use \Pramnos\Tests\Integration\Audience\AdminScreen;

    /** The application's info before the test. */
    private array $info = [];

    /** The probe with the to-do table, and the settings the test changes. */
    private function ready(string $type, string $host, int $port, string $user): void
    {
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/todos/2026_11_09_000011_create_todos_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([CreateTodosTable::class], $this->db);
        $this->db->queryBuilder()->table(Todos::TABLE)->delete();
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC'];
        Application::getInstance()->applicationInfo['todos'] = [];
        Todos::forgetSources();
    }

    /** The settings back, the request cleared, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        if ($this->info !== []) {
            Application::getInstance()->applicationInfo = $this->info;
        }
        Todos::forgetSources();
        $this->clearRequest();
        \Pramnos\Http\Request::setRawInput('');
        $this->probeTearDown();
    }

    /**
     * The engine's reads for the screens: the urgent five (one's own soonest first, and the overdue
     * nobody has — every overdue one with `urgent_any_overdue`), "about this" by path, the
     * calendar's feed and a drag to another day, the groups, who may be given items, and who may
     * read or write (`todos.can`) in which organisation (`todos.tenant`).
     */
    #[DataProvider('databases')]
    public function testWhatTheScreensRead(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $now      = time();
        $today    = gmdate('Y-m-d', $now);
        $past     = gmdate('Y-m-d', $now - 3 * 86400);
        $soon     = gmdate('Y-m-d', $now + 3 * 86400);
        $far      = gmdate('Y-m-d', $now + 30 * 86400);
        $late     = Todos::add(['title' => 'Late', 'due' => $past], $eleni);
        $nobodys  = Todos::add(['title' => 'Nobody\'s late', 'due' => $past, 'assigned_to' => 0, 'link' => '/admin/Users/edit/4'], $eleni);
        $marias   = Todos::add(['title' => 'Maria\'s late', 'due' => $past, 'assigned_to' => $maria], $eleni);
        $todays   = Todos::add(['title' => 'Today', 'due' => $today, 'link' => 'https://site.test/admin/Users/edit/4'], $eleni);
        $week     = Todos::add(['title' => 'This week', 'due' => $soon], $eleni);
        $later    = Todos::add(['title' => 'Later', 'due' => $far], $eleni);
        $undated  = Todos::add(['title' => 'No day'], $eleni);
        $done     = Todos::add(['title' => 'Done'], $eleni);
        Todos::update($done, ['status' => Todos::DONE], $eleni);
        Todos::source('ticket', static fn (): array => ['ticket:1' => ['title' => 'A ticket']]);
        Todos::touch(0);

        // Act
        $urgent   = Todos::urgent(0, $eleni);
        Application::getInstance()->applicationInfo['todos'] = ['urgent_any_overdue' => true];
        $any      = Todos::urgent(0, $eleni);
        $about    = array_column(Todos::about('/admin/Users/edit/4', 0, $eleni), 'id');
        $feed     = Todos::feed(0, $eleni, $past, gmdate('Y-m-d', $now + 7 * 86400));
        $moved    = Todos::move(['id' => $week, 'start' => $far], $eleni);
        $groups   = array_map(static fn (array $g): array => array_column($g, 'id'), Todos::groups(Todos::list(0, $eleni), 0, $now));
        Application::getInstance()->applicationInfo['todos'] = ['people' => static fn (int $t): array => [$maria => 'Maria', $nikos => 'Nikos'],
            'assignable' => static fn (int $t, int $u): bool => $u !== $nikos, 'tenant' => static fn (object $u): int => 7,
            'can' => static fn (object $u, string $what): bool => $what === 'read'];
        $people   = Todos::people(0);
        $reader   = (object) ['userid' => $maria, 'usertype' => 1];

        // Assert
        $this->assertSame([$late, $nobodys, $todays, $week, $later], array_column($urgent['items'], 'id'),
            'five: hers soonest first, nobody\'s overdue among them; not maria\'s');
        $this->assertContains($nobodys, array_column(Todos::urgent(0, $eleni, 10)['items'], 'id'));
        $this->assertNotContains($marias, array_column(Todos::urgent(0, $eleni, 10)['items'], 'id'));
        $this->assertSame(6, $urgent['count'], 'and one more without a day');
        $this->assertSame(7, $any['count'], 'every overdue one, maria\'s too');
        $this->assertEqualsCanonicalizing([$nobodys, $todays], $about, 'by path, with or without the site\'s address');
        $this->assertEqualsCanonicalizing([$late, $nobodys, $marias, $todays, $week], array_column($feed, 'id'));
        $this->assertSame(['pf-todo-overdue'], array_column($feed, 'classNames', 'id')[$late]);
        $this->assertSame(['ok' => true, 'due' => $far], $moved);
        $this->assertEqualsCanonicalizing([$late, $nobodys, $marias], $groups['overdue']);
        $this->assertSame([$todays], $groups['today']);
        $this->assertEqualsCanonicalizing([$week, $later], $groups['later'], 'moved there');
        $this->assertSame([$undated], $groups['none']);
        $this->assertCount(1, $groups['system'], 'the ticket nobody has');
        $this->assertSame([$done], $groups['closed']);
        $this->assertSame([$maria => 'Maria'], $people, 'only who may be given items');
        $this->assertSame([true, false, 7], [Todos::can($reader, 'read'), Todos::can($reader, 'write'), Todos::tenantOf($reader)]);
        $this->assertFalse(Todos::can(null));
    }

    /**
     * `/admin/Todo`: an item added; the list in its groups, mine, everyone's and the team's
     * counts; a change in place in JSON (status, title, assignee) and one refused; the calendar's
     * feed and a drag; a delete; somebody who may only read is turned away from writing; the header,
     * "about this" and the card drawn; every theme's template drawn.
     */
    #[DataProvider('databases')]
    public function testTheScreen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $this->clearRequest();
        $this->signIn(98, $eleni);
        $screen = $this->screen(\Pramnos\Application\Controllers\Todo::class);
        $theirs = Todos::add(['title' => 'Maria\'s', 'assigned_to' => $maria, 'link' => '/admin/Users/edit/9', 'due' => gmdate('Y-m-d', time() - 86400)], $maria);

        // Act
        $this->request('POST', null, [], ['title' => 'Call the supplier https://example.test/s', 'assigned_to' => (string) $eleni, 'due' => gmdate('Y-m-d'), 'remind' => gmdate('Y-m-d\TH:i', time() + 3600)]);
        $screen->add();
        $this->request('POST', null, [], ['title' => '']);
        $screen->add();
        $mine = Todos::list(0, $eleni);
        $id   = (int) array_column($mine, 'id', 'title')['Call the supplier'];
        $this->request('GET');
        $screen->display();
        $listed = (array) $screen->view;
        $this->request('GET', null, ['view' => 'everyone']);
        $screen->display();
        $everyone = (array) $screen->view;
        $this->request('GET', null, ['view' => 'team']);
        $screen->display();
        $team = (array) $screen->view;
        $this->request('POST', null, [], ['id' => (string) $id, 'status' => 'in_progress', 'title' => 'Call the supplier today']);
        $changed = json_decode((string) $screen->change()->getBody(), true);
        $this->request('POST', null, [], ['id' => (string) $id, 'remind' => 'tomorrow-ish']);
        $badRemind = $screen->change()->getStatusCode();
        $this->request('POST', null, [], ['id' => '999999', 'status' => 'done']);
        $missing = $screen->change()->getStatusCode();
        $this->request('GET', null, ['start' => gmdate('Y-m-d', time() - 7 * 86400), 'end' => gmdate('Y-m-d', time() + 7 * 86400)]);
        $events = json_decode((string) $screen->events()->getBody(), true);
        \Pramnos\Http\Request::setRawInput((string) json_encode(['id' => $id, 'start' => gmdate('Y-m-d', time() + 2 * 86400)]));
        $moved = json_decode((string) $screen->move()->getBody(), true);
        \Pramnos\Http\Request::setRawInput((string) json_encode(['id' => 999999, 'start' => '2026-01-01']));
        $movedMissing = $screen->move()->getStatusCode();
        \Pramnos\Http\Request::setRawInput((string) json_encode(['id' => $id, 'start' => 'never']));
        $movedBad = $screen->move()->getStatusCode();
        $header = \Pramnos\Todos\Html::headerButton('<li>', '</li>');
        $about  = \Pramnos\Todos\Html::aboutThis('/admin/Users/edit/9');
        $card   = (new \Pramnos\Todos\TodosWidget())->render();
        $this->request('POST', null, [], ['id' => (string) $id]);
        $screen->delete();
        $calendar = $this->render('bootstrap', 'todos/todos.tpl.php', ['mode' => 'calendar'] + $listed);
        Application::getInstance()->applicationInfo['todos'] = ['can' => static fn (object $u, string $what): bool => $what === 'read'];
        $this->request('POST', null, [], ['title' => 'Not mine to add']);
        $screen->add();
        $this->request('POST', null, [], ['id' => (string) $theirs, 'status' => 'done']);
        $readOnly = $screen->change()->getStatusCode();
        $this->request('GET', null, ['start' => '2026-01-01', 'end' => '2026-01-31']);
        $readEvents = $screen->events()->getStatusCode();
        Application::getInstance()->applicationInfo['todos'] = ['can' => static fn (): bool => false];
        $nobody = \Pramnos\Todos\Html::headerButton() . \Pramnos\Todos\Html::aboutThis('/admin/Users/edit/9');
        $this->request('GET');
        $screen->display();

        // Assert
        $this->assertSame([['message', 'Added.'], ['error', 'A to-do needs a title.'], ['message', 'Deleted.'], ['error', 'You do not have access to that screen.'],
            ['error', 'You do not have access to that screen.']], $screen->flash);
        $this->assertSame('https://example.test/s', array_column($mine, 'link', 'title')['Call the supplier']);
        $this->assertNotNull(array_column($mine, 'remind_at', 'title')['Call the supplier']);
        $this->assertSame('mine', $listed['mode']);
        $this->assertSame([$id], array_column($listed['groups']['today'], 'id'), 'mine: not maria\'s');
        $this->assertSame([$theirs], array_column($everyone['groups']['overdue'], 'id'), 'everyone\'s: maria\'s too');
        $this->assertSame(['open' => 1, 'overdue' => 1, 'done' => 0], $team['team'][$maria]);
        $this->assertSame([Todos::IN_PROGRESS, 'Call the supplier today'], [$changed['todo']['status'], $changed['todo']['title']]);
        $this->assertSame([422, 404], [$badRemind, $missing]);
        $this->assertEqualsCanonicalizing([$id, $theirs], array_column($events, 'id'));
        $this->assertSame([true, gmdate('Y-m-d', time() + 2 * 86400)], [$moved['ok'], $moved['due']]);
        $this->assertSame([404, 422], [$movedMissing, $movedBad]);
        $this->assertStringContainsString('Call the supplier today', $header);
        $this->assertStringContainsString('pf-todo-urgent-count', $header);
        $this->assertStringContainsString('Maria&#039;s', $about);
        $this->assertStringContainsString('Call the supplier today', $card);
        $this->assertNull(Todos::get($id), 'deleted');
        $this->assertStringContainsString('data-pf-calendar', $calendar);
        $this->assertSame(403, $readOnly, 'read only: no change');
        $this->assertSame(200, $readEvents, 'but the calendar is read');
        $this->assertSame('', $nobody, 'nothing for somebody who may not read the list');
        $this->assertNotEmpty($screen->redirects);
        foreach (['bootstrap', 'plain-css', 'tailwind'] as $theme) {
            foreach (['mine' => $listed, 'everyone' => $everyone, 'team' => $team] as $which => $vars) {
                $html = $this->render($theme, 'todos/todos.tpl.php', $vars);
                $this->assertStringContainsString('data-pf-todos', $html, $theme . '/' . $which);
                $this->assertStringNotContainsString('{{', $html, $theme . '/' . $which . ': compiled');
            }
            $this->assertStringContainsString('data-id="' . $theirs . '"', $this->render($theme, 'todos/todos.tpl.php', $everyone), $theme . ': maria\'s listed');
        }
    }
}
