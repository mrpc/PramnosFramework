<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * What a conversation remembers about the person — `chat_memory`.
 *
 * ## Written by the model, kept by the person
 *
 * Nothing extracts memories behind anybody's back with a second call after every turn: the
 * model has a `remember` tool and is told to use it when somebody says something worth keeping.
 * It is cheaper, and it happens where it can be seen — every call is listed under the answer
 * that made it — and the person can see and delete every memory.
 *
 * ## Bounded, because it goes into every prompt
 *
 * At most {@see max()} entries of {@see chars()} characters (`'llm' => ['chat' => ['memory' =>
 * ['max' => 40, 'chars' => 300]]]`); a full memory refuses a new entry and says so.
 *
 * ## Only this person's
 *
 * Every read and write is scoped by {@see Scope}, which comes from the request or the row —
 * never from what the model sent.
 */
class Memory
{
    public const KINDS = ['fact', 'preference'];

    /** How many messages a search returns, and how much of each. */
    public const HITS = 12;

    public const SNIPPET = 400;

    public function __construct(private readonly Scope $scope)
    {
    }

    /** The most memories a person may have. */
    public static function max(): int
    {
        return max(1, (int) (self::config()['max'] ?? 40));
    }

    /** The longest a memory may be. */
    public static function chars(): int
    {
        return max(1, (int) (self::config()['chars'] ?? 300));
    }

    /** @return list<array{memory_id: int, kind: string, content: string, created_at: string}> */
    public function all(): array
    {
        try {
            $result = $this->scope->apply($this->table('chat_memory'))
                ->select(['memory_id', 'kind', 'content', 'created_at'])->orderBy('memory_id')->get();
        } catch (\Throwable) {
            return [];
        }

        // PostgreSQL answers a failed query with false rather than an exception.
        if (!$result) {
            return [];
        }

        $list = [];

        while (($row = $result->fetch()) !== null) {
            $list[] = ['memory_id' => (int) $row['memory_id'], 'kind' => (string) $row['kind'],
                'content' => (string) $row['content'], 'created_at' => (string) $row['created_at']];
        }

        return $list;
    }

    /** Keep one thing. Returns what the model is told. */
    public function remember(string $kind, string $content): string
    {
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));

        if ($content === '') {
            return 'Error: there is nothing to remember.';
        }

        if (count($this->all()) >= self::max()) {
            return 'Error: the memory is full (' . self::max() . ' entries). Forget one that no longer holds, then remember this.';
        }

        $stamp = CallLog::instant(time());
        $this->table('chat_memory')->insert($this->scope->columns() + [
            'kind'       => in_array($kind, self::KINDS, true) ? $kind : 'fact',
            'content'    => mb_substr($content, 0, self::chars()),
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return 'Remembered as memory ' . (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId() . '.';
    }

    /** Forget one thing. False when there was none of this person's by that id. */
    public function forget(int $memoryId): bool
    {
        $query = $this->scope->apply($this->table('chat_memory'))->where('memory_id', $memoryId);

        if ((clone $query)->count() === 0) {
            return false;
        }

        $query->delete();

        return true;
    }

    /**
     * This person's messages in their other conversations that mention any of the words, newest
     * first — so "yesterday you told me…" can be found and checked rather than agreed with.
     *
     * @return list<array{conversation_id: int, title: string, role: string, said: string, at: string}>
     */
    public function search(Conversations $chat, string $query, int $days = 90, ?int $except = null): array
    {
        $words = array_slice(array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [],
            static fn (string $word): bool => mb_strlen($word) >= 3
        )), 0, 8);

        $titles = array_column($chat->list(), 'title', 'conversation_id');
        if ($except !== null) {
            unset($titles[$except]);
        }

        if ($words === [] || $titles === []) {
            return [];
        }

        // LOWER … LIKE rather than ILIKE, which is PostgreSQL's alone.
        $likes = array_map(
            static fn (string $word): string => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%',
            $words
        );

        $result = $this->table('chat_messages')
            ->select(['conversation_id', 'role', 'content', 'created_at'])
            ->whereIn('conversation_id', array_keys($titles))
            ->where('created_at', '>', CallLog::instant(time() - max(1, $days) * 86400))
            ->whereRaw('(' . implode(' OR ', array_fill(0, count($likes), 'LOWER(content) LIKE ?')) . ')', $likes)
            ->orderBy('message_id', 'desc')
            ->limit(self::HITS)
            ->get();
        $found = [];

        while (($row = $result->fetch()) !== null) {
            $found[] = [
                'conversation_id' => (int) $row['conversation_id'],
                'title'           => (string) ($titles[(int) $row['conversation_id']] ?? ''),
                'role'            => (string) $row['role'],
                'said'            => self::snippet((string) $row['content'], $words),
                'at'              => (string) $row['created_at'],
            ];
        }

        return $found;
    }

    /**
     * The tools that give the model this memory. Not part of any public MCP surface: they write,
     * and they read one person's private words.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(Conversations $chat, ?int $conversationId): array
    {
        return [
            'remember' => [
                'description' => 'Keep a fact about this person or their work, or a preference about how they want answers, '
                    . 'for future conversations. Use it when they state something durable. One short sentence each. '
                    . 'Not for numbers the tools can read again.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'kind'    => ['type' => 'string', 'enum' => self::KINDS],
                    'content' => ['type' => 'string', 'description' => 'One sentence, under ' . self::chars() . ' characters.'],
                ], 'required' => ['kind', 'content']],
                'run' => fn (array $a): string => $this->remember((string) ($a['kind'] ?? 'fact'), (string) ($a['content'] ?? '')),
            ],
            'forget' => [
                'description' => 'Forget a memory by its id, when it no longer holds or the person asks.',
                'parameters'  => ['type' => 'object', 'properties' => ['memory_id' => ['type' => 'integer']], 'required' => ['memory_id']],
                'run' => fn (array $a): string => $this->forget((int) ($a['memory_id'] ?? 0)) ? 'Forgotten.' : 'Error: there is no such memory.',
            ],
            'search_conversations' => [
                'description' => 'Search this person\'s earlier conversations for words, newest first — for "yesterday you told '
                    . 'me…". Returns snippets with the conversation id and date; read one whole with read_conversation.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to look for, any of them. Try word stems and both languages.'],
                    'days'  => ['type' => 'integer', 'description' => 'How far back. Defaults to 90.'],
                ], 'required' => ['query']],
                'run' => fn (array $a): string => (string) json_encode(
                    $this->search($chat, (string) ($a['query'] ?? ''), (int) ($a['days'] ?? 90), $conversationId),
                    JSON_UNESCAPED_UNICODE
                ),
            ],
            'read_conversation' => [
                'description' => 'Read one of this person\'s earlier conversations in full, by id.',
                'parameters'  => ['type' => 'object', 'properties' => ['conversation_id' => ['type' => 'integer']], 'required' => ['conversation_id']],
                'run' => static function (array $a) use ($chat): string {
                    $found = $chat->find((int) ($a['conversation_id'] ?? 0));

                    return $found === null ? 'Error: there is no such conversation.' : (string) json_encode([
                        'title'    => $found['title'],
                        'messages' => array_map(static fn (array $m): array => [
                            'role' => $m['role'], 'said' => mb_substr((string) $m['content'], 0, 3000), 'at' => $m['created_at'],
                        ], $found['messages']),
                    ], JSON_UNESCAPED_UNICODE);
                },
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat['memory'] ?? null) ? $chat['memory'] : [];
    }

    /**
     * Some text either side of the first word found, so the hit reads in context.
     *
     * @param list<string> $words
     */
    private static function snippet(string $content, array $words): string
    {
        if (mb_strlen($content) <= self::SNIPPET) {
            return $content;
        }

        $at = null;

        foreach ($words as $word) {
            $position = mb_stripos($content, $word);

            if ($position !== false && ($at === null || $position < $at)) {
                $at = $position;
            }
        }

        $start = max(0, (int) $at - intdiv(self::SNIPPET, 3));

        return ($start > 0 ? '…' : '') . mb_substr($content, $start, self::SNIPPET) . '…';
    }

    private function table(string $name): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table($name);
    }
}
