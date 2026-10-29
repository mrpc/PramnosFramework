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
 * ({@see documents()}). The conversation is sent never starting on an answer, each earlier answer
 * followed by a note of the tools that wrote in it — without which a later answer sees no result
 * for a change it made and says it changed nothing. Past {@see Assistant::turns()} turns and half
 * as many again, the older part is folded into a summary (`summary`, up to `summary_upto`) sent
 * with the system prompt, so a long conversation costs one summary call every few turns rather
 * than losing its start; `'llm' => ['chat' => ['summarize' => false]]` sends the last `turns()`
 * only. A conversation
 * that holds data of a tag moves first to a connection that may take it, or is refused.
 *
 * Only a successful answer is stored, question and answer together, with the union of the tags
 * its data carried.
 */
class Conversations
{
    /** One question's length, so a pasted spreadsheet is not a bill. */
    public const CHARS = 8000;

    /** Past this share of the window on the last answer, the conversation's older part is folded, however few its messages. */
    public const FULL = 0.6;

    /** An attached document's characters, beside the question's own. */
    public const ATTACHMENT_CHARS = 20_000;

    /** How much of the first question becomes the conversation's title. */
    public const TITLE = 80;

    /** How the framework notes the tools an earlier answer wrote with. Only this class writes it. */
    public const MARK = '⟦Tools that wrote in this answer: ';

    private ?Scope $scope = null;

    private ?bool $tracks = null;

    private bool $scheduled = false;

    /** @var array<string, int> What every call is marked with besides the scope: the scheduled task */
    private array $about = [];

    /** @var (callable(string): void)|null Told the answer's text so far, as it is written */
    private $onText = null;

    /** @var (callable(): bool)|null Whether the answer being written has been stopped */
    private $stopWhen = null;

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

    /**
     * These conversations, with every call they make marked as made for a scheduled task — its
     * `routine_id` goes to the call log beside the purpose, which still routes the call.
     */
    public function forTask(int $routineId): static
    {
        $chat        = clone $this;
        $chat->about = ['routine_id' => $routineId];

        return $chat;
    }

    /**
     * These conversations, telling `$listener` an answer's text so far while it is written — see
     * `Client::onText()`. The answer only: not a summary folded or a memory learned beside it.
     *
     * @param callable(string): void $listener
     */
    public function streaming(callable $listener): static
    {
        $chat         = clone $this;
        $chat->onText = $listener;

        return $chat;
    }

    /**
     * These conversations, stopping an answer when `$stopped` says so — see `Client::cancelWhen()`.
     * A stopped answer keeps nothing: neither the question nor a part of the answer is stored.
     *
     * @param callable(): bool $stopped
     */
    public function stoppable(callable $stopped): static
    {
        $chat           = clone $this;
        $chat->stopWhen = $stopped;

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

        $made = (new Artifacts($this->scope))->forConversation($conversationId);
        while (($row = $result->fetch()) !== null) {
            $messages[] = self::message($row) + ['artifacts' => $made[(int) $row['message_id']] ?? []];
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
        (new Artifacts($this->scope))->deleteConversation($conversationId);
        Shares::forget(Shares::CONVERSATION, $conversationId);
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
        $ids     = [];
        // How full the window was on the last answer, as the provider counted it.
        $lastContext = 0;
        $held    = [];
        $summary = '';

        if ($conversationId !== null) {
            $found = $this->find($conversationId);

            if ($found === null) {
                return self::refusal('missing', 'There is no such conversation.');
            }

            $held    = $found['tags'];
            $row     = (array) $this->conversation($conversationId);
            $summary = trim((string) ($row['summary'] ?? ''));

            foreach ($found['messages'] as $turn) {
                // What the summary already holds is not sent again.
                if ((int) $turn['message_id'] <= (int) ($row['summary_upto'] ?? 0)) {
                    continue;
                }
                $history[] = ['role' => $turn['role'], 'content' => $turn['content'] . $this->whatItDid($turn['tools'] ?? [])];
                $ids[]     = (int) $turn['message_id'];
                $lastContext = $turn['role'] === 'assistant' ? (int) ($turn['context'] ?? 0) : $lastContext;
            }
        }

        $client = $this->client($this->scope);

        // A conversation already holding data of a kind is asked only where that kind may go —
        // its summary too.
        foreach ($held as $tag) {
            if (!$client->moveFor($tag)) {
                return self::refusal('tags', $this->assistant->heldRefusal($tag));
            }
        }

        // A long conversation folds its older part into the summary — a few messages at a time,
        // not a call every turn — and sends the rest as it was said.
        // Without a summary to keep it, what does not fit is left out.
        $keep  = max(1, $this->assistant->turns());
        $slice = !self::summarizes() || !$this->tracks();
        // Or by how full the last answer left the window: a few long messages fill it as surely as many.
        $window = (int) ($client->connection()->config()['context_window'] ?? 0);
        $full   = $window > 0 && $lastContext > $window * self::FULL && count($history) > 2;
        if (!$slice && ($full || count($history) + 1 > $keep + max(2, intdiv($keep, 2)))) {
            // Too full: all but the last exchange; too many: all but the last turns().
            $cut = max($full ? count($history) - 2 : 0, count($history) + 1 - $keep);
            // An answer goes with its question: what is sent never starts on one.
            $cut   += ($history[$cut]['role'] ?? '') === 'assistant' ? 1 : 0;
            $folded = $this->fold((int) $conversationId, $client, $summary, array_slice($history, 0, $cut), $ids[$cut - 1]);
            $slice  = $folded === null;
            if ($folded !== null) {
                [$summary, $history] = [$folded, array_slice($history, $cut)];
            }
        }

        // The title is the person's own words, not what the assistant adds to them.
        $asked   = $question;
        $preview = [...$history, ['role' => 'user', 'content' => $question]];
        $asking  = new Turn($this->scope, $conversationId, $this->scheduled, $preview, self::documents($preview), $extra, $this);
        $question = $this->assistant->question($asking, $question);

        $messages = [...$history, ['role' => 'user', 'content' => $question]];
        if ($slice) {
            $messages = array_slice($messages, -$keep);
        }

        // A cut that starts on an answer would show the model a reply to nothing.
        if ($messages[0]['role'] === 'assistant') {
            array_shift($messages);
        }

        $turn   = new Turn($this->scope, $conversationId, $this->scheduled, $messages, self::documents($messages), $extra, $this);
        // What question() kept for the answer — a draft it named — is the answer's too.
        $turn->bag = $asking->bag;

        $memory    = new Memory($this->scope);
        $routines  = new Routines($this);
        $artifacts = new Artifacts($this->scope);
        // The same services the tools write through: what they made is linked to the conversation below.
        $tools     = $this->buildTools($turn, $memory, $routines, $artifacts);

        // Reads asked for at once run at once, shared with the `llm_tool` workers, where the
        // application turned it on: a worker builds these same tools again from this.
        $parallel = ParallelTools::forAnswer([
            'space'           => $this->scope->space,
            'userid'          => $this->scope->userId,
            'organization_id' => $this->scope->organizationId,
            'conversation_id' => $conversationId,
            'scheduled'       => $this->scheduled,
            'extra'           => $extra,
        ]);

        $answer = $client->onText($this->onText)->cancelWhen($this->stopWhen)->inParallel($parallel)->askWithTools(
            $this->assistant->systemPrompt($turn) . "\n\n" . ($turn->shared() ? trim($this->assistant->context($turn)) : $this->context($turn, $memory))
                . ($turn->documents === [] ? '' : "\n\n" . Client::DATA_NOTICE)
                . ($summary === '' ? '' : "\n\nEarlier in this conversation, in short (those messages are not shown again):\n" . $summary),
            $messages,
            $tools,
            $this->assistant->rounds(),
            $this->assistant->due($turn),
            $this->assistant->verify($turn)
        );
        $client->onText(null)->cancelWhen(null)->inParallel(null);

        if (!$answer['ok']) {
            return self::refusal(isset($answer['cancelled']) ? 'cancelled' : (isset($answer['consent']) ? 'consent' : (isset($answer['limited']) ? 'limit' : 'provider')), (string) $answer['says']);
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
        // A reminder asked for in a first question leads back to the conversation it started.
        $routines->linkMade($conversationId);

        $this->store($conversationId, 'user', $question, [], null, $stamp);
        // The answer alone, and what the model said while it called tools beside it: those lines
        // ("Let me check…") are steps, not the start of the answer — kept, folded away, and left out
        // of the copy and of the history the next question replays.
        $steps   = array_values(array_filter(array_map('strval', (array) ($answer['steps'] ?? [])), static fn (string $one): bool => trim($one) !== ''));
        $message = $this->store($conversationId, 'assistant', self::withoutMark((string) ($answer['final'] ?? $answer['reply'])),
            (array) ($answer['calls'] ?? []), $answer['cost'], $stamp, $answer['context'] ?? null,
            $steps === [] ? [] : ['steps' => $steps]);
        $message['artifacts'] = $artifacts->link($conversationId, (int) $message['message_id'], $turn->tags);

        // What the person stated, kept — once several of their messages have gathered. An answer
        // others read, or a scheduled run, is nothing of the person's to learn from.
        if (Memory::learns() && !$turn->shared() && !$this->scheduled) {
            $this->tracks() ? $this->learnFrom($conversationId, $memory, $client, false) : $memory->learn($client, $asked);
        } elseif ($this->tracks()) {
            $this->mark($conversationId, ['learned_upto' => (int) $message['message_id']]);
        }

        return ['ok' => true, 'says' => '', 'why' => '', 'conversation_id' => $conversationId,
            'message' => $this->assistant->answered($turn, $message)];
    }

    /**
     * Start a conversation that already holds an exchange — a report opened to ask about, say —
     * without asking anything. Answers its id, or null with nobody to own it.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public function seed(string $title, array $messages): ?int
    {
        if ($this->scope === null) {
            return null;
        }

        $stamp = CallLog::instant(time());
        $this->table('#PREFIX#chat_conversations')->insert($this->scope->columns() + [
            'title' => self::titleFor($title), 'created_at' => $stamp, 'updated_at' => $stamp,
        ]);
        $conversationId = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();

        $last = 0;
        foreach ($messages as $message) {
            $last = (int) $this->store($conversationId, $message['role'] === 'assistant' ? 'assistant' : 'user', (string) $message['content'], [], null, $stamp)['message_id'];
        }
        // What was put here, not said: nothing to learn from.
        if ($this->tracks()) {
            $this->mark($conversationId, ['learned_upto' => $last]);
        }

        return $conversationId;
    }

    /**
     * Ask once, headless, for a report: no conversation is kept, nothing is written — the
     * assistant's write tools ({@see Assistant::writes()}), the memory — its tools and what it holds —
     * and the scheduling tools are left out — and the answer is handed back for the run to record.
     *
     * With `$relevant`, the model also decides whether the result is worth telling anybody about,
     * through a tool it is made to call on the round before the last: a decision it was only
     * asked for in words is one it forgets. No decision means tell.
     *
     * @param array<string, mixed> $extra The application's, for its assistant — the task, say
     * @return array{ok: bool, says: string, why: string, content: string, steps: list<string>, cost: mixed, relevant: bool|null}
     */
    public function report(string $question, array $extra = [], bool $relevant = false): array
    {
        if ($this->scope === null) {
            return self::refusal('scope', 'This needs somebody signed in.') + ['content' => '', 'steps' => [], 'cost' => null, 'relevant' => null];
        }

        $messages = [['role' => 'user', 'content' => mb_substr(trim($question), 0, self::CHARS)]];
        $turn     = new Turn($this->scope, null, true, $messages, [], $extra, $this);
        $client   = ($this->client ?? new Client($this->assistant->connection($this->scope)))
            ->about(['purpose' => $this->scope->purpose()] + $this->scope->context() + $this->about);
        $tools    = array_diff_key($this->assistant->tools($turn), array_flip($this->assistant->writes()));
        $decided  = null;
        $due      = [];

        if ($relevant) {
            $tools['report_relevance'] = [
                'description' => 'Say whether this report is worth telling its readers about: true when something changed or needs '
                    . 'attention, false when there is nothing new. Call it once, before your final answer.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'relevant' => ['type' => 'boolean'],
                    'why'      => ['type' => 'string', 'description' => 'One sentence.'],
                ], 'required' => ['relevant']],
                'run' => static function (array $a) use (&$decided): string {
                    $decided = filter_var($a['relevant'] ?? true, FILTER_VALIDATE_BOOLEAN);

                    return 'Noted.';
                },
            ];
            $due['report_relevance'] = static fn (array $calls): bool => !in_array('report_relevance', array_column($calls, 'name'), true);
        }

        $answer = $client->askWithTools(
            // Not the maker's memory: a shared report is read by others, and what the maker told
            // the assistant about themselves is not theirs to read.
            trim($this->assistant->systemPrompt($turn) . "\n\n" . trim($this->assistant->context($turn))),
            $messages,
            $tools,
            $this->assistant->rounds(),
            $due + $this->assistant->due($turn)
        );

        if (!$answer['ok']) {
            return self::refusal(isset($answer['limited']) ? 'limit' : 'provider', (string) $answer['says'])
                + ['content' => '', 'steps' => [], 'cost' => null, 'relevant' => null];
        }

        return ['ok' => true, 'says' => '', 'why' => '', 'content' => self::withoutMark((string) ($answer['final'] ?? $answer['reply'])),
            'steps' => array_values(array_filter(array_map('strval', (array) ($answer['steps'] ?? [])), static fn (string $one): bool => trim($one) !== '')),
            'cost' => $answer['cost'] ?? null, 'relevant' => $decided];
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
     * Learn from the conversations that went quiet with something of the person's not yet learned
     * from — each as its owner. Run by `llm:routines`. Returns how many were read.
     */
    public function learnQuiet(int $now, int $limit = 20): int
    {
        if (!Memory::learns() || !$this->tracks()) {
            return 0;
        }

        $result = $this->table('#PREFIX#chat_conversations c')
            ->join('#PREFIX#chat_messages m', static fn (\Pramnos\Database\JoinClause $on) => $on
                ->on('m.conversation_id', '=', 'c.conversation_id')->on('m.message_id', '>', 'c.learned_upto'))
            ->select(['c.conversation_id', 'c.organization_id', 'c.userid', 'c.space'])->distinct()
            ->where('m.role', 'user')
            ->where('c.updated_at', '<', CallLog::instant($now - Memory::idle()))
            ->orderBy('c.conversation_id')->limit(max(1, $limit))->get();
        $read = 0;

        while ($result && ($row = $result->fetch()) !== null) {
            $scope  = new Scope((int) $row['userid'], $row['organization_id'] === null ? null : (int) $row['organization_id'], (string) $row['space']);
            $chat   = $this->as($scope);
            $client = $this->client($scope);
            // Data of a kind goes only where that kind may go; where nothing may take it, it is not learned from.
            $barred = array_filter(self::tagsOf((array) $chat->conversation((int) $row['conversation_id'])), static fn (string $tag): bool => !$client->moveFor($tag));
            $read  += (int) $chat->learnFrom((int) $row['conversation_id'], new Memory($scope), $barred === [] ? $client : null, true);
        }

        return $read;
    }

    /**
     * Learn from what the person said since the last pass: once {@see Memory::every()} of their
     * messages have gathered, or at once (`$now`). Without a client, only marks them read.
     * Returns whether it learned.
     */
    private function learnFrom(int $conversationId, Memory $memory, ?Client $client, bool $now): bool
    {
        $since  = (int) ($this->conversation($conversationId)['learned_upto'] ?? 0);
        $result = $this->table('#PREFIX#chat_messages')->select(['message_id', 'role', 'content'])
            ->where('conversation_id', $conversationId)->where('message_id', '>', $since)->orderBy('message_id')->get();
        $said = [];
        $last = $since;

        while ($result && ($row = $result->fetch()) !== null) {
            $last = (int) $row['message_id'];
            if ($row['role'] === 'user') {
                // An attached document is not what the person said — and somebody else's words
                // must not become this person's memory.
                $said[] = mb_substr((string) preg_split('/(?:^|\n\n)---\n📎/u', (string) $row['content'])[0], 0, 800);
            }
        }

        if ($said === [] || (!$now && count($said) < Memory::every())) {
            return false;
        }

        if ($client !== null) {
            $memory->learn($client, implode("\n\n", $said));
        }
        $this->mark($conversationId, ['learned_upto' => $last]);

        return $client !== null;
    }

    /**
     * Fold older messages into the conversation's summary, up to message `$upto`. The new summary,
     * or null when it could not be written — then nothing is marked folded and they are left out
     * this turn, as without a summary.
     *
     * @param list<array{role: string, content: string}> $older
     */
    private function fold(int $conversationId, Client $client, string $summary, array $older, int $upto): ?string
    {
        // ponytail: the newest 40 only, each cut to 2000 characters — a conversation from before
        // summaries, folded for the first time, would otherwise be one enormous call.
        $lines = array_map(static fn (array $one): string => $one['role'] . ': ' . mb_substr($one['content'], 0, 2000), array_slice($older, -40));

        try {
            $answer = $client->askCheaply('You keep the running summary of a conversation between a person and an assistant, so that it can go on '
                . 'without its older messages. Rewrite the summary to take in the messages given: what was asked, what was found or decided, '
                . 'names, figures and ids that may be referred to again, and what is still open. Plain sentences in the conversation\'s '
                . 'language, at most 300 words. Answer with the summary only.', [['role' => 'user', 'content' =>
                    ($summary === '' ? '' : "Summary so far:\n" . $summary . "\n\n") . "Messages to take in:\n\n" . implode("\n\n", $lines)]]);
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('llm conversation summary: ' . $why->getMessage(), 'llm');

            return null;
        }

        $folded = trim((string) ($answer['reply'] ?? ''));
        if (!($answer['ok'] ?? false) || $folded === '') {
            return null;
        }
        $this->mark($conversationId, ['summary' => $folded, 'summary_upto' => $upto]);

        return $folded;
    }

    /** @param array<string, mixed> $values A conversation's columns, written as they are */
    private function mark(int $conversationId, array $values): void
    {
        $this->table('#PREFIX#chat_conversations')->where('conversation_id', $conversationId)->update($values);
    }

    /** Whether chat_conversations has its summary and watermarks yet — asked once per instance. */
    private function tracks(): bool
    {
        try {
            return $this->tracks ??= \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn('chat_conversations', 'learned_upto');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether the application folds a long conversation into a summary, rather than leaving its start out. */
    public static function summarizes(): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return (bool) ($chat['summarize'] ?? true);
    }

    /** The client a call in this scope is made with. */
    private function client(Scope $scope): Client
    {
        return ($this->client ?? new Client($this->assistant->connection($scope)))
            ->about(['purpose' => $scope->purpose()] + $scope->context() + $this->about);
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
            : 'What you remember about this person from earlier conversations (id, kind, as of):';

        // Dated, so a newer word is known to be newer: the latest wins.
        foreach ($remembered as $one) {
            $lines[] = '- [' . $one['memory_id'] . ', ' . $one['kind'] . ', ' . substr($one['created_at'], 0, 10) . '] ' . $one['content'];
        }

        $shared = $memory->shared();
        if ($shared !== []) {
            $lines[] = '';
            $lines[] = 'What this organisation keeps about its shared work, for everybody in it (id, kind, as of):';
            foreach ($shared as $one) {
                $lines[] = '- [' . $one['memory_id'] . ', ' . $one['kind'] . ', ' . substr($one['created_at'], 0, 10) . '] ' . $one['content'];
            }
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
            // What the person thought of it: 1, -1, or null — null, too, before the columns exist.
            'rating'     => ($fields['rating'] ?? null) === null ? null : (int) $fields['rating'],
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
    /**
     * Every tool an answer is given: the assistant's, and — unless others read it too — memory,
     * schedules, artifacts and proposals. A queue worker running one call of a round
     * ({@see ParallelTools}) builds them again here, so it runs what the answer would have.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toolsFor(Turn $turn): array
    {
        return $this->buildTools($turn, new Memory($this->scope), new Routines($this), new Artifacts($this->scope));
    }

    /**
     * {@see toolsFor()}, through the services the answer goes on using: the tasks and artifacts its
     * tools make are linked to the conversation once it is stored.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildTools(Turn $turn, Memory $memory, Routines $routines, Artifacts $artifacts): array
    {
        // An answer others read too carries nothing of the asker's own: not what was remembered
        // about them, not their tasks.
        $tools    = $this->assistant->tools($turn) + ($turn->shared() ? [] : $memory->tools($this, $turn->conversationId)
            // Not from a scheduled run: a task that schedules tasks has no end.
            + ($this->scheduled ? [] : $routines->toolsFor($this->scope, $this->assistant->timezone($this->scope), $turn->conversationId)
                // Kept with a conversation the person can open: a scheduled run has none yet.
                + $artifacts->tools()
                // And changes to approve, where the application turned them on: nobody approves in a run.
                + (new Proposals($this->scope))->tools($artifacts)));

        // A change is made only on the person's word: after reading what somebody else wrote, the
        // client holds these back (Client::HELD_WRITE).
        foreach ($this->assistant->writes() as $name) {
            if (isset($tools[$name])) {
                $tools[$name]['writes'] = true;
            }
        }

        return $tools;
    }

    private static function refusal(string $why, string $says): array
    {
        return ['ok' => false, 'says' => $says, 'why' => $why, 'conversation_id' => null, 'message' => null];
    }

    private function table(string $name): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table($name);
    }
}
