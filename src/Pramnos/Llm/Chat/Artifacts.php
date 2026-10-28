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
    /** What an artifact can be. */
    public const KINDS = ['table', 'list', 'text', 'chart'];

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
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('chat_artifacts');
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
                . 'in the answer, say briefly what it holds rather than repeating it.',
            'parameters'  => ['type' => 'object', 'properties' => [
                'kind'    => ['type' => 'string', 'enum' => self::KINDS],
                'title'   => ['type' => 'string', 'description' => 'A short name for it.'],
                'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'table: the column headings.'],
                'rows'    => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => ['string', 'number', 'boolean', 'null']]], 'description' => 'table: the rows, each a list of cells in column order.'],
                'entity'  => ['type' => 'string', 'description' => 'list: what the ids are, singular and lower case, e.g. "user".'],
                'ids'     => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'description' => 'list: the records\' ids, exactly as the tools gave them.'],
                'text'    => ['type' => 'string', 'description' => 'text: the document, in Markdown.'],
                'chart_type' => ['type' => 'string', 'enum' => self::CHARTS, 'description' => 'chart: how it is drawn.'],
                'labels'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'chart: the categories along the axis (or the slices). list: a readable name per id.'],
                'series'  => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'data' => ['type' => 'array', 'items' => ['type' => 'number']]]],
                    'description' => 'chart: each series, its numbers in the order of labels.'],
            ], 'required' => ['kind', 'title']],
            'run' => function (array $a): string {
                $made = $this->create($a);

                return is_string($made) ? 'Error: ' . $made : 'Saved as artifact ' . $made['artifact_id'] . '.';
            },
        ]];
    }

    /**
     * Make an artifact from what the model or the application gave. Answers it, or why not.
     *
     * @param array<string, mixed> $input kind, title, and the kind's fields
     * @return array<string, mixed>|string
     */
    public function create(array $input): array|string
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
        if (is_string($content)) {
            return $content;
        }

        return $this->keep($kind, $title, $kind === 'list' ? (string) $input['entity'] : null, $content);
    }

    /**
     * Keep an artifact whose content is already checked — the kinds above, or one of the
     * framework's own (a proposal) — as made during this turn, to be linked with its answer.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function keep(string $kind, string $title, ?string $entity, array $content): array
    {
        $stamp = CallLog::instant(time());
        $row   = $this->scope->columns() + [
            'kind'       => $kind,
            'title'      => mb_substr($title, 0, self::TITLE),
            'entity'     => $entity,
            'content'    => (string) json_encode($content, JSON_UNESCAPED_UNICODE),
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
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
        $result = $this->scope->apply($this->table())->where('artifact_id', $artifactId)->first();

        return $result && (int) $result->numRows > 0 ? self::shape($result->fields) : null;
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
        if ($this->find($artifactId) === null) {
            return false;
        }
        $this->scope->apply($this->table())->where('artifact_id', $artifactId)->delete();
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
        $key  = $artifactId . '@' . $this->scope->space . '@' . ($this->scope->organizationId ?? '');
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
            [$id, $space, $organization] = array_pad(explode('@', $key), 3, '');
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

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_artifacts');
    }
}
