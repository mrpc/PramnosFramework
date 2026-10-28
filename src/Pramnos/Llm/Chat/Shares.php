<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * Sharing an Ask conversation or artifact with other people.
 *
 * A conversation is shared as it stands: the recipient sees the messages up to the moment it was
 * shared, not what is said after. Sharing it again with the same person moves that point on.
 *
 * The recipient opens a share by **taking a copy**: the snapshot becomes a conversation of their
 * own — carrying the data tags of the original — which they read on the ordinary conversation
 * screens and can go on asking in, with their own rights. An artifact arrives the same way, as a
 * conversation holding a copy of it. Opening it again returns to that copy.
 *
 * Who may share, with whom, and where the notification leads are the {@see SharePolicy}'s.
 *
 * ```php
 * $shares = new Shares();
 * $shares->share($scope, Shares::CONVERSATION, $conversationId, ['maria', 'nikos@example.com']);
 * $shares->withMe($userId);                       // what others shared with this person
 * $conversationId = $shares->open($shareId, $conversations->as($viewerScope));
 * ```
 */
final class Shares
{
    /** A whole conversation, as it stood. */
    public const CONVERSATION = 'conversation';

    /** One artifact. */
    public const ARTIFACT = 'artifact';

    public function __construct(private readonly ?SharePolicy $policy = null)
    {
    }

    /** Whether this installation can keep shares: their table exists. */
    public static function available(): bool
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('chat_shares');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Share one of this person's conversations or artifacts with the people named.
     *
     * @param list<string> $names Usernames or emails
     * @return array{shared: list<string>, unknown: list<string>}|string Who it went to and who
     *         was nobody, or why it could not be shared
     */
    public function share(Scope $by, string $kind, int $targetId, array $names): array|string
    {
        if (!self::available()) {
            return 'Sharing is not set up here.';
        }
        if (($why = $this->policy()->refusal($by)) !== null) {
            return $why;
        }
        $target = $this->target($by, $kind, $targetId);
        if ($target === null) {
            return $kind === self::ARTIFACT ? 'There is no such artifact.' : 'There is no such conversation.';
        }
        $names      = array_values(array_unique(array_filter(array_map(static fn ($n): string => trim((string) $n), $names), static fn (string $n): bool => $n !== '')));
        $recipients = $this->policy()->recipients($by, $names);
        if ($names === []) {
            return 'Name somebody to share it with.';
        }

        $stamp  = CallLog::instant(time());
        $shared = [];
        foreach (array_unique($recipients) as $recipient) {
            $row = [
                'title'                => $target['title'],
                'up_to_message_id'     => $target['up_to'],
                // A new snapshot: the next opening takes a fresh copy.
                'copy_conversation_id' => null,
                'updated_at'           => $stamp,
            ];
            $existing = $this->table()->where('kind', $kind)->where('target_id', $targetId)->where('recipient', $recipient)->first();
            if ($existing && (int) $existing->numRows > 0) {
                $this->table()->where('share_id', (int) $existing->fields['share_id'])->update($row);
                $shareId = (int) $existing->fields['share_id'];
            } else {
                $this->table()->insert($by->columns() + $row + ['kind' => $kind, 'target_id' => $targetId, 'recipient' => $recipient, 'created_at' => $stamp]);
                $shareId = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
            }
            $this->notify($recipient, $by, ['share_id' => $shareId, 'kind' => $kind, 'space' => $by->space, 'title' => $target['title']]);
        }
        foreach ($recipients as $name => $recipient) {
            $shared[] = $name;
        }

        return ['shared' => $shared, 'unknown' => array_values(array_diff($names, $shared))];
    }

    /**
     * Who one of this person's conversations or artifacts is shared with.
     *
     * @return list<array{share_id: int, recipient: int, name: string, created_at: string}>
     */
    public function sharesOf(Scope $by, string $kind, int $targetId): array
    {
        if (!self::available()) {
            return [];
        }
        $result = $by->apply($this->table())->where('kind', $kind)->where('target_id', $targetId)->orderBy('share_id')->get();
        $out    = [];
        while (($row = $result->fetch()) !== null) {
            $out[] = ['share_id' => (int) $row['share_id'], 'recipient' => (int) $row['recipient'], 'name' => self::nameOf((int) $row['recipient']), 'created_at' => (string) $row['created_at']];
        }

        return $out;
    }

    /** Withdraw one of this person's shares. False when there was none. A copy already taken stays the recipient's. */
    public function revoke(Scope $by, int $shareId): bool
    {
        if (!self::available()) {
            return false;
        }
        $found = $by->apply($this->table())->where('share_id', $shareId)->first();
        if (!$found || (int) $found->numRows === 0) {
            return false;
        }
        $this->table()->where('share_id', $shareId)->delete();

        return true;
    }

    /** Withdraw every share of a conversation or artifact, as it is deleted. Copies already taken stay. */
    public static function forget(string $kind, int $targetId): void
    {
        if (self::available()) {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_shares')
                ->where('kind', $kind)->where('target_id', $targetId)->delete();
        }
    }

    /**
     * What others shared with this person, newest first.
     *
     * @return list<array{share_id: int, kind: string, title: string, from: string, space: string, opened: bool, created_at: string}>
     */
    public function withMe(int $userId, int $limit = 100): array
    {
        if (!self::available()) {
            return [];
        }
        $result = $this->table()->where('recipient', $userId)->orderBy('updated_at', 'desc')->orderBy('share_id', 'desc')->limit(max(1, $limit))->get();
        $out    = [];
        while (($row = $result->fetch()) !== null) {
            $out[] = [
                'share_id'   => (int) $row['share_id'],
                'kind'       => (string) $row['kind'],
                'title'      => (string) $row['title'],
                'from'       => self::nameOf((int) $row['userid']),
                'space'      => (string) $row['space'],
                'opened'     => $row['copy_conversation_id'] !== null,
                'created_at' => (string) $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * Open a share as its recipient: the conversation of their own that holds it, made on the first
     * opening. Null when the share is not theirs or what it shared is gone.
     */
    public function open(int $shareId, Conversations $viewer): ?int
    {
        $scope = $viewer->scope();
        if ($scope === null || !self::available()) {
            return null;
        }
        $found = $this->table()->where('share_id', $shareId)->where('recipient', $scope->userId)->first();
        if (!$found || (int) $found->numRows === 0) {
            return null;
        }
        $share = $found->fields;

        // Opened before, and the copy is still there: back to it.
        if ($share['copy_conversation_id'] !== null && $viewer->find((int) $share['copy_conversation_id']) !== null) {
            return (int) $share['copy_conversation_id'];
        }

        $copy = (string) $share['kind'] === self::ARTIFACT ? $this->copyArtifact($share, $viewer) : $this->copyConversation($share, $viewer);
        if ($copy !== null) {
            $this->table()->where('share_id', $shareId)->update(['copy_conversation_id' => $copy, 'updated_at' => CallLog::instant(time())]);
        }

        return $copy;
    }

    /**
     * The shared conversation's messages up to the share, and their artifacts, as the recipient's
     * own conversation.
     *
     * @param array<string, mixed> $share
     */
    private function copyConversation(array $share, Conversations $viewer): ?int
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $source = $db->queryBuilder()->table('#PREFIX#chat_conversations')->where('conversation_id', (int) $share['target_id'])->first();
        if (!$source || (int) $source->numRows === 0) {
            return null;
        }
        $query = $db->queryBuilder()->table('#PREFIX#chat_messages')->where('conversation_id', (int) $share['target_id']);
        if ($share['up_to_message_id'] !== null) {
            $query->where('message_id', '<=', (int) $share['up_to_message_id']);
        }
        $result   = $query->orderBy('message_id')->get();
        $messages = [];
        $ids      = [];
        while (($row = $result->fetch()) !== null) {
            $messages[] = ['role' => (string) $row['role'], 'content' => (string) $row['content']];
            $ids[]      = (int) $row['message_id'];
        }
        $copy = $this->seed($viewer, (string) $share['title'], $messages, (string) ($source->fields['tags'] ?? ''), (int) $share['userid']);
        if ($copy === null) {
            return null;
        }

        // Each answer's artifacts follow it into the copy.
        $copied = $this->messageIds($copy);
        if (Artifacts::available() && $ids !== []) {
            $made = $db->queryBuilder()->table('#PREFIX#chat_artifacts')->where('conversation_id', (int) $share['target_id'])
                ->whereIn('message_id', $ids)->orderBy('artifact_id')->get();
            while (($row = $made->fetch()) !== null) {
                $at = array_search((int) $row['message_id'], $ids, true);
                $this->copyArtifactRow($row, $viewer->scope(), $copy, $copied[(int) $at] ?? null);
            }
        }

        return $copy;
    }

    /**
     * The shared artifact, as a conversation of the recipient's own holding a copy of it.
     *
     * @param array<string, mixed> $share
     */
    private function copyArtifact(array $share, Conversations $viewer): ?int
    {
        if (!Artifacts::available()) {
            return null;
        }
        $found = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_artifacts')->where('artifact_id', (int) $share['target_id'])->first();
        if (!$found || (int) $found->numRows === 0) {
            return null;
        }
        $from = self::nameOf((int) $share['userid']);
        $copy = $this->seed($viewer, (string) $share['title'], [
            ['role' => 'assistant', 'content' => sprintf(\Pramnos\Translator\Language::getInstance()->_('%s shared this with you.'), $from)],
        ], (string) ($found->fields['tags'] ?? ''), (int) $share['userid']);
        if ($copy !== null) {
            $this->copyArtifactRow($found->fields, $viewer->scope(), $copy, $this->messageIds($copy)[0] ?? null);
        }

        return $copy;
    }

    /**
     * A conversation of the viewer's own holding these messages, with the original's data tags, so
     * a provider that may not have that data is still not asked about it.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    private function seed(Conversations $viewer, string $title, array $messages, string $tags, int $from): ?int
    {
        $copy = $viewer->seed($title, $messages === [] ? [['role' => 'assistant', 'content' => '']] : $messages);
        if ($copy !== null && $tags !== '' && $tags !== 'null') {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_conversations')
                ->where('conversation_id', $copy)->update(['tags' => $tags]);
        }

        return $copy;
    }

    /**
     * A copy of an artifact row in the viewer's scope, attached to their conversation.
     *
     * @param array<string, mixed> $row
     */
    private function copyArtifactRow(array $row, Scope $scope, int $conversationId, ?int $messageId): void
    {
        $stamp = CallLog::instant(time());
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_artifacts')->insert($scope->columns() + [
            'conversation_id' => $conversationId,
            'message_id'      => $messageId,
            'kind'            => (string) $row['kind'],
            'title'           => (string) $row['title'],
            'entity'          => $row['entity'],
            'content'         => (string) $row['content'],
            'tags'            => $row['tags'],
            'created_at'      => $stamp,
            'updated_at'      => $stamp,
        ]);
    }

    /** @return list<int> A conversation's message ids, in order */
    private function messageIds(int $conversationId): array
    {
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_messages')
            ->select(['message_id'])->where('conversation_id', $conversationId)->orderBy('message_id')->get();
        $ids = [];
        while (($row = $result->fetch()) !== null) {
            $ids[] = (int) $row['message_id'];
        }

        return $ids;
    }

    /**
     * What is being shared, if it is this person's: its title and, for a conversation, its last
     * message so far.
     *
     * @return array{title: string, up_to: ?int}|null
     */
    private function target(Scope $by, string $kind, int $targetId): ?array
    {
        if ($kind === self::ARTIFACT) {
            $artifact = (new Artifacts($by))->find($targetId);

            return $artifact === null ? null : ['title' => $artifact['title'], 'up_to' => null];
        }
        if ($kind !== self::CONVERSATION) {
            return null;
        }
        $db    = \Pramnos\Framework\Factory::getDatabase();
        $found = $by->apply($db->queryBuilder()->table('#PREFIX#chat_conversations'))->where('conversation_id', $targetId)->first();
        if (!$found || (int) $found->numRows === 0) {
            return null;
        }
        $last = $db->queryBuilder()->table('#PREFIX#chat_messages')->where('conversation_id', $targetId)->max('message_id');

        return ['title' => (string) $found->fields['title'], 'up_to' => $last === null ? null : (int) $last];
    }

    /**
     * Tell the recipient, with the link that opens it.
     *
     * @param array<string, mixed> $share
     */
    private function notify(int $recipient, Scope $by, array $share): void
    {
        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $class = is_array($chat) && is_string($chat['share_notification'] ?? null) && class_exists($chat['share_notification'])
            ? $chat['share_notification'] : SharedWithYou::class;
        $words = sprintf(\Pramnos\Translator\Language::getInstance()->_('%s shared “%s” with you'), self::nameOf($by->userId), (string) $share['title']);
        try {
            $user = new \Pramnos\User\User($recipient);
            if ((int) ($user->userid ?? 0) === $recipient) {
                $user->notify(new $class($words, $this->policy()->link($share)));
            }
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('llm share notification: ' . $why->getMessage());
        }
    }

    /** A person's name as others read it: their full name, or their username. */
    private static function nameOf(int $userId): string
    {
        $found = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')->where('userid', $userId)->first();
        if (!$found || (int) $found->numRows === 0) {
            return '#' . $userId;
        }
        $full = trim((string) ($found->fields['firstname'] ?? '') . ' ' . (string) ($found->fields['lastname'] ?? ''));

        return $full !== '' ? $full : (string) ($found->fields['username'] ?? ('#' . $userId));
    }

    private function policy(): SharePolicy
    {
        return $this->policy ?? SharePolicy::current();
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#chat_shares');
    }
}
