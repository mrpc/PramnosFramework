<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Segments as Screen;
use Pramnos\Audience\Segments;

/**
 * `/admin/Segments` on MySQL, prefixed MySQL and PostgreSQL: the library, a segment's page with
 * "why", the form, and the writes — each change in the audit log — and every theme's view rendered
 * with what the controller gave it.
 */
#[CoversClass(Screen::class)]
#[CoversClass(\Pramnos\Html\Datatable\ArraySource::class)]
#[CoversClass(Segments::class)]
#[CoversClass(\Pramnos\Html\AudiencePanel::class)]
class SegmentsScreenTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }
    use AdminScreen;

    /** Clear the request, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $this->clearRequest();
        $this->probeTearDown();
    }

    /** "Greek speakers", worked out once: anna and cara. */
    private function greek(): int
    {
        $id = Segments::create('Greek speakers', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]], owner: 2);
        Segments::recompute($id);

        return $id;
    }

    /** Every theme's rendering of one view, with these variables. @return array<string, string> */
    private function everyTheme(string $file, array $vars): array
    {
        $html = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $html[$theme] = $this->render($theme, $file, $vars);
        }

        return $html;
    }

    /** One answer of the library's datatable: `Segments/data` with these parameters, decoded. */
    private function page(object $screen, array $post): array
    {
        $this->request('POST', null, [], $post + ['draw' => 3, 'start' => 0, 'length' => 10]);

        return json_decode((string) $screen->data()->getBody(), true);
    }

    /** The first cell of each row: the name's link, its text only. @return list<string> */
    private static function names(array $answer): array
    {
        return array_map(static fn (array $row): string => strip_tags(explode('<span class="pf-row-desc">', $row[0])[0]), $answer['data']);
    }

    /**
     * The library's page is the tabs with their counts and the framework's datatable, which asks
     * `Segments/data` with the tab's filter, the most members first — in every theme.
     */
    #[DataProvider('databases')]
    public function testTheLibraryIsTabsAndADatatable(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $this->request('GET');
        $screen->display();
        $empty = $this->everyTheme('segments/segments.html.php', (array) $screen->view);
        $this->greek();

        // Act
        $this->request('GET', null, ['kind' => 'groups']);
        $screen->display();
        $vars = (array) $screen->view;
        $html = $this->everyTheme('segments/segments.html.php', $vars);
        $this->request('GET');
        $screen->display();

        // Assert
        $this->assertSame(['all' => 3, 'segments' => 1, 'groups' => 2, 'mine' => 1], $vars['counts'], 'each tab says how many it holds');
        $this->assertSame(adminUrl('Segments/data'), $vars['datatable']->source);
        $this->assertSame([['name' => 'kind', 'value' => 'groups'], ['name' => 'mine', 'value' => '']], $vars['datatable']->aoData, 'the tab is the filter sent');
        $this->assertSame([2, 'desc'], [$vars['datatable']->sortColumn, $vars['datatable']->sortOrder], 'the most members first');
        $this->assertSame([['name' => 'kind', 'value' => ''], ['name' => 'mine', 'value' => '']], $screen->view->datatable->aoData);
        foreach ($empty as $theme => $page) {
            // Groups alone are no library yet: the empty state is about segments.
            $this->assertStringNotContainsString('No segments yet', $page, $theme);
        }
        foreach ($html as $theme => $page) {
            $this->assertStringContainsString('<span class="pf-count">3</span>', $page, $theme);
            $this->assertStringContainsString("jQuery('#pf-segments-table').dataTable(", $page, $theme);
            $this->assertStringContainsString('"serverSide": true', $page, $theme);
            $this->assertStringContainsString('Last worked out', $page, $theme);
        }
    }

    /**
     * `Segments/data` answers a page: each segment with its count, the week's movement, what uses
     * it, its owner and when it was worked out; a group with "—" where it has none of those. It
     * sorts by any column but "used by", narrows to a tab, searches name and description, pages.
     */
    #[DataProvider('databases')]
    public function testTheLibrarysRowsSortFilterSearchAndPage(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = $this->greek();
        $built = Segments::create('Built on it', ['conditions' => [['segment' => $greek, 'op' => 'in']]], $this->ids['bob'], 'Made from the Greek speakers');
        Segments::usage('campaigns', static fn (int $id): array => $id === $greek ? ['The newsletter'] : []);
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $order  = static fn (int $column, string $dir): array => ['order' => [['column' => $column, 'dir' => $dir]]];

        // Act
        $byMembers = $this->page($screen, $order(2, 'desc'));
        $byName    = $this->page($screen, $order(0, 'asc'));
        $byOwner   = $this->page($screen, $order(6, 'desc'));
        $byUse     = $this->page($screen, $order(5, 'asc'));
        $groups    = $this->page($screen, ['kind' => 'groups'] + $order(0, 'asc'));
        $segments  = $this->page($screen, ['kind' => 'segments'] + $order(0, 'asc'));
        $mine      = $this->page($screen, ['mine' => '1'] + $order(0, 'asc'));
        $searched  = $this->page($screen, ['search' => ['value' => 'GREEK']] + $order(0, 'asc'));
        $second    = $this->page($screen, ['start' => 1, 'length' => 2] + $order(0, 'asc'));

        // Assert
        $this->assertSame(3, $byMembers['draw'], 'the draw counter comes back');
        $this->assertSame([4, 4], [$byMembers['recordsTotal'], $byMembers['recordsFiltered']]);
        $this->assertSame(['Greek speakers', 'Volunteers', 'Press', 'Built on it'], self::names($byMembers), 'most members first; not yet worked out last');
        $this->assertSame(['Built on it', 'Greek speakers', 'Press', 'Volunteers'], self::names($byName));
        $this->assertSame('Built on it', self::names($byOwner)[0], 'Bob owns it; the others have no owner named');
        $this->assertSame(self::names($byName), self::names($byUse), '"used by" is not sorted by: segments, then groups, each by name');
        $rows = array_combine(self::names($byName), $byName['data']);
        $this->assertSame('<span class="pf-nowrap">2</span>', $rows['Greek speakers'][2]);
        $this->assertStringContainsString('not yet worked out', $rows['Built on it'][2]);
        $this->assertSame(['<span class="pf-up">2</span>', '<span class="pf-zero">0</span>'], [$rows['Greek speakers'][3], $rows['Greek speakers'][4]], 'entered and left this week');
        $this->assertStringContainsString('class="pf-chip" href="' . adminUrl('Segments/view/') . $built . '"', $rows['Greek speakers'][5], 'what uses it is a link');
        $this->assertStringContainsString('<span class="pf-chip">The newsletter</span>', $rows['Greek speakers'][5], 'what the application reports, without a page');
        $this->assertSame(['Bob', date('d/m/Y')], [strip_tags($rows['Built on it'][6]), substr(strip_tags($rows['Greek speakers'][7]), 0, 10)], 'the date as the administration writes it');
        $this->assertStringContainsString('>Audience segment</span>', $rows['Greek speakers'][1], 'the kind under keys of its own: an application\'s "Group" may mean something else');
        $this->assertStringContainsString('>User group</span>', $rows['Press'][1]);
        $this->assertStringContainsString('Made from the Greek speakers', $rows['Built on it'][0], 'the description under the name');
        $this->assertSame(array_fill(0, 5, '<span class="pf-zero">—</span>'), [$rows['Press'][3], $rows['Press'][4], $rows['Press'][5], $rows['Press'][6], $rows['Press'][7]], 'a group has none of these');
        $this->assertSame(['Press', 'Volunteers'], self::names($groups));
        $this->assertSame(['Built on it', 'Greek speakers'], self::names($segments));
        $this->assertSame(['Greek speakers'], self::names($mine));
        $this->assertSame(['Built on it', 'Greek speakers'], self::names($searched), 'the description matches too, ignoring case');
        $this->assertSame([4, 2], [$searched['recordsTotal'], $searched['recordsFiltered']], 'all there are, and those found');
        $this->assertSame(['Greek speakers', 'Press'], self::names($second), 'the second and third');
    }

    /**
     * Saving a new segment works it out at once and records the change; a filter that does not
     * check is sent back to the form with what is wrong.
     */
    #[DataProvider('databases')]
    public function testSavingCreatesWorksOutAndAudits(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $filter = json_encode(['conditions' => [['attribute' => 'usertype', 'op' => 'is', 'value' => 2]]]);

        // Act
        $this->request('POST', null, [], ['name' => 'Professionals', 'description' => 'Both kinds', 'filter' => $filter]);
        $screen->save();
        $id = Segments::all()[0]['id'];
        $this->request('POST', null, [], ['segmentid' => (string) $id, 'name' => 'Professionals', 'filter' => '{"conditions":[{"attribute":"nope","op":"is","value":1}]}']);
        $screen->save();
        $this->request('POST', null, [], ['segmentid' => (string) $id, 'name' => 'Parents', 'filter' => json_encode(['conditions' => [['attribute' => 'usertype', 'op' => 'is', 'value' => 1]]])]);
        $screen->save();
        $this->request('POST', null, [], ['segmentid' => '424242', 'name' => 'Gone']);
        $screen->save();

        // Assert
        $this->assertSame([
            ['message', 'Saved: 2 people in it now.'],
            ['error', 'filter.0: no attribute nope.'],
            ['message', 'Saved: 1 people in it now.'],
            ['error', 'That segment no longer exists.'],
        ], $screen->flash);
        $this->assertSame([adminUrl('Segments/view/') . $id, adminUrl('Segments/edit/') . $id, adminUrl('Segments/view/') . $id, adminUrl('Segments')], $screen->redirects);
        $this->assertSame('Parents', Segments::get($id)['name']);
        $this->assertSame(2, Segments::get($id)['owner']);
        $audit = $this->db->queryBuilder()->table('authserver.audit_log')->where('object_type', 'segment')->orderBy('auditid')->getAll();
        $this->assertSame(['segment.created', 'segment.updated'], array_column($audit, 'event_type'));
    }

    /**
     * A segment's page has its numbers, newest members and use, and explains one person
     * condition by condition — in every theme.
     */
    #[DataProvider('databases')]
    public function testASegmentsPageExplainsAPerson(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = Segments::create('Greek professionals', ['match' => 'all', 'conditions' => [
            ['attribute' => 'language', 'op' => 'is', 'value' => 'el'],
            ['match' => 'any', 'conditions' => [['attribute' => 'usertype', 'op' => 'is', 'value' => 2], ['attribute' => 'groups', 'op' => 'any_of', 'value' => [$this->groups['Press']]]]],
        ]]);
        Segments::recompute($id);
        $this->signIn();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('GET', $id, ['person' => (string) $this->ids['anna']]);
        $screen->view();
        $template = $screen->view->template;
        $vars     = (array) $screen->view;
        $html = $this->everyTheme('segments/view.html.php', $vars);
        $this->request('GET', 424242);
        $gone = $screen->view();

        // Assert
        $this->assertSame('view', $template);
        $this->assertSame(1, $vars['segment']['members'], 'cara');
        $this->assertFalse($vars['why']['ok'], 'anna is a parent outside Press');
        $this->assertFalse($vars['isMember']);
        $this->assertSame('Cara', $vars['members'][0]['name']);
        $this->assertNull($gone);
        foreach ($html as $theme => $page) {
            $this->assertStringContainsString('Does not match the filter now.', $page, $theme);
            $this->assertStringContainsString('has: el', $page, $theme);
            $this->assertStringContainsString('Cara', $page, $theme);
            $this->assertStringContainsString('✗', $page, $theme);
        }
    }

    /**
     * The form carries the segment and the builder, and a new one starts empty; working it out
     * again reports the change; an unknown id goes back to the library.
     */
    #[DataProvider('databases')]
    public function testTheFormAndWorkingItOutAgain(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = $this->greek();
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids['bob'])->update(['language' => 'el']);
        $this->signIn();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('GET', $id);
        $screen->edit();
        $edit = (array) $screen->view;
        $this->request('GET');
        $screen->edit();
        $new = (array) $screen->view;
        $forms = array_merge($this->everyTheme('segments/edit.html.php', $edit), ['new' => $this->render('tailwind', 'segments/edit.html.php', $new)]);
        $this->request('POST', $id);
        $screen->recompute();
        $this->request('POST', 424242);
        $screen->recompute();
        $this->request('GET', 424242);
        $screen->edit();

        // Assert
        $this->assertSame('Greek speakers', $edit['segment']['name']);
        $this->assertNull($new['segment']);
        foreach ($forms as $theme => $page) {
            $this->assertStringContainsString('data-pf-audience="filter"', $page, (string) $theme);
            $this->assertStringContainsString("Segments/save", $page, (string) $theme);
        }
        $this->assertStringContainsString('name="segmentid"', $forms['bootstrap']);
        $this->assertStringNotContainsString('name="segmentid"', $forms['new']);
        $this->assertSame(['message', '3 in it now: 1 entered, 0 left.'], $screen->flash[0]);
        $this->assertSame(['error', 'That segment no longer exists.'], $screen->flash[1]);
        $this->assertSame(['error', 'That segment no longer exists.'], $screen->flash[2]);
    }

    /** A segment in use is not deleted and the page says by what; one in no use is, and audited. */
    #[DataProvider('databases')]
    public function testDeletingIsRefusedWhileInUse(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = $this->greek();
        Segments::usage('rules', fn (int $s): array => ['rule Welcome']);
        $this->signIn();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('POST', $id);
        $screen->delete();
        Segments::reset();
        $screen->delete();
        $this->request('POST', $id);
        $screen->delete();

        // Assert
        $this->assertSame([
            ['error', 'This segment is in use: rule Welcome'],
            ['message', 'Segment deleted.'],
            ['error', 'That segment no longer exists.'],
        ], $screen->flash);
        $this->assertNull(Segments::get($id));
        $events = array_column($this->db->queryBuilder()->table('authserver.audit_log')->where('object_type', 'segment')->getAll(), 'event_type');
        $this->assertSame(['segment.deleted'], $events);
    }

    /** Below the administration floor nothing is read or written. */
    #[DataProvider('databases')]
    public function testEveryActionIsRefusedBelowTheFloor(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = $this->greek();
        $this->signIn(1);
        $screen = $this->screen(Screen::class);
        $this->request('POST', $id, [], ['name' => 'x', 'filter' => '']);

        // Act
        $answers = [$screen->display(), $screen->data(), $screen->view(), $screen->edit()];
        $screen->save();
        $screen->recompute();
        $screen->delete();

        // Assert
        $this->assertSame([null, null, null, null], $answers, 'the datatable\'s rows too');
        $this->assertCount(7, $screen->redirects);
        $this->assertNotNull(Segments::get($id));
        $this->assertCount(1, Segments::all());
    }

    /**
     * The person card's panel lists the segments a person is in, since when, and their groups,
     * each linked; a person in none says so; and every theme's user page carries it.
     */
    #[DataProvider('databases')]
    public function testThePersonCardShowsSegmentsAndGroups(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = $this->greek();

        // Act
        $anna = \Pramnos\Html\AudiencePanel::person($this->ids['anna']);
        $dan  = \Pramnos\Html\AudiencePanel::person($this->ids['dan']);
        // Without the segment tables the card still shows the groups.
        $this->db->schema()->dropTableIfExists('segment_members');
        $this->probeChanged($this->db->type);
        $bare = \Pramnos\Html\AudiencePanel::person($this->ids['anna']);

        // Assert
        $this->assertStringContainsString(adminUrl('Segments/view/') . $greek . '?person=' . $this->ids['anna'], $anna);
        $this->assertStringContainsString('Greek speakers', $anna);
        $this->assertStringContainsString('since ' . date('Y-m-d'), $anna);
        $this->assertStringContainsString(adminUrl('Groups/view/') . $this->groups['Volunteers'], $anna);
        $this->assertStringContainsString('In no segment or group.', $dan);
        $this->assertStringNotContainsString('Greek speakers', $bare);
        $this->assertStringContainsString('Volunteers', $bare);
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $this->assertStringContainsString('AudiencePanel::person($uid)', (string) file_get_contents(ROOT . '/scaffolding/themes/' . $theme . '/views/users/view.html.php'), $theme);
        }
    }
}
