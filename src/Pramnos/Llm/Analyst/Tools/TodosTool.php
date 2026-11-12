<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Mcp\McpToolInterface;
use Pramnos\Todos\Todos;

/**
 * The to-do list for the assistant: who has what to do, by when. Its name is the application's
 * (`todos.tool`, `todos` by default); adding and changing is {@see TodosChangeTool}.
 */
final class TodosTool implements McpToolInterface
{
    /** The most one answer lists. */
    public const LIMIT = 100;

    /**
     * @param int         $adminId Who is asking; 0 is nobody signed in
     * @param object|null $as      The person asking, where the caller has them already
     */
    public function __construct(private readonly int $adminId = 0, private readonly ?object $as = null)
    {
    }

    /** The tool's name: `todos.tool`, else `todos`. */
    public function name(): string
    {
        return Todos::toolName();
    }

    public function description(): string
    {
        return 'The to-do list: what is to be done, for whom, by when. who: "me" (the default), "all", "team" (counts per person), '
            . 'a user id or a name. about: a page\'s path or a user id — the items about it. closed: true for those closed this week too. '
            . 'Each item: id, title, status (open, in_progress, done, dismissed), assigned_to and its name, due (YYYY-MM-DD), remind_at, overdue, link, kind (own, or the source\'s).';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'who'    => ['type' => 'string', 'description' => '"me", "all", "team", a user id or a name.'],
            'about'  => ['type' => 'string', 'description' => 'A page\'s path, or a user id.'],
            'closed' => ['type' => 'boolean'],
        ]];
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): mixed
    {
        $user = $this->as ?? ($this->adminId > 0 ? new \Pramnos\User\User($this->adminId) : null);
        if (!Todos::installed() || !Todos::can($user, 'read')) {
            return 'Error: the to-do list is not open to you.';
        }
        $tenant = Todos::tenantOf($user);
        if (!Todos::allowed($tenant)) {
            return 'Error: this plan has no to-dos.';
        }
        $me    = (int) $user->userid;
        $items = Todos::list($tenant, $me);
        if (empty($input['closed'])) {
            $items = array_filter($items, static fn (array $i): bool => in_array($i['status'], [Todos::OPEN, Todos::IN_PROGRESS], true));
        }
        $about = trim((string) ($input['about'] ?? ''));
        if ($about !== '') {
            $ids   = array_column(Todos::about(ctype_digit($about) ? '/' . $about : $about, $tenant, $me), 'id');
            $items = array_filter($items, static fn (array $i): bool => in_array($i['id'], $ids, true)
                || (ctype_digit($about) && $i['link'] !== null && preg_match('~/' . $about . '(\D|$)~', $i['link']) === 1));
        }
        $who = mb_strtolower(trim((string) ($input['who'] ?? 'me')));
        if ($who === 'team') {
            $team = [];
            foreach ($items as $i) {
                $key = (int) ($i['assigned_to'] ?? 0);
                $team[$key] ??= ['open' => 0, 'overdue' => 0];
                $team[$key]['open']++;
                $team[$key]['overdue'] += $i['due_at'] !== null && $i['due_at'] < time() ? 1 : 0;
            }
            $names = \Pramnos\Conversations\People::names(array_keys($team));

            return ['team' => array_map(static fn (int $id, array $c): array => ['user' => $id, 'name' => $id === 0 ? 'nobody in particular' : ($names[$id] ?? '#' . $id)] + $c,
                array_keys($team), $team)];
        }
        if ($who !== 'all') {
            $person = in_array($who, ['', 'me', 'εγώ', 'εμένα'], true) ? $me : self::person($who, $tenant);
            if (is_string($person)) {
                return 'Error: ' . $person;
            }
            $items = array_filter($items, static fn (array $i): bool => $i['assigned_to'] === $person);
        }
        $items = array_slice(array_values($items), 0, self::LIMIT);
        $names = \Pramnos\Conversations\People::names(array_column($items, 'assigned_to'));
        $zone  = \Pramnos\Metrics\Window::zoneOf($tenant);

        return ['me' => $me, 'items' => array_map(static fn (array $i): array => self::item($i, $names, $zone), $items)];
    }

    /**
     * An item as the assistant reads it.
     *
     * @param array<string, mixed> $i
     * @param array<int, string> $names
     * @return array<string, mixed>
     */
    public static function item(array $i, array $names, \DateTimeZone $zone): array
    {
        $day = $i['due_at'] !== null ? (new \DateTimeImmutable('@' . $i['due_at']))->setTimezone($zone)->format('Y-m-d') : null;

        return ['id' => $i['id'], 'title' => $i['title'], 'status' => $i['status'], 'assigned_to' => $i['assigned_to'] ?? 0,
            'assigned_name' => $i['assigned_to'] !== null ? ($names[$i['assigned_to']] ?? null) : null, 'due' => $day,
            'remind_at' => $i['remind_at'] !== null ? (new \DateTimeImmutable('@' . $i['remind_at']))->setTimezone($zone)->format('Y-m-d H:i') : null,
            'overdue' => $i['due_at'] !== null && $i['due_at'] < time() && in_array($i['status'], [Todos::OPEN, Todos::IN_PROGRESS], true),
            'link' => $i['link'], 'kind' => $i['kind'], 'private' => $i['private'], 'repeat' => $i['repeat'] ?? null];
    }

    /** A person by id or by one name among who may be given items; else why not. */
    public static function person(string $who, int $tenant): int|string
    {
        if (ctype_digit($who)) {
            return (int) $who;
        }
        $hits = array_keys(array_filter(Todos::people($tenant), static fn (string $name): bool => str_contains(mb_strtolower($name), mb_strtolower($who))));

        return count($hits) === 1 ? (int) $hits[0] : ($hits === [] ? 'nobody named "' . $who . '" may be given to-dos.' : 'more than one is named "' . $who . '".');
    }
}
