<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Database\Instant;
use Pramnos\Llm\CallLog;

/**
 * The assistant's mood with a person: one it says itself, that lasts, and fades.
 *
 * Turned on with `'llm' => ['chat' => ['mood' => true]]` — the words are then the signals the
 * avatar set declares ({@see \Pramnos\Assist\AvatarSets::moods()}) — or with
 * `['mood' => ['words' => ['happy', 'sleepy', …]]]`. Only in the `chat` space unless
 * `'spaces'` names others. Off, nothing here runs.
 *
 * - **Said by the model.** Each answer ends with a private tag, `[[mood:happy|70]]`: a word from
 *   the vocabulary and how strongly, 0–100. It costs no call, and is taken out before the answer
 *   is stored or shown ({@see strip()}).
 * - **Per conversation, and per person.** The tag sets the conversation's mood ({@see feel()}):
 *   its latest word is the mood there. A strong one ({@see STRONG} and up) also colours the
 *   person's other conversations, at {@see DAMPEN} of its strength, where none of their own is
 *   felt; the strongest of those wins. Never global for everybody.
 * - **Fading.** {@see FADE} an hour, read rather than swept: nothing to schedule. Under
 *   {@see FELT} it is not felt, and the prompt says nothing.
 * - **Sensed before the answer, where the application says how** ({@see senseWith()}): the
 *   person's message read the moment it is sent — by words, or by a cheap model — for the avatar
 *   to react while the answer is still being written. Shown, not kept: the answer's own tag is the
 *   mood. Off unless the application gives a sensor.
 * - **As tone, never said.** The next answer is told the mood to colour its tone, and not to state
 *   or explain it. The application reads it — `mood` on the answer's message, or `GET {chat}/mood`
 *   — to show it on the avatar, idle included.
 */
final class Mood
{
    /** Under this it is not felt: no directive, and nothing to show. */
    public const FELT = 15;

    /** From this strength a mood also colours the person's other conversations. */
    public const STRONG = 60;

    /** How much of a strong mood reaches the person's other conversations. */
    public const DAMPEN = 0.8;

    /** Intensity lost per hour. */
    public const FADE = 25;

    /** The private tag the model ends an answer with. */
    public const TAG = '/\[\[\s*mood\s*:\s*([\p{L}\p{N}_-]+)\s*\|\s*(\d{1,3})\s*\]\]/u';

    /** @var (callable(string, Scope): (string|array|null))|null What reads a person's message for a mood */
    private static $sensor = null;

    public function __construct(private readonly Scope $scope)
    {
    }

    /**
     * Read each question for a mood before it is answered: `$sensor($message, $scope)` answers a
     * word of the vocabulary, `[word, intensity]`, `['mood' => …, 'intensity' => …]`, or null.
     * It is the assistant's mood in reply — a person's good news, `happy`; their sadness, a word
     * that cares. Null turns it off.
     *
     * ```php
     * Mood::senseWith(fn (string $message): ?string => preg_match('/!!|🎉|χαίρομαι/u', $message) ? 'happy' : null);
     * ```
     */
    public static function senseWith(?callable $sensor): void
    {
        self::$sensor = $sensor;
    }

    /** Whether a question here is read for a mood before it is answered. */
    public static function senses(Scope $scope): bool
    {
        return self::$sensor !== null && self::on($scope);
    }

    /**
     * The mood a question calls for, read before it is answered — for the avatar now, not kept:
     * the answer's own tag is the mood. Null when nothing is sensed, the word is not in the
     * vocabulary, or the sensor fails.
     *
     * @return array{mood: string, intensity: int, plays: string|null}|null
     */
    public static function sense(Scope $scope, string $message): ?array
    {
        if (!self::senses($scope) || trim($message) === '') {
            return null;
        }
        try {
            $said = (self::$sensor)($message, $scope);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('llm mood sensor: ' . $e->getMessage(), 'llm');

            return null;
        }
        $word      = mb_strtolower(trim((string) (is_array($said) ? ($said['mood'] ?? $said[0] ?? '') : ($said ?? ''))));
        $intensity = is_array($said) ? (int) ($said['intensity'] ?? $said[1] ?? self::STRONG) : self::STRONG;

        return in_array($word, self::words(), true)
            ? ['mood' => $word, 'intensity' => max(0, min(100, $intensity)), 'plays' => self::plays()[$word] ?? null]
            : null;
    }

    /**
     * The vocabulary, or [] when moods are off.
     *
     * @return list<string>
     */
    public static function words(): array
    {
        $config = ((array) \Pramnos\Llm\Llm::config('chat', []))['mood'] ?? false;
        if (is_array($config)) {
            return array_values(array_unique(array_filter(array_map('strval', (array) ($config['words'] ?? [])))));
        }

        return $config === true ? array_map('strval', array_keys(self::plays())) : [];
    }

    /**
     * Whether moods are on — there is a vocabulary — and, given a scope, in its space: the
     * conversation page's (`chat`) unless `['mood' => ['spaces' => [...]]]` names others, so the
     * administrators' analyst has none.
     */
    public static function on(?Scope $scope = null): bool
    {
        $config = ((array) \Pramnos\Llm\Llm::config('chat', []))['mood'] ?? false;
        $spaces = is_array($config) && is_array($config['spaces'] ?? null) ? array_map('strval', $config['spaces']) : [Scope::CHAT];

        return self::words() !== [] && ($scope === null || in_array($scope->space, $spaces, true));
    }

    /**
     * What each word plays on the avatar, as the set declares it — for the application to show.
     *
     * @return array<string, string>
     */
    public static function plays(): array
    {
        return \Pramnos\Assist\AvatarSets::moods();
    }

    /** What the model is told to end an answer with. */
    public static function instruction(): string
    {
        return 'End your answer with a private tag on a line of its own, [[mood:WORD|N]]: WORD is how THIS conversation makes you feel now, one of '
            . implode(', ', self::words()) . '; N is how strongly, 0 to 100. It is taken out before anyone reads the answer. '
            . 'Never mention or explain it.';
    }

    /**
     * The mood a reply says, or null for none or a word outside the vocabulary.
     *
     * @return array{mood: string, intensity: int}|null
     */
    public static function parse(string $reply): ?array
    {
        if (preg_match_all(self::TAG, $reply, $m, PREG_SET_ORDER) < 1) {
            return null;
        }
        $last = end($m);
        $word = mb_strtolower($last[1]);

        return in_array($word, self::words(), true) ? ['mood' => $word, 'intensity' => max(0, min(100, (int) $last[2]))] : null;
    }

    /** A reply without its tags — and, while it streams, without a tag still being written. */
    public static function strip(string $reply): string
    {
        return rtrim((string) preg_replace([self::TAG, '/\[\[[^\]]*\]?$/u'], '', $reply));
    }

    /** What is left of an intensity set `$seconds` ago. */
    public static function faded(int $intensity, int $seconds): int
    {
        return max(0, $intensity - intdiv(max(0, $seconds) * self::FADE, 3600));
    }

    /**
     * Set the conversation's mood from what the model said. Its latest word is the mood: it read the
     * whole conversation, so "good night" after good news is sleepy, not the news still.
     */
    public function feel(int $conversationId, string $mood, int $intensity): void
    {
        try {
            $this->scope->apply($this->table())->where('conversation_id', $conversationId)
                ->update(['mood' => $mood, 'mood_intensity' => $intensity, 'mood_at' => CallLog::instant(time())]);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('llm mood: ' . $e->getMessage(), 'llm');
        }
    }

    /**
     * The mood now: in a conversation, its own while it is felt, else the strongest a strong mood
     * elsewhere leaves for this person; without one, the strongest of the person's — what the
     * avatar shows while idle. Null when nothing is felt.
     *
     * @return array{mood: string, intensity: int, plays: string|null}|null
     */
    public function now(?int $conversationId = null): ?array
    {
        $felt = $this->felt($conversationId);

        return $felt === null ? null : array_diff_key($felt, ['from' => true]);
    }

    /**
     * The mood that reached a conversation from the person's others, or null when it has its own
     * or none: what the model must not simply say back ({@see feel()} is not called for that).
     * 0 is a conversation not started yet.
     */
    public function bled(int $conversationId): ?string
    {
        $felt = $this->felt($conversationId);

        return $felt !== null && $felt['from'] === 'elsewhere' ? $felt['mood'] : null;
    }

    /** Whether a strong mood reaches the person's other conversations: `['mood' => ['bleed' => false]]` keeps each to its own. */
    public static function bleeds(): bool
    {
        $config = ((array) \Pramnos\Llm\Llm::config('chat', []))['mood'] ?? false;

        return !is_array($config) || ($config['bleed'] ?? true) !== false;
    }

    /**
     * {@see now()}, and whether the mood is the conversation's own (`here`) or reached it from
     * another (`elsewhere`).
     *
     * @return array{mood: string, intensity: int, plays: string|null, from: string}|null
     */
    private function felt(?int $conversationId): ?array
    {
        $rows = $this->rows();
        if ($conversationId !== null && ($rows[$conversationId]['intensity'] ?? 0) >= self::FELT) {
            $own = $rows[$conversationId];

            return ['mood' => $own['mood'], 'intensity' => $own['intensity'], 'plays' => self::plays()[$own['mood']] ?? null, 'from' => 'here'];
        }
        if ($conversationId !== null && !self::bleeds()) {
            return null;
        }
        $best = null;
        foreach ($rows as $id => $row) {
            $value = $conversationId === null || $id === $conversationId
                ? $row['intensity']
                : ($row['set'] >= self::STRONG ? (int) floor($row['intensity'] * self::DAMPEN) : 0);
            if ($best === null || $value > $best['intensity'] || ($value === $best['intensity'] && $id === $conversationId)) {
                $best = ['mood' => $row['mood'], 'intensity' => $value, 'id' => $id];
            }
        }
        if ($best === null || $best['intensity'] < self::FELT) {
            return null;
        }

        return ['mood' => $best['mood'], 'intensity' => $best['intensity'], 'plays' => self::plays()[$best['mood']] ?? null,
            'from' => $conversationId !== null && $best['id'] !== $conversationId ? 'elsewhere' : 'here'];
    }

    /** What the next answer is told of its mood, or '' when nothing is felt. */
    public function directive(?int $conversationId): string
    {
        // A conversation not started yet has no mood of its own: what reaches it comes from the others.
        $now = $this->felt($conversationId ?? 0);
        if ($now === null) {
            return '';
        }
        $how = $now['intensity'] >= 70 ? 'strongly' : ($now['intensity'] >= 40 ? 'noticeably' : 'slightly');

        // From another conversation it is the past, and optional: told as "right now", the model
        // said it back, it was kept, and it reached the next — a person telling of a lost job was
        // answered excited because of exam results in another conversation.
        return $now['from'] === 'elsewhere'
            ? 'Earlier, in another conversation with this person, you felt ' . $now['mood'] . ', ' . $how . '. It need not carry over: '
                . 'if this conversation calls for something else, let it go. Do not say it or explain it.'
            : 'Your mood right now: ' . $now['mood'] . ', ' . $how . '. Let it colour your tone; do not say it or explain it.';
    }

    /**
     * The person's conversations with a mood that may still be felt, faded to now — or one of
     * them.
     *
     * @return array<int, array{mood: string, intensity: int, set: int}>
     */
    private function rows(?int $only = null): array
    {
        try {
            $db    = \Pramnos\Framework\Factory::getDatabase();
            $query = $this->scope->apply($this->table())
                ->select(['conversation_id', 'mood', 'mood_intensity', Instant::unix($db, 'mood_at', 'at')])
                ->whereNotNull('mood')->where('mood_at', '>=', CallLog::instant(time() - intdiv(100 * 3600, self::FADE)));
            if ($only !== null) {
                $query->where('conversation_id', $only);
            }
            $result = $query->get();
        } catch (\Throwable) {
            // Not migrated yet: no mood.
            return [];
        }
        $out = [];
        while ($result && ($row = $result->fetch()) !== null) {
            $out[(int) $row['conversation_id']] = ['mood' => (string) $row['mood'], 'set' => (int) $row['mood_intensity'],
                'intensity' => self::faded((int) $row['mood_intensity'], time() - (int) $row['at'])];
        }

        return $out;
    }

    /** The conversations' table. */
    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('assist.chat_conversations');
    }
}
