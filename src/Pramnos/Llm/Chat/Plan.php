<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * A person's plan: one row a project — work, a hobby, the family, the house — kept by the assistant
 * from what the person says. On with `'llm' => ['chat' => ['plan' => true]]`, in the `plan` skill.
 *
 * It is a table artifact titled `Plan`, the person's own: every persona of the companion and the
 * administration's Ask read and change the same one. Its columns are {@see COLUMNS}; a project's
 * deadlines and next steps are to-dos with a date, not columns. The tools name a project, and the
 * code finds it — a change never lands on a project the person did not name.
 *
 * Every question carries the plan a line a project ({@see context()}); {@see TEMPLATES} offers a
 * weekday morning briefing and a Sunday review. What is finished is taken out, not kept.
 */
final class Plan
{
    /** The table's title. */
    public const TITLE = 'Plan';

    /** Its columns, in order. */
    public const COLUMNS = ['project', 'kind', 'phase', 'status', 'focus', 'blockers', 'updated'];

    /** Where a project stands. */
    public const PHASES = ['active', 'paused', 'waiting', 'done'];

    /** How many projects a plan holds at most. */
    public const MAX = 50;

    /** The routines it offers beside the application's own `routine_templates`, which override them by key. */
    public const TEMPLATES = [
        'plan_morning' => [
            'title'   => 'The plan, this morning',
            'prompt'  => 'Brief me on my plan in about a minute of speech: only what needs a decision today — a deadline today '
                . 'or past, a blocker, a project that has not moved for a week (its updated date). Do not list the projects. '
                . 'Close with one question.',
            'cadence' => 'weekdays',
            'at'      => '10:00',
        ],
        'plan_week' => [
            'title'   => 'The week\'s review',
            'prompt'  => 'Review my plan with me for the week: what moved, what is stuck, what is waiting on somebody, and the '
                . 'deadlines of the week ahead. Ask me about one project at a time, and change the plan only with what I say.',
            'cadence' => 'weekly',
            'day'     => 7,
            'at'      => '20:00',
        ],
    ];

    /** How the plan's tools are used, told in each of their descriptions. */
    private const RULES = 'Change the plan only with what the person says, never from what you infer. Change one field at a '
        . 'time, and confirm it in one sentence. Before closing a project, setting it done or paused, or when it is not '
        . 'clear which project they mean, ask first. Deadlines and next steps are to-dos with a date; a deadline past is '
        . 'closed or moved, never left overdue.';

    /** The person's, in their own conversations: every persona, and the Ask, read the same plan. */
    private readonly Scope $scope;

    public function __construct(Scope $scope, private readonly string $timezone = 'UTC')
    {
        // The person's — every persona's and the Ask's alike — and each profile's its own.
        $this->scope = $scope->inSpace(Scope::CHAT);
    }

    /** Whether the application turned it on, for a person's own conversations or the Ask. */
    public static function on(Scope $scope): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return $scope->userId > 0 && in_array($scope->space, [Scope::CHAT, Scope::ADMIN], true) && is_array($chat) && !empty($chat['plan']);
    }

    /**
     * The projects, each by its column, in the order kept.
     *
     * @return list<array<string, string>>
     */
    public function projects(): array
    {
        $found = $this->artifact();
        if ($found === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($found['content']['rows'] ?? []) as $row) {
            $out[] = array_combine(self::COLUMNS, array_map(static fn ($v): string => (string) ($v ?? ''), array_pad(array_slice(array_values((array) $row), 0, count(self::COLUMNS)), count(self::COLUMNS), '')));
        }

        return $out;
    }

    /**
     * The plan in a few words, for whoever looks after the person: how many projects in each
     * phase, which are blocked, which have not moved for a week.
     */
    public function summary(): string
    {
        $projects = $this->projects();
        if ($projects === []) {
            return 'Nothing in the plan yet.';
        }
        $phases = array_count_values(array_column($projects, 'phase'));
        $parts  = [count($projects) . ' in the plan: ' . implode(', ', array_map(static fn (string $p, int $n): string => $n . ' ' . $p, array_keys($phases), $phases))];
        $stuck  = array_column(array_filter($projects, static fn (array $p): bool => $p['blockers'] !== ''), 'project');
        if ($stuck !== []) {
            $parts[] = 'blocked: ' . implode(', ', $stuck);
        }
        $still = array_column(array_filter($projects, fn (array $p): bool => $p['phase'] === 'active' && $p['updated'] !== '' && $p['updated'] < (new \DateTimeImmutable('-7 days', new \DateTimeZone($this->timezone)))->format('Y-m-d')), 'project');
        if ($still !== []) {
            $parts[] = 'not moved for a week: ' . implode(', ', $still);
        }

        return implode('; ', $parts) . '.';
    }

    /** The plan for a question's context, a line a project; empty when there is none. */
    public function context(): string
    {
        $projects = $this->projects();
        if ($projects === []) {
            return '';
        }
        $lines = ['The person\'s plan, a project a line (change it with plan_update, plan_add, plan_close):'];
        foreach ($projects as $p) {
            $lines[] = '- ' . $p['project'] . ($p['kind'] !== '' ? ' (' . $p['kind'] . ')' : '') . ' — ' . $p['phase']
                . ($p['status'] !== '' ? '; ' . $p['status'] : '') . ($p['focus'] !== '' ? '; focus: ' . $p['focus'] : '')
                . ($p['blockers'] !== '' ? '; blocked by: ' . $p['blockers'] : '') . ($p['updated'] !== '' ? '; updated ' . $p['updated'] : '');
        }

        // The tools wait behind the skill: the news itself is the cue to load it and use them.
        $lines[] = 'When they tell you news about a project listed here, update it with plan_update (one field, usually status) '
            . 'and say so in one sentence; ask first only to close it, set it done or paused, or when it is unclear which project.';

        return implode("\n", $lines);
    }

    /**
     * `plan_add`, `plan_update`, `plan_close`, where artifacts can be kept.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        if (!Artifacts::available()) {
            return [];
        }
        $fields = array_values(array_diff(self::COLUMNS, ['project', 'updated']));

        return [
            'plan_add' => [
                'description' => 'Add a project to the person\'s plan: anything they are working on — work, a hobby, the family, the house. ' . self::RULES,
                'parameters'  => ['type' => 'object', 'properties' => [
                    'project'  => ['type' => 'string', 'description' => 'Its name, short.'],
                    'kind'     => ['type' => 'string', 'description' => 'work, hobby, family, home, or another word.'],
                    'phase'    => ['type' => 'string', 'enum' => self::PHASES],
                    'status'   => ['type' => 'string', 'description' => 'Where it stands, one line.'],
                    'focus'    => ['type' => 'string'],
                    'blockers' => ['type' => 'string'],
                ], 'required' => ['project']],
                'run'         => fn (array $a): string => $this->add($a),
                'writes'      => true,
                'tags'        => ['personal' => true],
            ],
            'plan_update' => [
                'description' => 'Change one field of a project in the person\'s plan. ' . self::RULES,
                'parameters'  => ['type' => 'object', 'properties' => [
                    'project' => ['type' => 'string', 'description' => 'The project\'s name as the plan has it.'],
                    'field'   => ['type' => 'string', 'enum' => $fields],
                    'value'   => ['type' => 'string', 'description' => 'phase: one of ' . implode(', ', self::PHASES) . '.'],
                ], 'required' => ['project', 'field', 'value']],
                'run'         => fn (array $a): string => $this->change((string) ($a['project'] ?? ''), (string) ($a['field'] ?? ''), (string) ($a['value'] ?? '')),
                'writes'      => true,
                'tags'        => ['personal' => true],
            ],
            'plan_close' => [
                'description' => 'Take a finished or dropped project out of the person\'s plan; nothing of it is kept. Ask first. ' . self::RULES,
                'parameters'  => ['type' => 'object', 'properties' => ['project' => ['type' => 'string']], 'required' => ['project']],
                'run'         => fn (array $a): string => $this->close((string) ($a['project'] ?? '')),
                'writes'      => true,
                'tags'        => ['personal' => true],
            ],
        ];
    }

    /**
     * A project added.
     *
     * @param array<string, mixed> $a
     */
    public function add(array $a): string
    {
        $name     = self::clean((string) ($a['project'] ?? ''));
        $projects = $this->projects();
        if ($name === '') {
            return 'Error: a project needs a name.';
        }
        if ($this->index($projects, $name) !== null) {
            return 'Error: the plan has ' . $name . ' already; change it with plan_update.';
        }
        if (count($projects) >= self::MAX) {
            return 'Error: the plan is full (' . self::MAX . ' projects); close one first.';
        }
        $phase = (string) ($a['phase'] ?? 'active');
        if (!in_array($phase, self::PHASES, true)) {
            return 'Error: phase is one of ' . implode(', ', self::PHASES) . '.';
        }
        $projects[] = ['project' => $name, 'kind' => self::clean((string) ($a['kind'] ?? '')), 'phase' => $phase,
            'status' => self::clean((string) ($a['status'] ?? '')), 'focus' => self::clean((string) ($a['focus'] ?? '')),
            'blockers' => self::clean((string) ($a['blockers'] ?? '')), 'updated' => $this->today()];

        return $this->keep($projects) ?? 'Added ' . $name . ' to the plan.';
    }

    /** One field of a project changed: the project must be in the plan. */
    public function change(string $project, string $field, string $value): string
    {
        $projects = $this->projects();
        $at       = $this->index($projects, $project);
        if ($at === null) {
            return $this->unknown($projects, $project);
        }
        if (!in_array($field, self::COLUMNS, true) || in_array($field, ['project', 'updated'], true)) {
            return 'Error: a field is one of kind, phase, status, focus, blockers.';
        }
        $value = self::clean($value);
        if ($field === 'phase' && !in_array($value, self::PHASES, true)) {
            return 'Error: phase is one of ' . implode(', ', self::PHASES) . '.';
        }
        $projects[$at][$field]    = $value;
        $projects[$at]['updated'] = $this->today();

        return $this->keep($projects) ?? 'Changed ' . $field . ' of ' . $projects[$at]['project'] . '.';
    }

    /** A project taken out: nothing of it is kept. */
    public function close(string $project): string
    {
        $projects = $this->projects();
        $at       = $this->index($projects, $project);
        if ($at === null) {
            return $this->unknown($projects, $project);
        }
        $name = $projects[$at]['project'];
        unset($projects[$at]);

        return $this->keep(array_values($projects)) ?? 'Closed ' . $name . '; it is out of the plan.';
    }

    /** The plan's artifact id, or null before its first project. */
    public function artifactId(): ?int
    {
        $found = $this->artifact();

        return $found === null ? null : (int) $found['artifact_id'];
    }

    /** The plan's table, or null before its first project. */
    private function artifact(): ?array
    {
        $artifacts = new Artifacts($this->scope);
        foreach ($artifacts->search(['kind' => 'table', 'query' => self::TITLE]) as $found) {
            if ($found['title'] === self::TITLE) {
                return $artifacts->find((int) $found['artifact_id']);
            }
        }

        return null;
    }

    /**
     * The projects written: the table made on the first, replaced after.
     *
     * @param list<array<string, string>> $projects
     * @return string|null An error, or null when kept
     */
    private function keep(array $projects): ?string
    {
        $artifacts = new Artifacts($this->scope);
        $content   = ['columns' => self::COLUMNS, 'rows' => array_map(static fn (array $p): array => array_values(array_merge(array_fill_keys(self::COLUMNS, ''), $p)), $projects)];
        $found     = $this->artifact();
        $done      = $found === null
            // A board by phase at first; the person may show it otherwise, and it stays so.
            ? $artifacts->create(['kind' => 'table', 'title' => self::TITLE, 'view' => ['type' => 'board', 'by' => 'phase']] + $content)
            : $artifacts->update((int) $found['artifact_id'], 'replace', $content);

        return is_string($done) ? 'Error: ' . $done : null;
    }

    /** A project's place by its name, whatever the case; null for none. */
    private function index(array $projects, string $name): ?int
    {
        $name = mb_strtolower(self::clean($name));
        foreach ($projects as $i => $p) {
            if (mb_strtolower($p['project']) === $name) {
                return $i;
            }
        }

        return null;
    }

    /** What the model is told for a project the plan does not have: the ones it has. */
    private function unknown(array $projects, string $name): string
    {
        return 'Error: the plan has no project "' . self::clean($name) . '". '
            . ($projects === [] ? 'It is empty.' : 'It has: ' . implode(', ', array_column($projects, 'project')) . '. Ask which one they mean.');
    }

    /** Today, on the person's clock. */
    private function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('Y-m-d');
    }

    /** A value on one line, at most 300 characters. */
    private static function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, 300);
    }
}
