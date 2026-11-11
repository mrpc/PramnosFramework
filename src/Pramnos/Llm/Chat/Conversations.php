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
    /** Questions a conversation stays on the tier it was given before it is decided again. */
    public const CASCADE_HOLD = 4;

    /**
     * Tools offered at once above which they are grouped in skills, when the application has not
     * said either way (`'llm' => ['chat' => ['skills' => …]]`): every round carries every tool's
     * description, and past this many that is most of a small question's prompt.
     */
    public const SKILLS_FROM = 20;

    /** The calls of a cut answer named for the next question, at most. */
    public const STOPPED_CALLS = 20;

    public const MARK = '⟦Tools that wrote in this answer: ';

    /** Told to the fast tier when it answers in place of the frontier one, which is down. */
    public const DEGRADED = 'The stronger model is unavailable right now, so you are answering in its place: answer as well as you can, '
        . 'and say plainly if the question needs more than you can give. If the person is upset or confiding in you, answer them warmly first.';

    /**
     * The framework's own skills, and what each is for — where `'llm' => ['chat' => ['skills' =>
     * true]]` offers their tools only once the model turns one on ({@see grouped()}).
     */
    public const SKILLS = [
        'tasks'     => 'scheduling questions, reminders and reports',
        'artifacts' => 'reading, changing, finding and pinning artifacts kept earlier',
        'profile'   => 'what the person confirmed about themselves and the people in their life',
        'calendar'  => 'the person\'s appointments and tasks',
        'journal'   => 'the person\'s journal for the day',
        'web'       => 'searching the web and reading a page',
        Work::MODE  => Work::SKILL,
    ];

    private ?Scope $scope = null;

    private ?bool $tracks = null;

    /** Whether an answer is already inside its persona's avatar set (ask()). */
    private bool $inPersona = false;

    /** Whether `chat_conversations.ended_at` exists (Ending), asked once. */
    private ?bool $ends = null;

    private bool $scheduled = false;

    /** Asked from the console (`llm:ask`): kept, but not learned from, and marked as such. */
    private bool $console = false;

    /** @var array<string, int> What every call is marked with besides the scope: the scheduled task */
    private array $about = [];

    /** One id for every call of the turn under way — the answer, a tier fallback, its fold and its learn. */
    private ?string $traceId = null;

    /** @var (callable(string): void)|null Told the answer's text so far, as it is written */
    private $onText = null;

    /** @var (callable(string, array<string, mixed>, array<string, mixed>): void)|null */
    private $onTool = null;

    /** @var (callable(): bool)|null Whether the answer being written has been stopped */
    private $stopWhen = null;

    /** What names the answer, so its changes are made once — see `Client::answering()` */
    private ?string $answerKey = null;

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
     * These conversations, asked from the console (`llm:ask`) — somebody checking how the
     * assistant answers, not the person speaking. Kept as any conversation and opened the same
     * way; the answer is marked `extra.source = console`, so `help:gaps` leaves it out, and
     * nothing in it is learned about the person.
     */
    public function fromConsole(): static
    {
        $chat          = clone $this;
        $chat->console = true;

        return $chat;
    }

    /**
     * These conversations, telling `$listener` each tool an answer is about to run: its name, its
     * arguments and its definition — see `Client::onTool()`.
     *
     * @param callable(string, array<string, mixed>, array<string, mixed>): void $listener
     */
    public function doing(callable $listener): static
    {
        $chat         = clone $this;
        $chat->onTool = $listener;

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

    /**
     * These conversations, naming the answer — a queued question's `ask:{id}` — so another run of
     * it does not make the changes the first one made. See `Client::answering()`.
     */
    public function answering(string $key): static
    {
        $chat            = clone $this;
        $chat->answerKey = $key;

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

        $result = $this->scope->apply($this->table('assist.chat_conversations'))
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

        $result = $this->table('assist.chat_messages')->where('conversation_id', $conversationId)->orderBy('message_id')->get();
        $messages = [];

        $made = (new Artifacts($this->scope))->forConversation($conversationId);
        while (($row = $result->fetch()) !== null) {
            $messages[] = self::message($row) + ['artifacts' => $made[(int) $row['message_id']] ?? []];
        }

        return [
            'conversation_id' => $conversationId,
            'title'           => (string) $conversation['title'],
            'tags'            => self::tagsOf($conversation),
            // Ended by the assistant (Ending): it takes no more questions.
            'ended'           => !empty($conversation['ended_at']),
            // A work conversation (Work): the business's data, kept out of what is learned.
            'mode'            => Work::in(self::tagsOf($conversation)) ? Work::MODE : null,
            'messages'        => $this->assistant->present($this->scope, $messages),
        ];
    }

    /** Forget a conversation and its messages. False when there was none of this person's. */
    public function delete(int $conversationId): bool
    {
        if ($this->conversation($conversationId) === null) {
            return false;
        }

        $this->table('assist.chat_messages')->where('conversation_id', $conversationId)->delete();
        (new Artifacts($this->scope))->deleteConversation($conversationId);
        Shares::forget(Shares::CONVERSATION, $conversationId);
        $this->scope->apply($this->table('assist.chat_conversations'))->where('conversation_id', $conversationId)->delete();

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

        $chosen = $this->client === null && $scope->userId > 0 ? ModelChoices::chosen(new \Pramnos\User\User($scope->userId)) : null;

        return $router->route($router->context(['purpose' => $scope->purpose()] + $scope->context()), $chosen !== null ? ModelChoices::connection($chosen) : $this->assistant->connection($scope));
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

        // A persona with a picture of its own answers with it: its moods, acts and wear.
        $set = Personas::avatarSet($this->scope);
        if ($set !== null && !$this->inPersona) {
            $this->inPersona = true;
            try {
                return \Pramnos\Assist\AvatarSets::using($set, fn (): array => $this->ask($conversationId, $question, $attachment, $extra),
                    Personas::get((string) Personas::of($this->scope))['name'] ?? null);
            } finally {
                $this->inPersona = false;
            }
        }

        $this->traceId = self::newTrace();
        $question      = mb_substr(trim($question), 0, self::CHARS);
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

            // Ended by the assistant (Ending): no more questions here; a new conversation is open to them.
            if (!empty($found['ended'])) {
                return self::refusal('ended', Ending::SAYS);
            }

            $held    = $found['tags'];
            $row     = (array) $this->conversation($conversationId);
            $summary = trim((string) ($row['summary'] ?? ''));

            foreach ($found['messages'] as $turn) {
                // What the summary already holds is not sent again.
                if ((int) $turn['message_id'] <= (int) ($row['summary_upto'] ?? 0)) {
                    continue;
                }
                $history[] = ['role' => $turn['role'], 'content' => $turn['content'] . $this->whatItDid($turn['tools'] ?? []) . self::whereItStopped($turn)];
                $ids[]     = (int) $turn['message_id'];
                $lastContext = $turn['role'] === 'assistant' ? (int) ($turn['context'] ?? 0) : $lastContext;
            }
        }

        // The model the person chose, checked again now; or, on Automatic, one among those they may
        // choose; or the tier the question needs, where the application has tiers — never over a
        // client the caller gave, which is its choice.
        $tier    = null;
        $choice  = null;
        $allowed = [];
        if ($this->client === null) {
            $user    = $this->scope->userId > 0 ? new \Pramnos\User\User($this->scope->userId) : null;
            $choice  = ModelChoices::chosen($user);
            $allowed = $choice === null ? ModelChoices::allowed($user) : [];
            $cascade = \Pramnos\Llm\Llm::cascade();
            $context = ['purpose' => $this->scope->purpose()] + $this->scope->context();
            if ($choice === null && count($allowed) > 1 && $cascade instanceof \Pramnos\Llm\Policy\ModelCascade) {
                $keys   = array_column($allowed, 'key');
                $picked = $this->heldTier($conversationId, fn (): ?string => $cascade->choose($context, $question, $history, $allowed), 'choice-', $keys);
                $choice = $allowed[(int) array_search($picked, $keys, true)] ?? null;
                $choice = $picked === null ? null : $choice;
            } elseif ($choice === null) {
                $allowed = [];
                $tier    = $this->heldTier($conversationId, fn (): ?string => $cascade->tier($context, $question, $history));
            } else {
                // Chosen by the person: nothing to fall back to but what they chose.
                $allowed = [];
            }
        }
        $client = $choice !== null ? $this->chosen($choice) : ($this->tiered($tier) ?? $this->client($this->scope));

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
        $tools     = $this->grouped($this->buildTools($turn, $memory, $routines, $artifacts), $turn);

        // Reads asked for at once run at once, shared with the `llm_tool` workers, where the
        // application turned it on: a worker builds these same tools again from this.
        $parallel = ParallelTools::forAnswer([
            'space'           => $this->scope->stored(),
            'userid'          => $this->scope->userId,
            'organization_id' => $this->scope->organizationId,
            'conversation_id' => $conversationId,
            'scheduled'       => $this->scheduled,
            'extra'           => $extra,
        ]);

        // In three zones, the part that never changes first, so the provider's cache keeps the most.
        // How the person feels changes with a word, so it goes last, where nothing is cached anyway.
        // The assistant's own mood, where the application turned it on: said by the model in a tag,
        // kept with the conversation, given back as tone (Mood).
        $mood   = Mood::on($this->scope) && !$this->scheduled && !$turn->shared() ? new Mood($this->scope) : null;
        // The mood that reached this conversation from the person's others, as the model is told it.
        $bled   = $mood?->bled($conversationId ?? 0);
        // What the answer does and wears, picked the same way (Acts).
        $acts   = Acts::on($this->scope) && !$this->scheduled && !$turn->shared();
        // …and whether it may end the conversation of somebody who keeps insulting it (Ending).
        $ending = Ending::on($this->scope) && !$this->scheduled && !$turn->shared() && $this->ends();
        $tagged = $mood !== null || $acts || $ending;
        $zones  = (new ContextAssembler($this->assistant))->alsoNow(
            (!$turn->shared() && Profile::on($this->scope) && Profile::available() ? (new Profile($this->scope))->moodContext() : '')
            . ($mood === null ? '' : "\n\n" . trim($mood->directive($conversationId) . "\n" . Mood::instruction()))
            . ($acts ? "\n\n" . Acts::instruction() : '')
            . ($ending ? "\n\n" . Ending::instruction() : '')
            . (Work::in($held) ? "\n\n" . Work::instruction() : '')
            . ($this->scope->space === Scope::CHAT && !$this->scheduled && !$turn->shared() && ($soon = $this->upcoming()) !== '' ? "\n\n" . $soon : '')
        )->since($this->scheduled || $turn->shared() ? null : $this->sinceLast());
        $prefix = $zones->staticPrefix($turn);
        $zones->checkPrefix($prefix, $conversationId);
        $semi   = ($turn->shared() ? trim($this->assistant->context($turn)) : $this->context($turn, $memory))
            . ($summary === '' ? '' : "\n\nEarlier in this conversation, in short (those messages are not shown again):\n" . $summary);

        $sent = $zones->messages($turn, $semi, $messages);
        $onText = !$tagged || $this->onText === null ? $this->onText : fn (string $soFar) => ($this->onText)(Ending::strip(Acts::strip(Mood::strip($soFar))));
        $run  = function (\Pramnos\Llm\Client $client, array $sent) use ($prefix, $tools, $turn, $parallel, $onText): array {
            $answer = $client->onText($onText)->onTool($this->onTool)->cancelWhen($this->stopWhen)->inParallel($parallel)->answering($this->answerKey)->askWithTools(
                $prefix,
                $sent,
                $tools,
                $this->assistant->rounds(),
                $this->assistant->due($turn),
                $this->verifier($turn, $tools)
            );
            $client->onText(null)->onTool(null)->cancelWhen(null)->inParallel(null)->answering(null);

            return $answer;
        };
        $answer = $run($client, $sent);

        // The frontier tier down: answered on the fast one instead, which is told so.
        if (!$answer['ok'] && $tier === 'frontier' && !isset($answer['cancelled']) && !isset($answer['consent']) && !isset($answer['limited'])
            && ($fast = $this->tiered('fast')) !== null
            && array_filter($held, static fn (string $tag): bool => !$fast->moveFor($tag)) === []) {
            $sent[count($sent) - 2]['content'] .= "\n\n" . self::DEGRADED;
            $answer = $run($fast, $sent);
        }

        // Automatic's pick down: answered on the cheapest the person may choose, which is told so.
        if (!$answer['ok'] && $choice !== null && $allowed !== [] && $allowed[0]['key'] !== $choice['key']
            && !isset($answer['cancelled']) && !isset($answer['consent']) && !isset($answer['limited'])
            && array_filter($held, fn (string $tag): bool => !$this->chosen($allowed[0])->moveFor($tag)) === []) {
            $sent[count($sent) - 2]['content'] .= "\n\n" . self::DEGRADED;
            $answer = $run($this->chosen($allowed[0]), $sent);
        }

        if ($answer['ok']) {
            ContextAssembler::cacheReport((array) ($answer['usage'] ?? []), count($messages) > 1, $conversationId);
        }

        if (!$answer['ok']) {
            return self::refusal(isset($answer['cancelled']) ? 'cancelled' : (isset($answer['consent']) ? 'consent' : (isset($answer['limited']) ? 'limit' : 'provider')), (string) $answer['says']);
        }

        $turn->tags = array_values(array_unique([...$held, ...(array) ($answer['tags'] ?? [])]));
        $stamp      = CallLog::instant(time());

        if ($conversationId === null) {
            $this->table('assist.chat_conversations')->insert($this->scope->columns() + [
                'title'      => self::titleFor($asked),
                'tags'       => $turn->tags === [] ? null : json_encode($turn->tags),
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $conversationId = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
        } else {
            $this->scope->apply($this->table('assist.chat_conversations'))->where('conversation_id', $conversationId)
                ->update(['updated_at' => $stamp, 'tags' => $turn->tags === [] ? null : json_encode($turn->tags)]);
        }

        $turn->conversationId = $conversationId;
        // A reminder asked for in a first question leads back to the conversation it started.
        $routines->linkMade($conversationId);

        // Who asked it, where the account is shared — a parent, a child (FW-442).
        $this->store($conversationId, 'user', $question, [], null, $stamp, null,
            is_array($turn->extra['speaker'] ?? null) ? ['speaker' => $turn->extra['speaker']] : []);
        // The answer alone, and what the model said while it called tools beside it: those lines
        // ("Let me check…") are steps, not the start of the answer — kept, folded away, and left out
        // of the copy and of the history the next question replays.
        $steps   = array_values(array_filter(array_map('strval', (array) ($answer['steps'] ?? [])), static fn (string $one): bool => trim($one) !== ''));
        // An answer cut at its budget or its rounds is remembered as cut, so the next question
        // goes on from what it read instead of reading it again (whereItStopped()).
        $stopped = !empty($answer['over_budget']) ? 'budget' : (!empty($answer['out_of_rounds']) ? 'rounds' : null);
        $reply   = (string) ($answer['final'] ?? $answer['reply']);
        // A model that wrote its answer, then called a tool and said nothing more, left the answer
        // among its steps and nothing to show or read aloud: the steps are the answer then — with
        // whatever tags the last round held.
        if ($steps !== [] && trim($tagged ? Ending::strip(Acts::strip(Mood::strip($reply))) : $reply) === '') {
            $reply = implode("\n\n", $steps) . ($reply === '' ? '' : "\n" . $reply);
            $steps = [];
        }
        $felt    = $mood === null ? null : Mood::parse($reply);
        // Felt before the answer is stored, so the answer keeps the mood it was given in — unless
        // the model only said back the mood that reached it from another conversation: kept, it
        // would reach the next one in turn, and never fade.
        if ($mood !== null && $felt !== null && $felt['mood'] !== $bled) {
            $mood->feel($conversationId, $felt['mood'], $felt['intensity']);
        }
        $feels   = $mood?->now($conversationId);
        $message = $this->store($conversationId, 'assistant', self::withoutMark($this->polished($turn, $tagged ? Ending::strip(Acts::strip(Mood::strip($reply))) : $reply)),
            (array) ($answer['calls'] ?? []), $answer['cost'], $stamp, $answer['context'] ?? null,
            // With moods on, `mood` is always there — null when nothing is felt — so a reader tells
            // "no mood" from "a backend without moods".
            array_filter(['steps' => $steps, 'stopped' => $stopped, 'source' => $this->console ? 'console' : null,
                'mode' => Work::in($turn->tags) ? Work::MODE : null]) + ($mood === null ? [] : ['mood' => $feels])
            + ($acts ? ['act' => Acts::act($reply), 'wear' => Acts::worn($reply)] : [])
            + ($ending && Ending::ends($reply) ? ['ended' => true] : []));
        if ($ending && Ending::ends($reply)) {
            $this->scope->apply($this->table('assist.chat_conversations'))->where('conversation_id', $conversationId)->update(['ended_at' => $stamp]);
        }
        $message['artifacts'] = $artifacts->link($conversationId, (int) $message['message_id'], $turn->tags);

        // What the person stated, kept — once several of their messages have gathered. An answer
        // others read, or a scheduled run, is nothing of the person's to learn from.
        // A work conversation is the business's data, not the person's life: nothing learned from it (Work).
        if (Memory::learns() && !$turn->shared() && !$this->scheduled && !$this->console && !Work::in($turn->tags)) {
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
        $this->table('assist.chat_conversations')->insert($this->scope->columns() + [
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

        $this->traceId = self::newTrace();
        $messages      = [['role' => 'user', 'content' => mb_substr(trim($question), 0, self::CHARS)]];
        $turn          = new Turn($this->scope, null, true, $messages, [], $extra, $this);
        $client        = ($this->client ?? new Client($this->assistant->connection($this->scope)))
            ->about(['purpose' => $this->scope->purpose(), 'trace_id' => $this->trace()] + $this->scope->context() + $this->about);
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

        $zones  = new ContextAssembler($this->assistant);
        $answer = $client->askWithTools(
            $zones->staticPrefix($turn),
            // Not the maker's memory: a shared report is read by others, and what the maker told
            // the assistant about themselves is not theirs to read.
            $zones->messages($turn, trim($this->assistant->context($turn)), $messages),
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

    /**
     * For an earlier answer cut at its budget or its rounds, what it had already called, with the
     * arguments: the next question — "go on" — starts from there rather than from the beginning.
     *
     * @param array<string, mixed> $turn A stored message
     */
    public static function whereItStopped(array $turn): string
    {
        $why = (string) ($turn['extra']['stopped'] ?? '');
        if ($why === '' || ($turn['tools'] ?? []) === []) {
            return '';
        }
        $calls = array_map(
            static fn (array $call): string => ($call['name'] ?? '?') . '(' . mb_substr((string) json_encode($call['arguments'] ?? [], JSON_UNESCAPED_UNICODE), 0, 200) . ')',
            array_slice((array) $turn['tools'], 0, self::STOPPED_CALLS)
        );

        return "\n\n⟦This answer stopped at its " . ($why === 'budget' ? 'budget' : 'limit of rounds') . ' after calling: '
            . implode('; ', $calls) . '. Go on from there; do not repeat those calls. ⟧';
    }

    /**
     * The answer as the assistant's optional `polish(Turn, string): string` leaves it, before it is
     * stored — so the history, the memory and the screen all keep the corrected text. A polish that
     * throws or answers nothing leaves the answer as it was: a correction must never cost an answer.
     */
    private function polished(Turn $turn, string $answer): string
    {
        if (!method_exists($this->assistant, 'polish')) {
            return $answer;
        }
        try {
            $polished = (string) $this->assistant->polish($turn, $answer);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('polish() failed, the answer kept as it was: ' . $e->getMessage(), 'llm');

            return $answer;
        }

        return trim($polished) === '' ? $answer : $polished;
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
    /**
     * The evening's diary pages ({@see Diary}): for each person and persona who talked today and
     * whose evening has come, the day's page, written from the day's conversations — work ones
     * left out — once. Returns how many were written.
     */
    public function writeDiaries(int $now, int $limit = 20): int
    {
        if (!Diary::on() || !Artifacts::available()) {
            return 0;
        }
        $result = $this->table('assist.chat_conversations')->select(['organization_id', 'userid', 'space'])->distinct()
            ->where('updated_at', '>=', CallLog::instant($now - 26 * 3600))->whereLike('space', 'chat%')
            ->orderBy('userid')->limit(max(1, $limit) * 5)->get();
        $written = 0;
        while ($result && ($row = $result->fetch()) !== null && $written < $limit) {
            $scope = new Scope((int) $row['userid'], $row['organization_id'] === null ? null : (int) $row['organization_id'], (string) $row['space']);
            if ($scope->space !== Scope::CHAT) {
                continue;
            }
            $zone  = new \DateTimeZone($this->assistant->timezone($scope));
            $local = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
            $day   = $local->format('Y-m-d');
            $diary = new Diary($scope);
            if ($local->format('H:i') < str_pad(Diary::at(), 5, '0', STR_PAD_LEFT) || $diary->page($day) !== null) {
                continue;
            }
            $talk = $this->asTold($scope, (new \DateTimeImmutable($day . ' 00:00', $zone))->getTimestamp(), $now);
            if ($talk === '') {
                continue;
            }
            $name   = Personas::get((string) Personas::of($scope))['name'] ?? \Pramnos\Llm\Analyst\Analyst::name();
            // The application's own rules for the page — how its name is spelled, say — where its assistant has some.
            $rules  = method_exists($this->assistant, 'diaryRules') ? (string) $this->assistant->diaryRules($scope) : '';
            $person = trim((string) ((new \Pramnos\User\User($scope->userId))->firstname ?? ''));
            $answer = $this->client($scope)->askCheaply(Diary::prompt($name, $person, $rules), [['role' => 'user', 'content' => $talk]]);
            if (!empty($answer['ok']) && trim((string) ($answer['reply'] ?? '')) !== '' && is_array($diary->keep($day, (string) $answer['reply']))) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * The morning's thought for a day ({@see Today}): the one written, or written now — once, with
     * the cheapest model, from what the assistant knows of the person — and kept as a text
     * artifact `Thought Y-m-d` of the persona's. Null where it cannot be written.
     */
    public function thought(Scope $scope, string $day): ?string
    {
        $artifacts = new Artifacts($scope);
        foreach ($artifacts->search(['kind' => 'text', 'query' => Today::THOUGHT . ' ' . $day]) as $found) {
            if ($found['title'] === Today::THOUGHT . ' ' . $day) {
                return trim((string) ($artifacts->find((int) $found['artifact_id'])['content']['text'] ?? '')) ?: null;
            }
        }
        $known = trim(implode("\n\n", array_filter([
            Profile::on($scope) && Profile::available() ? (new Profile($scope))->context() : '',
            Diary::on($scope) ? (new Diary($scope))->recall($day) : '',
            Calendar::on($scope) && Calendar::available() ? (new Calendar($scope, $this->assistant->timezone($scope)))->context() : '',
        ])));
        $name   = Personas::get((string) Personas::of($scope))['name'] ?? \Pramnos\Llm\Analyst\Analyst::name();
        $person = trim((string) ((new \Pramnos\User\User($scope->userId))->firstname ?? ''));
        $answer = $this->client($scope)->askCheaply(Today::prompt($name, $person), [['role' => 'user', 'content' => $known !== '' ? $known : '(nothing yet)']]);
        $text   = trim((string) ($answer['reply'] ?? ''));
        if (empty($answer['ok']) || $text === '') {
            return null;
        }
        $artifacts->create(['kind' => 'text', 'title' => Today::THOUGHT . ' ' . $day, 'text' => $text]);

        return $text;
    }

    /**
     * A day's conversations of one scope, as a diary is written from them — the person's words and
     * the assistant's, in order, work conversations left out — up to {@see Diary::READS}.
     */
    private function asTold(Scope $scope, int $from, int $until): string
    {
        $chat  = $this->as($scope);
        $lines = [];
        $size  = 0;
        foreach ($chat->list(50) as $conversation) {
            $row = (array) $chat->conversation((int) $conversation['conversation_id']);
            if (Work::in(self::tagsOf($row))) {
                continue;
            }
            $messages = $this->table('assist.chat_messages')->select(['role', 'content'])
                ->where('conversation_id', (int) $conversation['conversation_id'])
                ->where('created_at', '>=', CallLog::instant($from))->where('created_at', '<=', CallLog::instant($until))->orderBy('message_id')->get();
            while ($messages && ($m = $messages->fetch()) !== null && $size < Diary::READS) {
                $line    = ($m['role'] === 'user' ? 'Them: ' : 'You: ') . mb_substr(trim((string) $m['content']), 0, 600);
                $lines[] = $line;
                $size   += mb_strlen($line);
            }
        }

        return implode("\n", $lines);
    }

    public function learnQuiet(int $now, int $limit = 20): int
    {
        if (!Memory::learns() || !$this->tracks()) {
            return 0;
        }

        $result = $this->table('assist.chat_conversations c')
            ->join('assist.chat_messages m', static fn (\Pramnos\Database\JoinClause $on) => $on
                ->on('m.conversation_id', '=', 'c.conversation_id')->on('m.message_id', '>', 'c.learned_upto'))
            ->select(['c.conversation_id', 'c.organization_id', 'c.userid', 'c.space'])->distinct()
            ->where('m.role', 'user')
            ->where('c.updated_at', '<', CallLog::instant($now - Memory::idle()))
            ->orderBy('c.conversation_id')->limit(max(1, $limit))->get();
        $read = 0;

        while ($result && ($row = $result->fetch()) !== null) {
            $this->traceId = self::newTrace();
            $scope         = new Scope((int) $row['userid'], $row['organization_id'] === null ? null : (int) $row['organization_id'], (string) $row['space']);
            $chat          = $this->as($scope);
            $client        = $this->client($scope);
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
        $row    = (array) $this->conversation($conversationId);
        $since  = (int) ($row['learned_upto'] ?? 0);
        // A work conversation: read, and nothing learned (Work).
        if (Work::in(self::tagsOf($row))) {
            $client = null;
        }
        $result = $this->table('assist.chat_messages')->select(['message_id', 'role', 'content'])
            ->where('conversation_id', $conversationId)->where('message_id', '>', $since)->orderBy('message_id')->get();
        $said    = [];
        $replied = [];
        $last    = $since;

        while ($result && ($row = $result->fetch()) !== null) {
            $last = (int) $row['message_id'];
            if ($row['role'] === 'assistant' && Memory::selfOn($this->scope)) {
                $replied[] = mb_substr((string) $row['content'], 0, 800);
            }
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
            $memory->learnConversation($client, implode("\n\n", $said), implode("\n\n", $replied));
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
        $this->table('assist.chat_conversations')->where('conversation_id', $conversationId)->update($values);
    }

    /** Whether chat_conversations has its summary and watermarks yet — asked once per instance. */
    /**
     * What is scheduled for the person in the next day, in their time, for zone 3 — so "is my alarm
     * set?" is answered from what is so, not from nothing.
     */
    private function upcoming(): string
    {
        $soon = (new Routines($this))->upcoming($this->scope);
        if ($soon === null) {
            return '';
        }
        if ($soon === []) {
            return 'Nothing is scheduled for this person in the next 24 hours.';
        }
        $zone  = new \DateTimeZone($this->assistant->timezone($this->scope));
        $lines = array_map(static fn (array $r): string => '- ' . (new \DateTimeImmutable($r['next_run_at'], new \DateTimeZone('UTC')))->setTimezone($zone)->format('D j M H:i')
            . ' — ' . $r['title'] . ' (' . $r['kind'] . ')', $soon);

        return "Scheduled for this person in the next 24 hours:\n" . implode("\n", $lines);
    }

    /**
     * What reads an answer against the calls it made: the assistant's own first, then a
     * promise of a reminder that nothing scheduled (Routines::unkept()), where schedule_task
     * is offered. One correction at most, the first that finds something.
     *
     * @param array<string, array<string, mixed>> $tools
     */
    private function verifier(Turn $turn, array $tools): ?callable
    {
        $own = $this->assistant->verify($turn);
        if (!isset($tools['schedule_task'])) {
            return $own;
        }

        return static fn (string $answer, array $calls): ?string => ($own === null ? null : $own($answer, $calls))
            ?? Routines::unkept($answer, $calls) ?? Routines::unchecked($answer, $calls);
    }

    /** Whether conversations can be ended here: the column exists. */
    private function ends(): bool
    {
        try {
            return $this->ends ??= \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn('assist.chat_conversations', 'ended_at');
        } catch (\Throwable) {
            return false;
        }
    }

    private function tracks(): bool
    {
        try {
            return $this->tracks ??= \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn('assist.chat_conversations', 'learned_upto');
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

    /**
     * A client on a tier's feature (`'llm' => ['tiers' => [name => feature]]`), marked as the
     * scope's other calls are; null for no tier, or one the application did not name.
     */
    private function tiered(?string $tier): ?\Pramnos\Llm\Client
    {
        $feature = $tier === null ? null : (((array) \Pramnos\Llm\Llm::config('tiers', []))[$tier] ?? null);
        if (!is_string($feature) || $feature === '' || $this->scope === null) {
            return null;
        }

        return (new \Pramnos\Llm\Client(new \Pramnos\Llm\Connection(null, $feature)))
            ->about(['purpose' => $this->scope->purpose(), 'tier' => $tier, 'trace_id' => $this->trace()] + $this->scope->context() + $this->about);
    }

    /**
     * Seconds since this person's last conversation in this scope moved — read before this answer
     * moves it — or null for none, or a database that cannot say.
     */
    private function sinceLast(): ?int
    {
        try {
            $db  = \Pramnos\Framework\Factory::getDatabase();
            $row = $this->scope->apply($this->table('assist.chat_conversations'))
                ->select([\Pramnos\Database\Instant::unix($db, 'updated_at', 'at')])
                ->orderBy('updated_at', 'desc')->first();
        } catch (\Throwable) {
            return null;
        }

        return !$row || $row->numRows < 1 || ($row->fields['at'] ?? null) === null ? null : max(0, time() - (int) $row->fields['at']);
    }

    /** A client on a model the person may choose ({@see ModelChoices}), marked as the scope's other calls are. */
    private function chosen(array $choice): Client
    {
        return (new Client(ModelChoices::connection($choice)))
            ->about(['purpose' => $this->scope?->purpose() ?? 'chat', 'model_choice' => $choice['key'], 'trace_id' => $this->trace()] + ($this->scope?->context() ?? []) + $this->about);
    }

    /** The client a call in this scope is made with. */
    private function client(Scope $scope): Client
    {
        return ($this->client ?? new Client($this->assistant->connection($scope)))
            ->about(['purpose' => $scope->purpose(), 'trace_id' => $this->trace()] + $scope->context() + $this->about);
    }

    /** One id for every call of the turn under way, made fresh by {@see newTrace()}. */
    private function trace(): string
    {
        return $this->traceId ??= self::newTrace();
    }

    /** A new id for one turn — short enough for a log line, random enough never to repeat. */
    private static function newTrace(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * The assistant's context, then what is remembered about the person.
     */
    private function context(Turn $turn, Memory $memory): string
    {
        $remembered = $memory->all();
        // What the assistant said about itself goes apart: it is its own word to keep, not the person's.
        $itself     = array_values(array_filter($remembered, static fn (array $m): bool => $m['kind'] === Memory::SELF));
        $remembered = array_values(array_filter($remembered, static fn (array $m): bool => $m['kind'] !== Memory::SELF));
        $lines      = [trim($this->assistant->context($turn)), ''];
        $lines[]    = $remembered === []
            ? 'You remember nothing about this person yet.'
            : 'What you remember about this person from earlier conversations (id, kind, as of):';

        // Dated, so a newer word is known to be newer: the latest wins.
        foreach ($remembered as $one) {
            $lines[] = '- [' . $one['memory_id'] . ', ' . $one['kind'] . ', ' . substr($one['created_at'], 0, 10) . '] ' . $one['content'];
        }

        if ($itself !== []) {
            $lines[] = '';
            $lines[] = 'What you have said about yourself to this person — keep to it, never contradict it:';
            foreach ($itself as $one) {
                $lines[] = '- ' . $one['content'];
            }
        }

        $shared = $memory->shared();
        if ($shared !== []) {
            $lines[] = '';
            $lines[] = 'What this organisation keeps about its shared work, for everybody in it (id, kind, as of):';
            foreach ($shared as $one) {
                $lines[] = '- [' . $one['memory_id'] . ', ' . $one['kind'] . ', ' . substr($one['created_at'], 0, 10) . '] ' . $one['content'];
            }
        }

        // The assistant's own diary, its latest page (Diary): what it wrote, it remembers.
        if (Diary::on($this->scope) && !$turn->shared()) {
            $today  = (new \DateTimeImmutable('now', new \DateTimeZone($this->assistant->timezone($this->scope))))->format('Y-m-d');
            $recall = (new Diary($this->scope))->recall($today);
            if ($recall !== '') {
                $lines[] = '';
                $lines[] = $recall;
            }
        }

        // What the person pinned, as it stands at the start of this question: a change made during
        // the answer is in its tool result now, and here from the next question on.
        $pinned = (new Artifacts($this->scope))->pinnedContext();
        if ($pinned !== '') {
            $lines[] = '';
            $lines[] = $pinned;
        }

        // The person's own, where the application turned them on: what they confirmed about
        // themselves and what waits for their word, and today in their calendar.
        if ($this->scope !== null && !$turn->shared()) {
            $own = [
                Profile::on($this->scope) && Profile::available() ? (new Profile($this->scope))->context() : '',
                Calendar::on($this->scope) ? (new Calendar($this->scope, $this->assistant->timezone($this->scope)))->context() : '',
            ];
            foreach (array_filter($own) as $part) {
                $lines[] = '';
                $lines[] = $part;
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

        $result = $this->scope->apply($this->table('assist.chat_conversations'))->where('conversation_id', $conversationId)->first();

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

        $this->table('assist.chat_messages')->insert($row);

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

        $extra = is_array($extra) ? $extra : [];

        return [
            'message_id' => (int) $fields['message_id'],
            'role'       => (string) $fields['role'],
            'content'    => (string) $fields['content'],
            'tools'      => is_array($tools) ? $tools : [],
            'cost'       => ($fields['cost'] ?? null) === null ? null : (float) $fields['cost'],
            'context'    => ($fields['context_tokens'] ?? null) === null ? null : (int) $fields['context_tokens'],
            'extra'      => $extra,
            // What the person thought of it: 1, -1, or null — null, too, before the columns exist.
            'rating'     => ($fields['rating'] ?? null) === null ? null : (int) $fields['rating'],
            'created_at' => (string) $fields['created_at'],
        // The assistant's mood as it gave this answer (Mood), where moods were on; in the history too.
        ] + array_map(static fn ($v): ?array => is_array($v) ? $v : null,
            // …and what it did and wore (Acts), where the set has acts or wear.
            array_intersect_key($extra, array_flip(['mood', 'act', 'wear'])))
            // Who asked, where the account is shared (FW-442).
            + (is_array($extra['speaker'] ?? null) ? ['speaker' => $extra['speaker']] : [])
            // The answer that ended the conversation (Ending).
            + (!empty($extra['ended']) ? ['ended' => true] : [])
            // An answer of a work conversation (Work).
            + (($extra['mode'] ?? null) === Work::MODE ? ['mode' => Work::MODE] : []);
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
        $tools    = $this->assistant->tools($turn)
            // The web, where the application offers it: nobody's own, so in a shared answer too.
            + (\Pramnos\Llm\Web\Web::on($this->scope) ? (new \Pramnos\Llm\Web\Web())->tools() : [])
            // The external MCP servers, where the application offers them here: the application's.
            + (self::mcpOn() ? \Pramnos\Llm\Mcp\Servers::tools() : [])
            + ($turn->shared() ? [] : $memory->tools($this, $turn->conversationId)
            // Not from a scheduled run: a task that schedules tasks has no end.
            + ($this->scheduled ? [] : self::inSkill($routines->toolsFor($this->scope, $this->assistant->timezone($this->scope), $turn->conversationId), 'tasks',
                // On the conversation page, "remind me at ten" must not wait for the model to open
                // the tasks skill first: it reaches for set_busy instead and promises a reminder
                // nothing sends.
                // Nor "is my alarm set?" for it to list them.
                $this->scope->space === Scope::CHAT ? ['schedule_task', 'list_scheduled_tasks'] : [])
                // Kept with a conversation the person can open: a scheduled run has none yet.
                + self::inSkill($artifacts->tools(), 'artifacts', ['save_artifact'])
                // And changes to approve, where the application turned them on: nobody approves in a run.
                + (new Proposals($this->scope))->tools($artifacts)
                // A way of working kept as one of the person's skills, on their word.
                + (new StoredSkills())->tools($this->scope))
            // The person's profile, calendar and journal, where the application turned each on.
            + $this->personalTools()
            // The business's own data, for a person the application lets work here (Work).
            + (Work::allowed($this->scope) ? Work::tools($turn) : []));

        // A change is made only on the person's word: after reading what somebody else wrote, the
        // client holds these back (Client::HELD_WRITE).
        foreach ($this->assistant->writes() as $name) {
            if (isset($tools[$name])) {
                $tools[$name]['writes'] = true;
            }
        }

        return $tools;
    }

    /**
     * Where `'llm' => ['chat' => ['skills' => true]]`: the tools of no skill, and `use_skill` for
     * the rest — a skill's tools are added to the answer once the model turns it on, so a model
     * with many tools starts from a short list. Off, every tool, as they are. On by default past
     * {@see SKILLS_FROM} tools, or when the application has skill files ({@see Skills}).
     *
     * @param array<string, array<string, mixed>> $tools
     * @return array<string, array<string, mixed>>
     */
    private function grouped(array $tools, ?Turn $turn = null): array
    {
        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $files = Skills::load();
        // A role's and the person's own (StoredSkills): only the tools the answer already has — a
        // skill opens nothing its reader may not use — and never under a name of the files'.
        $stored = [];
        // The application's from the catalogues (CatalogSkills), to whoever reads the answer, then
        // a role's and the person's own.
        $offered = $turn !== null ? (new CatalogSkills())->forReader($turn->rightsOf(), $files) + (new StoredSkills())->forTurn($turn) : [];
        foreach ($offered as $name => $skill) {
            $skill['tools'] = array_values(array_filter($skill['tools'], static fn (string $tool): bool => isset($tools[$tool])));
            $stored[$name]  = $skill;
        }
        // Skill files mean skills: a method is no use if nothing can turn it on.
        $skills = is_array($chat) && array_key_exists('skills', $chat) ? (bool) $chat['skills'] : (count($tools) > self::SKILLS_FROM || $files !== [] || $stored !== []);
        if (!$skills) {
            return $tools;
        }

        $registry = (new \Pramnos\Llm\ToolRegistry())->add($tools);
        $notes    = self::SKILLS + (method_exists($this->assistant, 'skills') ? (array) $this->assistant->skills() : []);
        foreach ($notes as $skill => $note) {
            $registry->describeSkill((string) $skill, (string) $note);
        }
        // The application's skill files: a specialist's method, the tools that go with it, and
        // its references, read on demand.
        Skills::declare($registry, $files + $stored);
        $context = $this->scope?->context() ?? [];

        return $registry->tools($context, []) + $registry->skillTool($context);
    }

    /**
     * Mark tools as a skill's, but those named in `$except`, which stay offered always.
     *
     * @param array<string, array<string, mixed>> $tools
     * @param list<string>                        $except
     * @return array<string, array<string, mixed>>
     */
    /**
     * The tier a conversation is on, decided once and held for {@see CASCADE_HOLD} questions
     * (`'llm' => ['cascade_hold' => …]`, 1 to decide every time): the decision is a model call of
     * its own, and a conversation rarely changes its nature from one message to the next. A new
     * conversation, or one the cache cannot hold for, decides each time. Automatic among the
     * models a person may choose is held the same way, under `choice-`, and a held pick that is not
     * among `$valid` any more is decided again.
     *
     * @param callable(): ?string $decide
     * @param list<string>|null   $valid
     */
    private function heldTier(?int $conversationId, callable $decide, string $prefix = 'tier-', ?array $valid = null): ?string
    {
        $hold = (int) \Pramnos\Llm\Llm::config('cascade_hold', self::CASCADE_HOLD);
        if ($conversationId === null || $hold <= 1 || ($valid === null && \Pramnos\Llm\Llm::config('tiers', []) === [])) {
            return $decide();
        }
        $key = $prefix . $conversationId;
        try {
            // Held for an hour at most: a conversation picked up the next day is decided again.
            $cache = \Pramnos\Cache\Cache::getInstance('llm');
            $held  = json_decode((string) $cache->load($key), true);
        } catch (\Throwable) {
            return $decide();
        }
        // A held pick the person may no longer have is decided again.
        if (is_array($held) && (int) ($held['left'] ?? 0) > 0 && (int) ($held['until'] ?? 0) > time()
            && ($valid === null || in_array($held['tier'] ?? null, $valid, true))) {
            $tier = $held['tier'] === null ? null : (string) $held['tier'];
            $held = ['left' => (int) $held['left'] - 1] + $held;
        } else {
            $tier = $decide();
            $held = ['tier' => $tier, 'left' => $hold - 1, 'until' => time() + 3600];
        }
        try {
            $cache->save((string) json_encode($held), $key);
        } catch (\Throwable) {
            // A cache that cannot keep it costs a decision next time, never the answer.
        }

        return $tier;
    }

    /** Whether the application offers its external MCP servers' tools in conversations: `'llm' => ['chat' => ['mcp' => true]]`. */
    public static function mcpOn(): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat) && !empty($chat['mcp']);
    }

    /**
     * The profile's, the calendar's and the journal's tools, each where the application turned it
     * on, one set per answer: a proposal made in it is known to it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function personalTools(): array
    {
        if ($this->scope === null) {
            return [];
        }
        $zone = $this->assistant->timezone($this->scope);

        // The servers the person connected themselves: theirs, so never in a shared answer, which
        // never comes this way.
        $connected = $this->scope->space === Scope::CHAT && \Pramnos\Llm\Mcp\Connections::on()
            ? (new \Pramnos\Llm\Mcp\Connections($this->scope))->tools()
            : [];

        return $connected
            + (Profile::on($this->scope) ? self::inSkill((new Profile($this->scope))->tools(), 'profile') : [])
            + (Calendar::on($this->scope) ? self::inSkill((new Calendar($this->scope, $zone))->tools(), 'calendar', ['set_busy']) : [])
            + (Journal::on($this->scope) ? self::inSkill((new Journal($this->scope, $zone))->tools(), 'journal') : []);
    }

    public static function inSkill(array $tools, string $skill, array $except = []): array
    {
        foreach ($tools as $name => $tool) {
            if (!in_array($name, $except, true)) {
                $tools[$name]['skills'] = [...(array) ($tool['skills'] ?? []), $skill];
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
