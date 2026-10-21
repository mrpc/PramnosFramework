<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Audience\Attributes;
use Pramnos\Audience\Filter;
use Pramnos\Audience\Segments as SegmentStore;

/**
 * `/admin/Segments` — the library of segments and groups: how many are in each now, who came and
 * went this week, where each is used; and a segment's own page, its form, and "why is this person
 * in or out".
 *
 * Every administrator sees and makes segments. A segment in use — by another segment, or by what
 * an application reports through `Segments::usage()` — is not deleted. Each change is in the audit
 * log with the filter before and after.
 *
 * Actions:
 *   - display()       — segments and groups, `?kind=segments|groups`, `?mine=1`
 *   - view($id)       — one segment: members, movement, use, a sample, and `?person=` explained
 *   - edit($id)       — create (no id) or edit, with the filter builder
 *   - save()          — POST: create or update, then work it out
 *   - recompute($id)  — POST: work it out again now
 *   - delete($id)     — POST: refused while in use
 */
class Segments extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.segments';

    /** How many members a segment's page lists. */
    public const SAMPLE = 25;

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'view', 'edit', 'save', 'recompute', 'delete']);
        $this->addWriteAction(['save', 'recompute', 'delete']);
        parent::__construct($application);
    }

    /** The library: segments with their movement and use, then the groups. */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('Segments and groups');
        $kind = (string) \Pramnos\Http\Request::staticGet('kind', '', 'get');
        $mine = (string) \Pramnos\Http\Request::staticGet('mine', '', 'get') === '1';
        $me   = $this->me();

        // Every row once, so each tab can say how many it holds.
        $segments = [];
        foreach (SegmentStore::all() as $segment) {
            $uses       = SegmentStore::uses($segment['id']);
            $segments[] = ['kind' => 'segment', 'id' => $segment['id'], 'name' => $segment['name'], 'description' => $segment['description'],
                'members' => $segment['members'], 'movement' => SegmentStore::movement($segment['id']), 'usedBy' => array_column($uses, 'name'),
                'uses' => $uses, 'computed' => $segment['recomputedAt'] !== null, 'url' => adminUrl('Segments/view/') . $segment['id'],
                'mine' => $segment['owner'] === $me];
        }
        $groups = array_map(static fn (array $group): array => ['kind' => 'group', 'id' => $group['id'], 'name' => $group['name'],
            'description' => '', 'members' => $group['members'], 'movement' => null, 'usedBy' => [], 'uses' => [], 'computed' => true,
            'url' => adminUrl('Groups/view/') . $group['id'], 'mine' => false], $this->groups());
        $own  = array_values(array_filter($segments, static fn (array $row): bool => $row['mine']));
        $rows = $mine ? $own : ($kind === 'segments' ? $segments : ($kind === 'groups' ? $groups : array_merge($segments, $groups)));

        $view       = $this->getView('segments');
        $view->rows = $rows;
        $view->kind = $kind;
        $view->mine   = $mine;
        $view->counts = ['all' => count($segments) + count($groups), 'segments' => count($segments), 'groups' => count($groups), 'mine' => count($own)];

        return $view->display();
    }

    /** One segment: its numbers, where it is used, a sample of members, and "why" for a person. */
    public function view(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $segment = SegmentStore::get((int) \Pramnos\Http\Request::staticGetOption());
        if ($segment === null) {
            return $this->gone();
        }
        \Pramnos\Framework\Factory::getDocument()->title = $segment['name'];

        $view           = $this->getView('segments');
        $view->segment  = $segment;
        $view->movement = SegmentStore::movement($segment['id']);
        $view->uses     = SegmentStore::uses($segment['id']);
        $view->usedBy   = array_column($view->uses, 'name');
        $view->members  = $this->members($segment['id']);
        $view->person   = (int) \Pramnos\Http\Request::staticGet('person', 0, 'get');
        $view->why      = null;
        if ($view->person > 0) {
            $view->why = Filter::fromArray($segment['filter'])->explain($view->person);
            $view->isMember = SegmentStore::isMember($segment['id'], $view->person);
        }

        return $view->display('view');
    }

    /** The form: a name, a description and the filter, with the live count. */
    public function edit(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $id      = (int) \Pramnos\Http\Request::staticGetOption();
        $segment = $id > 0 ? SegmentStore::get($id) : null;
        if ($id > 0 && $segment === null) {
            return $this->gone();
        }
        \Pramnos\Framework\Factory::getDocument()->title = $segment === null ? t('New segment') : t('Edit segment');

        $view          = $this->getView('segments');
        $view->segment = $segment;

        return $view->display('edit');
    }

    /**
     * Create or change a segment, and work it out: a new one records its members silently, a
     * changed one announces who entered and who left.
     */
    public function save(): void
    {
        if ($this->refused()) {
            return;
        }
        $id      = (int) ($_POST['segmentid'] ?? 0);
        $before  = $id > 0 ? SegmentStore::get($id) : null;
        if ($id > 0 && $before === null) {
            $this->gone();

            return;
        }
        $fields = ['name' => (string) ($_POST['name'] ?? ''), 'description' => trim((string) ($_POST['description'] ?? '')),
            'filter' => (string) ($_POST['filter'] ?? '')];
        try {
            if ($before === null) {
                $id = SegmentStore::create($fields['name'], $fields['filter'], $this->me() ?: null, $fields['description']);
            } else {
                SegmentStore::update($id, $fields);
            }
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
            $this->redirect(adminUrl('Segments/edit/') . ($before === null ? '' : $id));

            return;
        }
        $result = SegmentStore::recompute($id);
        $this->audit($before === null ? 'segment.created' : 'segment.updated', $id, $before, SegmentStore::get($id));
        $this->addMessage(t('Saved: %s people in it now.', number_format($result['members'])));
        $this->redirect(adminUrl('Segments/view/') . $id);
    }

    /** Work a segment out again now, rather than at the next hour. */
    public function recompute(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if (SegmentStore::get($id) === null) {
            $this->gone();

            return;
        }
        $result = SegmentStore::recompute($id);
        $this->addMessage(t('%s in it now: %s entered, %s left.', number_format($result['members']), number_format($result['entered']), number_format($result['left'])));
        $this->redirect(adminUrl('Segments/view/') . $id);
    }

    /** Delete a segment — refused, with what uses it, while anything does. */
    public function delete(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }
        $id     = (int) \Pramnos\Http\Request::staticGetOption();
        $before = SegmentStore::get($id);
        if ($before === null) {
            $this->gone();

            return;
        }
        try {
            SegmentStore::delete($id);
        } catch (\RuntimeException $e) {
            $this->addError($e->getMessage());
            $this->redirect(adminUrl('Segments/view/') . $id);

            return;
        }
        $this->audit('segment.deleted', $id, $before, null);
        $this->addMessage(t('Segment deleted.'));
        $this->redirect(adminUrl('Segments'));
    }

    /**
     * The first members of a segment, newest first.
     *
     * @return list<array{userid: int, name: string, since: int, url: string}>
     */
    protected function members(int $segment): array
    {
        $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#segment_members AS segment_members')
            ->join(Attributes::FROM, 'users.userid', '=', 'segment_members.userid')
            ->select(['users.userid', 'users.username', 'users.firstname', 'users.lastname', 'segment_members.entered_at'])
            ->where('segment_members.segmentid', $segment)->whereNull('segment_members.left_at')
            ->orderBy('segment_members.entered_at', 'desc')->orderBy('users.userid', 'desc')
            ->limit(self::SAMPLE)->getAllOrFail();

        return array_map(static fn (array $row): array => [
            'userid' => (int) $row['userid'],
            'name'   => trim($row['firstname'] . ' ' . $row['lastname']) ?: (string) $row['username'],
            'since'  => (int) $row['entered_at'],
            'url'    => \Pramnos\Html\AudienceBuilder::personUrl((int) $row['userid']),
        ], $rows);
    }

    /**
     * The groups with their member counts; none where groups are not installed.
     *
     * @return list<array{id: int, name: string, members: int}>
     */
    protected function groups(): array
    {
        try {
            $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#usergroups AS usergroups')
                ->leftJoin('#PREFIX#userstogroups AS userstogroups', 'userstogroups.groupid', '=', 'usergroups.groupid')
                ->select(['usergroups.groupid', 'usergroups.name', new \Pramnos\Database\Expression('COUNT(userstogroups.userid) AS members')])
                ->groupBy(['usergroups.groupid', 'usergroups.name'])->orderBy('usergroups.name')->getAllOrFail();
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $row): array => ['id' => (int) $row['groupid'], 'name' => (string) $row['name'], 'members' => (int) $row['members']], $rows);
    }

    /**
     * A change in the audit log, with the segment before and after.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    protected function audit(string $event, int $id, ?array $before, ?array $after): void
    {
        $keep = static fn (?array $s): array => $s === null ? [] : ['name' => $s['name'], 'description' => $s['description'], 'filter' => $s['filter']];
        \Pramnos\Auth\AuditLog::change($event, $this->me() ?: null, 'segment', $id, $keep($before), $keep($after));
    }

    /**
     * Whether this request stops here: below the administration floor, or before the migrations
     * have made the tables — an application may hold them back — where the screen says so.
     */
    protected function refused(): bool
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return true;
        }
        if (!\Pramnos\Audience\Installed::segments()) {
            $this->addError(t('Segments are not installed here yet: run migrate.'));
            $this->redirect(adminUrl(''));

            return true;
        }

        return false;
    }

    /** The signed-in account's id, or 0. */
    protected function me(): int
    {
        $user = \Pramnos\User\User::current();

        return $user ? (int) $user->userid : 0;
    }

    /** A segment that is not there: back to the library. */
    protected function gone(): mixed
    {
        $this->addError(t('That segment no longer exists.'));
        $this->redirect(adminUrl('Segments'));

        return null;
    }
}
