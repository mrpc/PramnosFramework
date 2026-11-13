<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * The administration's analyst in the conversations, two ways:
 *
 * - **A conversation with Ask** — a `bot` conversation named "Ask" ({@see conversationFor()}):
 *   every message is a question, answered in the conversation itself, with the asker's rights, as
 *   one analyst conversation that goes on from answer to answer — the same one `/admin/Ai` shows.
 * - **`@Ask` anywhere else** — answered in the message's thread. Everybody who reads the
 *   conversation reads the answer, so it is given with the rights of its least privileged reader
 *   ({@see readerOf()}), without the asker's memory, and the analyst says what it may not show
 *   here. Its cost is the asker's.
 *
 * ```
 * @Ask πόσες νέες εγγραφές γονέων είχαμε χθες στη Θεσσαλονίκη;
 * ```
 *
 * The question is answered in the queue, as everything the analyst answers is; the answer comes
 * signed "Ask", or why there is none. There where the application has the analyst (`llm`, a key)
 * and for whoever may ask it.
 */
final class Ask
{
    /** Who signs the answers. */
    public const NAME = 'Ask';

    /** @var (callable(int, string, array<string, mixed>, ?int): int)|null How a question is queued, where not the analyst's own way */
    private static $queue = null;

    /**
     * Queue questions another way — a test's, which records them. Null restores the analyst's.
     *
     * @param (callable(int, string, array<string, mixed>, ?int): int)|null $queue The asker, the question, where the answer goes, the analyst conversation it goes on — the ask's id
     */
    public static function queueWith(?callable $queue): void
    {
        self::$queue = $queue;
    }

    /**
     * Whether a message calls on the analyst: `@Ask`, its name or one of its mentions
     * ({@see \Pramnos\Llm\Analyst\Analyst::mentions()}) as a word of its own, anywhere in it.
     */
    public static function called(string $body): bool
    {
        return preg_match(\Pramnos\Llm\Analyst\Analyst::mentionPattern(), $body) === 1;
    }

    /**
     * A bot conversation's name as people see it: the analyst's own name for Ask's, which is kept
     * under {@see NAME} whatever the application calls it.
     */
    public static function displayName(string $name): string
    {
        return $name === self::NAME ? \Pramnos\Llm\Analyst\Analyst::name() : $name;
    }

    /** Whether this person may call on the analyst here. */
    public static function canAsk(?object $person): bool
    {
        return $person !== null && \Pramnos\Llm\Analyst\Analyst::available()
            && \Pramnos\Auth\AdminAccess::allows($person, 'admin.ai', \Pramnos\Llm\Analyst\Analyst::floor());
    }

    /** A person's conversation with Ask, made the first time; null for whoever may not ask it. */
    public static function conversationFor(object $person): ?int
    {
        return self::canAsk($person) ? Conversations::create('bot', self::NAME, [(int) $person->userid]) : null;
    }

    /** Whether a conversation is somebody's conversation with Ask. */
    public static function isOwn(?array $conversation): bool
    {
        return $conversation !== null && $conversation['kind'] === 'bot' && $conversation['name'] === self::NAME;
    }

    /**
     * Put the message's question to the analyst, as its writer: in a conversation with Ask, every
     * message, answered there; elsewhere one that calls on it, answered in its thread with the
     * rights of the conversation's least privileged reader. Nothing for a person who may not ask.
     *
     * @return int|null The ask, followed in the queue
     */
    public static function start(int $messageId, object $person): ?int
    {
        $message = Messages::get($messageId);
        if ($message === null || !self::canAsk($person)) {
            return null;
        }
        $where = Conversations::get($message['conversationId']);
        $own   = self::isOwn($where) && $message['threadRootId'] === null;
        if (!$own && !self::called($message['body'])) {
            return null;
        }
        $question = trim((string) preg_replace(\Pramnos\Llm\Analyst\Analyst::mentionPattern('[,:]?'), '', $message['body']));
        $question = $question !== '' ? $question : $message['body'];
        $extra    = ['conversations' => ['conversation' => $message['conversationId'], 'thread' => $own ? null : ($message['threadRootId'] ?? $message['id']), 'message' => $messageId]];
        if (!$own) {
            $extra['rights_of'] = self::readerOf((int) $where['id'], (int) $person->userid);
        }
        $goesOn = $own ? self::analystConversation((int) $where['id'], (int) $person->userid) : null;
        if (self::$queue !== null) {
            return (self::$queue)((int) $person->userid, $question, $extra, $goesOn);
        }
        // The analyst's own queue, which its own tests exercise.
        // @codeCoverageIgnoreStart
        $asks = new \Pramnos\Llm\Chat\Asks(new \Pramnos\Llm\Chat\Conversations(\Pramnos\Llm\Analyst\Analyst::assistant()));

        return $asks->start(\Pramnos\Llm\Analyst\Analyst::scope((int) $person->userid), $goesOn, $question, null, $extra);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Whose rights an answer in a conversation is given with: the least privileged of those who
     * read it — its members, or, in one open to everybody, everybody who may use conversations —
     * the asker among them. Least privileged is the lowest usertype; the lower id breaks a tie.
     */
    public static function readerOf(int $conversationId, int $asker): int
    {
        $conversation = Conversations::get($conversationId);
        // ponytail: an open conversation's readers are People::search's first 200; a permission index if teams grow past that.
        $readers = $conversation !== null && in_array($conversation['kind'], Conversations::OPEN, true)
            ? array_column(People::search('', 200, true), 'id')
            : Conversations::members($conversationId);
        $rows = Conversations::db()->queryBuilder()->table('#PREFIX#users')->select(['userid', 'usertype'])
            ->whereIn('userid', array_values(array_unique([$asker, ...array_map('intval', $readers)])))
            ->orderBy('usertype')->orderBy('userid')->limit(1)->getAllOrFail();

        return (int) ($rows[0]['userid'] ?? $asker);
    }

    /**
     * The analyst conversation a conversation with Ask goes on: the one its last answered question
     * was answered in — its answers carry their ask — as long as it is still there.
     *
     * One that was deleted is passed over, and so is an answer with no ask: going on in a
     * conversation that is gone failed every question after it with "There is no such
     * conversation", and the failure, pointing to the same one, kept it so. With none left, the
     * question starts a conversation of its own.
     *
     * One that has **ended** ({@see \Pramnos\Llm\Chat\Ending} after abuse, or a deliberate
     * `/clear`/`/new`) is never resumed either — but unlike a deleted one, it is not passed over
     * to an older conversation behind it: ending it on purpose means starting clean, not quietly
     * falling back to whatever came before. `null` here is exactly what makes the next question
     * open a conversation of its own.
     */
    private static function analystConversation(int $conversationId, int $person): ?int
    {
        $asks  = Conversations::db()->queryBuilder()->table('chat.conversation_messages')->select(['externalid'])
            ->where('conversationid', $conversationId)->where('source', 'ask')->orderBy('messageid', 'desc')->limit(10)->getAllOrFail();
        $scope = \Pramnos\Llm\Analyst\Analyst::scope($person);
        $ended = Conversations::db()->schema()->hasColumn('assist.chat_conversations', 'ended_at');
        // ponytail: a question sent before the last one is answered starts a conversation of its own; a pending-ask lookup if that matters.
        foreach ($asks as $row) {
            $askId = (int) ($row['externalid'] ?? 0);
            if ($askId <= 0) {
                continue;
            }
            $id = (int) ($scope->apply(Conversations::db()->queryBuilder()->table('assist.chat_asks'))
                ->select(['conversation_id'])->where('ask_id', $askId)->limit(1)->getAllOrFail()[0]['conversation_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $found = $scope->apply(Conversations::db()->queryBuilder()->table('assist.chat_conversations'))
                ->where('conversation_id', $id)->select($ended ? ['conversation_id', 'ended_at'] : ['conversation_id'])
                ->limit(1)->getAllOrFail();
            if ($found === []) {
                continue; // gone: an earlier conversation may still be there
            }

            return $ended && !empty($found[0]['ended_at']) ? null : $id;
        }

        return null;
    }

    /**
     * The analyst has begun on an ask from a conversation: the people there are told, in the
     * thread it answers in or in the conversation itself.
     *
     * @param array<string, mixed> $job As the ask was queued
     */
    public static function thinking(array $job): void
    {
        $where = $job['extra']['conversations'] ?? null;
        if (is_array($where) && isset($where['conversation'])) {
            Realtime::assistant((int) $where['conversation'], empty($where['thread']) ? null : (int) $where['thread']);
        }
    }

    /**
     * Whether an answer in a thread is sent to the conversation too (FW-258's broadcast):
     * `llm.analyst.thread_broadcast` — `if_last` (the default) when the question is still the
     * conversation's newest message, nobody having written since; `always`; or `never`.
     */
    public static function broadcasts(int $conversationId, int $thread): bool
    {
        $config = \Pramnos\Llm\Llm::config('analyst', []);
        $rule   = is_array($config) && is_string($config['thread_broadcast'] ?? null) ? $config['thread_broadcast'] : 'if_last';

        return match ($rule) {
            'always' => true,
            'never'  => false,
            default  => (Conversations::get($conversationId)['lastMessageId'] ?? null) === $thread,
        };
    }

    /**
     * Post an answered ask in its thread — the analyst's answer, or why there is none. An ask that
     * did not come from a conversation is left alone. `Asks::run()` calls it for every ask whose
     * `extra` names a conversation — in whatever process answers it, providers booted or not.
     *
     * @param array<string, mixed> $job    As the ask was queued
     * @param array<string, mixed> $answer The analyst's
     */
    public static function answered(array $job, array $answer): void
    {
        $where = $job['extra']['conversations'] ?? null;
        if (!is_array($where) || !isset($where['conversation'])) {
            return;
        }
        $text = !empty($answer['ok']) ? (string) ($answer['message']['content'] ?? '') : '⚠ ' . (string) ($answer['says'] ?? 'The AI could not answer.');
        $card = ['from' => self::NAME];
        // In the conversation with Ask, the way to the same conversation on Ask's own page.
        if (empty($where['thread']) && !empty($answer['conversation_id'])) {
            // In English: the worker knows no reader; the page puts it in the reader's language.
            $card += ['title' => 'Open in Ask', 'url' => \Pramnos\Llm\Analyst\AnalystAssistant::areaUrl('Ai?c=' . (int) $answer['conversation_id'])];
        }
        $thread = empty($where['thread']) ? null : (int) $where['thread'];
        try {
            Messages::post((int) $where['conversation'], null, mb_substr(trim($text) !== '' ? $text : '…', 0, Messages::MAX_LENGTH), array_filter([
                'thread' => $thread, 'card' => $card, 'source' => 'ask', 'externalid' => (string) ($job['ask_id'] ?? ''),
                // Asked in a channel and nobody has written since: the answer is seen there too.
                'broadcast' => $thread !== null && self::broadcasts((int) $where['conversation'], $thread) ? true : null,
            ], static fn ($v): bool => $v !== null));
        } finally {
            Realtime::assistant((int) $where['conversation'], $thread, true);
        }
    }
}
