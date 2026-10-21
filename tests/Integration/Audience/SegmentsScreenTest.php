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

    /**
     * The library lists segments with their count, movement and use, then the groups; the tabs
     * narrow it to segments, groups, or the account's own.
     */
    #[DataProvider('databases')]
    public function testTheLibraryListsSegmentsAndGroups(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = $this->greek();
        Segments::create('Built on it', ['conditions' => [['segment' => $greek, 'op' => 'in']]], owner: 9);
        $this->signIn();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('GET');
        $screen->display();
        $all = (array) $screen->view;
        $this->request('GET', null, ['kind' => 'groups']);
        $screen->display();
        $groups = array_column($screen->view->rows, 'name');
        $this->request('GET', null, ['mine' => '1']);
        $screen->display();
        $mine = array_column($screen->view->rows, 'name');
        $html = $this->everyTheme('segments/segments.html.php', $all);

        // Assert
        $byName = array_column($all['rows'], null, 'name');
        $this->assertSame(['Built on it', 'Greek speakers', 'Press', 'Volunteers'], array_column($all['rows'], 'name'));
        $this->assertSame(2, $byName['Greek speakers']['members']);
        $this->assertSame(['entered' => 2, 'left' => 0], $byName['Greek speakers']['movement']);
        $this->assertSame(['segment Built on it'], $byName['Greek speakers']['usedBy']);
        $this->assertFalse($byName['Built on it']['computed']);
        $this->assertSame(2, $byName['Volunteers']['members']);
        $this->assertSame(['Press', 'Volunteers'], $groups);
        $this->assertSame(['Greek speakers'], $mine);
        foreach ($html as $theme => $page) {
            $this->assertStringContainsString('Greek speakers', $page, $theme);
            $this->assertStringContainsString('+2 / −0', $page, $theme);
            $this->assertStringContainsString('segment Built on it', $page, $theme);
            $this->assertStringContainsString('not yet worked out', $page, $theme);
        }
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
        $answers = [$screen->display(), $screen->view(), $screen->edit()];
        $screen->save();
        $screen->recompute();
        $screen->delete();

        // Assert
        $this->assertSame([null, null, null], $answers);
        $this->assertCount(6, $screen->redirects);
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
