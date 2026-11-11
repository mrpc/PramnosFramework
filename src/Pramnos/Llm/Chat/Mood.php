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

    public function __construct(private readonly Scope $scope)
    {
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
        return 'End your answer with a private tag on a line of its own, [[mood:WORD|N]]: WORD is how you feel now, one of '
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
        $rows = $this->rows();
        if ($conversationId !== null && ($rows[$conversationId]['intensity'] ?? 0) >= self::FELT) {
            $own = $rows[$conversationId];

            return ['mood' => $own['mood'], 'intensity' => $own['intensity'], 'plays' => self::plays()[$own['mood']] ?? null];
        }
        $best = null;
        foreach ($rows as $id => $row) {
            $value = $conversationId === null || $id === $conversationId
                ? $row['intensity']
                : ($row['set'] >= self::STRONG ? (int) floor($row['intensity'] * self::DAMPEN) : 0);
            if ($best === null || $value > $best['intensity'] || ($value === $best['intensity'] && $id === $conversationId)) {
                $best = ['mood' => $row['mood'], 'intensity' => $value];
            }
        }
        if ($best === null || $best['intensity'] < self::FELT) {
            return null;
        }

        return $best + ['plays' => self::plays()[$best['mood']] ?? null];
    }

    /** What the next answer is told of its mood, or '' when nothing is felt. */
    public function directive(?int $conversationId): string
    {
        $now = $this->now($conversationId);
        if ($now === null) {
            return '';
        }
        $how = $now['intensity'] >= 70 ? 'strongly' : ($now['intensity'] >= 40 ? 'noticeably' : 'slightly');

        return 'Your mood right now: ' . $now['mood'] . ', ' . $how . '. Let it colour your tone; do not say it or explain it.';
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
