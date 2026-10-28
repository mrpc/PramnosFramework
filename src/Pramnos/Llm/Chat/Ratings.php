<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * What people think of the answers, and the queue of bad ones somebody should look at.
 *
 * A person rates an answer in their own conversation — good or bad, with a note if they like —
 * and rating again replaces it. A bad answer waits in the review queue, with the question it
 * answered and the note, until a reviewer marks it reviewed; that is written to the audit log
 * (`ai.answer_reviewed`). The queue is read by whoever reads everybody's Ask conversations
 * (`admin.ai.all`), since it shows their questions.
 *
 * ```php
 * (new Ratings())->rate($scope, $messageId, -1, 'It counted the cancelled ones');
 * (new Ratings())->queue();                         // bad answers not yet reviewed
 * (new Ratings())->reviewed($messageId, $reviewer);
 * (new Ratings())->summary(30);                     // good, bad, waiting
 * ```
 */
final class Ratings
{
    /** Characters a note may hold, as the column. */
    public const NOTE = 500;

    /** Characters of a question and an answer the queue shows. */
    public const EXCERPT = 2000;

    /** Whether this installation keeps ratings: the columns exist. */
    public static function available(): bool
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn('chat_messages', 'rating');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rate an answer in one of this person's conversations: 1 good, -1 bad, 0 to take it back.
     * Answers null, or why not.
     */
    public function rate(Scope $scope, int $messageId, int $rating, string $note = ''): ?string
    {
        if (!self::available()) {
            return 'Rating is not set up here.';
        }
        if (!in_array($rating, [-1, 0, 1], true)) {
            return 'A rating is good (1), bad (-1), or none (0).';
        }
        $found = $this->table('#PREFIX#chat_messages')->select(['conversation_id', 'role'])->where('message_id', $messageId)->first();
        if (!$found || (int) $found->numRows === 0 || (string) $found->fields['role'] !== 'assistant') {
            return 'There is no such answer.';
        }
        $mine = $scope->apply($this->table('#PREFIX#chat_conversations'))->where('conversation_id', (int) $found->fields['conversation_id'])->first();
        if (!$mine || (int) $mine->numRows === 0) {
            return 'There is no such answer.';
        }
        $note = mb_substr(trim($note), 0, self::NOTE);
        $this->table('#PREFIX#chat_messages')->where('message_id', $messageId)->update([
            'rating'      => $rating === 0 ? null : $rating,
            'rating_note' => $rating === 0 || $note === '' ? null : $note,
            'rated_at'    => $rating === 0 ? null : CallLog::instant(time()),
            // A new verdict is looked at anew.
            'reviewed_at' => null,
        ]);

        return null;
    }

    /**
     * The bad answers nobody has reviewed yet, newest first: each with its question, its note,
     * whose it is and in which space.
     *
     * @return list<array{message_id: int, conversation_id: int, title: string, owner: int, owner_name: string, space: string, question: string, answer: string, note: string, rated_at: string}>
     */
    public function queue(int $limit = 100, bool $includeReviewed = false): array
    {
        if (!self::available()) {
            return [];
        }
        $query = $this->table('#PREFIX#chat_messages')->select(['message_id', 'conversation_id', 'content', 'rating_note', 'rated_at', 'reviewed_at'])
            ->where('rating', -1);
        if (!$includeReviewed) {
            $query->whereNull('reviewed_at');
        }
        $rows = $query->orderBy('rated_at', 'desc')->orderBy('message_id', 'desc')->limit(max(1, min(500, $limit)))->getAllOrFail();
        if ($rows === []) {
            return [];
        }
        $conversations = [];
        foreach ($this->table('#PREFIX#chat_conversations')->select(['conversation_id', 'userid', 'title', 'space'])
            ->whereIn('conversation_id', array_map('intval', array_column($rows, 'conversation_id')))->getAllOrFail() as $row) {
            $conversations[(int) $row['conversation_id']] = $row;
        }
        $names = \Pramnos\Conversations\People::names(array_map(static fn (array $c): int => (int) $c['userid'], $conversations));

        $out = [];
        foreach ($rows as $row) {
            $conversation = $conversations[(int) $row['conversation_id']] ?? null;
            if ($conversation === null) {
                continue;
            }
            $out[] = [
                'message_id'      => (int) $row['message_id'],
                'conversation_id' => (int) $row['conversation_id'],
                'title'           => (string) $conversation['title'],
                'owner'           => (int) $conversation['userid'],
                'owner_name'      => $names[(int) $conversation['userid']] ?? '#' . $conversation['userid'],
                'space'           => (string) $conversation['space'],
                'question'        => mb_substr($this->questionBefore((int) $row['conversation_id'], (int) $row['message_id']), 0, self::EXCERPT),
                'answer'          => mb_substr((string) $row['content'], 0, self::EXCERPT),
                'note'            => (string) ($row['rating_note'] ?? ''),
                'rated_at'        => (string) $row['rated_at'],
                'reviewed'        => $row['reviewed_at'] !== null,
            ];
        }

        return $out;
    }

    /** Take a bad answer out of the queue, as looked at by this reviewer. False when it is not in it. */
    public function reviewed(int $messageId, int $reviewer): bool
    {
        if (!self::available()) {
            return false;
        }
        $found = $this->table('#PREFIX#chat_messages')->select(['conversation_id'])->where('message_id', $messageId)->where('rating', -1)->whereNull('reviewed_at')->first();
        if (!$found || (int) $found->numRows === 0) {
            return false;
        }
        $this->table('#PREFIX#chat_messages')->where('message_id', $messageId)->update(['reviewed_at' => CallLog::instant(time())]);
        \Pramnos\Auth\AuditLog::record('ai.answer_reviewed', $reviewer, null, [], 'chat_message', $messageId);

        return true;
    }

    /**
     * How the answers were rated over the last days: good, bad, and bad still waiting.
     *
     * @return array{good: int, bad: int, waiting: int}
     */
    public function summary(int $days = 30): array
    {
        if (!self::available()) {
            return ['good' => 0, 'bad' => 0, 'waiting' => 0];
        }
        $since = CallLog::instant(time() - $days * 86400);
        $count = fn (int $rating, bool $waiting = false): int => (int) ($waiting
            ? $this->table('#PREFIX#chat_messages')->where('rating', $rating)->whereNull('reviewed_at')->count()
            : $this->table('#PREFIX#chat_messages')->where('rating', $rating)->where('rated_at', '>=', $since)->count());

        return ['good' => $count(1), 'bad' => $count(-1), 'waiting' => $count(-1, true)];
    }

    /** The person's question an answer replied to: the last of theirs before it. */
    private function questionBefore(int $conversationId, int $messageId): string
    {
        $found = $this->table('#PREFIX#chat_messages')->select(['content'])->where('conversation_id', $conversationId)
            ->where('message_id', '<', $messageId)->where('role', 'user')->orderBy('message_id', 'desc')->first();

        return $found && (int) $found->numRows > 0 ? (string) $found->fields['content'] : '';
    }

    private function table(string $name): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table($name);
    }
}
