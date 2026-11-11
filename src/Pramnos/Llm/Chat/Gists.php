<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * What a conversation was about, in a sentence or two — its gist — and what a set of them comes
 * to over a window: "what happened since I last looked".
 *
 * A gist is written on the cheapest model the first time it is asked for, kept on the
 * conversation (`gist`, up to `gist_upto`), and written again only when the conversation has new
 * messages. A summary is made from gists, in the reader's language, narrowed to what touches an
 * `about` where one is given. When a reader last looked at something is kept for them
 * ({@see seen()}, {@see markSeen()}), so "since last" is theirs.
 *
 * What a gist and a summary are asked to stress is the application's:
 * `'llm' => ['chat' => ['gists' => ['prompt' => '…', 'summary' => '…']]]`.
 */
final class Gists
{
    /** What a gist is asked to say, unless the application words it. */
    public const PROMPT = 'Say in one or two plain sentences what this conversation between a person and an assistant was about '
        . 'and how it went: what they did, and anything worth knowing. No greeting, no list.';

    /** What a summary of gists is asked to say, unless the application words it. */
    public const SUMMARY = 'Here are short accounts of conversations with an assistant, newest first. Sum them up for the reader '
        . 'in two or three plain sentences: what was done, and anything worth knowing.';

    /** The user detail a reader's last looks are kept in, by what they looked at. */
    public const SEEN = 'askseen';

    /**
     * A conversation's gist: the kept one while nothing was said since, else written now and kept;
     * null where it could not be written and none is kept.
     *
     * @param array<string, mixed> $row The conversation's row: conversation_id, gist, gist_upto
     */
    public static function of(Conversations $asker, array $row, Scope $reader): ?string
    {
        $db       = \Pramnos\Framework\Factory::getDatabase();
        $id       = (int) $row['conversation_id'];
        $messages = $db->queryBuilder()->table('assist.chat_messages')->select(['message_id', 'role', 'content'])
            ->where('conversation_id', $id)->orderBy('message_id')->getAllOrFail();
        $kept     = ($row['gist'] ?? null) === null ? null : (string) $row['gist'];
        $last     = (int) (end($messages)['message_id'] ?? 0);
        if ($messages === [] || ($kept !== null && (int) ($row['gist_upto'] ?? 0) >= $last)) {
            return $kept;
        }
        $said = implode("\n", array_map(static fn (array $m): string => $m['role'] . ': ' . mb_substr((string) $m['content'], 0, 600), array_slice($messages, -30)));
        $made = $asker->cheaply(self::worded('prompt', self::PROMPT) . ' ' . self::inLanguageOf($reader), $said);
        if ($made === null) {
            return $kept;
        }
        $db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', $id)->update(['gist' => $made, 'gist_upto' => $last]);

        return $made;
    }

    /**
     * What some gists come to, in the reader's language: over a window, or since the reader last
     * looked (said so at the start), narrowed to what touches `about` where one is given.
     *
     * @param list<string> $gists
     */
    public static function summary(Conversations $asker, array $gists, Scope $reader, string $window, string $about = '', bool $sinceLast = false): ?string
    {
        if ($gists === []) {
            return null;
        }

        return $asker->cheaply(self::worded('summary', self::SUMMARY) . ' ' . $window . '. '
            . (trim($about) !== '' ? 'Only what touches this: ' . mb_substr(trim($about), 0, 200) . '; if nothing does, say so in a sentence. ' : '')
            . ($sinceLast ? 'Begin by saying it is since the reader last looked. ' : '')
            . self::inLanguageOf($reader), implode("\n", array_map(static fn (string $g): string => '- ' . $g, $gists)));
    }

    /** The newest conversations a digest writes a gist for now, at most; the older keep what they have. */
    public const FRESH = 10;

    /**
     * The conversations of a scope — its stored space: a persona, a partition — for a reader, the
     * newest first, each with its gist, and what they come to: since this reader last looked
     * (`last`, seven days the first time), `today`, from a day (`Y-m-d`) or over so many days;
     * narrowed to what touches `about` where given. Whoever may read whose is the caller's to
     * decide. Reading moves the reader's last look on.
     *
     * @return array{summary: ?string, since_last: bool, conversations: list<array<string, mixed>>}
     */
    public static function digest(Scope $of, Scope $reader, int|string $since = 'last', string $about = ''): array
    {
        $what      = 'space:' . $of->stored();
        $seen      = self::seen($reader, $what);
        $day       = is_string($since) && preg_match('/^\d{4}-\d\d-\d\d$/', $since) === 1;
        $sinceLast = !is_int($since) && $since !== 'today' && !$day && $seen !== null;
        $from      = match (true) {
            is_int($since)     => time() - max(1, $since) * 86400,
            $since === 'today' => (int) strtotime('today'),
            $day               => (int) strtotime($since . ' 00:00'),
            default            => $seen ?? time() - 7 * 86400,
        };
        $db    = \Pramnos\Framework\Factory::getDatabase();
        $rows  = $of->apply($db->queryBuilder()->table('assist.chat_conversations'))
            ->where('updated_at', $sinceLast ? '>' : '>=', \Pramnos\Llm\CallLog::instant($from))->orderBy('updated_at', 'desc')->limit(50)->getAllOrFail();
        self::markSeen($reader, $what);
        $asker = (new Conversations(new BaseAssistant()))->as($reader);
        $list  = [];
        foreach ($rows as $i => $row) {
            $list[] = ['conversation_id' => (int) $row['conversation_id'], 'title' => (string) $row['title'], 'from' => (string) $row['created_at'], 'to' => (string) $row['updated_at'],
                'gist' => $i < self::FRESH ? self::of($asker, $row, $reader) : (($row['gist'] ?? null) === null ? null : (string) $row['gist'])];
        }
        $days    = max(1, (int) ceil((time() - $from) / 86400));
        $summary = self::summary($asker, array_values(array_filter(array_column($list, 'gist'))), $reader,
            $sinceLast ? 'These are since the reader last looked' : 'These are of the last ' . $days . ' days', $about, $sinceLast);

        return ['summary' => $summary, 'since_last' => $sinceLast, 'conversations' => $list];
    }

    /** When this reader — in their partition, where they have one — last looked at something; null for never. */
    public static function seen(Scope $reader, string $what): ?int
    {
        $map = json_decode((string) ((new \Pramnos\User\User($reader->userId))->{self::SEEN} ?? ''), true);
        $at  = is_array($map) ? ($map[($reader->partition ?? 0) . ':' . $what] ?? null) : null;

        return is_numeric($at) ? (int) $at : null;
    }

    /** This reader's look at something, kept as now. */
    public static function markSeen(Scope $reader, string $what): void
    {
        $user = new \Pramnos\User\User($reader->userId);
        if ((int) ($user->userid ?? 0) !== $reader->userId) {
            return;
        }
        $map = json_decode((string) ($user->{self::SEEN} ?? ''), true);
        $map = is_array($map) ? $map : [];
        $map[($reader->partition ?? 0) . ':' . $what] = time();
        $user->{self::SEEN} = (string) json_encode($map);
        $user->save();
    }

    /** "Write it in …": the reader's language, by its code, for what a model writes for them. */
    public static function inLanguageOf(Scope $reader): string
    {
        $code = trim((string) ((new \Pramnos\User\User($reader->userId))->language ?? ''));

        return $code === '' ? 'Write it in the language of the conversation.' : 'Write it in the language whose code is "' . $code . '".';
    }

    /** The application's wording for a prompt, or the framework's. */
    private static function worded(string $key, string $default): string
    {
        $chat = (array) \Pramnos\Llm\Llm::config('chat', []);
        $own  = ((array) ($chat['gists'] ?? []))[$key] ?? ($key === 'prompt' ? (((array) ($chat['profiles'] ?? []))['gist_prompt'] ?? null) : null);

        return is_string($own) && trim($own) !== '' ? trim($own) : $default;
    }
}
