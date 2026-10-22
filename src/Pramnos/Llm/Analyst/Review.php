<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

use Pramnos\Database\Expression;
use Pramnos\Llm\Chat\Scope;

/**
 * Every administrator's Ask conversations, for whoever reads them all — to debug what the analyst
 * did for somebody else. Read only: nothing here writes to a conversation, to anybody's memory, or
 * asks the model anything.
 *
 * `admin.ai.all` reads them; in usertype mode root's alone, as `admin.chat.all` is — reading other
 * people's questions is more than a superuser's work. A conversation opens as its owner sees it,
 * each answer with the model calls made while it was being answered (the call log's rows: the
 * prompt, the tools' input and output, the reply), so what a tool returned can be read too.
 */
final class Review
{
    /** The ability that reads every administrator's Ask conversations. */
    public const ABILITY = 'admin.ai.all';

    /** Whether an account reads every administrator's Ask conversations. */
    public static function readsAll(?object $user): bool
    {
        return $user !== null && (int) ($user->userid ?? 0) > 0 && (int) ($user->active ?? 1) === 1
            && \Pramnos\Auth\AdminAccess::allows($user, self::ABILITY, \Pramnos\User\Roles::level(\Pramnos\User\Roles::ROOT));
    }

    /**
     * The conversations, newest first, each with its owner, how many messages and what they cost;
     * `person` keeps one owner's, `q` those whose title holds it, `except` leaves one owner's out —
     * the reader's own, which open in Ask itself.
     *
     * @param array{person?: int, q?: string, except?: int} $filters
     * @return list<array{conversation_id: int, owner: int, owner_name: string, title: string, updated_at: string, messages: int, cost: float}>
     */
    public static function list(array $filters = [], int $limit = 200): array
    {
        $query = self::table('#PREFIX#chat_conversations')->select(['conversation_id', 'userid', 'title', 'updated_at'])->where('space', Scope::ADMIN);
        if ((int) ($filters['person'] ?? 0) > 0) {
            $query->where('userid', (int) $filters['person']);
        }
        if ((int) ($filters['except'] ?? 0) > 0) {
            $query->where('userid', '!=', (int) $filters['except']);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->whereLike('title', '%' . addcslashes($q, '%_\\') . '%');
        }
        $rows = $query->orderBy('updated_at', 'desc')->orderBy('conversation_id', 'desc')->limit(max(1, min(500, $limit)))->getAllOrFail();
        if ($rows === []) {
            return [];
        }
        $ids    = array_map('intval', array_column($rows, 'conversation_id'));
        $totals = [];
        foreach (self::table('#PREFIX#chat_messages')->select(['conversation_id', new Expression('COUNT(*) AS n'), new Expression('SUM(cost) AS cost')])
            ->whereIn('conversation_id', $ids)->groupBy('conversation_id')->getAllOrFail() as $row) {
            $totals[(int) $row['conversation_id']] = [(int) $row['n'], (float) ($row['cost'] ?? 0)];
        }
        $names = \Pramnos\Conversations\People::names(array_map('intval', array_column($rows, 'userid')));

        return array_map(static fn (array $r): array => [
            'conversation_id' => (int) $r['conversation_id'], 'owner' => (int) $r['userid'], 'owner_name' => $names[(int) $r['userid']] ?? '#' . $r['userid'],
            'title' => (string) $r['title'], 'updated_at' => (string) $r['updated_at'],
            'messages' => $totals[(int) $r['conversation_id']][0] ?? 0, 'cost' => $totals[(int) $r['conversation_id']][1] ?? 0.0,
        ], $rows);
    }

    /**
     * Who has Ask conversations, by name — for the filter; `$except`, an owner left out.
     *
     * @return array<int, string>
     */
    public static function owners(int $except = 0): array
    {
        $query = self::table('#PREFIX#chat_conversations')->select(['userid'])->where('space', Scope::ADMIN);
        if ($except > 0) {
            $query->where('userid', '!=', $except);
        }
        $ids   = array_map('intval', array_column($query->groupBy('userid')->getAllOrFail(), 'userid'));
        $names = \Pramnos\Conversations\People::names($ids);
        asort($names);

        return $names;
    }

    /**
     * One conversation as its owner sees it, with its owner; each answer carries the ids of the
     * model calls made while it was being answered. Null when there is no such Ask conversation.
     *
     * @return array{owner: int, owner_name: string, conversation: array<string, mixed>}|null
     */
    public static function open(int $conversationId): ?array
    {
        $row = self::table('#PREFIX#chat_conversations')->select(['userid'])->where('conversation_id', $conversationId)->where('space', Scope::ADMIN)
            ->limit(1)->getAllOrFail()[0] ?? null;
        if ($row === null) {
            return null;
        }
        $owner        = (int) $row['userid'];
        $conversation = Analyst::conversations($owner)->find($conversationId);
        if ($conversation === null) {
            return null;
        }
        $conversation['messages'] = self::withCalls($owner, $conversation['messages']);

        return ['owner' => $owner, 'owner_name' => \Pramnos\Conversations\People::names([$owner])[$owner] ?? '#' . $owner, 'conversation' => $conversation];
    }

    /**
     * Each answer with the analyst's calls logged between its question and the next one.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private static function withCalls(int $owner, array $messages): array
    {
        $questions = array_values(array_filter($messages, static fn (array $m): bool => $m['role'] === 'user'));
        $from      = null;
        foreach ($messages as $i => $message) {
            if ($message['role'] === 'user') {
                $from = (string) $message['created_at'];
                continue;
            }
            $next  = null;
            foreach ($questions as $q) {
                if ((string) $q['created_at'] > (string) $message['created_at']) {
                    $next = (string) $q['created_at'];
                    break;
                }
            }
            // ponytail: matched by time — an owner asking in two conversations at once shares calls between them; a call → message link if that matters.
            $query = self::table('#PREFIX#llm_calls')->select(['call_id'])->where('userid', $owner)->where('purpose', 'analyst')
                ->where('created_at', '>=', $from ?? (string) $message['created_at']);
            if ($next !== null) {
                $query->where('created_at', '<', $next);
            }
            $messages[$i]['calls'] = array_map('intval', array_column($query->orderBy('call_id')->limit(50)->getAllOrFail(), 'call_id'));
        }

        return $messages;
    }

    /** A table of the application's database. */
    private static function table(string $name): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table($name);
    }
}
