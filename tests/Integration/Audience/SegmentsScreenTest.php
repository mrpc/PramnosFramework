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
#[CoversClass(\Pramnos\Audience\Groups::class)]
#[CoversClass(\Pramnos\Audience\Rules\Rules::class)]
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

    /** One answer of a tab's datatable: `Segments/data` with these parameters, decoded. */
    private function page(object $screen, array $post): array
    {
        $this->request('POST', null, [], $post + ['tab' => 'segments', 'draw' => 3, 'start' => 0, 'length' => 10]);

        return json_decode((string) $screen->data()->getBody(), true);
    }

    /** The name cell of each row — the link, its text only. @return list<string> */
    private static function names(array $answer, int $column = 0): array
    {
        return array_map(static fn (array $row): string => strip_tags(explode('<span class="pf-row-desc">', $row[$column])[0]), $answer['data']);
    }

    /**
     * The library's page is a tab each for groups, segments and the account's own, with their
     * counts, and the tab's own datatable — its columns, its export name, its first order — which
     * asks `Segments/data` with the tab. Groups come first and are shown unless `?kind=` or
     * `audience.default_tab` says otherwise; the button makes what the tab lists. In every theme.
     */
    #[DataProvider('databases')]
    public function testTheLibraryIsATabEachWithItsOwnDatatable(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $this->request('GET', null, ['kind' => 'segments']);
        $screen->display();
        $empty = $this->everyTheme('segments/segments.html.php', (array) $screen->view);
        $this->greek();
        $info = &\Pramnos\Application\Application::getInstance()->applicationInfo;

        // Act
        $this->request('GET');
        $screen->display();
        $groups = (array) $screen->view;
        $this->request('GET', null, ['kind' => 'segments']);
        $screen->display();
        $segments = (array) $screen->view;
        $this->request('GET', null, ['mine' => '1']);
        $screen->display();
        $mine = (array) $screen->view;
        $info['audience']['default_tab'] = 'segments';
        $this->request('GET', null, ['kind' => 'all']);
        $screen->display();
        $fallback = $screen->view->tab;
        unset($info['audience']['default_tab']);
        $html = ['groups' => $this->everyTheme('segments/segments.html.php', $groups), 'segments' => $this->everyTheme('segments/segments.html.php', $segments)];
        $labels = static fn (array $vars): array => array_map(static fn ($column): string => $column->label, array_values($vars['datatable']->aoColumns));

        // Assert
        $this->assertSame('groups', $groups['tab'], 'groups unless asked');
        $this->assertSame('segments', $fallback, 'a tab that is no longer there is the default one');
        $this->assertSame(['groups' => 2, 'segments' => 1, 'mine' => 1], $groups['counts'], 'each tab says how many it holds');
        $this->assertSame(['#', 'Name', 'Members in it', 'Order in the list', 'Used by'], $labels($groups));
        $this->assertSame(['Name', 'Members in it', 'Entered, 7 days', 'Left, 7 days', 'Used by', 'Owned by', 'Last worked out'], $labels($segments));
        $this->assertSame(['Name', 'Members in it', 'Entered, 7 days', 'Left, 7 days', 'Used by', 'Last worked out'], $labels($mine), 'no owner among one\'s own');
        $this->assertSame(['pf-groups-table', [['name' => 'tab', 'value' => 'groups']], 3, 'asc', 'User groups'],
            [$groups['datatable']->name, $groups['datatable']->aoData, $groups['datatable']->sortColumn, $groups['datatable']->sortOrder, $groups['datatable']->exportName], 'groups by their order');
        $this->assertSame(['pf-segments-table', 1, 'desc', 'Audience segments'],
            [$segments['datatable']->name, $segments['datatable']->sortColumn, $segments['datatable']->sortOrder, $segments['datatable']->exportName], 'segments by most members');
        $this->assertSame('My segments', $mine['datatable']->exportName);
        foreach ($empty as $theme => $page) {
            $this->assertStringContainsString('No segments yet', $page, $theme);
        }
        foreach ($html['groups'] as $theme => $page) {
            $this->assertStringContainsString('Groups and segments', $page, $theme);
            $this->assertStringContainsString("jQuery('#pf-groups-table').dataTable(", $page, $theme);
            $this->assertStringContainsString('"filename": "User groups"', $page, $theme);
            $this->assertStringContainsString(adminUrl('Groups/edit'), $page, $theme);
            $this->assertStringNotContainsString(adminUrl('Segments/edit'), $page, 'the button makes a group here');
            $this->assertLessThan(strpos($page, '?kind=segments'), strpos($page, '?kind=groups'), 'groups first');
        }
        foreach ($html['segments'] as $theme => $page) {
            $this->assertStringContainsString(adminUrl('Segments/edit'), $page, $theme);
            $this->assertStringContainsString('<span class="pf-count">1</span>', $page, $theme);
        }
    }

    /**
     * A tab's rows: groups with their number, members, order and what uses them — a segment
     * naming the group, a rule putting people in it, what the application reports — sorted by the
     * order with a group with none after; segments with members ("being worked out" before the
     * first time), the week's movement, uses, owner and when; the account's own without the
     * owner. Each sorts by any column but "used by", searches number, name and description, pages.
     */
    #[DataProvider('databases')]
    public function testEachTabsRowsSortSearchAndPage(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = $this->greek();
        $press = $this->groups['Press'];
        $built = Segments::create('Built on it', ['conditions' => [['segment' => $greek, 'op' => 'in'], ['attribute' => 'groups', 'op' => 'any_of', 'value' => [$press]]]],
            $this->ids['bob'], 'Made from the Greek speakers');
        $rule = \Pramnos\Audience\Rules\Rules::create('Paid to press', ['trigger' => ['type' => 'event', 'event' => 'payment.made'], 'steps' => [
            'a' => ['type' => 'action', 'action' => 'group_add', 'params' => ['group' => $press], 'next' => null],
        ]], 2);
        \Pramnos\Audience\Groups::usage('coupons', static fn (int $id): array => $id === $press ? [['name' => 'coupon SUMMER', 'url' => '/c/4'], 'package Gold'] : []);
        $this->db->queryBuilder()->table('usergroups')->where('groupid', $this->groups['Volunteers'])->update(['order' => 3]);
        Segments::usage('campaigns', static fn (int $id): array => $id === $greek ? ['The newsletter'] : []);
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $order  = static fn (string $tab, int $column, string $dir): array => ['tab' => $tab, 'order' => [['column' => $column, 'dir' => $dir]]];

        // Act
        $groups     = $this->page($screen, $order('groups', 3, 'asc'));
        $groupsDesc = $this->page($screen, $order('groups', 2, 'desc'));
        $byNumber   = $this->page($screen, ['tab' => 'groups', 'search' => ['value' => '#' . $press]]);
        $segments   = $this->page($screen, $order('segments', 1, 'desc'));
        $byName     = $this->page($screen, $order('segments', 0, 'asc'));
        $byOwner    = $this->page($screen, $order('segments', 5, 'desc'));
        $byUse      = $this->page($screen, $order('segments', 4, 'asc'));
        $mine       = $this->page($screen, $order('mine', 0, 'asc'));
        $searched   = $this->page($screen, ['tab' => 'segments', 'search' => ['value' => 'GREEK']] + $order('segments', 0, 'asc'));
        $second     = $this->page($screen, ['start' => 1, 'length' => 1] + $order('segments', 0, 'asc'));
        $unknown    = $this->page($screen, $order('everything', 3, 'asc'));

        // Assert
        $this->assertSame(3, $groups['draw'], 'the draw counter comes back');
        $this->assertSame(['Volunteers', 'Press'], self::names($groups, 1), 'by the order; none comes after');
        $rows = array_combine(self::names($groups, 1), $groups['data']);
        $this->assertSame([(string) $press, '<span class="pf-nowrap">1</span>', '<span class="pf-zero">—</span>'], [$rows['Press'][0], $rows['Press'][2], $rows['Press'][3]]);
        $this->assertSame('3', $rows['Volunteers'][3]);
        foreach (['href="' . adminUrl('Segments/view/') . $built . '"', 'href="' . adminUrl('Rules/view/') . $rule . '"', 'href="/c/4"', '<span class="pf-chip">package Gold</span>'] as $use) {
            $this->assertStringContainsString($use, $rows['Press'][4], 'a group is used by ' . $use);
        }
        $this->assertSame('<span class="pf-zero">—</span>', $rows['Volunteers'][4]);
        $this->assertSame(['Volunteers', 'Press'], self::names($groupsDesc, 1), 'two members, then one');
        $this->assertSame(['Press'], self::names($byNumber, 1), 'found by its number');
        $this->assertSame([2, 2], [$segments['recordsTotal'], $segments['recordsFiltered']], 'segments alone');
        $this->assertSame(['Greek speakers', 'Built on it'], self::names($segments), 'most members first; not yet worked out last');
        $this->assertSame(['Built on it', 'Greek speakers'], self::names($byName));
        $this->assertSame('Built on it', self::names($byOwner)[0], 'Bob owns it');
        $this->assertSame(['Built on it', 'Greek speakers'], self::names($byUse), '"used by" is not sorted by');
        $rows = array_combine(self::names($byName), $byName['data']);
        $this->assertSame('<span class="pf-nowrap">2</span>', $rows['Greek speakers'][1]);
        $this->assertStringContainsString('Being worked out…', $rows['Built on it'][1], 'not 0, which reads as empty');
        $this->assertSame(['<span class="pf-up">2</span>', '<span class="pf-zero">0</span>'], [$rows['Greek speakers'][2], $rows['Greek speakers'][3]], 'entered and left this week');
        $this->assertStringContainsString('class="pf-chip" href="' . adminUrl('Segments/view/') . $built . '"', $rows['Greek speakers'][4], 'what uses it is a link');
        $this->assertStringContainsString('<span class="pf-chip">The newsletter</span>', $rows['Greek speakers'][4], 'what the application reports, without a page');
        $this->assertSame(['Bob', date('d/m/Y')], [strip_tags($rows['Built on it'][5]), substr(strip_tags($rows['Greek speakers'][6]), 0, 10)], 'the date as the administration writes it');
        $this->assertStringContainsString('Made from the Greek speakers', $rows['Built on it'][0], 'the description under the name');
        $this->assertSame(['Greek speakers'], self::names($mine));
        $this->assertCount(6, $mine['data'][0], 'no owner among one\'s own');
        $this->assertSame(['Built on it', 'Greek speakers'], self::names($searched), 'the description matches too, ignoring case');
        $this->assertSame([2, 2], [$searched['recordsTotal'], $searched['recordsFiltered']]);
        $this->assertSame(['Greek speakers'], self::names($second), 'the second');
        $this->assertSame(['Volunteers', 'Press'], self::names($unknown, 1), 'an unknown tab is the groups');
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
