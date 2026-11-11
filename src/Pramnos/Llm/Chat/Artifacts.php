<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * Results of an Ask conversation kept as objects of their own — a table, a list of records, a
 * text — so they can be opened, shared and acted on apart from the answer they came with.
 *
 * The model makes one with the `save_artifact` tool, giving the data itself rather than leaving
 * it to be read back out of its answer's Markdown. Made during a turn, an artifact is linked to
 * the conversation and the answer once both are stored ({@see link()}), and comes back with that
 * answer as `artifacts` on the message.
 *
 * A list names what its ids are (`entity`: `user`, a device, …), which is what later lets it be
 * turned into a group or acted on in bulk. Read and written within a {@see Scope}: a person sees
 * their own.
 *
 * ```php
 * $artifacts = new Artifacts($scope);
 * $made = $artifacts->create(['kind' => 'list', 'title' => 'Dormant buyers', 'entity' => 'user', 'ids' => [4, 9]]);
 * $artifacts->find($made['artifact_id']);   // with its content, or null when not theirs
 * ```
 */
final class Artifacts
{
    /** How much of one artifact a model reads at once ({@see asText()}), in characters. */
    public const READ = 20000;

    /** How much of each pinned artifact goes into every question's context. */
    public const PINNED_EACH = 2000;

    /** How much of all the pinned artifacts goes into every question's context. */
    public const PINNED_ALL = 8000;

    /** How many of a table's columns each of its rows shows in the pinned index. */
    public const KEY_COLUMNS = 3;

    /**
     * A limit as the application sets it — `'llm' => ['chat' => ['artifacts' => ['pinned_each' =>
     * …, 'pinned_all' => …, 'read' => …]]]` — or the default: {@see PINNED_EACH}, {@see PINNED_ALL},
     * {@see READ}.
     */
    public static function limit(string $name): int
    {
        $defaults = ['pinned_each' => self::PINNED_EACH, 'pinned_all' => self::PINNED_ALL, 'read' => self::READ];
        $given    = ((array) (((array) \Pramnos\Llm\Llm::config('chat', []))['artifacts'] ?? []))[$name] ?? null;

        return is_numeric($given) && (int) $given > 0 ? (int) $given : $defaults[$name];
    }

    /** Who else shares an artifact live ({@see shareLive()}). */
    public const MEMBERS = 'assist.chat_artifact_members';

    /** What a member may do: read it, or change it too. */
    public const ROLES = ['read', 'edit'];

    /** How an artifact can be changed ({@see update()}). */
    public const MODES = ['replace', 'append', 'patch'];

    /** What an artifact can be. */
    public const KINDS = ['table', 'list', 'text', 'chart'];

    /** Told on the person's private channel when one of theirs is changed or pinned: `{id, pinned?}`. */
    public const CHANGED = 'artifact.changed';

    /** How a chart may be drawn. */
    public const CHARTS = ['bar', 'line', 'pie', 'doughnut'];

    /** Labels, and series, a chart may hold. */
    public const CHART_LABELS = 100;

    public const CHART_SERIES = 10;

    /** Artifacts a person may pin to their dashboard. */
    public const PINS = 12;

    /** The user setting the pins are kept under. */
    public const PIN_SETTING = 'ai.pinned';

    /** Rows a table may hold. */
    public const ROWS = 1000;

    /** Columns a table may hold. */
    public const COLUMNS = 50;

    /** Ids a list may hold. */
    public const IDS = 10000;

    /** Characters a text may hold. */
    public const TEXT = 50000;

    /** Characters a title may hold, as the column. */
    public const TITLE = 200;

    /** @var list<int> Made during this turn and not linked to an answer yet */
    private array $pending = [];

    public function __construct(private readonly Scope $scope)
    {
    }

    /** Whether this installation can keep artifacts: their table exists. */
    public static function available(): bool
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('assist.chat_artifacts');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The model's tool for making one, or none when the installation cannot keep them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        if (!self::available()) {
            return [];
        }

        return ['save_artifact' => [
            'description' => 'Keep a result as an artifact the person can open, share and act on: a table, a list of records, or a text. '
                . 'Use it when the answer is a table of more than a few rows, a list of records (users, orders, devices…) the person may want to do something with, '
                . 'or a document they asked you to write — and when they ask to keep, share or export something. Give the data itself here; '
                . 'in the answer, say briefly what it holds rather than repeating it. A table holds up to ' . self::ROWS . ' rows: past that, '
                . 'or for rows read from the database rather than from a tool\'s answer, use export_csv where it is offered. Never both for the same rows.',
            'parameters'  => ['type' => 'object', 'properties' => [
                'kind'    => ['type' => 'string', 'enum' => self::KINDS],
                'title'   => ['type' => 'string', 'description' => 'A short name for it.'],
                'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'table: the column headings.'],
                'rows'    => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => ['string', 'number', 'boolean', 'null']]], 'description' => 'table: the rows, each a list of cells in column order.'],
                'entity'  => ['type' => 'string', 'description' => 'list: what the ids are, singular and lower case, e.g. "user".'],
                'subject' => ['type' => 'string', 'description' => 'The one record it is about, as entity:id — "user:123", "ticket:88" — so it shows on that record\'s screen.'],
                'view'    => ['type' => 'object', 'description' => 'table: how it is shown — type table, board (a column per value of `by`) or calendar (by a date column `by`).',
                    'properties' => ['type' => ['type' => 'string', 'enum' => self::VIEWS], 'by' => ['type' => 'string']]],
                'ids'     => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'description' => 'list: the records\' ids, exactly as the tools gave them.'],
                'text'    => ['type' => 'string', 'description' => 'text: the document, in Markdown — its parts as ## sections, another artifact held as a line [[artifact:ID]].'],
                'chart_type' => ['type' => 'string', 'enum' => self::CHARTS, 'description' => 'chart: how it is drawn.'],
                'labels'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'chart: the categories along the axis (or the slices). list: a readable name per id.'],
                'series'  => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'data' => ['type' => 'array', 'items' => ['type' => 'number']]]],
                    'description' => 'chart: each series, its numbers in the order of labels.'],
            ], 'required' => ['kind', 'title']],
            'run' => function (array $a): string {
                $made = $this->create($a);

                return is_string($made) ? 'Error: ' . $made : 'Saved as artifact ' . $made['artifact_id'] . '.';
            },
        ]] + $this->workspaceTools();
    }

    /**
     * The tools that work on artifacts kept earlier, in any conversation: read one, change it,
     * find them, pin one. A pinned artifact is in the context of every question that follows.
     *
     * @return array<string, array<string, mixed>>
     */
    private function workspaceTools(): array
    {
        $id = ['type' => 'integer', 'description' => 'The artifact\'s id.'];

        return [
            'read_artifact' => [
                'description' => 'Read an artifact this person kept, from any conversation, by its id or its title.',
                'parameters'  => ['type' => 'object', 'properties' => ['id' => $id, 'title' => ['type' => 'string', 'description' => 'Its title, or a part of it.']]],
                'run'         => function (array $a): string {
                    $found = $this->lookUp($a);

                    return $found === null ? 'Error: there is no such artifact.' : self::asText($found, self::limit('read'));
                },
            ],
            'update_artifact' => [
                'description' => 'Change an artifact this person kept. mode replace: give its kind\'s fields anew. '
                    . 'mode append: table rows, list ids (and labels), or text to add at the end. '
                    . 'mode patch: a new title; for a text, find (exactly once) and replace, or a section by its ## heading with its new text (a new one goes at the end); '
                    . 'for a list, remove ids; for a table, remove_rows by number from 1. A document holds another artifact as a line [[artifact:ID]].',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'id'          => $id,
                    'mode'        => ['type' => 'string', 'enum' => self::MODES],
                    'title'       => ['type' => 'string'],
                    'columns'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'rows'        => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => ['string', 'number', 'boolean', 'null']]]],
                    'ids'         => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
                    'labels'      => ['type' => 'array', 'items' => ['type' => 'string']],
                    'text'        => ['type' => 'string'],
                    'chart_type'  => ['type' => 'string', 'enum' => self::CHARTS],
                    'series'      => ['type' => 'array', 'items' => ['type' => 'object']],
                    'find'        => ['type' => 'string'],
                    'replace'     => ['type' => 'string'],
                    'remove'      => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
                    'remove_rows' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'section'     => ['type' => 'string', 'description' => 'text, mode patch: the ## heading of the section whose text this is.'],
                    'view'        => ['type' => 'object', 'description' => 'table, mode patch: how it is shown (type table, board or calendar; by a column).',
                        'properties' => ['type' => ['type' => 'string', 'enum' => self::VIEWS], 'by' => ['type' => 'string']]],
                ], 'required' => ['id', 'mode']],
                'run'         => function (array $a): string {
                    $changed = $this->update((int) ($a['id'] ?? 0), (string) ($a['mode'] ?? ''), $a);

                    return is_string($changed) ? 'Error: ' . $changed : 'Changed artifact ' . $changed['artifact_id'] . '.';
                },
            ],
            'list_artifacts' => [
                'description' => 'Find artifacts this person kept, newest change first, by kind, entity, tag or words in the title.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'kind'   => ['type' => 'string', 'enum' => self::KINDS],
                    'entity' => ['type' => 'string'],
                    'tag'    => ['type' => 'string'],
                    'query'  => ['type' => 'string', 'description' => 'Words in the title.'],
                    'limit'  => ['type' => 'integer', 'description' => 'At most 50.'],
                ]],
                'run'         => function (array $a): string {
                    $found  = $this->search($a, min(50, max(1, (int) ($a['limit'] ?? 20))));
                    $lines  = array_map(static fn (array $one): string => '#' . $one['artifact_id'] . ' ' . $one['kind'] . ': ' . $one['title']
                        . ($one['entity'] !== null ? ' (' . $one['entity'] . ')' : '') . ($one['tags'] !== [] ? ' [' . implode(', ', $one['tags']) . ']' : '')
                        . ', ' . substr($one['updated_at'], 0, 10), $found);
                    // What others share live with them, by words in the title where asked.
                    $words  = mb_strtolower(trim((string) ($a['query'] ?? '')));
                    foreach ($this->sharedWithMe() as $one) {
                        if (($words === '' || str_contains(mb_strtolower($one['title']), $words)) && (!isset($a['kind']) || $a['kind'] === $one['kind'])) {
                            $lines[] = '#' . $one['artifact_id'] . ' ' . $one['kind'] . ': ' . $one['title'] . ' (shared with them by ' . Shares::nameOf($one['owner']) . ', ' . $one['role'] . ')';
                        }
                    }

                    return $lines === [] ? 'No artifacts found.' : implode("\n", $lines);
                },
            ],
        ] + ($this->scope->space === Scope::ADMIN && !empty(((array) \Pramnos\Llm\Llm::config('analyst', []))['sql']) ? [
            'save_query' => [
                'description' => 'Save a read-only SELECT as a query to run again — its result a table anyone it is shared with can refresh, '
                    . 'pinned live, or run in a scheduled task. Only when the person asks to keep or repeat a query. Run it with sql first.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'title'   => ['type' => 'string'],
                    'sql'     => ['type' => 'string', 'description' => 'The SELECT, as it ran.'],
                    'limit'   => ['type' => 'integer', 'description' => 'Rows, at most ' . self::ROWS . '.'],
                    'subject' => ['type' => 'string', 'description' => 'The one record it is about, entity:id, if any.'],
                ], 'required' => ['title', 'sql']],
                'run'         => function (array $a): string {
                    $made = $this->saveQuery((string) ($a['title'] ?? ''), (string) ($a['sql'] ?? ''), (int) ($a['limit'] ?? self::ROWS), isset($a['subject']) ? (string) $a['subject'] : null);

                    return is_string($made) ? 'Error: ' . $made : 'Saved as query ' . $made['artifact_id'] . ', ' . count($made['content']['rows']) . ' rows now.';
                },
                'writes'      => true,
            ],
        ] : []) + ($this->scope->space === Scope::ADMIN ? [
            'run_query' => [
                'description' => 'Run a saved query again and read its result as it is now.',
                'parameters'  => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
                'run'         => function (array $a): string {
                    $ran = $this->runQuery((int) ($a['id'] ?? 0));

                    return is_string($ran) ? 'Error: ' . $ran : self::asText($ran, self::limit('read'));
                },
            ],
        ] : []) + (self::sharing() ? [
            'share_artifact_live' => [
                'description' => 'Share one of this person\'s artifacts live — the same artifact, every change reaching everyone — with '
                    . 'people by username or email, or with their whole organisation; to read, or to change too. Only when they ask. '
                    . 'role read or edit; remove: true takes the people named off.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'id'           => $id,
                    'with'         => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Usernames or emails.'],
                    'role'         => ['type' => 'string', 'enum' => self::ROLES],
                    'organization' => ['type' => 'boolean', 'description' => 'Everyone in their organisation.'],
                    'remove'       => ['type' => 'boolean'],
                ], 'required' => ['id']],
                'run'         => function (array $a): string {
                    $id = (int) ($a['id'] ?? 0);
                    if (!empty($a['remove'])) {
                        $names = SharePolicy::current()->recipients($this->scope, array_map('strval', (array) ($a['with'] ?? [])));
                        foreach ($names as $userId) {
                            $this->unshareLive($id, (int) $userId);
                        }
                        if (!empty($a['organization'])) {
                            $this->unshareLive($id, null);
                        }

                        return $this->own($id) === null ? 'Error: only its owner shares an artifact.' : 'Taken off.';
                    }
                    $done = $this->shareLive($id, array_map('strval', (array) ($a['with'] ?? [])), (string) ($a['role'] ?? 'read'), !empty($a['organization']));

                    return is_string($done) ? 'Error: ' . $done : 'Shared live' . ($done['shared'] !== [] ? ' with ' . implode(', ', $done['shared']) : '')
                        . (!empty($a['organization']) ? ($done['shared'] !== [] ? ' and' : '') . ' with the organisation' : '') . '.'
                        . ($done['unknown'] !== [] ? ' Nobody is called ' . implode(', ', $done['unknown']) . '.' : '');
                },
                'writes'      => true,
            ],
        ] : []) + [
            'pin_artifact' => [
                'description' => 'Pin an artifact, or take it off: a pinned one is on this person\'s dashboard and in front of you in every question that follows.',
                'parameters'  => ['type' => 'object', 'properties' => ['id' => $id, 'pinned' => ['type' => 'boolean']], 'required' => ['id']],
                'run'         => function (array $a): string {
                    $on  = (bool) ($a['pinned'] ?? true);
                    $why = $this->pin((int) ($a['id'] ?? 0), $on);

                    return $why !== null ? 'Error: ' . $why : ($on ? 'Pinned.' : 'Taken off.');
                },
            ],
        ];
    }

    /**
     * Change one of this person's artifacts — see the `update_artifact` tool for the modes. The
     * whole of the new content passes the checks a new one would. Answers it, or why not.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    public function update(int $artifactId, string $mode, array $input): array|string
    {
        $found = $this->find($artifactId);
        if ($found === null) {
            return 'There is no such artifact.';
        }
        if (($found['shared'] ?? null) === 'read') {
            return 'It is shared with you to read; only its owner or an editor changes it.';
        }
        if (!in_array($found['kind'], self::KINDS, true)) {
            return 'A ' . $found['kind'] . ' cannot be changed here.';
        }
        if (!in_array($mode, self::MODES, true)) {
            return 'Change it with one of: ' . implode(', ', self::MODES) . '.';
        }

        $kind   = $found['kind'];
        $old    = $found['content'];
        $title  = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? ''))) ?: $found['title'];
        $fields = match ($mode) {
            'replace' => ['entity' => $found['entity']] + $input,
            'append'  => self::appended($kind, $old, $input, (string) $found['entity']),
            default   => self::patched($kind, $old, $input, (string) $found['entity']),
        };
        if (is_string($fields)) {
            return $fields;
        }

        $content = match ($kind) {
            'table' => self::tableContent($fields),
            'list'  => self::listContent($fields),
            'chart' => self::chartContent($fields),
            default => self::textContent($fields),
        };
        if (is_string($content)) {
            return $content;
        }
        if ($kind === 'table') {
            // What a table keeps beside its rows — the query it came from, how it is shown — stays.
            $content += array_intersect_key($old, array_flip(['query', 'view']));
            if (array_key_exists('view', $input)) {
                $view = self::viewOf($input['view'], $content['columns']);
                if (is_string($view)) {
                    return $view;
                }
                $content['view'] = $view;
            }
        }

        // An editor changes the owner's artifact itself: the one row every member reads.
        (isset($found['shared']) ? $this->table() : $this->scope->apply($this->table()))->where('artifact_id', $artifactId)->update([
            'title'      => mb_substr($title, 0, self::TITLE),
            'content'    => (string) json_encode($content, JSON_UNESCAPED_UNICODE),
            'updated_at' => CallLog::instant(time()),
        ]);
        // An open page showing it redraws it now, for its owner and every member; the prompt has it from the next question.
        foreach ($this->audience($artifactId, (int) $found['userid']) as $userId) {
            \Pramnos\Broadcasting\BroadcastingManager::toUser($userId, self::CHANGED, ['id' => $artifactId]);
        }

        return $this->find($artifactId) ?? 'There is no such artifact.';
    }

    /**
     * This person's artifacts matching what is asked — kind, entity, a tag, words in the title —
     * newest change first, without their content.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, int $limit = 20): array
    {
        if (!self::available()) {
            return [];
        }
        $query = $this->scope->apply($this->table())
            ->select(['artifact_id', 'kind', 'title', 'entity', 'tags', 'conversation_id', 'created_at', 'updated_at'])
            ->whereIn('kind', self::KINDS);
        foreach (['kind', 'entity'] as $field) {
            if (trim((string) ($filters[$field] ?? '')) !== '') {
                $query->where($field, trim((string) $filters[$field]));
            }
        }
        if (trim((string) ($filters['query'] ?? '')) !== '') {
            $query->whereLike('title', '%' . trim((string) $filters['query']) . '%');
        }
        // ponytail: the tag is checked here, over the newest 500 — a tag in a column of its own if that stops being enough.
        $result = $query->orderBy('updated_at', 'desc')->orderBy('artifact_id', 'desc')->limit(500)->get();
        $tag    = trim((string) ($filters['tag'] ?? ''));
        $out    = [];
        while (($row = $result->fetch()) !== null && count($out) < max(1, $limit)) {
            $one = self::shape($row + ['userid' => $this->scope->userId, 'message_id' => null, 'content' => '']);
            if ($tag === '' || in_array($tag, $one['tags'], true)) {
                $out[] = array_diff_key($one, ['content' => true, 'message_id' => true]) + ['updated_at' => (string) ($row['updated_at'] ?? $row['created_at'])];
            }
        }

        return $out;
    }

    /**
     * What this person pinned in this space, as text for the context of every question: an index,
     * nothing left out without saying so. Each artifact is whole where it fits its share
     * (`pinned_each`, {@see limit()}); a table that does not is one line a row, its first
     * {@see KEY_COLUMNS} columns; other text is cut. Once all of them (`pinned_all`) are spent, the
     * rest are a line each. Every cut says how to read the whole. Empty when nothing is pinned here.
     */
    public function pinnedContext(): string
    {
        if (!self::available()) {
            return '';
        }
        $user = new \Pramnos\User\User($this->scope->userId);
        if ((int) ($user->userid ?? 0) !== $this->scope->userId) {
            return '';
        }
        $parts = [];
        $left  = self::limit('pinned_all');
        foreach (self::pins($user) as $key) {
            [$id, $space, $organization] = self::pinParts((string) $key);
            if ($space !== $this->pinSpace() || $organization !== (string) ($this->scope->organizationId ?? '')) {
                continue;
            }
            $found = $this->find((int) $id);
            if ($found === null) {
                continue;
            }
            // A saved query pinned is live: run again where its result has grown old.
            $found = $this->fresh($found);
            $share = min(self::limit('pinned_each'), $left);
            // No room left: still named, so the model knows it is there.
            $text    = $share < 200 ? self::indexLine($found) : self::pinnedText($found, $share);
            $left   -= mb_strlen($text);
            $parts[] = $text;
        }

        return $parts === [] ? '' : "What this person pinned, as it stands now (read_artifact for the whole of one):\n\n" . implode("\n\n", $parts);
    }

    /** An artifact in a line: its id, kind, title and size, and how to read it. */
    private static function indexLine(array $artifact): string
    {
        $c    = (array) ($artifact['content'] ?? []);
        $size = match ((string) $artifact['kind']) {
            'table' => count((array) ($c['rows'] ?? [])) . ' rows',
            'list'  => count((array) ($c['ids'] ?? [])) . ' items',
            default => mb_strlen(self::asText($artifact, PHP_INT_MAX)) . ' characters',
        };

        return '#' . (int) $artifact['artifact_id'] . ' ' . $artifact['kind'] . ': ' . $artifact['title'] . ' (' . $size
            . '; not shown here: read_artifact ' . (int) $artifact['artifact_id'] . ')';
    }

    /**
     * A pinned artifact in its share: whole where it fits; a table one line a row, its key columns,
     * as many rows as fit; anything else cut, saying so.
     */
    private static function pinnedText(array $artifact, int $share): string
    {
        $whole = self::asText($artifact, PHP_INT_MAX);
        if (mb_strlen($whole) <= $share || (string) $artifact['kind'] !== 'table') {
            return mb_strlen($whole) <= $share ? $whole : self::asText($artifact, $share);
        }
        $c     = (array) ($artifact['content'] ?? []);
        $rows  = array_values((array) ($c['rows'] ?? []));
        $cell  = static fn ($v): string => $v === null ? '' : mb_substr(is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE), 0, 80);
        $line  = static fn (array $row): string => implode("\t", array_map($cell, array_slice(array_values($row), 0, self::KEY_COLUMNS)));
        $id    = (int) $artifact['artifact_id'];
        $text  = '#' . $id . ' table: ' . $artifact['title'] . ' (' . count($rows) . ' rows, its first ' . self::KEY_COLUMNS
            . ' columns; read_artifact ' . $id . " for the whole)\n" . $line((array) ($c['columns'] ?? []));
        $shown = 0;
        foreach ($rows as $row) {
            $next = "\n" . $line((array) $row);
            // Room kept for the line that says what is left.
            if (mb_strlen($text) + mb_strlen($next) > $share - 60) {
                break;
            }
            $text .= $next;
            $shown++;
        }

        return $shown < count($rows) ? $text . "\n[… " . (count($rows) - $shown) . ' more rows; read_artifact ' . $id . ']' : $text;
    }

    /**
     * An artifact as a model reads it: its id, kind and title, then its content, cut to `$limit`
     * characters with a line saying so.
     *
     * @param array<string, mixed> $artifact As {@see shape()} answers
     */
    public static function asText(array $artifact, int $limit = self::READ): string
    {
        $c    = (array) ($artifact['content'] ?? []);
        $cell = static fn ($v): string => $v === null ? '' : (is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE));
        $head = '#' . (int) $artifact['artifact_id'] . ' ' . $artifact['kind'] . ': ' . $artifact['title']
            . (($artifact['entity'] ?? null) !== null ? ' (' . $artifact['entity'] . ')' : '')
            . (($artifact['subject'] ?? null) !== null ? ' about ' . $artifact['subject'] : '')
            . (is_array($c['query'] ?? null) ? ' (a saved query, run ' . date('Y-m-d H:i', (int) ($c['query']['ran_at'] ?? 0)) . '; run_query to refresh)' : '');
        $body = match ((string) $artifact['kind']) {
            'table' => implode("\n", array_map(
                static fn (array $row): string => implode("\t", array_map($cell, $row)),
                [(array) ($c['columns'] ?? []), ...(array) ($c['rows'] ?? [])]
            )),
            'list'  => implode("\n", array_map(
                static fn ($id, $at): string => $id . (isset($c['labels'][$at]) ? "\t" . $c['labels'][$at] : ''),
                (array) ($c['ids'] ?? []),
                array_keys((array) ($c['ids'] ?? []))
            )),
            'chart' => ($c['chart'] ?? 'bar') . ' chart; labels: ' . implode(', ', (array) ($c['labels'] ?? [])) . "\n" . implode("\n", array_map(
                static fn (array $one): string => ($one['label'] ?? '') . ': ' . implode(', ', (array) ($one['data'] ?? [])),
                (array) ($c['series'] ?? [])
            )),
            default => (string) ($c['text'] ?? json_encode($c, JSON_UNESCAPED_UNICODE)),
        };
        $text = $head . "\n" . $body;
        $note = "\n[… cut here; read_artifact " . (int) $artifact['artifact_id'] . ' for the whole]';

        // The note counts within the limit, so what is pinned never goes past its share.
        return mb_strlen($text) > $limit ? mb_substr($text, 0, max(0, $limit - mb_strlen($note))) . $note : $text;
    }

    /**
     * One of this person's artifacts by id, or the newest whose title is the one given — or,
     * failing that, holds it.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>|null
     */
    private function lookUp(array $a): ?array
    {
        if ((int) ($a['id'] ?? 0) > 0) {
            return $this->find((int) $a['id']);
        }
        $title = mb_strtolower(trim((string) ($a['title'] ?? '')));
        if ($title === '') {
            return null;
        }
        $candidates = $this->search(['query' => $title], 50);
        $exact      = array_values(array_filter($candidates, static fn (array $one): bool => mb_strtolower($one['title']) === $title));
        $best       = $exact[0] ?? $candidates[0] ?? null;

        return $best === null ? null : $this->find($best['artifact_id']);
    }

    /**
     * The fields of an artifact with more added at its end.
     *
     * @param array<string, mixed> $old   Its content
     * @param array<string, mixed> $input What to add
     * @return array<string, mixed>|string
     */
    private static function appended(string $kind, array $old, array $input, string $entity): array|string
    {
        return match ($kind) {
            'table' => ['columns' => $old['columns'] ?? [], 'rows' => [...(array) ($old['rows'] ?? []), ...(array) ($input['rows'] ?? [])]],
            'list'  => ['entity' => $entity,
                'ids'    => [...(array) ($old['ids'] ?? []), ...(array) ($input['ids'] ?? [])],
                'labels' => [...(array) ($old['labels'] ?? []), ...(array) ($input['labels'] ?? [])]],
            'text'  => ['text' => rtrim((string) ($old['text'] ?? '')) . "\n\n" . trim((string) ($input['text'] ?? ''))],
            default => 'A chart is changed whole: use mode replace.',
        };
    }

    /**
     * The fields of an artifact with a part changed — see the `update_artifact` tool.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    private static function patched(string $kind, array $old, array $input, string $entity): array|string
    {
        // A document's section: its body replaced under its `## ` heading, or a new section at the end.
        if ($kind === 'text' && isset($input['section'])) {
            $text    = (string) ($old['text'] ?? '');
            $heading = trim((string) preg_replace('/\s+/u', ' ', (string) $input['section']));
            $body    = trim((string) ($input['text'] ?? ''));
            if ($heading === '') {
                return 'A section is named by its heading.';
            }
            $pattern = '/^##[ \t]+' . preg_quote($heading, '/') . '[ \t]*$\n?(.*?)(?=^##[ \t]|\z)/msu';
            $count   = 0;
            $changed = preg_replace_callback($pattern, static fn (array $m): string => '## ' . $heading . "\n\n" . $body . "\n\n", $text, 1, $count);

            return ['text' => $count === 1 ? rtrim((string) $changed) : rtrim($text) . "\n\n## " . $heading . "\n\n" . $body];
        }
        if ($kind === 'text' && isset($input['find'])) {
            $text = (string) ($old['text'] ?? '');
            $find = (string) $input['find'];
            if ($find === '' || substr_count($text, $find) !== 1) {
                return 'What to find must be in the text exactly once.';
            }

            return ['text' => str_replace($find, (string) ($input['replace'] ?? ''), $text)];
        }
        if ($kind === 'list' && isset($input['remove'])) {
            $remove = array_map('strval', (array) $input['remove']);
            $keep   = array_filter((array) ($old['ids'] ?? []), static fn ($id): bool => !in_array((string) $id, $remove, true));

            return ['entity' => $entity, 'ids' => array_values($keep),
                'labels' => array_values(array_intersect_key((array) ($old['labels'] ?? []), $keep))];
        }
        if ($kind === 'table' && isset($input['remove_rows'])) {
            $drop = array_map(static fn ($n): int => (int) $n - 1, (array) $input['remove_rows']);

            return ['columns' => $old['columns'] ?? [], 'rows' => array_values(array_diff_key((array) ($old['rows'] ?? []), array_flip($drop)))];
        }
        // Only how it is shown: the content as it was.
        if ($kind === 'table' && isset($input['view'])) {
            return $old;
        }
        if (trim((string) ($input['title'] ?? '')) === '') {
            return 'Nothing to change: give a new title, or what to change in it.';
        }

        // A new title alone: the content as it was.
        return match ($kind) {
            'list'  => ['entity' => $entity] + $old,
            'chart' => ['chart_type' => $old['chart'] ?? 'bar'] + $old,
            default => $old,
        };
    }

    /**
     * Make an artifact from what the model or the application gave. Answers it, or why not.
     *
     * @param array<string, mixed> $input kind, title, and the kind's fields
     * @return array<string, mixed>|string
     */
    public function create(array $input): array|string
    {
        $made = self::made($input);
        if (is_string($made)) {
            return $made;
        }
        [$kind, $title, $content] = $made;
        if ($kind === 'table' && isset($input['view'])) {
            $view = self::viewOf($input['view'], $content['columns']);
            if (is_string($view)) {
                return $view;
            }
            $content['view'] = $view;
        }

        return $this->keep($kind, $title, $kind === 'list' ? (string) $input['entity'] : null, $content, self::subjectOf($input['subject'] ?? null));
    }

    /**
     * What is wrong with an artifact as {@see create()} would take it, or null: checked, not kept.
     *
     * @param array<string, mixed> $input
     */
    public static function check(array $input): ?string
    {
        $made = self::made($input);

        return is_string($made) ? $made : null;
    }

    /**
     * An artifact's kind, title and content from what was given, or why not.
     *
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string, 2: array<string, mixed>}|string
     */
    private static function made(array $input): array|string
    {
        $kind  = (string) ($input['kind'] ?? '');
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? '')));
        if (!in_array($kind, self::KINDS, true)) {
            return 'An artifact is one of: ' . implode(', ', self::KINDS) . '.';
        }
        if ($title === '') {
            return 'An artifact needs a title.';
        }

        $content = match ($kind) {
            'table' => self::tableContent($input),
            'list'  => self::listContent($input),
            'chart' => self::chartContent($input),
            default => self::textContent($input),
        };

        return is_string($content) ? $content : [$kind, $title, $content];
    }

    /**
     * Keep an artifact whose content is already checked — the kinds above, or one of the
     * framework's own (a proposal) — as made during this turn, to be linked with its answer.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function keep(string $kind, string $title, ?string $entity, array $content, ?string $subject = null): array
    {
        $stamp = CallLog::instant(time());
        $row   = $this->scope->columns() + [
            'kind'       => $kind,
            'title'      => mb_substr($title, 0, self::TITLE),
            'entity'     => $entity,
            'content'    => (string) json_encode($content, JSON_UNESCAPED_UNICODE),
            'created_at' => $stamp,
            'updated_at' => $stamp,
        // Written only when given: an installation that has not run the migration adding it keeps every other.
        ] + ($subject === null ? [] : ['subject' => $subject]);
        $this->table()->insert($row);
        $id = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
        $this->pending[] = $id;

        return self::shape(['artifact_id' => $id, 'conversation_id' => null, 'message_id' => null, 'tags' => null] + $row);
    }

    /**
     * Attach what this turn made to the conversation and the answer it came with, and carry the
     * conversation's data tags. Answers those artifacts.
     *
     * @param list<string> $tags
     * @return list<array<string, mixed>>
     */
    public function link(int $conversationId, int $messageId, array $tags = []): array
    {
        if ($this->pending === []) {
            return [];
        }
        $this->table()->whereIn('artifact_id', $this->pending)->update([
            'conversation_id' => $conversationId,
            'message_id'      => $messageId,
            'tags'            => $tags === [] ? null : json_encode(array_values($tags)),
        ]);
        $this->pending = [];

        return $this->forConversation($conversationId)[$messageId] ?? [];
    }

    /**
     * A conversation's artifacts, by the answer each came with.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function forConversation(int $conversationId): array
    {
        if (!self::available()) {
            return [];
        }
        $result = $this->scope->apply($this->table())->where('conversation_id', $conversationId)->orderBy('artifact_id')->get();
        $out    = [];
        while (($row = $result->fetch()) !== null) {
            $out[(int) $row['message_id']][] = self::shape($row);
        }

        return $out;
    }

    /**
     * One artifact with its content, or null when there is none of this person's.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $artifactId): ?array
    {
        if (!self::available()) {
            return null;
        }

        return $this->own($artifactId) ?? $this->shared($artifactId);
    }

    /** One of this person's own artifacts, in this space; null for one that is not theirs. */
    private function own(int $artifactId): ?array
    {
        $result = $this->scope->apply($this->table())->where('artifact_id', $artifactId)->first();

        return $result && (int) $result->numRows > 0 ? self::shape($result->fields) : null;
    }

    /**
     * One shared with this person live ({@see shareLive()}), the same artifact as its owner's, with
     * `shared` — their role — and `owner`; null where they are no member.
     */
    private function shared(int $artifactId): ?array
    {
        $role = $this->roleIn($artifactId);
        if ($role === null) {
            return null;
        }
        $result = $this->table()->where('artifact_id', $artifactId)->first();

        return $result && (int) $result->numRows > 0 ? self::shape($result->fields) + ['shared' => $role, 'owner' => (int) $result->fields['userid']] : null;
    }

    /** This person's role in an artifact shared with them — edit before read — or null. */
    private function roleIn(int $artifactId): ?string
    {
        if (!self::sharing() || $this->scope->userId <= 0) {
            return null;
        }
        $user  = $this->scope->userId;
        $org   = $this->scope->organizationId;
        // A member by name, or by their organisation.
        $roles = array_column($this->members()->select(['role'])->where('artifact_id', $artifactId)
            ->where(static function ($q) use ($user, $org): void {
                $q->where('userid', $user);
                if ($org !== null) {
                    $q->orWhere(static fn ($o) => $o->whereNull('userid')->where('organization_id', $org));
                }
            })->getAllOrFail(), 'role');

        return $roles === [] ? null : (in_array('edit', $roles, true) ? 'edit' : 'read');
    }

    /**
     * This person's artifacts, newest first, without their content.
     *
     * @return list<array{artifact_id: int, kind: string, title: string, entity: ?string, conversation_id: ?int, created_at: string}>
     */
    public function list(int $limit = 100): array
    {
        if (!self::available()) {
            return [];
        }
        $result = $this->scope->apply($this->table())
            ->select(['artifact_id', 'kind', 'title', 'entity', 'conversation_id', 'created_at'])
            ->orderBy('artifact_id', 'desc')->limit(max(1, $limit))->get();
        $out = [];
        while (($row = $result->fetch()) !== null) {
            $out[] = [
                'artifact_id'     => (int) $row['artifact_id'],
                'kind'            => (string) $row['kind'],
                'title'           => (string) $row['title'],
                'entity'          => $row['entity'] === null ? null : (string) $row['entity'],
                'conversation_id' => $row['conversation_id'] === null ? null : (int) $row['conversation_id'],
                'created_at'      => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /** Remove one of this person's artifacts. False when there was none. */
    public function delete(int $artifactId): bool
    {
        // Its owner's to delete; a member only stops being one (unshareLive).
        if (!self::available() || $this->own($artifactId) === null) {
            return false;
        }
        $this->scope->apply($this->table())->where('artifact_id', $artifactId)->delete();
        if (self::sharing()) {
            $this->members()->where('artifact_id', $artifactId)->delete();
        }
        Shares::forget(Shares::ARTIFACT, $artifactId);

        return true;
    }

    /** Remove what a conversation made, as the conversation goes. */
    public function deleteConversation(int $conversationId): void
    {
        if (!self::available()) {
            return;
        }
        $result = $this->scope->apply($this->table())->select(['artifact_id'])->where('conversation_id', $conversationId)->get();
        while (($row = $result->fetch()) !== null) {
            Shares::forget(Shares::ARTIFACT, (int) $row['artifact_id']);
        }
        $this->scope->apply($this->table())->where('conversation_id', $conversationId)->delete();
    }

    /**
     * A stored row as the screens and the API read it: content decoded, ids as integers.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shape(array $row): array
    {
        $content = json_decode((string) ($row['content'] ?? ''), true);
        $tags    = json_decode((string) ($row['tags'] ?? ''), true);

        return [
            'artifact_id'     => (int) $row['artifact_id'],
            'kind'            => (string) $row['kind'],
            'title'           => (string) $row['title'],
            'entity'          => ($row['entity'] ?? null) === null ? null : (string) $row['entity'],
            'content'         => is_array($content) ? $content : [],
            'conversation_id' => ($row['conversation_id'] ?? null) === null ? null : (int) $row['conversation_id'],
            'message_id'      => ($row['message_id'] ?? null) === null ? null : (int) $row['message_id'],
            'userid'          => (int) $row['userid'],
            'subject'         => ($row['subject'] ?? null) === null ? null : (string) $row['subject'],
            'tags'            => is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
            'created_at'      => (string) $row['created_at'],
        ];
    }

    /**
     * A table's content, or why it cannot be one.
     *
     * @param array<string, mixed> $input
     * @return array{columns: list<string>, rows: list<list<string|int|float|bool|null>>}|string
     */
    private static function tableContent(array $input): array|string
    {
        $columns = array_values(array_map(static fn ($c): string => mb_substr(trim((string) $c), 0, 200), (array) ($input['columns'] ?? [])));
        $rows    = array_values((array) ($input['rows'] ?? []));
        if ($columns === [] || count($columns) > self::COLUMNS) {
            return 'A table needs between 1 and ' . self::COLUMNS . ' columns.';
        }
        if ($rows === [] || count($rows) > self::ROWS) {
            return 'A table needs between 1 and ' . self::ROWS . ' rows.';
        }
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) > count($columns)) {
                return 'Each row is a list of cells, no more than there are columns.';
            }
            $cells = array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE), array_values($row));
            $clean[] = array_pad($cells, count($columns), null);
        }

        return ['columns' => $columns, 'rows' => $clean];
    }

    /**
     * A list's content, or why it cannot be one.
     *
     * @param array<string, mixed> $input
     * @return array{ids: list<int|string>, labels: list<string>}|string
     */
    private static function listContent(array $input): array|string
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', (string) ($input['entity'] ?? '')) !== 1) {
            return 'A list needs an entity: what its ids are, e.g. "user".';
        }
        $ids = [];
        foreach ((array) ($input['ids'] ?? []) as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            } elseif (is_string($id) && trim($id) !== '') {
                $ids[] = mb_substr(trim($id), 0, 200);
            }
        }
        $labels = array_values(array_map(static fn ($l): string => mb_substr((string) $l, 0, 200), (array) ($input['labels'] ?? [])));
        if ($ids === [] || count($ids) > self::IDS) {
            return 'A list needs between 1 and ' . self::IDS . ' ids.';
        }
        if ($labels !== [] && count($labels) !== count($ids)) {
            return 'Give one label per id, or none.';
        }
        if (count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            return 'A list names each id once.';
        }

        return ['ids' => $ids, 'labels' => $labels];
    }

    /**
     * A chart's content, or why it cannot be one: its kind, the labels, and each series' numbers
     * in the labels' order — what `Html\Chart` draws.
     *
     * @param array<string, mixed> $input
     * @return array{chart: string, labels: list<string>, series: list<array{label: string, data: list<float|int>}>}|string
     */
    private static function chartContent(array $input): array|string
    {
        $chart  = (string) ($input['chart_type'] ?? 'bar');
        $labels = array_values(array_map(static fn ($l): string => mb_substr((string) $l, 0, 100), (array) ($input['labels'] ?? [])));
        if (!in_array($chart, self::CHARTS, true)) {
            return 'A chart is one of: ' . implode(', ', self::CHARTS) . '.';
        }
        if ($labels === [] || count($labels) > self::CHART_LABELS) {
            return 'A chart needs between 1 and ' . self::CHART_LABELS . ' labels.';
        }
        $series = [];
        foreach (array_slice(array_values((array) ($input['series'] ?? [])), 0, self::CHART_SERIES + 1) as $one) {
            $data = array_values((array) ($one['data'] ?? []));
            if (count($data) !== count($labels) || array_filter($data, static fn ($v): bool => !is_int($v) && !is_float($v)) !== []) {
                return 'Each series gives one number per label.';
            }
            $series[] = ['label' => mb_substr((string) ($one['label'] ?? ''), 0, 100), 'data' => $data];
        }
        if ($series === [] || count($series) > self::CHART_SERIES) {
            return 'A chart needs between 1 and ' . self::CHART_SERIES . ' series.';
        }

        return ['chart' => $chart, 'labels' => $labels, 'series' => $series];
    }

    /**
     * An artifact as CSV — a table's rows, a list's ids and labels, a chart's numbers — or null for
     * one that is not rows. Spreadsheet programs open it; a cell that starts like a formula is
     * written as text, so a value somebody typed cannot run in the program that opens it.
     *
     * @param array<string, mixed> $artifact As {@see shape()} answers
     */
    public static function csv(array $artifact): ?string
    {
        $content = (array) ($artifact['content'] ?? []);
        $rows    = match ((string) ($artifact['kind'] ?? '')) {
            'table' => [(array) ($content['columns'] ?? []), ...array_map(static fn ($r): array => (array) $r, (array) ($content['rows'] ?? []))],
            'list'  => [['id', 'label'], ...array_map(static fn ($id, $i): array => [$id, ((array) ($content['labels'] ?? []))[$i] ?? ''],
                (array) ($content['ids'] ?? []), array_keys((array) ($content['ids'] ?? [])))],
            'chart' => [['', ...array_map(static fn (array $s): string => (string) $s['label'], (array) ($content['series'] ?? []))],
                ...array_map(static fn ($label, $i): array => [$label, ...array_map(static fn (array $s) => $s['data'][$i] ?? '', (array) ($content['series'] ?? []))],
                    (array) ($content['labels'] ?? []), array_keys((array) ($content['labels'] ?? [])))],
            default => null,
        };
        if ($rows === null) {
            return null;
        }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, array_map(static function ($cell): string {
                $text = is_bool($cell) ? ($cell ? 'true' : 'false') : (string) $cell;

                return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($text) ? "'" . $text : $text;
            }, $row), ',', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** A file name for an artifact's export: its title, made safe, and the extension. */
    public static function filename(array $artifact, string $extension): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', (string) ($artifact['title'] ?? 'artifact')), '-');

        return ($base !== '' ? mb_substr($base, 0, 60) : 'artifact') . '-' . (int) ($artifact['artifact_id'] ?? 0) . '.' . $extension;
    }

    /**
     * Pin one of this person's artifacts to their dashboard, or take it off. Answers null, or why not.
     */
    public function pin(int $artifactId, bool $pinned = true): ?string
    {
        $user = new \Pramnos\User\User($this->scope->userId);
        if ((int) ($user->userid ?? 0) !== $this->scope->userId) {
            return 'There is nobody to pin it for.';
        }
        $pins = self::pins($user);
        $key  = $artifactId . '@' . $this->pinSpace() . '@' . ($this->scope->organizationId ?? '');
        if ($pinned) {
            if ($this->find($artifactId) === null) {
                return 'There is no such artifact.';
            }
            if (!in_array($key, $pins, true) && count($pins) >= self::PINS) {
                return 'Up to ' . self::PINS . ' pinned at a time; take one off first.';
            }
            $pins = array_values(array_unique([...$pins, $key]));
        } else {
            $pins = array_values(array_filter($pins, static fn (string $one): bool => $one !== $key));
        }
        $user->setSetting(self::PIN_SETTING, json_encode($pins));
        \Pramnos\Broadcasting\BroadcastingManager::toUser($this->scope->userId, self::CHANGED, ['id' => $artifactId, 'pinned' => $pinned]);

        return null;
    }

    /**
     * What a person pinned, each still there, newest pin last.
     *
     * @return list<array<string, mixed>>
     */
    public static function pinned(object $user): array
    {
        $out = [];
        foreach (self::pins($user) as $key) {
            [$id, $space, $organization] = self::pinParts((string) $key);
            $found = (new self(new Scope((int) $user->userid, $organization === '' ? null : (int) $organization, $space !== '' ? $space : Scope::CHAT)))->find((int) $id);
            if ($found !== null) {
                $out[] = $found;
            }
        }

        return $out;
    }

    /** @return list<int> The ids of what a person pinned — for a page that marks them. */
    public static function pinnedIds(object $user): array
    {
        return array_map(static fn (string $key): int => (int) strtok($key, '@'), self::pins($user));
    }

    /** @return list<string> A person's pins as kept: `id@space@organisation` */
    private static function pins(object $user): array
    {
        $raw  = method_exists($user, 'getSetting') ? $user->getSetting(self::PIN_SETTING, '[]') : '[]';
        $pins = json_decode(is_string($raw) ? $raw : (string) json_encode($raw), true);

        return is_array($pins) ? array_values(array_filter($pins, 'is_string')) : [];
    }

    /**
     * A text's content, or why it cannot be one.
     *
     * @param array<string, mixed> $input
     * @return array{text: string}|string
     */
    private static function textContent(array $input): array|string
    {
        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > self::TEXT) {
            return 'A text needs between 1 and ' . self::TEXT . ' characters.';
        }

        return ['text' => $text];
    }

    /**
     * A subject as kept, `entity:id`, or null for none or one that is not one.
     */
    public static function subjectOf(mixed $subject): ?string
    {
        $subject = trim((string) $subject);

        return preg_match('/^[a-z][a-z0-9_.-]{0,63}:[A-Za-z0-9_.-]{1,30}$/', $subject) === 1 ? $subject : null;
    }

    /**
     * What this person has about one record — their own, and what others share live with them —
     * newest change first, without content: for that record's own screen.
     *
     * @return list<array{artifact_id: int, kind: string, title: string, updated_at: string, shared: bool}>
     */
    public function about(string $entity, string|int $id): array
    {
        $subject = self::subjectOf($entity . ':' . $id);
        if ($subject === null || !self::available()) {
            return [];
        }
        try {
            $rows = $this->scope->apply($this->table())->select(['artifact_id', 'kind', 'title', 'updated_at'])->where('subject', $subject)
                ->orderBy('updated_at', 'desc')->limit(50)->getAllOrFail();
            $theirs = array_column($this->sharedWithMe(), 'artifact_id');
            $shared = $theirs === [] ? [] : $this->table()->select(['artifact_id', 'kind', 'title', 'updated_at'])->whereIn('artifact_id', $theirs)
                ->where('subject', $subject)->orderBy('updated_at', 'desc')->limit(50)->getAllOrFail();
        } catch (\Throwable) {
            // An installation without the subject column has nothing about any record.
            return [];
        }
        $out = [];
        foreach ([[$rows, false], [$shared, true]] as [$list, $isShared]) {
            foreach ($list as $row) {
                $out[] = ['artifact_id' => (int) $row['artifact_id'], 'kind' => (string) $row['kind'], 'title' => (string) $row['title'],
                    'updated_at' => (string) $row['updated_at'], 'shared' => $isShared];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['updated_at'], $a['updated_at']));

        return $out;
    }

    /** How a table may be shown: as rows, as a board in columns by one of its columns, or on a calendar by a date column. */
    public const VIEWS = ['table', 'board', 'calendar'];

    /**
     * A table's view as kept — `{type, by}`, `by` one of its columns; `{type: table}` for rows, said
     * outright so a table with a default view of its own (the plan) stays as rows — or why not.
     *
     * @param list<string> $columns
     * @return array{type: string, by: string}|string
     */
    public static function viewOf(mixed $view, array $columns): array|string
    {
        $view = is_array($view) ? $view : ['type' => (string) $view];
        $type = (string) ($view['type'] ?? 'table');
        if (!in_array($type, self::VIEWS, true)) {
            return 'A view is one of: ' . implode(', ', self::VIEWS) . '.';
        }
        if ($type === 'table') {
            return ['type' => 'table', 'by' => ''];
        }
        $by = (string) ($view['by'] ?? '');
        if (!in_array($by, $columns, true)) {
            return 'A ' . $type . ' goes by one of the columns: ' . implode(', ', $columns) . '.';
        }

        return ['type' => $type, 'by' => $by];
    }

    /**
     * How a table is shown, changed: by its owner or an editor.
     *
     * @param array<string, mixed> $view
     * @return array<string, mixed>|string
     */
    public function setView(int $artifactId, array $view): array|string
    {
        return $this->update($artifactId, 'patch', ['view' => $view]);
    }

    /** How long a saved query's result stands before a pinned one is run again, in seconds. */
    public const QUERY_STALE = 600;

    /**
     * Whether this person may write or change a saved query's SQL: the application's
     * `'llm' => ['analyst' => ['sql_writers' => fn (int $userId): bool]]` (or an ability), root
     * otherwise. Running a saved one needs only the artifact.
     */
    public static function writesSql(int $userId): bool
    {
        $hook = ((array) \Pramnos\Llm\Llm::config('analyst', []))['sql_writers'] ?? null;
        $user = new \Pramnos\User\User($userId);
        if (is_callable($hook)) {
            return (bool) $hook($userId);
        }
        if (is_string($hook) && $hook !== '') {
            return \Pramnos\Auth\AdminAccess::allows($user, $hook, \Pramnos\Auth\AdminAccess::defaultUsertype());
        }

        return (int) ($user->userid ?? 0) === $userId && \Pramnos\User\Roles::atLeast($user, \Pramnos\User\Roles::ROOT);
    }

    /**
     * A saved query: a table that keeps the read-only SELECT it came from, to run again
     * (`content.query` = `{sql, limit, ran_at}`). Written by whoever {@see writesSql()}; its result
     * is read as any table's.
     *
     * @return array<string, mixed>|string
     */
    public function saveQuery(string $title, string $sql, int $limit = 200, ?string $subject = null): array|string
    {
        if (!self::writesSql($this->scope->userId)) {
            return 'Only who may write SQL saves a query.';
        }
        $ran = self::runSql($sql, $limit);
        if (is_string($ran)) {
            return $ran;
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            return 'An artifact needs a title.';
        }
        // A query's result may be empty, as a table made by hand may not.
        return $this->keep('table', $title, null, ['columns' => $ran['columns'], 'rows' => $ran['rows'],
            'query' => ['sql' => trim($sql), 'limit' => $ran['limit'], 'ran_at' => time()]], self::subjectOf($subject));
    }

    /**
     * A saved query run again, its result kept — by anybody who may open the artifact, the SQL as
     * it was saved. With `$sql`, the statement changed first, by whoever {@see writesSql()} and may
     * change the artifact.
     *
     * @return array<string, mixed>|string
     */
    public function runQuery(int $artifactId, ?string $sql = null): array|string
    {
        $found = $this->find($artifactId);
        $query = $found === null ? null : ($found['content']['query'] ?? null);
        if (!is_array($query)) {
            return 'There is no such saved query.';
        }
        if ($sql !== null && trim($sql) !== trim((string) $query['sql'])) {
            if (!self::writesSql($this->scope->userId) || ($found['shared'] ?? null) === 'read') {
                return 'Only who may write SQL, and change this artifact, changes its query.';
            }
            $query['sql'] = trim($sql);
        }
        $ran = self::runSql((string) $query['sql'], (int) ($query['limit'] ?? 200));
        if (is_string($ran)) {
            return $ran;
        }
        $content = ['columns' => $ran['columns'], 'rows' => $ran['rows'], 'query' => ['sql' => (string) $query['sql'], 'limit' => $ran['limit'], 'ran_at' => time()]];
        // Shown as it was, where the column it goes by is still there.
        if (is_array($found['content']['view'] ?? null) && in_array($found['content']['view']['by'] ?? '', $ran['columns'], true)) {
            $content['view'] = $found['content']['view'];
        }
        // Its result, kept for everyone who reads it: a reader's run refreshes it as an owner's does.
        $this->table()->where('artifact_id', $artifactId)->update(['content' => (string) json_encode($content, JSON_UNESCAPED_UNICODE), 'updated_at' => CallLog::instant(time())]);
        foreach ($this->audience($artifactId, (int) $found['userid']) as $userId) {
            \Pramnos\Broadcasting\BroadcastingManager::toUser($userId, self::CHANGED, ['id' => $artifactId]);
        }

        return ['content' => $content] + $found;
    }

    /**
     * An artifact as it stands now: a saved query whose result is older than {@see QUERY_STALE}
     * run again first, so what is pinned is live. Anything else as it is.
     *
     * @param array<string, mixed> $artifact
     * @return array<string, mixed>
     */
    public function fresh(array $artifact): array
    {
        $query = $artifact['content']['query'] ?? null;
        if (!is_array($query) || (int) ($query['ran_at'] ?? 0) > time() - self::QUERY_STALE) {
            return $artifact;
        }
        $ran = $this->runQuery((int) $artifact['artifact_id']);

        return is_array($ran) ? $ran : $artifact;
    }

    /**
     * One read-only SELECT through the analyst's guard, its rows with credentials withheld.
     *
     * @return array{columns: list<string>, rows: list<list<mixed>>, limit: int}|string
     */
    private static function runSql(string $sql, int $limit): array|string
    {
        $limit = max(1, min(self::ROWS, $limit));
        $rows  = [];
        try {
            \Pramnos\Llm\Analyst\ReadOnlySql::each(\Pramnos\Framework\Factory::getDatabase(), $sql, $limit, static function (array $row) use (&$rows): void {
                $rows[] = \Pramnos\Llm\Analyst\ReadOnlySql::withhold($row);
            });
        } catch (\InvalidArgumentException $e) {
            return 'Refused: ' . $e->getMessage();
        } catch (\Throwable $e) {
            return 'The database refused it: ' . \Pramnos\Llm\Analyst\ReadOnlySql::said($e);
        }
        $columns = $rows === [] ? [] : array_slice(array_map('strval', array_keys($rows[0])), 0, self::COLUMNS);
        $cells   = static fn (array $r): array => array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE), array_slice(array_values($r), 0, self::COLUMNS));

        return ['columns' => $columns === [] ? ['—'] : $columns, 'rows' => array_map($cells, $rows), 'limit' => $limit];
    }

    /** The space a pin is kept under: every persona's alike, each partition's its own. */
    private function pinSpace(): string
    {
        return $this->scope->space . ($this->scope->partition === null ? '' : '@' . $this->scope->partition);
    }

    /**
     * A pin's parts — its artifact, the space it was pinned in and the organisation — from
     * `<id>@<space>@<organisation>`, where the space may hold a partition's `@` of its own.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function pinParts(string $key): array
    {
        $parts = explode('@', $key);
        $id    = (string) array_shift($parts);
        $org   = count($parts) > 1 ? (string) array_pop($parts) : '';

        return [$id, implode('@', $parts), $org];
    }

    /** Whether artifacts can be shared live here: the members' table exists. */
    public static function sharing(): bool
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable(self::MEMBERS);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One of this person's artifacts shared live — the artifact itself, not a copy: every change
     * reaches every member — with the people named (usernames or emails, as {@see SharePolicy}
     * allows), or with their whole organisation; `read` or `edit`. Naming a member again changes
     * their role.
     *
     * @param list<string> $names
     * @return array{shared: list<string>, unknown: list<string>}|string Who it went to and who was nobody, or why not
     */
    public function shareLive(int $artifactId, array $names, string $role = 'read', bool $organization = false): array|string
    {
        if (!self::sharing()) {
            return 'Sharing artifacts live is not set up here: run migrate.';
        }
        if ($this->own($artifactId) === null) {
            return 'Only its owner shares an artifact.';
        }
        if (!in_array($role, self::ROLES, true)) {
            return 'A member reads it (read) or changes it too (edit).';
        }
        $policy = SharePolicy::current();
        if (($why = $policy->refusal($this->scope)) !== null) {
            return $why;
        }
        $stamp = CallLog::instant(time());
        $keep  = function (?int $userId, ?int $org) use ($artifactId, $role, $stamp): void {
            $row = $this->members()->where('artifact_id', $artifactId);
            $userId === null ? $row->whereNull('userid')->where('organization_id', $org) : $row->where('userid', $userId);
            if ($row->count() > 0) {
                $row = $this->members()->where('artifact_id', $artifactId);
                ($userId === null ? $row->whereNull('userid')->where('organization_id', $org) : $row->where('userid', $userId))->update(['role' => $role, 'updated_at' => $stamp]);
            } else {
                $this->members()->insert(['artifact_id' => $artifactId, 'userid' => $userId, 'organization_id' => $org, 'role' => $role, 'created_at' => $stamp, 'updated_at' => $stamp]);
            }
            if ($userId !== null) {
                \Pramnos\Broadcasting\BroadcastingManager::toUser($userId, self::CHANGED, ['id' => $artifactId]);
            }
        };
        if ($organization) {
            if ($this->scope->organizationId === null) {
                return 'There is no organisation to share it with.';
            }
            $keep(null, $this->scope->organizationId);
        }
        $names = array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), $names), static fn (string $n): bool => $n !== ''));
        $found = $names === [] ? [] : $policy->recipients($this->scope, $names);
        foreach (array_unique($found) as $userId) {
            $keep((int) $userId, null);
        }
        if (!$organization && $names === []) {
            return 'Name somebody to share it with, or the organisation.';
        }

        return ['shared' => array_keys($found), 'unknown' => array_values(array_diff($names, array_keys($found)))];
    }

    /**
     * Who shares an artifact live: its owner's own, or a member's to see. `userid` null is the
     * whole organisation.
     *
     * @return list<array{userid: ?int, organization_id: ?int, name: string, role: string}>
     */
    public function membersOf(int $artifactId): array
    {
        if (!self::sharing() || $this->find($artifactId) === null) {
            return [];
        }
        $out = [];
        foreach ($this->members()->where('artifact_id', $artifactId)->orderBy('member_id')->getAllOrFail() as $row) {
            $userId = $row['userid'] === null ? null : (int) $row['userid'];
            $out[]  = ['userid' => $userId, 'organization_id' => $row['organization_id'] === null ? null : (int) $row['organization_id'],
                'name' => $userId === null ? 'Everyone in the organisation' : Shares::nameOf($userId), 'role' => (string) $row['role']];
        }

        return $out;
    }

    /**
     * A member taken off — by the owner, or a member leaving (their own id). Null takes the whole
     * organisation off.
     */
    public function unshareLive(int $artifactId, ?int $userId): bool
    {
        if (!self::sharing()) {
            return false;
        }
        $owner = $this->own($artifactId) !== null;
        if (!$owner && $userId !== $this->scope->userId) {
            return false;
        }
        $row = $this->members()->where('artifact_id', $artifactId);
        ($userId === null ? $row->whereNull('userid') : $row->where('userid', $userId))->delete();

        return true;
    }

    /**
     * What others share live with this person, newest first, without content.
     *
     * @return list<array{artifact_id: int, kind: string, title: string, owner: int, role: string}>
     */
    public function sharedWithMe(): array
    {
        if (!self::sharing() || $this->scope->userId <= 0) {
            return [];
        }
        $user = $this->scope->userId;
        $org  = $this->scope->organizationId;
        $ids  = array_map('intval', array_column($this->members()->select(['artifact_id'])
            ->where(static function ($q) use ($user, $org): void {
                $q->where('userid', $user);
                if ($org !== null) {
                    $q->orWhere(static fn ($o) => $o->whereNull('userid')->where('organization_id', $org));
                }
            })->getAllOrFail(), 'artifact_id'));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->table()->select(['artifact_id', 'kind', 'title', 'userid'])->whereIn('artifact_id', array_values(array_unique($ids)))
            ->where('userid', '!=', $user)->orderBy('updated_at', 'desc')->getAllOrFail() as $row) {
            $out[] = ['artifact_id' => (int) $row['artifact_id'], 'kind' => (string) $row['kind'], 'title' => (string) $row['title'],
                'owner' => (int) $row['userid'], 'role' => (string) $this->roleIn((int) $row['artifact_id'])];
        }

        return $out;
    }

    /**
     * Who hears of a change: its owner and every member named.
     *
     * @return list<int>
     */
    private function audience(int $artifactId, int $owner): array
    {
        // ponytail: an organisation-wide member hears nothing live; its people see the change on their next read.
        $members = self::sharing() ? array_map('intval', array_filter(array_column($this->members()->select(['userid'])->where('artifact_id', $artifactId)->getAllOrFail(), 'userid'), static fn ($v): bool => $v !== null)) : [];

        return array_values(array_unique([$owner, ...$members]));
    }

    private function members(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(self::MEMBERS);
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_artifacts');
    }
}
