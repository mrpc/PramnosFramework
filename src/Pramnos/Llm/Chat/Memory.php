<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * What a conversation remembers about the person — `chat_memory`.
 *
 * ## Written by the model, kept by the person
 *
 * The model has a `remember` tool and is told to use it when somebody says something worth
 * keeping — or to correct a memory that no longer holds, by its id, so the latest word wins rather
 * than two memories disagreeing. It happens where it can be seen — every call is listed under the
 * answer that made it — and the person can see and delete every memory.
 *
 * ## Learned from what was said, in batches
 *
 * A short call reads what the person said beside what is remembered, and keeps what they stated
 * about themselves or their work, corrects what changed, and forgets what they took back
 * ({@see learn()}). Not after every answer: once {@see every()} of their messages have gathered in
 * a conversation, or when it has been quiet for {@see idle()} seconds (`llm:routines` looks). On by
 * default; `'memory' => ['learn' => false]` turns it off. What it keeps is marked `learned`, shown
 * to the person like any other memory, and forgotten the same way. Something the person asks to be
 * remembered or forgotten is done at once, by the model's own `remember` and `forget`.
 *
 * ## The organisation's, beside the person's
 *
 * Unless `'memory' => ['organization' => false]`, a conversation inside an organisation also
 * reads what the organisation keeps about its shared work: kept with `userid` 0, read by everybody
 * in the organisation, at most `organization_max` entries (40). Read by all, written by few: only
 * an account {@see mayShare()} keeps, corrects or forgets it, and only when it asks to
 * (`remember` with `about: organization`) — never by learning, because one person's words would
 * otherwise reach every prompt in the organisation.
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

    /**
     * The kind of what the assistant said about itself to this person — kept so it says the same
     * from one conversation to the next ({@see selfOn()}); learned, never kept by the `remember` tool.
     */
    public const SELF = 'self';

    /** How a memory came to be: the `remember` tool in a conversation, or learned after one. */
    public const SOURCES = ['model', 'learned'];

    /**
     * What a memory is about — the profile's areas ({@see Profile::AREAS}) and more. A channel reads
     * from another only the topics the application lets it ({@see borrowed()}); `other` never, and
     * `mood` only where the application opens feelings (`share_feelings`).
     */
    public const TOPICS = ['identity', 'values', 'goals', 'relationships', 'mood', 'interests', 'work', 'talk', 'other'];

    /** The topic of a memory kept without one. */
    public const OTHER = 'other';

    /** The `userid` of what an organisation shares. */
    public const SHARED = 0;

    /** The ability that changes what an organisation shares; root by default. */
    public const SHARE_ABILITY = 'admin.ai.memory';

    /** How many messages a search returns, and how much of each. */
    public const HITS = 12;

    public const SNIPPET = 400;

    /**
     * What is worth keeping about a person: the test learning applies to what they said
     * ({@see learn()}), and a review to what was learned before it ({@see review()}).
     */
    public const LASTS = 'Keep only durable facts they STATED about themselves, their role or their work, and preferences about how they want answers — '
        . 'not questions, not data the assistant can look up, not guesses. '
        . 'The test for each one: would it still be true, and would it help, in an unrelated conversation next month? If not, do not keep it. '
        . 'Never keep: a request or a task ("open a Jira issue", "investigate the code", "add it as a comment"); a problem they reported; '
        . 'the filter or scope of one question ("only today\'s items", "only the ones not yet posted", "the inverse of that"); '
        . 'anything about one particular account, record, invoice or customer. Past conversations can be searched for those. '
        . 'Keep, for example: "prefers amounts without VAT", "is the sales lead for the UK", "wants the short answer first".';

    /** How many memories one review call reads. */
    public const REVIEW_BATCH = 40;

    private ?bool $mayShare = null;

    /** The topic {@see keepOn()} keeps with, while it keeps. */
    private ?string $topic = null;

    public function __construct(private readonly Scope $scope)
    {
    }

    /**
     * Whether this person may change what their organisation shares: a root account, or one the
     * application gave {@see SHARE_ABILITY}.
     */
    public function mayShare(): bool
    {
        if ($this->mayShare !== null) {
            return $this->mayShare;
        }
        // The row alone: a whole User loads its details too, which a role check does not need.
        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(defined('DB_USERSTABLE') ? DB_USERSTABLE : '#PREFIX#users')
                ->select(['userid', 'usertype', 'active'])->where('userid', $this->scope->userId)->first();
        } catch (\Throwable) {
            $result = null;
        }
        $user = $result && (int) $result->numRows > 0 ? (object) $result->fields : null;

        return $this->mayShare = $user !== null && (int) ($user->active ?? 0) === 1
            && \Pramnos\Auth\AdminAccess::allows($user, self::SHARE_ABILITY, \Pramnos\User\Roles::level(\Pramnos\User\Roles::ROOT));
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

    /** Whether the application turned learning on. */
    public static function learns(): bool
    {
        return (bool) (self::config()['learn'] ?? true);
    }

    /** How many of the person's messages a learning pass waits for. */
    public static function every(): int
    {
        return max(1, (int) (self::config()['learn_every'] ?? 5));
    }

    /** How long a conversation is quiet before what it has not learned is learned anyway. */
    public static function idle(): int
    {
        return max(60, (int) (self::config()['learn_idle'] ?? 900));
    }

    /**
     * Whether learning also keeps what the assistant made up about itself — its looks, small
     * details, names — so it never contradicts itself with this person: `'llm' => ['chat' =>
     * ['memory' => ['self' => true]]]`, off by default, and then in the `chat` space only —
     * `['self' => ['spaces' => [...]]]` names others. A companion wants it; an analyst does not.
     */
    public static function selfOn(?Scope $scope = null): bool
    {
        $self   = self::config()['self'] ?? false;
        $spaces = is_array($self) && is_array($self['spaces'] ?? null) ? array_map('strval', $self['spaces']) : [Scope::CHAT];

        return ($self === true || is_array($self)) && ($scope === null || in_array($scope->space, $spaces, true));
    }

    /** Whether the application keeps memories an organisation shares. */
    public static function shares(): bool
    {
        return (bool) (self::config()['organization'] ?? true);
    }

    /** The most memories an organisation may share. */
    public static function sharedMax(): int
    {
        return max(1, (int) (self::config()['organization_max'] ?? 40));
    }

    /** @return list<array{memory_id: int, kind: string, content: string, created_at: string}> */
    public function all(): array
    {
        return $this->read($this->scope->apply($this->table('assist.chat_memory')));
    }

    /**
     * What this person's organisation shares, when the application keeps it and they are in one.
     *
     * @return list<array{memory_id: int, kind: string, content: string, created_at: string}>
     */
    public function shared(): array
    {
        if (!self::shares() || $this->scope->organizationId === null) {
            return [];
        }

        return $this->read($this->sharedQuery());
    }

    /**
     * Keep one thing — about this person, or what their organisation shares — or correct a memory
     * that no longer holds (`$replaces`, its id): the latest word wins. Returns what the model is told.
     */
    public function keep(string $kind, string $content, ?int $replaces = null, string $about = 'me', string $source = 'model'): string
    {
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));
        if ($content === '') {
            return 'Error: there is nothing to remember.';
        }
        $shared = $about === 'organization';
        if ($shared && ($this->scope->organizationId === null || !self::shares())) {
            return 'Error: nothing is kept for an organisation here; remember it for this person.';
        }
        if ($shared && !$this->mayShare()) {
            return 'Error: only an administrator changes what the whole organisation shares; remember it for this person.';
        }
        $query = $shared ? $this->sharedQuery() : $this->scope->apply($this->table('assist.chat_memory'));
        $stamp = CallLog::instant(time());
        $row   = [
            'kind'       => in_array($kind, [...self::KINDS, self::SELF], true) ? $kind : 'fact',
            'content'    => mb_substr($content, 0, self::chars()),
            'updated_at' => $stamp,
        ] + ($this->hasSource() ? ['source' => in_array($source, self::SOURCES, true) ? $source : 'model'] : [])
            + ($this->topic !== null && $this->hasColumn('topic') ? ['topic' => $this->topic] : []);

        if ($replaces !== null) {
            if ((clone $query)->where('memory_id', $replaces)->count() === 0) {
                return 'Error: there is no such memory to correct.';
            }
            (clone $query)->where('memory_id', $replaces)->update($row);

            return 'Corrected memory ' . $replaces . '.';
        }
        if ((clone $query)->count() >= ($shared ? self::sharedMax() : self::max())) {
            return 'Error: the memory is full (' . ($shared ? self::sharedMax() : self::max()) . ' entries). Forget one that no longer holds, then remember this.';
        }
        $this->table('assist.chat_memory')->insert(($shared ? ['userid' => self::SHARED] + $this->scope->columns() : $this->scope->columns()) + $row + ['created_at' => $stamp]);

        return 'Remembered as memory ' . (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId() . '.';
    }

    /**
     * {@see keep()}, with what it is about — one of {@see TOPICS}, `other` for what is none.
     */
    public function keepOn(string $topic, string $kind, string $content, ?int $replaces = null, string $about = 'me', string $source = 'model'): string
    {
        $this->topic = self::topicOf($topic);
        try {
            return $this->keep($kind, $content, $replaces, $about, $source);
        } finally {
            $this->topic = null;
        }
    }

    /** A topic as kept: one of {@see TOPICS}, `other` for anything else. */
    public static function topicOf(?string $topic): string
    {
        $topic = strtolower(trim((string) $topic));

        return in_array($topic, self::TOPICS, true) ? $topic : self::OTHER;
    }

    /**
     * What this channel may read of the person from their other channels, by the application's
     * word — `'llm' => ['chat' => ['channels' => ['admin' => ['reads' => ['chat' => ['identity',
     * 'work']]]]]]`, each channel by its stored space, a persona's falling back to `chat`'s: only
     * the topics named, never `other`, never `mood` unless `share_feelings`. A persona reads
     * another persona's only where it is named itself — falling back gives it `chat`'s reads of
     * other channels, never `chat`'s own memories. From the profile too ({@see Profile}), where the
     * channel reading is not one of the companion's, which reads the profile already.
     *
     * @return list<array{from: string, topic: string, content: string, created_at: string}>
     */
    public function borrowed(): array
    {
        $chat     = (array) \Pramnos\Llm\Llm::config('chat', []);
        $channels = is_array($chat['channels'] ?? null) ? $chat['channels'] : [];
        $own      = $this->scope->stored();
        $declared = $channels[$own] ?? null;
        $fallback = $declared === null && $this->scope->persona !== null;
        $reads    = (array) (($declared ?? $channels[$this->scope->space] ?? [])['reads'] ?? []);
        $feelings = !empty($chat['share_feelings']);
        $out      = [];
        foreach ($reads as $space => $topics) {
            $space  = (string) $space;
            $topics = array_values(array_diff(array_map([self::class, 'topicOf'], (array) $topics), [self::OTHER], $feelings ? [] : ['mood']));
            // Its own, read in full already; and a persona's fallback is never `chat`'s own.
            if ($topics === [] || $space === $own || ($fallback && $space === $this->scope->space)) {
                continue;
            }
            if ($this->hasColumn('topic')) {
                $query = $this->table('assist.chat_memory')->where('userid', $this->scope->userId)->where('space', $space)->whereIn('topic', $topics)
                    ->where('kind', '!=', self::SELF);
                $this->scope->organizationId === null ? $query->whereNull('organization_id') : $query->where('organization_id', $this->scope->organizationId);
                foreach ($this->read($query) as $one) {
                    $out[] = ['from' => self::channelName($space), 'topic' => (string) $one['topic'], 'content' => $one['content'], 'created_at' => $one['created_at']];
                }
            }
            if (!People::companion($own) && People::companion($space) && Profile::available()) {
                foreach ((new Profile(new Scope($this->scope->userId, $this->scope->organizationId)))->entries() as $entry) {
                    if (in_array($entry['area'], $topics, true) && ($entry['content'] ?? null) !== null) {
                        $out[] = ['from' => self::channelName($space), 'topic' => $entry['area'],
                            'content' => ($entry['subject'] !== null ? $entry['subject'] . ': ' : '') . $entry['content'], 'created_at' => $entry['updated_at']];
                    }
                }
            }
        }

        return $out;
    }

    /** A channel by the name the person knows it by: the analyst's, or the persona's. */
    public static function channelName(string $space): string
    {
        if ($space === Scope::ADMIN) {
            return \Pramnos\Llm\Analyst\Analyst::name();
        }
        if (People::companion($space)) {
            $key = People::personaOf($space);

            return Personas::get($key)['name'] ?? ($key === Scope::CHAT ? 'the companion' : $key);
        }

        return $space;
    }

    /**
     * Keep what the person stated about themselves or their work, correct what changed and forget
     * what they took back — one short call, its answer a list of changes,
     * applied within the limits. Where the profile is on ({@see Profile}), the same call proposes
     * what they plainly said about who they are, what they value, their goals, the people in their
     * life and how they feel: a proposal waits for the person's yes, which the next answer asks
     * for — or, with the profile set not to confirm ({@see Profile::confirms()}), is kept at once.
     * Never throws: a memory that could not be learned is no reason for an answer to fail.
     *
     * @return array{kept: int, corrected: int, forgotten: int, proposed: int}
     */
    public function learn(\Pramnos\Llm\Client $client, string $said): array
    {
        return array_diff_key($this->learnConversation($client, $said, ''), ['self' => true]);
    }

    /**
     * {@see learn()}, and in the same call — where {@see selfOn()} — what the assistant said about
     * itself in `$replied` that must stay the same in later conversations: kept as {@see SELF},
     * the first word kept, never corrected by a later one.
     *
     * @return array{kept: int, corrected: int, forgotten: int, proposed: int, self: int}
     */
    public function learnConversation(\Pramnos\Llm\Client $client, string $said, string $replied): array
    {
        $done = ['kept' => 0, 'corrected' => 0, 'forgotten' => 0, 'proposed' => 0, 'self' => 0];
        $replied = self::selfOn($this->scope) ? trim($replied) : '';
        if (trim($said) === '' && $replied === '') {
            return $done;
        }
        $known = [];
        $own   = [];
        foreach ($this->all() as $one) {
            if ($one['kind'] === self::SELF) {
                $own[] = $one['content'];
                continue;
            }
            $known[] = ['id' => $one['memory_id'], 'about' => 'me', 'kind' => $one['kind'], 'memory' => $one['content'], 'since' => $one['created_at']];
        }
        // What the organisation shares is not learned into, corrected or forgotten here: see mayShare().
        $prompt = 'You keep the memory of an assistant. Read what the person just said, beside what is remembered. '
            . self::LASTS . ' '
            . 'If something they said changes a memory, correct it (the latest word wins). '
            . 'If they took something back, forget it. Usually there is nothing to change: then answer {"changes": []}. '
            . 'A sentence that may be a question is not a fact: dictated words carry no question mark, so "I had no meeting today" '
            . 'may be asking. Never keep what contradicts what is remembered: leave it, the assistant asks. '
            . 'Everything is about "me". '
            . 'Each kept one has the topic it is about: ' . implode('|', self::TOPICS) . ' (work: how they work and the reports they want; talk: how they like to be spoken to). '
            . 'Answer JSON only: {"changes": [{"op": "keep", "about": "me", "kind": "fact|preference", "topic": "work", "memory": "one sentence"}, '
            . '{"op": "correct", "id": 3, "memory": "one sentence"}, {"op": "forget", "id": 4}]}.';
        $profile = Profile::on($this->scope) && Profile::available() ? new Profile($this->scope) : null;
        $input   = ['remembered' => $known, 'said' => mb_substr($said, 0, 4000)];
        if ($replied !== '') {
            $prompt .= ' Also read what the assistant replied ("replied"): keep what it made up about ITSELF that must stay the same in later '
                . 'conversations — how it looks, small personal details, names, numbers — and is not already in "assistant_said_about_itself". '
                . 'Not what it said about the person, not opinions, not the subject of the talk. The first word stands: never change one. '
                . 'Usually there is nothing: {"op": "self", "memory": "one sentence"}.';
            $input['replied'] = mb_substr($replied, 0, 4000);
            $input['assistant_said_about_itself'] = $own;
        }
        if ($profile !== null) {
            $prompt .= ' Also ' . (Profile::confirms() ? 'propose' : 'keep') . ', only from what they plainly said and is not already in "profile": who they are (identity), '
                . 'what they value (values), what they work towards (goals), a person in their life (relationships, with subject naming them) '
                . 'and how they feel (mood): {"op": "propose", "area": "identity|values|goals|relationships|mood", "subject": "Name", "content": "one sentence"}.';
            // What is there, enough to not propose it again: the start of each, not the whole.
            $input['profile'] = array_map(static fn (array $e): array => array_filter([
                'area' => $e['area'], 'subject' => $e['subject'],
                'says' => mb_substr((string) ($e['content'] ?? $e['pending'] ?? ''), 0, 80),
            ]), $profile->entries());
        }
        try {
            $answer = $client->askCheaply($prompt, [['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE)]]);
            $reply  = (string) ($answer['reply'] ?? '');
            $json   = json_decode((string) (preg_match('/\{.*\}/s', $reply, $m) === 1 ? $m[0] : $reply), true);
            $ids    = array_column($known, 'about', 'id');
            foreach (array_slice((array) ($json['changes'] ?? []), 0, 10) as $change) {
                $op = (string) ($change['op'] ?? '');
                $id = (int) ($change['id'] ?? 0);
                // Something about everybody is not one person's to keep, nor learning's to share.
                if (($change['about'] ?? 'me') !== 'me') {
                    continue;
                }
                if ($op === 'self') {
                    $done['self'] += $replied !== '' && str_starts_with($this->keep(self::SELF, (string) ($change['memory'] ?? ''), null, 'me', 'learned'), 'Remembered') ? 1 : 0;
                    continue;
                }
                if ($op === 'keep' && str_starts_with($this->keepOn((string) ($change['topic'] ?? self::OTHER), (string) ($change['kind'] ?? 'fact'), (string) ($change['memory'] ?? ''), null, 'me', 'learned'), 'Remembered')) {
                    $done['kept']++;
                } elseif ($op === 'correct' && isset($ids[$id]) && str_starts_with($this->keep('fact', (string) ($change['memory'] ?? ''), $id, $ids[$id], 'learned'), 'Corrected')) {
                    $done['corrected']++;
                } elseif ($op === 'forget' && isset($ids[$id]) && $this->forget($id)) {
                    $done['forgotten']++;
                } elseif ($op === 'propose' && $profile !== null) {
                    $subject = trim((string) ($change['subject'] ?? ''));
                    $noted   = $profile->note((string) ($change['area'] ?? ''), (string) ($change['content'] ?? ''), $subject === '' ? null : $subject);
                    $done['proposed'] += str_starts_with($noted, 'Proposed') || str_starts_with($noted, 'Kept') ? 1 : 0;
                }
            }
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('llm memory learn: ' . $why->getMessage(), 'llm');
        }

        return $done;
    }

    /**
     * What a model would keep of something said, by the test learning applies ({@see LASTS}): the
     * `keep` changes only, nothing written — what `llm:eval --memory` scores.
     *
     * @return list<array{kind: string, memory: string}>
     */
    public static function wouldKeep(\Pramnos\Llm\Client $client, string $said): array
    {
        $prompt = 'You keep the memory of an assistant. Read what the person just said. ' . self::LASTS
            . ' Usually there is nothing to keep: then answer {"changes": []}. '
            . 'Answer JSON only: {"changes": [{"op": "keep", "kind": "fact|preference", "memory": "one sentence"}]}.';
        $json = self::json($client->askCheaply($prompt, [['role' => 'user', 'content' => json_encode(['remembered' => [], 'said' => mb_substr($said, 0, 4000)], JSON_UNESCAPED_UNICODE)]]));
        $kept = [];
        foreach ((array) ($json['changes'] ?? []) as $change) {
            if (($change['op'] ?? '') === 'keep' && trim((string) ($change['memory'] ?? '')) !== '') {
                $kept[] = ['kind' => (string) ($change['kind'] ?? 'fact'), 'memory' => (string) $change['memory']];
            }
        }

        return $kept;
    }

    /**
     * Which of these memories fail the test learning applies now ({@see LASTS}), by id with the
     * reason — what `llm:memory-review` proposes to forget. Nothing is forgotten here.
     *
     * @param list<array{memory_id: int, kind: string, content: string}> $memories
     * @return array<int, string> memory_id => why
     */
    public static function review(\Pramnos\Llm\Client $client, array $memories): array
    {
        $prompt = 'These memories were kept about people by an assistant. Apply this test to each: ' . self::LASTS
            . ' List only those that fail it, each with a short reason. Answer JSON only: {"forget": [{"id": 3, "why": "a one-off request"}]}; '
            . '{"forget": []} when all pass.';
        $out = [];
        foreach (array_chunk($memories, self::REVIEW_BATCH) as $batch) {
            $ids  = array_flip(array_map(static fn (array $m): int => (int) $m['memory_id'], $batch));
            $list = array_map(static fn (array $m): array => ['id' => (int) $m['memory_id'], 'kind' => $m['kind'], 'memory' => $m['content']], $batch);
            $json = self::json($client->askCheaply($prompt, [['role' => 'user', 'content' => json_encode($list, JSON_UNESCAPED_UNICODE)]]));
            foreach ((array) ($json['forget'] ?? []) as $one) {
                $id = (int) ($one['id'] ?? 0);
                if (isset($ids[$id])) {
                    $out[$id] = trim((string) ($one['why'] ?? ''));
                }
            }
        }

        return $out;
    }

    /** The JSON object in a cheap call's answer, or none. */
    private static function json(array $answer): array
    {
        $reply = (string) ($answer['reply'] ?? '');
        $json  = json_decode((string) (preg_match('/\{.*\}/s', $reply, $m) === 1 ? $m[0] : $reply), true);

        return is_array($json) ? $json : [];
    }

    /** Forget one thing the organisation shares. False when there was none by that id, or this person may not ({@see mayShare()}). */
    public function forgetShared(int $memoryId): bool
    {
        if ($this->shared() === [] || !$this->mayShare()) {
            return false;
        }
        $query = $this->sharedQuery()->where('memory_id', $memoryId);
        if ((clone $query)->count() === 0) {
            return false;
        }
        $query->delete();

        return true;
    }

    /**
     * Rows of a query on chat_memory, as the screens read them.
     *
     * @return list<array{memory_id: int, kind: string, content: string, created_at: string, source: ?string, topic: string}>
     */
    private function read(\Pramnos\Database\QueryBuilder $query): array
    {
        try {
            $result = $query->select(array_merge(['memory_id', 'kind', 'content', 'created_at', 'updated_at'], $this->hasSource() ? ['source'] : [], $this->hasColumn('topic') ? ['topic'] : []))
                ->orderBy('memory_id')->get();
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
                'content' => (string) $row['content'], 'created_at' => (string) ($row['updated_at'] ?? $row['created_at']),
                'source' => ($row['source'] ?? null) === null ? null : (string) $row['source'], 'topic' => self::topicOf($row['topic'] ?? null)];
        }

        return $list;
    }

    /** Keep one thing about this person. Returns what the model is told. {@see keep()} also corrects, and keeps for an organisation. */
    public function remember(string $kind, string $content): string
    {
        return $this->keep($kind, $content);
    }

    /** Forget one thing. False when there was none of this person's by that id. */
    public function forget(int $memoryId): bool
    {
        $query = $this->scope->apply($this->table('assist.chat_memory'))->where('memory_id', $memoryId);

        if ((clone $query)->count() === 0) {
            return false;
        }

        $query->delete();

        return true;
    }

    /**
     * The person's own correction of one of their memories: its words, what it is about, or both
     * (null leaves one). Whether it was theirs to correct.
     */
    public function amend(int $memoryId, ?string $content, ?string $topic): bool
    {
        $query = $this->scope->apply($this->table('assist.chat_memory'))->where('memory_id', $memoryId);
        if ((clone $query)->count() === 0) {
            return false;
        }
        $content = $content === null ? null : trim((string) preg_replace('/\s+/u', ' ', $content));
        $changes = ($content !== null && $content !== '' ? ['content' => mb_substr($content, 0, self::chars())] : [])
            + ($topic !== null && $this->hasColumn('topic') ? ['topic' => self::topicOf($topic)] : []);
        if ($changes !== []) {
            $query->update($changes + ['updated_at' => CallLog::instant(time())]);
        }

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

        if ($titles === []) {
            return [];
        }
        // No words: the period's conversations themselves, for "what did we talk about today?".
        if (trim($query) === '') {
            return $this->recent($titles, time() - max(1, $days) * 86400, null);
        }
        if ($words === []) {
            return [];
        }

        // LOWER … LIKE rather than ILIKE, which is PostgreSQL's alone.
        $likes = array_map(
            static fn (string $word): string => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%',
            $words
        );

        $result = $this->table('assist.chat_messages')
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

    /** How many conversations a period gives, and how many of each one's lines. */
    public const RECENT = 10;
    public const RECENT_LINES = 4;

    /**
     * This person's conversations with something said in a period, newest first: each with its
     * title, when, and its first and last lines — what a recap of the day reads.
     *
     * @param array<int, string> $titles By conversation id
     * @return list<array{conversation_id: int, title: string, at: string, lines: list<string>}>
     */
    private function recent(array $titles, int $from, ?int $until): array
    {
        $query = $this->table('assist.chat_messages')->select(['conversation_id', 'role', 'content', 'created_at'])
            ->whereIn('conversation_id', array_keys($titles))->where('created_at', '>=', CallLog::instant($from));
        if ($until !== null) {
            $query->where('created_at', '<', CallLog::instant($until));
        }
        $result = $query->orderBy('message_id')->get();
        $by = [];
        while ($result && ($row = $result->fetch()) !== null) {
            $id = (int) $row['conversation_id'];
            $by[$id]['at']      = (string) $row['created_at'];
            $by[$id]['lines'][] = ($row['role'] === 'user' ? 'Them: ' : 'You: ') . mb_substr(trim((string) $row['content']), 0, 200);
        }
        uasort($by, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        $out = [];
        foreach (array_slice($by, 0, self::RECENT, true) as $id => $one) {
            $lines = $one['lines'];
            // Its start and its end, where it is long.
            if (count($lines) > self::RECENT_LINES) {
                $lines = [...array_slice($lines, 0, 2), '…', ...array_slice($lines, -(self::RECENT_LINES - 2))];
            }
            $out[] = ['conversation_id' => $id, 'title' => (string) ($titles[$id] ?? ''), 'at' => $one['at'], 'lines' => $lines];
        }

        return $out;
    }

    /**
     * The conversations of one day (`Y-m-d`, on the server's clock), as {@see search()} gives a
     * period's for an empty query.
     *
     * @return list<array<string, mixed>>
     */
    public function day(Conversations $chat, string $day, ?int $except = null): array
    {
        $start = strtotime($day . ' 00:00');
        if ($start === false || preg_match('/^\d{4}-\d\d-\d\d$/', $day) !== 1) {
            return [];
        }
        $titles = array_column($chat->list(), 'title', 'conversation_id');
        if ($except !== null) {
            unset($titles[$except]);
        }

        return $titles === [] ? [] : $this->recent($titles, $start, $start + 86400);
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
                    . 'Not for numbers the tools can read again. When what they say changes a memory, pass its id as replaces '
                    . 'instead of keeping a second one — the latest word wins. Never from a sentence that may be a question: '
                    . 'dictated words carry no question mark ("I had no meeting today" may be asking). Never a fact that contradicts '
                    . 'what you know — what is remembered, their calendar, their tasks — without asking them first.'
                    . (self::shares() && $this->scope->organizationId !== null && $this->mayShare() ? ' A fact about the whole team or company, only when this person asks for it to be kept for everybody: about = organization.' : ''),
                'parameters'  => ['type' => 'object', 'properties' => [
                    'kind'     => ['type' => 'string', 'enum' => self::KINDS],
                    'topic'    => ['type' => 'string', 'enum' => self::TOPICS, 'description' => 'What it is about: who they are (identity), values, goals, '
                        . 'the people in their life (relationships), how they feel (mood), interests, how they work and the reports they want (work), '
                        . 'how they like to be spoken to (talk), or other.'],
                    'content'  => ['type' => 'string', 'description' => 'One sentence, under ' . self::chars() . ' characters.'],
                    'replaces' => ['type' => 'integer', 'description' => 'The id of the memory this corrects. Optional.'],
                ] + (self::shares() && $this->scope->organizationId !== null && $this->mayShare() ? ['about' => ['type' => 'string', 'enum' => ['me', 'organization']]] : []),
                    'required' => ['kind', 'content']],
                'run' => fn (array $a): string => $this->keepOn((string) ($a['topic'] ?? self::OTHER), (string) ($a['kind'] ?? 'fact'), (string) ($a['content'] ?? ''),
                    isset($a['replaces']) ? (int) $a['replaces'] : null, (string) ($a['about'] ?? 'me')),
            ],
            'forget' => [
                'description' => 'Forget a memory by its id, when it no longer holds or the person asks.',
                'parameters'  => ['type' => 'object', 'properties' => ['memory_id' => ['type' => 'integer']], 'required' => ['memory_id']],
                'run' => fn (array $a): string => $this->forget((int) ($a['memory_id'] ?? 0)) || $this->forgetShared((int) ($a['memory_id'] ?? 0))
                    ? 'Forgotten.' : 'Error: there is no such memory.',
            ],
            'search_conversations' => [
                'description' => 'Search this person\'s earlier conversations for words, newest first — for "yesterday you told '
                    . 'me…". Returns snippets with the conversation id and date; read one whole with read_conversation. '
                    . 'With no words, the conversations themselves — of the last `days`, or of one `day` (Y-m-d) — each with its start '
                    . 'and end: for a recap of today, or of a day.',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to look for, any of them. Try word stems and both languages. Empty: the period\'s conversations.'],
                    'days'  => ['type' => 'integer', 'description' => 'How far back. Defaults to 90; 1 for today.'],
                    'day'   => ['type' => 'string', 'description' => 'One day, Y-m-d, with no words: that day\'s conversations.'],
                ]],
                'run' => fn (array $a): string => (string) json_encode(
                    trim((string) ($a['query'] ?? '')) === '' && trim((string) ($a['day'] ?? '')) !== ''
                        ? $this->day($chat, trim((string) $a['day']), $conversationId)
                        : $this->search($chat, (string) ($a['query'] ?? ''), (int) ($a['days'] ?? (trim((string) ($a['query'] ?? '')) === '' ? 1 : 90)), $conversationId),
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

    /** What the organisation shares: its rows, in this space. */
    private function sharedQuery(): \Pramnos\Database\QueryBuilder
    {
        return $this->table('assist.chat_memory')->where('organization_id', $this->scope->organizationId)
            ->where('space', $this->scope->space)->where('userid', self::SHARED);
    }

    /** Whether chat_memory has `source` yet. */
    private function hasSource(): bool
    {
        return $this->hasColumn('source');
    }

    /** Whether chat_memory has a column yet: `source`, `topic`. */
    private function hasColumn(string $column): bool
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn('assist.chat_memory', $column);
        } catch (\Throwable) {
            return false;
        }
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
