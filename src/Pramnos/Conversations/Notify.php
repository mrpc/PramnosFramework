<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * Who a new message notifies — the bell and a push ({@see ConversationNotification}) — as Slack's:
 *
 * | why            | who                                                                 |
 * |----------------|---------------------------------------------------------------------|
 * | `direct`       | every member of a direct or group conversation                      |
 * | `mention`      | whoever it mentions — `<@id>`, a handle ({@see Handles})            |
 * | `thread`       | a reply: whoever wrote the thread's first message or replied in it  |
 * | `message`      | anything else in the conversation: members who asked for everything |
 *
 * Each member's own setting for the conversation decides ({@see Conversations::notify()}): `none`
 * nothing, `mentions` all but `message`, `all` everything; a muted conversation tells only who it
 * names with `<@id>`. Never its writer, and nobody the application's `quiet` hook pauses
 * ({@see Handles::paused()}) — its writer may still {@see nudge()} one of those it addressed, once.
 *
 * The notifications go out in the queue ({@see NotifyTask}): a message to a channel of fifty
 * is fifty pushes, which its writer does not wait for. The bell's notifications of a conversation
 * are settled when its reader reads it ({@see settle()}).
 *
 * ```php
 * 'conversations' => [
 *     'page'  => '/chat',                                                // where a notification opens, `#/c/{id}/m/{message}` after it
 *     'quiet' => fn (array $ids): array => Status::paused($ids),
 * ],
 * ```
 */
final class Notify
{
    /** The queue's task. */
    public const TASK = 'conversation_notify';

    /**
     * Queue a new message's notifications. Whatever fails here — no queue — leaves the message
     * written, unnotified, and says so in the log.
     *
     * @param array<int, string> $mentioned Who it mentions, with how, as {@see Messages::post()} worked out
     */
    public static function queue(int $messageId, array $mentioned): void
    {
        try {
            (new \Pramnos\Queue\QueueManager(\Pramnos\Queue\QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')))
                // Where its notifications open, as the request that wrote it knows: a worker run as
                // another application does not.
                ->addTask(self::TASK, ['message' => $messageId, 'mentioned' => $mentioned, 'page' => self::page()], 30, 2);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: message ' . $messageId . ' notifies nobody: ' . $e->getMessage(), 'queue');
        }
    }

    /**
     * Who a message notifies, each with why — before the `quiet` hook.
     *
     * @param array<int, string> $mentioned Who it mentions, with how
     * @return array<int, string> direct | mention | thread | message, by person
     */
    public static function recipients(int $messageId, array $mentioned): array
    {
        $message      = Messages::get($messageId);
        $conversation = $message === null || $message['deletedAt'] !== null ? null : Conversations::get($message['conversationId']);
        if ($conversation === null) {
            return [];
        }
        $direct  = in_array($conversation['kind'], ['dm', 'group'], true);
        $thread  = $message['threadRootId'] === null ? [] : self::threadPeople($message['threadRootId']);
        // A reply only in its thread is not news to the rest of the conversation.
        $inStream = $message['threadRootId'] === null || $message['broadcast'];
        $named    = self::named($message['body']);
        $out      = [];
        foreach (self::settings($message['conversationId']) as $person => [$notify, $muted]) {
            if ($person === $message['authorId'] || $notify === 'none') {
                continue;
            }
            $why = match (true) {
                isset($mentioned[$person])       => 'mention',
                $direct                          => 'direct',
                in_array($person, $thread, true) => 'thread',
                $inStream                        => 'message',
                default                          => null,
            };
            if ($why === null || ($muted && !in_array($person, $named, true)) || ($why === 'message' && $notify !== 'all')) {
                continue;
            }
            $out[$person] = $why;
        }

        return $out;
    }

    /**
     * Send a message's notifications: to each of its {@see recipients()} the `quiet` hook does not
     * pause. A person whose notification fails is logged and the rest still told.
     *
     * @param array<int, string> $mentioned
     * @param string|null        $page      Where they open ({@see page()}), as the request that wrote it said
     * @return list<int> Who was notified
     */
    public static function run(int $messageId, array $mentioned, ?string $page = null): array
    {
        $people = self::recipients($messageId, $mentioned);
        $paused = Handles::paused(array_keys($people));
        $told   = [];
        foreach ($people as $person => $why) {
            if (!in_array($person, $paused, true) && self::send($messageId, $person, $why, $page)) {
                $told[] = $person;
            }
        }

        return $told;
    }

    /**
     * Of the people a message addresses — whoever it names with `<@id>`, the other members of a
     * direct or group conversation — those paused now: for its writer's composer to offer
     * "Notify anyway".
     *
     * @return list<int>
     */
    public static function paused(int $messageId): array
    {
        $message = Messages::get($messageId);
        if ($message === null) {
            return [];
        }
        $kind      = (string) (Conversations::get($message['conversationId'])['kind'] ?? '');
        $members   = Conversations::members($message['conversationId']);
        $addressed = in_array($kind, ['dm', 'group'], true) ? $members : array_values(array_intersect(self::named($message['body']), $members));

        return Handles::paused(array_values(array_diff($addressed, [(int) $message['authorId']])));
    }

    /**
     * "Notify anyway": the writer of a message tells one it addressed and the `quiet` hook pauses,
     * past the pause — once for each message and person.
     *
     * @throws \InvalidArgumentException Not the writer, nobody paused it addressed, or told already
     */
    public static function nudge(int $messageId, int $by, int $person): void
    {
        $message = Messages::get($messageId);
        if ($message === null || $message['authorId'] !== $by) {
            throw new \InvalidArgumentException(t('Only its writer notifies somebody of a message.'));
        }
        if (!in_array($person, self::paused($messageId), true)) {
            throw new \InvalidArgumentException(t('That person is not waiting to be notified of this message.'));
        }
        if (self::nudged($messageId, $person)) {
            throw new \InvalidArgumentException(t('That person has been notified of this message already.'));
        }
        if (!self::send($messageId, $person, 'nudge')) {
            throw new \InvalidArgumentException(t('That person could not be notified.'));
        }
    }

    /**
     * A reader read a conversation: its notifications in their bell are settled. Best effort — a
     * bell that cannot be read is no reason a conversation is not.
     */
    public static function settle(int $conversationId, int $person): void
    {
        try {
            \Pramnos\Notification\Feed::settleAbout(['conversation:' . $conversationId], $person, Conversations::db());
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: the bell of ' . $person . ' was not settled: ' . $e->getMessage(), 'notifications');
        }
    }

    /** Tell one person of a message; false where it failed, logged. */
    private static function send(int $messageId, int $person, string $why, ?string $page = null): bool
    {
        try {
            $user = new \Pramnos\User\User($person);
            if ((int) ($user->userid ?? 0) !== $person) {
                return false;
            }
            $class = self::config('notification');
            $class = is_string($class) && class_exists($class) ? $class : ConversationNotification::class;
            $user->notify(new $class($messageId, $why, $page ?? self::page()));

            return true;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: ' . $person . ' was not notified of message ' . $messageId . ': ' . $e->getMessage(), 'notifications');

            return false;
        }
    }

    /** Whether a person was nudged about a message already: the bell keeps it. */
    private static function nudged(int $messageId, int $person): bool
    {
        $key = '%"nudge:' . $messageId . '"%';

        return Conversations::db()->queryBuilder()->table('#PREFIX#notifications')->where('notifiable_id', $person)
            ->where('data', 'LIKE', $key)->count() > 0;
    }

    /** @return list<int> Who wrote a thread's first message or replied in it */
    private static function threadPeople(int $root): array
    {
        $rows = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_messages')->select(['authorid'])->distinct()
            ->where(static fn ($q) => $q->where('messageid', $root)->orWhere('threadrootid', $root))->whereNotNull('authorid')->getAllOrFail();

        return array_map('intval', array_column($rows, 'authorid'));
    }

    /** @return list<int> Who a body names with `<@id>` */
    private static function named(string $body): array
    {
        preg_match_all('/<@(\d+)>/', $body, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * Each member's setting for a conversation.
     *
     * @return array<int, array{0: string, 1: bool}> [notify, muted], by person
     */
    private static function settings(int $conversationId): array
    {
        $out = [];
        foreach (Conversations::db()->queryBuilder()->table('#PREFIX#conversation_members')->select(['participantid', 'notify', 'muted'])
            ->where('conversationid', $conversationId)->where('participanttype', 'user')->whereNull('leftat')->getAllOrFail() as $row) {
            $out[(int) $row['participantid']] = [(string) $row['notify'], (bool) $row['muted']];
        }

        return $out;
    }

    /**
     * Where a notification opens — `#/c/{id}/m/{message}` follows: `conversations.page`; otherwise
     * the conversations' screen in the administration — this request's area, or, from a console
     * process, the area's prefix under the site.
     */
    public static function page(): string
    {
        $page = self::config('page');
        if (is_string($page) && $page !== '') {
            return $page;
        }
        if (\Pramnos\Http\AdminArea::isActive() || Conversations::areaPrefix() === '') {
            return \Pramnos\Http\AdminArea::address('Conversations');
        }
        $path = Conversations::areaPrefix() . '/Conversations';

        return \Pramnos\Http\SiteUrl::get() !== '' ? \Pramnos\Http\SiteUrl::to($path) : '/' . $path;
    }

    /** A `conversations` setting ({@see Conversations::settings()}). */
    public static function config(string $name): mixed
    {
        return Conversations::settings()[$name] ?? null;
    }
}
