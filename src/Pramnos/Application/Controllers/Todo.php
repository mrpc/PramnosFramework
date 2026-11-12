<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Todos\Todos;

/**
 * `/admin/Todo` — the to-do list ({@see Todos}).
 *
 * Actions:
 *   - display() — `?view=mine` (the default), `everyone` or `team`: the items in their groups
 *                 (overdue, today, the next seven days, later, no day), the system's waiting for
 *                 nobody, and those closed this week; the team view counts each person's
 *   - add()     — POST: an item from a line, for someone, with a day and a reminder
 *   - change()  — POST, answered in JSON: status, assignee, day, reminder, title, private, order
 *   - delete()  — POST: one's own item
 *   - events()  — the calendar's feed (`?start=&end=`); move() — an item dragged to another day
 *
 * Opening it settles the person's to-do notices. Who may read and who may write is `todos.can`.
 */
class Todo extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.todos';

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'add', 'change', 'delete', 'events', 'move']);
        $this->addWriteAction(['add', 'change', 'delete', 'move']);
        parent::__construct($application);
    }

    /** The list, in its groups; or the team's counts. */
    public function display(): mixed
    {
        if ($this->refused('read')) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('To-dos');
        $user   = $this->user();
        $me     = (int) $user->userid;
        $tenant = Todos::tenantOf($user);
        $mode   = in_array($_GET['view'] ?? '', ['everyone', 'team', 'calendar'], true) ? (string) $_GET['view'] : 'mine';
        $items  = Todos::list($tenant, $me);
        Todos::seen($me);
        if ($mode === 'mine') {
            $items = array_values(array_filter($items, static fn (array $i): bool => $i['assigned_to'] === $me
                || ($i['assigned_to'] === null && ($i['created_by'] === $me || $i['kind'] !== 'own'))));
        }
        $people = Todos::people($tenant);
        $view   = $this->getView('todos', 'tpl');
        $view->mode     = $mode;
        $view->me       = $me;
        $view->groups   = Todos::groups($items, $tenant);
        $view->people   = $people;
        $view->names    = $people + $this->names($items);
        $view->team     = $mode === 'team' ? $this->team($items) : [];
        $view->writes   = Todos::can($user, 'write');
        $view->zone     = \Pramnos\Metrics\Window::zoneOf($tenant)->getName();
        $view->csrfField = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
        $view->urls     = $this->urls();

        return $view->display();
    }

    /** An item from the form: a line, someone, a day, a reminder. */
    public function add(): void
    {
        if ($this->refused('write')) {
            return;
        }
        $user = $this->user();
        try {
            Todos::add([
                'tenant'      => Todos::tenantOf($user),
                'title'       => (string) ($_POST['title'] ?? ''),
                'assigned_to' => (int) ($_POST['assigned_to'] ?? $user->userid),
                'due'         => (string) ($_POST['due'] ?? '') ?: null,
                'remind_at'   => $this->moment((string) ($_POST['remind'] ?? '')),
                'private'     => !empty($_POST['private']),
                'repeat'      => (string) ($_POST['repeat'] ?? ''),
            ], (int) $user->userid);
            $this->addMessage(t('Added.'));
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
        }
        $this->redirect($this->urls()['list'] . (isset($_POST['view']) ? '?view=' . rawurlencode((string) $_POST['view']) : ''));
    }

    /** A change in place, answered in JSON: the item as it now is, or why not. */
    public function change(): mixed
    {
        if ($this->refused('write', true)) {
            return $this->json(['error' => t('You do not have access to that screen.')], 403);
        }
        $user = $this->user();
        $id   = (int) ($_POST['id'] ?? 0);
        $item = Todos::get($id);
        if ($item === null || $item['tenant'] !== Todos::tenantOf($user)) {
            return $this->json(['error' => t('There is no such to-do.')], 404);
        }
        $changes = array_intersect_key($_POST, array_flip(['status', 'assigned_to', 'due', 'link', 'sort', 'title', 'private', 'repeat']));
        try {
            if (array_key_exists('remind', $_POST)) {
                $changes['remind_at'] = $this->moment((string) $_POST['remind']) ?? 0;
            }
            Todos::update($id, $changes, (int) $user->userid);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['todo' => Todos::get($id)]);
    }

    /** Delete one's own item. */
    public function delete(): void
    {
        if ($this->refused('write')) {
            return;
        }
        try {
            Todos::delete((int) ($_POST['id'] ?? 0), (int) $this->user()->userid);
            $this->addMessage(t('Deleted.'));
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
        }
        $this->redirect($this->urls()['list']);
    }

    /** The calendar's feed: the items with a day in the visible range. */
    public function events(): mixed
    {
        if ($this->refused('read', true)) {
            return $this->json([], 403);
        }
        $user = $this->user();

        return $this->json(Todos::feed(Todos::tenantOf($user), (int) $user->userid, (string) ($_GET['start'] ?? ''), (string) ($_GET['end'] ?? '')));
    }

    /** An item dragged to another day: `{id, start}` as JSON. */
    public function move(): mixed
    {
        if ($this->refused('write', true)) {
            return $this->json(['error' => t('You do not have access to that screen.')], 403);
        }
        $body = (array) json_decode(\Pramnos\Http\Request::rawBody(), true);
        $item = Todos::get((int) ($body['id'] ?? 0));
        if ($item === null || $item['tenant'] !== Todos::tenantOf($this->user())) {
            return $this->json(['error' => t('There is no such to-do.')], 404);
        }
        try {
            return $this->json(Todos::move($body, (int) $this->user()->userid));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Not someone who may read (or write) the list: the request stops here — a
     * page sent away, an answer in JSON left to the caller.
     */
    protected function refused(string $what, bool $quiet = false): bool
    {
        // Signed in already (every action is behind sign-in); `todos.can` says the rest, so an
        // organisation's member below the administration's floor may be let in.
        if (Todos::can($this->user(), $what) && Todos::allowed(Todos::tenantOf($this->user()))) {
            return false;
        }
        if (!$quiet) {
            $this->addError(t('You do not have access to that screen.'));
            $this->redirect(adminUrl(''));
        }

        return true;
    }

    /** The person signed in. */
    protected function user(): object
    {
        return \Pramnos\User\User::current() ?? (object) ['userid' => 0, 'usertype' => 0];
    }

    /** @return array<string, string> The screen's addresses */
    protected function urls(): array
    {
        $base = adminUrl('Todo');

        return ['list' => $base, 'add' => $base . '/add', 'change' => $base . '/change', 'delete' => $base . '/delete',
            'events' => $base . '/events', 'move' => $base . '/move'];
    }

    /** A JSON answer. @param array<mixed> $data */
    protected function json(array $data, int $status = 200): \Pramnos\Http\Response
    {
        \Pramnos\Framework\Factory::getDocument('json');

        return \Pramnos\Http\Response::json($data, $status);
    }

    /** A `datetime-local` value as a moment in the organisation's zone; null for none. */
    private function moment(string $value): ?int
    {
        if (trim($value) === '') {
            return null;
        }
        $at = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', trim($value), \Pramnos\Metrics\Window::zoneOf(Todos::tenantOf($this->user())));

        return $at === false ? throw new \InvalidArgumentException(t('A reminder is a day and a time.')) : $at->getTimestamp();
    }

    /**
     * The names of the people the items name, beside those who may be given items.
     *
     * @param list<array<string, mixed>> $items
     * @return array<int, string>
     */
    private function names(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_merge(array_column($items, 'assigned_to'), array_column($items, 'created_by')))));

        return $ids === [] ? [] : \Pramnos\Conversations\People::names($ids);
    }

    /**
     * Each person's counts, for the team view: open, overdue, done this week.
     *
     * @param list<array<string, mixed>> $items
     * @return array<int, array{open: int, overdue: int, done: int}>
     */
    private function team(array $items): array
    {
        $now  = time();
        $team = [];
        foreach ($items as $i) {
            $who = (int) ($i['assigned_to'] ?? 0);
            $team[$who] ??= ['open' => 0, 'overdue' => 0, 'done' => 0];
            if ($i['status'] === Todos::DONE) {
                $team[$who]['done']++;
            } elseif ($i['status'] !== Todos::DISMISSED) {
                $team[$who]['open']++;
                $team[$who]['overdue'] += $i['due_at'] !== null && $i['due_at'] < $now ? 1 : 0;
            }
        }
        uasort($team, static fn (array $a, array $b): int => $b['open'] <=> $a['open']);

        return $team;
    }
}
