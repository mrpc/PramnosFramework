<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Broadcasting\Auth\ChannelRegistry;

/**
 * What the conversations tell connected browsers, after the database has it.
 *
 * | channel                         | events                                                     |
 * |---------------------------------|------------------------------------------------------------|
 * | `private-conversation.{id}`     | `message.posted`, `message.edited`, `message.deleted`, `message.reacted`, `message.pinned` — and `client-typing`, which browsers whisper to each other |
 * | each member's own (`private-users.{id}`) | `conversation.activity`: a message in one of their conversations — a reply also sent to it marked `broadcast` — whether it mentions them, and `notify`: the client to show a notification (the one they were last active in) or `all`; `conversation.read`: what they have read there moved — read, or marked unread — with what is left; `thread.read`: what they have read in one thread moved (FW-498), separately — a conversation's own read point moves with its top-level messages, never a reply; `conversation.draft`: their draft somewhere changed; `activity.seen`: they saw their activity |
 * | `presence-chat`                 | who of the team is connected                               |
 *
 * A message is written first and told second, so a browser that missed a telling asks for what came
 * after the last message it has (`Messages::list($id, ['after' => …])`). Without broadcasting set
 * up nothing is told and nothing fails.
 */
final class Realtime
{
    /** How long an answer may take before "… is thinking" is let go, if no `assistant.done` came. */
    public const ASSISTANT_SECONDS = 180;

    /** The team's presence channel. */
    public const PRESENCE = 'presence-chat';

    /** A conversation's channel. */
    public static function channel(int $conversationId): string
    {
        return 'private-conversation.' . $conversationId;
    }

    /**
     * Who may listen: a conversation's channel whoever reads it, the presence channel whoever may
     * use the conversations — as their name.
     */
    public static function authorize(ChannelRegistry $channels): void
    {
        $channels->channel('conversation.{id}', static fn (?object $user, string $id): bool => $user !== null && Conversations::canRead((int) $id, $user));
        $channels->channel('chat', static function (?object $user): array|bool {
            if (!Access::canUse($user)) {
                return false;
            }

            return ['user_id' => (string) $user->userid, 'user_info' => ['name' => People::names([(int) $user->userid])[(int) $user->userid] ?? '']];
        });
    }

    /**
     * A new message: to its conversation, as a reader sees it; and to each member but its writer,
     * whether it mentions them.
     *
     * @param array<int, string> $mentioned The people it mentions, with how
     */
    public static function posted(int $messageId, array $mentioned): void
    {
        $message = Messages::present(array_values(array_filter([Messages::get($messageId)])))[0] ?? null;
        if ($message === null) {
            return;
        }
        self::tell(self::channel($message['conversationId']), 'message.posted', $message);
        $others  = array_values(array_filter(Conversations::members($message['conversationId']), static fn (int $person): bool => $person !== $message['authorId']));
        // Which of each one's clients shows a notification: the one they were last active in, or all.
        $targets = Clients::targets($others);
        foreach ($others as $person) {
            self::tell(self::userChannel($person), 'conversation.activity', ['conversationId' => $message['conversationId'],
                'messageId' => $messageId, 'threadRootId' => $message['threadRootId'], 'broadcast' => $message['broadcast'], 'authorId' => $message['authorId'],
                'mention' => isset($mentioned[$person]), 'notify' => $targets[$person] ?? 'all']);
        }
    }

    /**
     * What a person has read in a conversation moved — they read it, or marked it unread — told on
     * their own channel, so their other pages and the dock count it again.
     */
    public static function read(int $conversationId, int $person): void
    {
        $lastRead = (int) (Conversations::member($conversationId, $person)['lastReadId'] ?? 0);
        self::tell(self::userChannel($person), 'conversation.read', ['conversationId' => $conversationId, 'lastReadId' => $lastRead]
            + Conversations::counts($conversationId, $person, $lastRead));
    }

    /**
     * What a person has read in one thread moved (FW-498) — told on their own channel, so their
     * other pages and the dock stop counting a reply they have, in fact, just read: the
     * conversation-level `conversation.read` is about its top-level messages, not a thread's own.
     */
    public static function threadRead(int $conversationId, int $root, int $person): void
    {
        $lastRead = (int) (Threads::read($root, $person)['lastReadId'] ?? 0);
        self::tell(self::userChannel($person), 'thread.read', ['conversationId' => $conversationId, 'rootId' => $root, 'lastReadId' => $lastRead]);
    }

    /**
     * The analyst is answering in a conversation — `assistant.thinking`, with the thread it answers
     * in (null for the conversation itself), its name and until when it may take — or has
     * finished, answered or not: `assistant.done`. A worker cannot whisper, so it says so here.
     */
    public static function assistant(int $conversationId, ?int $thread, bool $done = false): void
    {
        self::tell(self::channel($conversationId), $done ? 'assistant.done' : 'assistant.thinking', $done ? ['thread' => $thread]
            : ['thread' => $thread, 'name' => \Pramnos\Llm\Analyst\Analyst::name(), 'until' => time() + self::ASSISTANT_SECONDS]);
    }

    /**
     * A conversation changed under its members — somebody with no account turned out to have one,
     * or a group was renamed — told to each, for a sidebar to read again; `mergedFrom` the
     * conversation that was put into it, gone now; `name` a group's new name (null: its members).
     *
     * @param list<int> $people
     */
    public static function conversationChanged(int $conversationId, array $people, ?int $mergedFrom = null, ?string $name = null): void
    {
        foreach ($people as $person) {
            self::tell(self::userChannel($person), 'conversation.changed', ['conversationId' => $conversationId, 'mergedFrom' => $mergedFrom, 'name' => $name]);
        }
    }

    /**
     * Somebody's draft changed — written, sent, emptied — told on their own channel as
     * `conversation.draft`, for their other devices ({@see Drafts}).
     *
     * @param array{conversationId: int, thread: ?int, body: string, updatedAt: int} $draft
     */
    public static function draft(int $person, array $draft): void
    {
        self::tell(self::userChannel($person), 'conversation.draft', $draft);
    }

    /**
     * A canvas changed — created, saved, locked, unlocked, deleted — told as `canvas.changed` on
     * its conversation's channel: an open canvas reloads, or shows who is editing it.
     *
     * @param array{canvasId: int, what: string, by: int} $payload
     */
    public static function canvas(int $conversationId, array $payload): void
    {
        self::tell(self::channel($conversationId), 'canvas.changed', $payload + ['conversationId' => $conversationId]);
    }

    /**
     * A to-do added, changed or deleted — told as `todo.changed` on the people's own channels, so
     * their lists and a message's mark are drawn again on every device and tab.
     *
     * @param list<int> $people
     */
    public static function todo(array $people, int $todoId, bool $deleted = false): void
    {
        foreach (array_unique($people) as $person) {
            self::tell(self::userChannel($person), 'todo.changed', ['id' => $todoId, 'deleted' => $deleted]);
        }
    }

    /** Somebody saw their activity — told as `activity.seen` on their own channel, so every device clears its badge. */
    public static function activitySeen(int $person, ?int $seenAt): void
    {
        self::tell(self::userChannel($person), 'activity.seen', ['seenAt' => $seenAt]);
    }

    /** A message changed — edited, deleted, reacted to, pinned — told as it now reads. */
    public static function changed(int $messageId, string $event): void
    {
        $message = Messages::present(array_values(array_filter([Messages::get($messageId)])))[0] ?? null;
        if ($message !== null) {
            self::tell(self::channel($message['conversationId']), $event, $message);
        }
    }

    /** Tell a channel, where broadcasting is set up; a failure to tell is logged, never the writer's. */
    private static function tell(string $channel, string $event, array $payload): void
    {
        $manager = \Pramnos\Broadcasting\BroadcastingManager::currentInstance();
        if ($manager === null) {
            return;
        }
        try {
            $manager->broadcast($channel, $event, $payload);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Conversations: ' . $event . ' was not told on ' . $channel . ': ' . $e->getMessage(), 'broadcasting');
        }
    }

    /** A person's own channel, as the application names it. */
    private static function userChannel(int $person): string
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return \Pramnos\Broadcasting\RealtimeConfig::userChannel($person, is_object($app) && is_array($app->applicationInfo['broadcasting'] ?? null) ? $app->applicationInfo['broadcasting'] : []);
    }
}
