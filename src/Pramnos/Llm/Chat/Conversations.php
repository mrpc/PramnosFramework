<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;
use Pramnos\Llm\Client;
use Pramnos\Llm\Llm;

/**
 * Conversations with a model: what was asked, what it answered, and the tools each answer used.
 *
 * ```php
 * $chat   = (new Conversations(new App\Llm\Analyst()))->as(Scope::current());
 * $answer = $chat->ask(null, 'How did last week go?');
 * // ['ok' => true, 'conversation_id' => 12, 'message' => [...]]  or  ['ok' => false, 'why' => 'limit', 'says' => '…']
 * ```
 *
 * `why` says which refusal it was, for the caller to answer with the right status: `scope`,
 * `empty`, `missing`, `tags`, `consent`, `limit` or `provider`.
 *
 * ## What a turn does
 *
 * The question is capped at {@see CHARS} and an attached document's words at
 * {@see ATTACHMENT_CHARS}, after a `---` line and its name, so later answers can find it again
 * ({@see documents()}). The last {@see Assistant::turns()} turns are sent, never starting on an
 * answer, each earlier answer followed by a note of the tools that wrote in it — without which a
 * later answer sees no result for a change it made and says it changed nothing. A conversation
 * that holds data of a tag moves first to a connection that may take it, or is refused.
 *
 * Only a successful answer is stored, question and answer together, with the union of the tags
 * its data carried.
 */
class Conversations
{
    /** One question's length, so a pasted spreadsheet is not a bill. */
    public const CHARS = 8000;

    /** An attached document's characters, beside the question's own. */
    public const ATTACHMENT_CHARS = 20_000;

    /** How much of the first question becomes the conversation's title. */
    public const TITLE = 80;

    /** How the framework notes the tools an earlier answer wrote with. Only this class writes it. */
    public const MARK = '⟦Tools that wrote in this answer: ';

    private ?Scope $scope = null;

    private bool $scheduled = false;

    public function __construct(private readonly Assistant $assistant, private readonly ?Client $client = null)
    {
    }

    /**
     * The same conversations, as one person — the request's, or a queued or scheduled run's.
     *
     * A scheduled run (`$scheduled`) is told so through {@see Turn::$scheduled}, so the
     * assistant can leave out what only a person asking may use.
     */
    public function as(?Scope $scope, bool $scheduled = false): static
    {
        $chat            = clone $this;
        $chat->scope     = $scope;
        $chat->scheduled = $scheduled && $scope !== null;

        return $chat;
    }

    public function scope(): ?Scope
    {
        return $this->scope;
    }

    public function assistant(): Assistant
    {
        return $this->assistant;
    }

    /**
     * This person's conversations, most recently continued first.
     *
     * @return list<array{conversation_id: int, title: string, updated_at: string}>
     */
    public function list(int $limit = 200): array
    {
        if ($this->scope === null) {
            return [];
        }

        $result = $this->scope->apply($this->table('#PREFIX#chat_conversations'))
            ->select(['conversation_id', 'title', 'updated_at'])
            ->orderBy('updated_at', 'desc')->orderBy('conversation_id', 'desc')
            ->limit(max(1, $limit))->get();
        $list = [];

        while (($row = $result->fetch()) !== null) {
            $list[] = ['conversation_id' => (int) $row['conversation_id'], 'title' => (string) $row['title'], 'updated_at' => (string) $row['updated_at']];
        }

        return $list;
    }

    /**
     * One conversation and everything said in it, or null when it is not this person's.
     *
     * @return array{conversation_id: int, title: string, tags: list<string>, messages: list<array<string, mixed>>}|null
     */
    public function find(int $conversationId): ?array
    {
        $conversation = $this->conversation($conversationId);

        if ($conversation === null) {
            return null;
        }

        $result = $this->table('#PREFIX#chat_messages')->where('conversation_id', $conversationId)->orderBy('message_id')->get();
        $messages = [];

        while (($row = $result->fetch()) !== null) {
            $messages[] = self::message($row);
        }

        return [
            'conversation_id' => $conversationId,
            'title'           => (string) $conversation['title'],
            'tags'            => self::tagsOf($conversation),
            'messages'        => $this->assistant->present($this->scope, $messages),
        ];
    }

    /** Forget a conversation and its messages. False when there was none of this person's. */
    public function delete(int $conversationId): bool
    {
        if ($this->conversation($conversationId) === null) {
            return false;
        }

        $this->table('#PREFIX#chat_messages')->where('conversation_id', $conversationId)->delete();
        $this->scope->apply($this->table('#PREFIX#chat_conversations'))->where('conversation_id', $conversationId)->delete();

        return true;
    }

    /** What is remembered about the person asking, or null without one. */
    public function memory(): ?Memory
    {
        return $this->scope === null ? null : new Memory($this->scope);
    }

    /**
     * The connection a question here goes out on, as the router sends it — an organisation's own
     * provider or key — so what is checked before asking is what the answer will use.
     */
    public function connection(): \Pramnos\Llm\Connection
    {
        $scope  = $this->scope ?? new Scope(0);
        $router = \Pramnos\Llm\Llm::router();

        return $router->route($router->context(['purpose' => $scope->purpose()] + $scope->context()), $this->assistant->connection($scope));
    }

    /**
     * What a conversation here is allowed, for the screen to say out loud.
     *
     * @return array<string, mixed>
     */
    public function limits(): array
    {
        $connection = $this->connection();

        return [
            'provider'       => $connection->label(),
            'model'          => $connection->model(),
            'context_window' => (int) ($connection->config()['context_window'] ?? 0),
            'answer_tokens'  => $connection->maxTokens(),
            'turns'          => $this->assistant->turns(),
            'rounds'         => $this->assistant->rounds(),
            'question_chars' => self::CHARS,
            'memory'         => Memory::max(),
        ];
    }

    /**
     * Ask, in a new conversation or to continue one.
     *
     * @param array{name?: string, text?: string}|null $attachment A document's words, from {@see \Pramnos\Llm\DocumentText}
     * @param array<string, mixed>                     $extra      The application's, for its assistant
     * @return array{ok: bool, says: string, why: string, conversation_id: int|null, message: array<string, mixed>|null}
     */
    public function ask(?int $conversationId, string $question, ?array $attachment = null, array $extra = []): array
    {
        if ($this->scope === null) {
            return self::refusal('scope', 'This needs somebody signed in.');
        }

        $question = mb_substr(trim($question), 0, self::CHARS);
        $attached = trim((string) ($attachment['text'] ?? ''));

        // A document alone is a question too: "make this an article" is what it usually means.
        if ($question === '' && $attached === '') {
            return self::refusal('empty', 'Ask it something.');
        }

        if ($attached !== '') {
            $question = trim($question . "\n\n---\n📎 " . basename((string) ($attachment['name'] ?? 'document'))
                . "\n\n" . mb_substr($attached, 0, self::ATTACHMENT_CHARS));
        }

        $history = [];
        $held    = [];

        if ($conversationId !== null) {
            $found = $this->find($conversationId);

            if ($found === null) {
                return self::refusal('missing', 'There is no such conversation.');
            }

            $held = $found['tags'];

            foreach ($found['messages'] as $turn) {
                $history[] = ['role' => $turn['role'], 'content' => $turn['content'] . $this->whatItDid($turn['tools'] ?? [])];
            }
        }

        // The title is the person's own words, not what the assistant adds to them.
        $asked   = $question;
        $preview = [...$history, ['role' => 'user', 'content' => $question]];
        $asking  = new Turn($this->scope, $conversationId, $this->scheduled, $preview, self::documents($preview), $extra, $this);
        $question = $this->assistant->question($asking, $question);

        $messages = array_slice([...$history, ['role' => 'user', 'content' => $question]], -max(1, $this->assistant->turns()));

        // A cut that starts on an answer would show the model a reply to nothing.
        if ($messages[0]['role'] === 'assistant') {
            array_shift($messages);
        }

        $turn   = new Turn($this->scope, $conversationId, $this->scheduled, $messages, self::documents($messages), $extra, $this);
        // What question() kept for the answer — a draft it named — is the answer's too.
        $turn->bag = $asking->bag;
        $client = ($this->client ?? new Client($this->assistant->connection($this->scope)))
            ->about(['purpose' => $this->scope->purpose()] + $this->scope->context());

        // A conversation already holding data of a kind is asked only where that kind may go.
        foreach ($held as $tag) {
            if (!$client->moveFor($tag)) {
                return self::refusal('tags', $this->assistant->heldRefusal($tag));
            }
        }

        $memory = new Memory($this->scope);
        $tools  = $this->assistant->tools($turn) + $memory->tools($this, $conversationId)
            // Not from a scheduled run: a task that schedules tasks has no end.
            + ($this->scheduled ? [] : (new Routines($this))->toolsFor($this->scope, $this->assistant->timezone($this->scope), $conversationId));

        $answer = $client->askWithTools(
            $this->assistant->systemPrompt($turn) . "\n\n" . $this->context($turn, $memory),
            $messages,
            $tools,
            $this->assistant->rounds(),
            $this->assistant->due($turn),
            $this->assistant->verify($turn)
        );

        if (!$answer['ok']) {
            return self::refusal(isset($answer['consent']) ? 'consent' : (isset($answer['limited']) ? 'limit' : 'provider'), (string) $answer['says']);
        }

        $turn->tags = array_values(array_unique([...$held, ...(array) ($answer['tags'] ?? [])]));
        $stamp      = CallLog::instant(time());

        if ($conversationId === null) {
            $this->table('#PREFIX#chat_conversations')->insert($this->scope->columns() + [
                'title'      => self::titleFor($asked),
                'tags'       => $turn->tags === [] ? null : json_encode($turn->tags),
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $conversationId = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
        } else {
            $this->scope->apply($this->table('#PREFIX#chat_conversations'))->where('conversation_id', $conversationId)
                ->update(['updated_at' => $stamp, 'tags' => $turn->tags === [] ? null : json_encode($turn->tags)]);
        }

        $turn->conversationId = $conversationId;

        $this->store($conversationId, 'user', $question, [], null, $stamp);
        // The answer alone, and what the model said while it called tools beside it: those lines
        // ("Let me check…") are steps, not the start of the answer — kept, folded away, and left out
        // of the copy and of the history the next question replays.
        $steps   = array_values(array_filter(array_map('strval', (array) ($answer['steps'] ?? [])), static fn (string $one): bool => trim($one) !== ''));
        $message = $this->store($conversationId, 'assistant', self::withoutMark((string) ($answer['final'] ?? $answer['reply'])),
            (array) ($answer['calls'] ?? []), $answer['cost'], $stamp, $answer['context'] ?? null,
            $steps === [] ? [] : ['steps' => $steps]);

        return ['ok' => true, 'says' => '', 'why' => '', 'conversation_id' => $conversationId,
            'message' => $this->assistant->answered($turn, $message)];
    }

    /**
     * The documents attached in a conversation, by name, the latest last — each question
     * carries one after `---` and `📎 name`.
     *
     * @param list<array{role: string, content: string}> $messages
     * @return array<string, string>
     */
    public static function documents(array $messages): array
    {
        $found = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'user' && preg_match('/\n?---\n📎 ([^\n]+)\n\n(.+)$/su', (string) $message['content'], $doc) === 1) {
                unset($found[$doc[1]]);
                $found[$doc[1]] = trim($doc[2]);
            }
        }

        return $found;
    }

    /**
     * What an earlier answer wrote with, after its words — only for the assistant's write tools.
     *
     * @param list<array{name?: string, arguments?: array<string, mixed>}> $calls
     */
    public function whatItDid(array $calls): string
    {
        $done = [];

        foreach ($calls as $call) {
            $name = (string) ($call['name'] ?? '');

            if (in_array($name, $this->assistant->writes(), true)) {
                $done[] = $name;
            }
        }

        return $done === [] ? '' : "\n\n" . self::MARK . implode(', ', array_unique($done)) . ' ⟧';
    }

    /** A reply without any note it wrote itself in the system's name. */
    public static function withoutMark(string $reply): string
    {
        return trim((string) preg_replace('/\s*⟦[^⟧]*⟧/u', '', $reply));
    }

    /**
     * The assistant's context, then what is remembered about the person.
     */
    private function context(Turn $turn, Memory $memory): string
    {
        $remembered = $memory->all();
        $lines      = [trim($this->assistant->context($turn)), ''];
        $lines[]    = $remembered === []
            ? 'You remember nothing about this person yet.'
            : 'What you remember about this person from earlier conversations (id, kind):';

        foreach ($remembered as $one) {
            $lines[] = '- [' . $one['memory_id'] . ', ' . $one['kind'] . '] ' . $one['content'];
        }

        return trim(implode("\n", $lines));
    }

    /** @return array<string, mixed>|null The conversation's row, when it is this person's */
    private function conversation(int $conversationId): ?array
    {
        if ($this->scope === null) {
            return null;
        }

        $result = $this->scope->apply($this->table('#PREFIX#chat_conversations'))->where('conversation_id', $conversationId)->first();

        return $result && (int) $result->numRows > 0 ? $result->fields : null;
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    private function store(int $conversationId, string $role, string $content, array $tools, mixed $cost, string $stamp, ?int $context = null, array $extra = []): array
    {
        $row = [
            'conversation_id' => $conversationId,
            'organization_id' => $this->scope?->organizationId,
            'role'            => $role,
            'content'         => $content,
            'tools'           => $tools === [] ? null : (string) json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'cost'            => $cost,
            'context_tokens'  => $context ?: null,
            'extra'           => $extra === [] ? null : (string) json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'created_at'      => $stamp,
            'updated_at'      => $stamp,
        ];

        $this->table('#PREFIX#chat_messages')->insert($row);

        return self::message(['message_id' => (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId()] + $row);
    }

    /**
     * One stored message, as the screen reads it.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function message(array $fields): array
    {
        $tools = json_decode((string) ($fields['tools'] ?? ''), true);
        $extra = json_decode((string) ($fields['extra'] ?? ''), true);

        return [
            'message_id' => (int) $fields['message_id'],
            'role'       => (string) $fields['role'],
            'content'    => (string) $fields['content'],
            'tools'      => is_array($tools) ? $tools : [],
            'cost'       => ($fields['cost'] ?? null) === null ? null : (float) $fields['cost'],
            'context'    => ($fields['context_tokens'] ?? null) === null ? null : (int) $fields['context_tokens'],
            'extra'      => is_array($extra) ? $extra : [],
            'created_at' => (string) $fields['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $conversation
     * @return list<string>
     */
    private static function tagsOf(array $conversation): array
    {
        $tags = json_decode((string) ($conversation['tags'] ?? ''), true);

        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
    }

    private static function titleFor(string $question): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $question));

        return mb_strlen($line) > self::TITLE ? rtrim(mb_substr($line, 0, self::TITLE - 1)) . '…' : $line;
    }

    /** @return array{ok: false, says: string, why: string, conversation_id: null, message: null} */
    private static function refusal(string $why, string $says): array
    {
        return ['ok' => false, 'says' => $says, 'why' => $why, 'conversation_id' => null, 'message' => null];
    }

    private function table(string $name): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table($name);
    }
}
