<?php

declare(strict_types=1);

namespace Pramnos\Llm\Companion;

use Pramnos\Database\Instant;
use Pramnos\Llm\Chat\Scope;

/**
 * A person's own appointments and tasks, as the companion keeps them — `chat_calendar` — and the
 * tools it keeps them with.
 *
 * Times go in and come out on the person's clock (`Y-m-d H:i`, in `$timezone`) and are stored as
 * moments. An appointment that would overlap another is not made: the tool answers
 * `{"error": "conflict", "existing": …, "free_slots": [...]}` — the nearest free start before and
 * after it, the same length, between {@see DAY_STARTS} and {@see DAY_ENDS} — so the model can offer
 * them instead of saying it failed.
 *
 * Every change is announced to the person's private channel as `calendar.changed`, so a
 * `pf-calendar` with `data-channel` on an open page shows it at once. {@see feed()} and
 * {@see move()} are the two endpoints a `pf-calendar` takes (`data-events`, `data-update`).
 */
final class Calendar
{
    /** What an appointment lasts when nobody said. */
    public const MINUTES = 60;

    /** The hours a free slot is looked for in, on the person's clock. */
    public const DAY_STARTS = '08:00';
    public const DAY_ENDS   = '20:00';

    /** The step free slots are looked for at. */
    public const STEP = 30;

    /** What a list answers at most. */
    public const LIST = 100;

    /** Minutes before an appointment its reminder goes. */
    public const REMIND = 30;

    /** The event a page hears on a change. */
    public const CHANGED = 'calendar.changed';

    public function __construct(private readonly Scope $scope, private readonly string $timezone = 'UTC')
    {
    }

    /** Whether the table is there. */
    public static function available(): bool
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('chat_calendar');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Make an appointment, unless it overlaps one: then the conflict and two free starts.
     *
     * @return array<string, mixed>
     */
    public function create(string $title, string $start, ?string $end = null, bool $allDay = false, string $notes = ''): array
    {
        $title = trim($title);
        $from  = $this->moment($start);
        if ($title === '' || $from === null) {
            return ['error' => 'An appointment needs a title and a start, as Y-m-d H:i.'];
        }
        $to = $end !== null && $end !== '' ? $this->moment($end) : null;
        $to = $allDay ? $from + 86400 : ($to ?? $from + self::MINUTES * 60);
        if ($to <= $from) {
            return ['error' => 'It ends before it starts.'];
        }
        if (!$allDay && ($conflict = $this->conflict($from, $to)) !== null) {
            return $conflict;
        }

        $now = Instant::utc(time());
        $this->table()->insert($this->scope->columns() + [
            'kind' => 'event', 'title' => mb_substr($title, 0, 200), 'starts_at' => Instant::utc($from), 'ends_at' => Instant::utc($to),
            'all_day' => $allDay ? 1 : 0, 'notes' => $notes === '' ? null : $notes, 'done' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
        $this->announce();

        return ['created' => $this->find($id)];
    }

    /**
     * Change an appointment: its title, times or notes. A new time is checked for overlaps as a
     * new appointment is, leaving itself out.
     *
     * @param array<string, mixed> $changes title, start, end, all_day, notes
     * @return array<string, mixed>
     */
    public function update(int $id, array $changes): array
    {
        $item = $this->find($id, 'event');
        if ($item === null) {
            return ['error' => 'There is no appointment ' . $id . '.'];
        }
        $from = isset($changes['start']) ? $this->moment((string) $changes['start']) : $this->unix((string) $item['starts_at_utc']);
        $to   = isset($changes['end']) ? $this->moment((string) $changes['end']) : null;
        if ($from === null || (isset($changes['end']) && $to === null)) {
            return ['error' => 'A time is Y-m-d H:i.'];
        }
        $allDay = (bool) ($changes['all_day'] ?? $item['all_day']);
        // Moved without a new end: it keeps its length.
        $to ??= $from + ($this->unix((string) $item['ends_at_utc']) - $this->unix((string) $item['starts_at_utc']));
        if ($to <= $from) {
            return ['error' => 'It ends before it starts.'];
        }
        if (!$allDay && ($conflict = $this->conflict($from, $to, $id)) !== null) {
            return $conflict;
        }

        $this->scoped($id)->update(array_filter([
            'title'    => isset($changes['title']) ? mb_substr(trim((string) $changes['title']), 0, 200) : null,
            'notes'    => isset($changes['notes']) ? (string) $changes['notes'] : null,
        ], static fn ($v): bool => $v !== null) + [
            'starts_at' => Instant::utc($from), 'ends_at' => Instant::utc($to), 'all_day' => $allDay ? 1 : 0,
            'updated_at' => Instant::utc(time()),
        ]
            // A new time is a new reminder.
            + ($from !== $this->unix((string) $item['starts_at_utc']) ? ['alerted_at' => null] : []));
        $this->announce();

        return ['updated' => $this->find($id)];
    }

    /** Cancel an appointment, or drop a task. */
    public function delete(int $id): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        $this->scoped($id)->delete();
        $this->announce();

        return true;
    }

    /**
     * Appointments that overlap a span of days, on the person's clock: from the start of `$from`
     * to the end of `$to`.
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $from, string $to): array
    {
        $start = $this->moment($from . ' 00:00') ?? $this->moment($from);
        $end   = $this->moment($to . ' 00:00') ?? $this->moment($to);
        if ($start === null || $end === null) {
            return [];
        }

        return $this->between($start, $end + 86400);
    }

    /**
     * Make a task, due by a time or not.
     *
     * @return array<string, mixed>
     */
    public function addTask(string $title, ?string $due = null, string $notes = ''): array
    {
        $title = trim($title);
        $by    = $due !== null && $due !== '' ? $this->moment($due) : null;
        if ($title === '' || ($due !== null && $due !== '' && $by === null)) {
            return ['error' => 'A task needs a title; a due time is Y-m-d H:i.'];
        }
        $now = Instant::utc(time());
        $this->table()->insert($this->scope->columns() + [
            'kind' => 'task', 'title' => mb_substr($title, 0, 200), 'due_at' => $by === null ? null : Instant::utc($by),
            'notes' => $notes === '' ? null : $notes, 'done' => 0, 'all_day' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
        $this->announce();

        return ['created' => $this->find($id)];
    }

    /**
     * Change a task: its title, due time, notes, or whether it is done.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateTask(int $id, array $changes): array
    {
        if ($this->find($id, 'task') === null) {
            return ['error' => 'There is no task ' . $id . '.'];
        }
        $due = array_key_exists('due', $changes) && $changes['due'] !== null && $changes['due'] !== '' ? $this->moment((string) $changes['due']) : null;
        if (array_key_exists('due', $changes) && $changes['due'] !== null && $changes['due'] !== '' && $due === null) {
            return ['error' => 'A due time is Y-m-d H:i.'];
        }
        $this->scoped($id)->update(array_filter([
            'title' => isset($changes['title']) ? mb_substr(trim((string) $changes['title']), 0, 200) : null,
            'notes' => isset($changes['notes']) ? (string) $changes['notes'] : null,
            'done'  => isset($changes['done']) ? ((bool) $changes['done'] ? 1 : 0) : null,
        ], static fn ($v): bool => $v !== null) + (array_key_exists('due', $changes) ? ['due_at' => $due === null ? null : Instant::utc($due)] : [])
            + ['updated_at' => Instant::utc(time())]);
        $this->announce();

        return ['updated' => $this->find($id)];
    }

    /**
     * Tasks, open ones by default, those due soonest first and those with no due time last.
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(bool $done = false): array
    {
        $rows = $this->scope->apply($this->table())->where('kind', 'task')->where('done', $done ? 1 : 0)
            ->orderBy('due_at')->orderBy('item_id')->limit(self::LIST)->get();
        $out = [];
        while (($row = $rows->fetch()) !== null) {
            $out[] = $this->shape($row);
        }
        usort($out, static fn (array $a, array $b): int => [$a['due'] === null, $a['due']] <=> [$b['due'] === null, $b['due']]);

        return $out;
    }

    /** Whether the person is in an appointment at this moment. */
    public function busyAt(int $at): bool
    {
        foreach ($this->between($at, $at + 1) as $event) {
            if (!$event['all_day']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Appointments starting within `$minutes` of `$now` whose reminder has not gone, over every
     * person — for the routine that sends them. Answers each with its row's scope.
     *
     * @return list<array<string, mixed>>
     */
    public static function startingSoon(int $now, int $minutes): array
    {
        if (!self::available()) {
            return [];
        }
        $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_calendar')
            ->where('kind', 'event')->where('all_day', 0)->whereNull('alerted_at')
            ->where('starts_at', '>', Instant::utc($now))->where('starts_at', '<=', Instant::utc($now + $minutes * 60))
            ->orderBy('starts_at')->limit(500)->get();
        $out = [];
        while (($row = $rows->fetch()) !== null) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Mark an appointment's reminder as dealt with, so it is looked at once: sent now, or — held
     * by the person's quiet hours, the day's limit or being busy — passed, marked at the first
     * moment there is, so it neither goes later nor counts among the day's pings.
     */
    public static function alerted(int $id, bool $sent = true): void
    {
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_calendar')
            ->where('item_id', $id)->update(['alerted_at' => Instant::utc($sent ? time() : 1)]);
    }

    /**
     * The JSON feed a `pf-calendar` asks with the visible range: `[{id, title, start, end, allDay}]`.
     *
     * @return list<array<string, mixed>>
     */
    public function feed(string $start, string $end): array
    {
        $from = strtotime($start);
        $to   = strtotime($end);
        if ($from === false || $to === false) {
            return [];
        }

        return array_map(fn (array $e): array => [
            'id'     => $e['id'],
            'title'  => $e['title'],
            'start'  => $this->iso((string) $e['starts_at_utc']),
            'end'    => $this->iso((string) $e['ends_at_utc']),
            'allDay' => $e['all_day'],
        ], $this->between($from, $to));
    }

    /**
     * What a `pf-calendar`'s `data-update` receives — `{id, start, end, allDay}`, ISO times —
     * applied as {@see update()} does, overlaps refused.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function move(array $body): array
    {
        $local = fn ($iso): ?string => is_string($iso) && ($at = strtotime($iso)) !== false
            ? (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d H:i') : null;

        return $this->update((int) ($body['id'] ?? 0), array_filter([
            'start'   => $local($body['start'] ?? null),
            'end'     => $local($body['end'] ?? null),
            'all_day' => isset($body['allDay']) ? (bool) $body['allDay'] : null,
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * The model's tools. The ones that change something are `writes`, so they wait for the person
     * after somebody else's words and are made once per answer.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        if (!self::available()) {
            return [];
        }
        $time = ['type' => 'string', 'description' => 'Y-m-d H:i, on the person\'s clock (' . $this->timezone . ').'];
        $json = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE);

        return [
            'calendar_create_event' => [
                'description' => 'Make an appointment in the person\'s calendar. When it overlaps another, nothing is made: '
                    . 'the answer says which, and free_slots offers starts to suggest instead.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'], 'start' => $time, 'end' => ['description' => 'Optional; an hour after start when not given. ' . $time['description']] + $time,
                    'all_day' => ['type' => 'boolean'], 'notes' => ['type' => 'string'],
                ], 'required' => ['title', 'start']],
                'run'    => fn (array $a): string => $json($this->create((string) $a['title'], (string) $a['start'], isset($a['end']) ? (string) $a['end'] : null, !empty($a['all_day']), (string) ($a['notes'] ?? ''))),
                'writes' => true,
            ],
            'calendar_list_events' => [
                'description' => 'The person\'s appointments from one day to another, inclusive.',
                'parameters'  => ['type' => 'object', 'properties' => ['from' => ['type' => 'string', 'description' => 'Y-m-d'], 'to' => ['type' => 'string', 'description' => 'Y-m-d']], 'required' => ['from', 'to']],
                'run'      => fn (array $a): string => $json($this->events((string) $a['from'], (string) $a['to'])),
                'parallel' => true,
            ],
            'calendar_update_event' => [
                'description' => 'Change an appointment: move it, rename it, or change its notes. A new time that overlaps another is refused with free_slots.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'start' => $time, 'end' => $time, 'notes' => ['type' => 'string'],
                ], 'required' => ['id']],
                'run'    => fn (array $a): string => $json($this->update((int) $a['id'], array_diff_key($a, ['id' => 1]))),
                'writes' => true,
            ],
            'calendar_delete_event' => [
                'description' => 'Cancel an appointment.',
                'parameters'  => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
                'run'    => fn (array $a): string => $this->find((int) $a['id'], 'event') !== null && $this->delete((int) $a['id']) ? 'Cancelled.' : 'Error: there is no appointment ' . $a['id'] . '.',
                'writes' => true,
            ],
            'task_create' => [
                'description' => 'Add a task to the person\'s list, due by a time or not.',
                'parameters'  => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'due' => $time, 'notes' => ['type' => 'string']], 'required' => ['title']],
                'run'    => fn (array $a): string => $json($this->addTask((string) $a['title'], isset($a['due']) ? (string) $a['due'] : null, (string) ($a['notes'] ?? ''))),
                'writes' => true,
            ],
            'task_list' => [
                'description' => 'The person\'s open tasks, soonest due first; done ones with done: true.',
                'parameters'  => ['type' => 'object', 'properties' => ['done' => ['type' => 'boolean']]],
                'run'      => fn (array $a): string => $json($this->tasks(!empty($a['done']))),
                'parallel' => true,
            ],
            'task_update' => [
                'description' => 'Change a task: mark it done, rename it, or change when it is due.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'due' => $time, 'done' => ['type' => 'boolean'], 'notes' => ['type' => 'string'],
                ], 'required' => ['id']],
                'run'    => fn (array $a): string => $json($this->updateTask((int) $a['id'], array_diff_key($a, ['id' => 1]))),
                'writes' => true,
            ],
        ];
    }

    /**
     * The overlap with another appointment, and the nearest free starts before and after, or
     * null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private function conflict(int $from, int $to, ?int $except = null): ?array
    {
        $taken = array_values(array_filter($this->between($from, $to), static fn (array $e): bool => !$e['all_day'] && $e['id'] !== $except));
        if ($taken === []) {
            return null;
        }

        $day    = (new \DateTimeImmutable('@' . $from))->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d');
        $first  = (int) $this->moment($day . ' ' . self::DAY_STARTS);
        $last   = (int) $this->moment($day . ' ' . self::DAY_ENDS) - ($to - $from);
        $busy   = array_values(array_filter($this->between($first, $last + ($to - $from)), static fn (array $e): bool => !$e['all_day'] && $e['id'] !== $except));
        $before = null;
        $after  = null;
        for ($at = $first; $at <= $last; $at += self::STEP * 60) {
            $clear = true;
            foreach ($busy as $e) {
                if ($this->unix((string) $e['starts_at_utc']) < $at + ($to - $from) && $this->unix((string) $e['ends_at_utc']) > $at) {
                    $clear = false;
                    break;
                }
            }
            if ($clear && $at < $from) {
                $before = $at;
            }
            if ($clear && $at > $from && $after === null) {
                $after = $at;
            }
        }

        return [
            'error'      => 'conflict',
            'existing'   => $taken[0]['title'],
            'existing_at' => $taken[0]['start'],
            'free_slots' => array_values(array_map(fn (int $at): string => $this->local($at, 'H:i'), array_filter([$before, $after], static fn ($v): bool => $v !== null))),
        ];
    }

    /**
     * Appointments overlapping a span of moments, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function between(int $from, int $to): array
    {
        $rows = $this->scope->apply($this->table())->where('kind', 'event')
            ->where('starts_at', '<', Instant::utc($to))->where('ends_at', '>', Instant::utc($from))
            ->orderBy('starts_at')->limit(self::LIST)->get();
        $out = [];
        while (($row = $rows->fetch()) !== null) {
            $out[] = $this->shape($row);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function find(int $id, ?string $kind = null): ?array
    {
        $query = $this->scoped($id);
        if ($kind !== null) {
            $query->where('kind', $kind);
        }
        $row = $query->first();

        return $row && (int) $row->numRows > 0 ? $this->shape($row->fields) : null;
    }

    /**
     * A row as the model and the page read it: times on the person's clock.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $at = fn ($v): ?string => $v === null || $v === '' ? null : $this->local($this->unix((string) $v), 'Y-m-d H:i');

        return $row['kind'] === 'task'
            ? ['id' => (int) $row['item_id'], 'kind' => 'task', 'title' => (string) $row['title'], 'due' => $at($row['due_at']), 'done' => (bool) $row['done'], 'notes' => $row['notes']]
            : ['id' => (int) $row['item_id'], 'kind' => 'event', 'title' => (string) $row['title'], 'start' => $at($row['starts_at']), 'end' => $at($row['ends_at']),
                'all_day' => (bool) $row['all_day'], 'notes' => $row['notes'], 'starts_at_utc' => (string) $row['starts_at'], 'ends_at_utc' => (string) $row['ends_at']];
    }

    /** A time on the person's clock as a moment, or null. */
    private function moment(string $local): ?int
    {
        $local = trim($local);
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d'] as $format) {
            $at = \DateTimeImmutable::createFromFormat('!' . $format, $local, new \DateTimeZone($this->timezone));
            if ($at !== false && $at->format($format) === $local) {
                return $at->getTimestamp();
            }
        }

        return null;
    }

    /** A stored moment — with its offset on PostgreSQL, bare UTC on MySQL — as a Unix time. */
    private function unix(string $stored): int
    {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private function local(int $at, string $format): string
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($this->timezone))->format($format);
    }

    private function iso(string $stored): string
    {
        return $this->local($this->unix($stored), 'Y-m-d\TH:i:sP');
    }

    /** Tell an open page of the person's that their calendar changed. Best effort. */
    private function announce(): void
    {
        try {
            $manager = \Pramnos\Broadcasting\BroadcastingManager::currentInstance();
            if ($manager === null) {
                return;
            }
            $config = \Pramnos\Application\Application::currentInstance()?->applicationInfo['broadcasting'] ?? [];
            $manager->broadcast(\Pramnos\Broadcasting\RealtimeConfig::userChannel($this->scope->userId, is_array($config) ? $config : []), self::CHANGED, []);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Calendar change was not announced: ' . $e->getMessage(), 'broadcasting');
        }
    }

    private function scoped(int $id): \Pramnos\Database\QueryBuilder
    {
        return $this->scope->apply($this->table())->where('item_id', $id);
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_calendar');
    }
}
