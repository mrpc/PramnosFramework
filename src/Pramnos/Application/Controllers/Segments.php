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
 *   - display()       — all, groups, segments or the account's own, `?kind=all|groups|segments`, `?mine=1`, each in its datatable
 *   - data()          — POST: a page of a tab's rows, as JSON
 *   - edit($id)       — a segment's form; a new one may be filled in from `?draft=` (Ask)
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
        $this->addAuthAction(['display', 'data', 'view', 'edit', 'save', 'recompute', 'delete']);
        $this->addWriteAction(['save', 'recompute', 'delete']);
        parent::__construct($application);
    }

    /** The library's tabs, in the order they are shown; `audience.tabs` keeps some of them. */
    public const TABS = ['all', 'groups', 'segments', 'mine'];

    /**
     * The library: the tabs with their counts — all, groups, segments, the account's own — and
     * the tab's own datatable, whose rows {@see data()} answers a page at a time. With no tab
     * asked for, `audience.default_tab` in app.php names the one shown: `groups` (the default),
     * `all`, `segments` or `mine`.
     */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('Groups and segments');
        $tab = $this->tab();

        $view            = $this->getView('segments');
        $view->tab       = $tab;
        $view->tabs      = $this->tabs();
        $view->counts    = $this->library()['counts'];
        $view->datatable = $this->datatable($tab);

        return $view->display();
    }

    /**
     * A tab's rows, a page at a time, for its datatable (`tab` in the request): the search over
     * number, name and description, the order. What uses each is worked out for the rows shown
     * only.
     */
    public function data(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument('json');
        $tab     = in_array($_POST['tab'] ?? '', $this->tabs(), true) ? (string) $_POST['tab'] : $this->tabs()[0];
        $library = $this->library();
        $e       = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $none    = '<span class="pf-zero">—</span>';
        $number  = static fn (int $n, string $class): string => '<span class="' . ($n > 0 ? $class : 'pf-zero') . '">' . $e(number_format($n)) . '</span>';
        $uses    = static function (array $found) use ($e, $none): string {
            $chips = '';
            foreach ($found as $use) {
                $chips .= $use['url'] !== null
                    ? '<a class="pf-chip" href="' . $e($use['url']) . '">' . $e($use['name']) . '</a>'
                    : '<span class="pf-chip">' . $e($use['name']) . '</span>';
            }

            return $chips === '' ? $none : '<span class="pf-chips">' . $chips . '</span>';
        };
        // A plain link, not the row-wide one: the row's chips and actions are links of their own.
        $name    = static fn (array $row): string => '<a class="pf-row-name" href="' . $e($row['url']) . '">' . $e($row['name']) . '</a>'
            . ($row['description'] !== '' ? '<span class="pf-row-desc">' . $e($row['description']) . '</span>' : '');
        $members = static fn (array $row): string => $row['members'] >= 0
            ? '<span class="pf-nowrap">' . $e(number_format($row['members'])) . '</span>'
            : '<span class="pf-muted">' . $e(t('Being worked out…')) . '</span>';

        $withActions = $tab === 'all' ? $this->rowActions('groups') || $this->rowActions('segments') : $this->rowActions($tab);
        // Open, edit, delete — delete disabled, saying why, while something uses it; the server
        // refuses it then too.
        $actions = static fn (string $screen, int $id, array $found): string => '<span class="pf-row-actions">'
            . \Pramnos\Html\Icon::link(adminUrl($screen . '/view/') . $id, 'view', $screen === 'Groups' ? t('Open the group') : t('Open the segment'))
            . \Pramnos\Html\Icon::link(adminUrl($screen . '/edit/') . $id, 'edit', $screen === 'Groups' ? t('Edit the group') : t('Edit the segment'))
            . ($found === []
                ? \Pramnos\Html\Icon::postButton(adminUrl($screen . '/delete/') . $id, 'delete', $screen === 'Groups' ? t('Delete the group') : t('Delete the segment'),
                    ['class' => 'pf-action-danger', 'data-confirm' => $screen === 'Groups' ? t('Delete this group? Its members keep their accounts.') : t('Delete this segment and its history?')])
                : \Pramnos\Html\Icon::postButton(adminUrl($screen . '/delete/') . $id, 'delete', t('Used by: %s', implode(', ', array_column($found, 'name'))), ['disabled' => 'disabled']))
            . '</span>';

        if ($tab === 'all') {
            $groupActions   = $this->rowActions('groups');
            $segmentActions = $this->rowActions('segments');

            return \Pramnos\Http\Response::json(\Pramnos\Html\Datatable\ArraySource::answer(
                $library['all'],
                [0 => 'name', 1 => 'kindOrder', 2 => 'members'],
                static fn (array $row): string => '#' . $row['id'] . ' ' . $row['name'] . ' ' . $row['description'],
                static fn (array $page): array => array_map(static function (array $row) use ($name, $members, $e, $uses, $actions, $withActions, $groupActions, $segmentActions): array {
                    $group = $row['kind'] === 'group';
                    $found = $group ? \Pramnos\Audience\Groups::uses($row['id']) : SegmentStore::uses($row['id']);
                    $cells = [
                        $name($row),
                        '<span class="pf-badge ' . ($group ? 'pf-tone-neutral' : 'pf-tone-accent') . '">' . $e($group ? t('User group') : t('Audience segment')) . '</span>',
                        $members($row),
                        $uses($found),
                    ];
                    if ($withActions) {
                        $cells[] = ($group ? $groupActions : $segmentActions) ? $actions($group ? 'Groups' : 'Segments', $row['id'], $found) : '';
                    }

                    return $cells;
                }, $page),
            ));
        }

        if ($tab === 'groups') {
            return \Pramnos\Http\Response::json(\Pramnos\Html\Datatable\ArraySource::answer(
                $library['groups'],
                [0 => 'id', 1 => 'name', 2 => 'members', 3 => 'entered', 4 => 'left', 5 => 'orderKey'],
                static fn (array $row): string => '#' . $row['id'] . ' ' . $row['name'] . ' ' . $row['description'],
                static function (array $page) use ($name, $members, $none, $e, $uses, $actions, $withActions, $number): array {
                    return array_map(static function (array $row) use ($name, $members, $none, $e, $uses, $actions, $withActions, $number): array {
                        $found = \Pramnos\Audience\Groups::uses($row['id']);
                        $cells = [(string) $row['id'], $name($row), $members($row), $number($row['entered'], 'pf-up'), $number($row['left'], 'pf-down'),
                            $row['order'] === null ? $none : $e((string) $row['order']), $uses($found)];
                        if ($withActions) {
                            $cells[] = $actions('Groups', $row['id'], $found);
                        }

                        return $cells;
                    }, $page);
                },
            ));
        }

        $mine = $tab === 'mine';

        return \Pramnos\Http\Response::json(\Pramnos\Html\Datatable\ArraySource::answer(
            $mine ? $library['mine'] : $library['segments'],
            $mine ? [0 => 'name', 1 => 'members', 2 => 'entered', 3 => 'left', 5 => 'recomputedAt']
                : [0 => 'name', 1 => 'members', 2 => 'entered', 3 => 'left', 5 => 'ownerName', 6 => 'recomputedAt'],
            static fn (array $row): string => '#' . $row['id'] . ' ' . $row['name'] . ' ' . $row['description'],
            static fn (array $page): array => array_map(static function (array $row) use ($e, $none, $number, $uses, $name, $members, $mine, $actions, $withActions): array {
                $found = SegmentStore::uses($row['id']);
                $cells = [
                    $name($row),
                    $members($row),
                    $number($row['entered'], 'pf-up'),
                    $number($row['left'], 'pf-down'),
                    $uses($found),
                ];
                if (!$mine) {
                    $cells[] = $row['ownerName'] !== '' ? '<span class="pf-nowrap">' . $e($row['ownerName']) . '</span>' : $none;
                }
                $cells[] = $row['recomputedAt'] > 0 ? '<span class="pf-nowrap">' . $e(date('d/m/Y H:i', $row['recomputedAt'])) . '</span>' : $none;
                if ($withActions) {
                    $cells[] = $actions('Segments', $row['id'], $found);
                }

                return $cells;
            }, $page),
        ));
    }

    /**
     * The tabs shown: `audience.tabs`, in the library's order, or all four.
     *
     * @return non-empty-list<string>
     */
    protected function tabs(): array
    {
        $chosen = (array) ($this->application->applicationInfo['audience']['tabs'] ?? self::TABS);
        $tabs   = array_values(array_intersect(self::TABS, $chosen));

        return $tabs === [] ? self::TABS : $tabs;
    }

    /**
     * The tab asked for — `?kind=all|groups|segments`, `?mine=1` — where it is shown, or
     * `audience.default_tab`, or the first shown.
     */
    protected function tab(): string
    {
        $tabs  = $this->tabs();
        $asked = (string) \Pramnos\Http\Request::staticGet('mine', '', 'get') === '1' ? 'mine' : (string) \Pramnos\Http\Request::staticGet('kind', '', 'get');
        if (in_array($asked, $tabs, true)) {
            return $asked;
        }
        $default = (string) ($this->application->applicationInfo['audience']['default_tab'] ?? 'groups');

        return in_array($default, $tabs, true) ? $default : $tabs[0];
    }

    /**
     * A tab's datatable: its own columns, picker and export name, the tab sent with every
     * request, and its first order — groups by their order, segments by most members.
     */
    protected function datatable(string $tab): \Pramnos\Html\Datatable
    {
        $table             = new \Pramnos\Html\Datatable('pf-' . $tab . '-table');
        $table->source     = adminUrl('Segments/data');
        $table->bootstrap  = false;
        $table->aoData     = [['name' => 'tab', 'value' => $tab]];
        $table->exportName = match ($tab) {
            'all'      => t('Groups and segments'),
            'groups'   => t('User groups'),
            'segments' => t('Audience segments'),
            default    => t('My segments'),
        };
        $columns = match ($tab) {
            'all'    => [t('Name'), t('Kind of audience'), t('Members in it'), t('Used by')],
            'groups' => ['#', t('Name'), t('Members in it'), t('Entered, 7 days'), t('Left, 7 days'), t('Order in the list'), t('Used by')],
            'mine'   => [t('Name'), t('Members in it'), t('Entered, 7 days'), t('Left, 7 days'), t('Used by'), t('Last worked out')],
            default  => [t('Name'), t('Members in it'), t('Entered, 7 days'), t('Left, 7 days'), t('Used by'), t('Owned by'), t('Last worked out')],
        };
        $usedBy = array_search(t('Used by'), $columns, true);
        foreach ($columns as $i => $label) {
            $table->addColumn(htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), true, $i !== $usedBy, false);
        }
        if ($tab === 'all' ? $this->rowActions('groups') || $this->rowActions('segments') : $this->rowActions($tab)) {
            $table->addColumn(htmlspecialchars(t('Row actions'), ENT_QUOTES, 'UTF-8'), ['bSortable' => false, 'bSearchable' => false, 'sClass' => 'pf-actions pf-no-export']);
        }
        // All: by kind, groups first, each in its own order; groups by their order; segments by most members.
        [$table->sortColumn, $table->sortOrder] = match ($tab) {
            'all'    => [1, 'asc'],
            'groups' => [5, 'asc'],
            default  => [1, 'desc'],
        };

        return $table;
    }

    /**
     * Whether a tab's rows carry open, edit and delete: `audience.row_actions` names the kinds
     * that do — `['groups']` unless set, `['groups', 'segments']` for both (the account's own
     * follow segments; in *all*, each row its own kind).
     */
    protected function rowActions(string $tab): bool
    {
        $kinds = (array) ($this->application->applicationInfo['audience']['row_actions'] ?? ['groups']);

        return in_array($tab === 'groups' ? 'groups' : 'segments', $kinds, true);
    }

    /**
     * The groups, by their order; the segments by name, with the week's movement counted for all
     * in two queries; the account's own; both, groups first; and how many each tab holds. Nothing per row worked out
     * yet beyond that.
     *
     * @return array{all: list<array<string, mixed>>, groups: list<array<string, mixed>>, segments: list<array<string, mixed>>, mine: list<array<string, mixed>>, counts: array{all: int, groups: int, segments: int, mine: int}}
     */
    protected function library(): array
    {
        $me       = $this->me();
        $all      = SegmentStore::all();
        $moved    = SegmentStore::movements();
        $owners   = $this->names(array_values(array_filter(array_column($all, 'owner'))));
        $segments = array_map(static fn (array $segment): array => ['id' => $segment['id'], 'name' => $segment['name'],
            'description' => $segment['description'], 'members' => $segment['recomputedAt'] === null ? -1 : $segment['members'],
            'entered' => $moved[$segment['id']]['entered'] ?? 0, 'left' => $moved[$segment['id']]['left'] ?? 0,
            'ownerName' => $owners[(int) $segment['owner']] ?? '', 'recomputedAt' => (int) $segment['recomputedAt'],
            'url' => adminUrl('Segments/view/') . $segment['id'], 'mine' => $segment['owner'] === $me], $all);
        $groupMoves = \Pramnos\Audience\Groups::movements();
        $groups     = array_map(static fn (array $group): array => $group + ['url' => Groups::linkFor($group['id']),
            'entered' => $groupMoves[$group['id']]['entered'] ?? 0, 'left' => $groupMoves[$group['id']]['left'] ?? 0], $this->groups());
        $mine   = array_values(array_filter($segments, static fn (array $row): bool => $row['mine']));

        return ['groups' => $groups, 'segments' => $segments, 'mine' => $mine,
            'all' => array_merge(array_map(static fn (array $row): array => $row + ['kind' => 'group', 'kindOrder' => 0], $groups),
                array_map(static fn (array $row): array => $row + ['kind' => 'segment', 'kindOrder' => 1], $segments)),
            'counts' => ['all' => count($groups) + count($segments), 'groups' => count($groups), 'segments' => count($segments), 'mine' => count($mine)]];
    }

    /**
     * Accounts' names, in one query.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    protected function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (\Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(Attributes::FROM)
            ->select(['users.userid', 'users.username', 'users.firstname', 'users.lastname'])
            ->whereIn('users.userid', array_values(array_unique($ids)))->getAllOrFail() as $row) {
            $names[(int) $row['userid']] = trim($row['firstname'] . ' ' . $row['lastname']) ?: (string) $row['username'];
        }

        return $names;
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
        $view->draft   = $segment === null ? $this->draft() : null;

        return $view->display('edit');
    }

    /**
     * A new segment's form filled in from `?draft=` — what Ask's `audience_draft` links to: a
     * name, a description and a filter, the filter checked; anything else is no draft.
     *
     * @return array{name: string, description: string, filter: array<string, mixed>}|null
     */
    protected function draft(): ?array
    {
        $draft = json_decode((string) \Pramnos\Http\Request::staticGet('draft', '', 'get'), true);
        if (!is_array($draft)) {
            return null;
        }
        try {
            $filter = Filter::fromArray((array) ($draft['filter'] ?? []))->toArray();
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ['name' => mb_substr(trim((string) ($draft['name'] ?? '')), 0, 150), 'description' => trim((string) ($draft['description'] ?? '')), 'filter' => $filter];
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
     * The groups with their member counts and order, by that order; none where groups are not
     * installed.
     *
     * @return list<array{id: int, name: string, description: string, order: ?int, orderKey: int, members: int}>
     */
    protected function groups(): array
    {
        try {
            return Groups::all();
        } catch (\Throwable) {
            return [];
        }
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
