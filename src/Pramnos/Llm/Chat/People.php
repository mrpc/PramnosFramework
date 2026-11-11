<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * What an administrator sees of the people who talk to the companion, and the way to start one of
 * them over — over the companion's spaces only: `chat` and each persona's `chat:<persona>`, never
 * the Ask's or another space's.
 *
 * - {@see people()}: who talked to it, newest first, with their conversations and messages by persona;
 * - {@see person()}: one of them in full — conversations, mood, profile, memories, diary, today's
 *   thought, their choices, routines and push subscriptions;
 * - {@see messages()}: a conversation as it was, read without counting as the person's reading;
 * - {@see reset()}: everything the companion knows of them and did with them gone, their choices
 *   back to the defaults, `reset_at` set — and one line in the audit log.
 *
 * Who may do which is the application's: the class checks nobody.
 */
final class People
{
    /** When the person was last started over, kept with their account (ISO 8601). */
    public const RESET_DETAIL = 'askreset';

    /** The person's companion choices, by the start of their detail's name: each persona's too. */
    public const CHOICES = [Personas::USER_DETAIL, \Pramnos\Llm\Speech\Speech::VOICE_DETAIL, Acts::STYLE_DETAIL, Diary::READ_DETAIL];

    /** The tables a companion space's rows live in, beside the conversations' messages. */
    private const SPACED = ['assist.chat_conversations', 'assist.chat_asks', 'assist.chat_memory', 'assist.chat_artifacts', 'pramnos.ai_routines'];

    /** Whether a stored space is the companion's: `chat` or `chat:<persona>`. */
    public static function companion(string $space): bool
    {
        return preg_match('/^' . Scope::CHAT . '(:[A-Za-z0-9_-]+)?$/', $space) === 1;
    }

    /** The persona a stored space is, by key: the default's for plain `chat` (`chat` without personas). */
    public static function personaOf(string $space): string
    {
        return str_contains($space, ':') ? substr($space, (int) strpos($space, ':') + 1) : (Personas::default() ?? Scope::CHAT);
    }

    /**
     * The people who talked to the companion, the latest first.
     *
     * @return list<array{userid: int, username: string, name: string, last_at: ?string, conversations: int, messages: int,
     *         persona: ?string, personas: array<string, array{conversations: int, messages: int, last_at: ?string}>}>
     */
    public function people(int $limit = 50, int $offset = 0, string $search = ''): array
    {
        $query = $this->table('assist.chat_conversations')->select(['userid', $this->db()->queryBuilder()->raw('MAX(updated_at) AS last_at')])
            ->whereLike('space', Scope::CHAT . '%')->groupBy('userid')->orderBy('last_at', 'DESC')->limit(max(1, $limit))->offset(max(0, $offset));
        if (trim($search) !== '') {
            $found = $this->matching(trim($search));
            if ($found === []) {
                return [];
            }
            $query->whereIn('userid', $found);
        }
        $order  = [];
        $result = $query->get();
        while ($result && ($row = $result->fetch()) !== null) {
            $order[] = (int) $row['userid'];
        }
        if ($order === []) {
            return [];
        }
        $out = [];
        foreach ($order as $userId) {
            $user = new \Pramnos\User\User($userId);
            $out[$userId] = ['userid' => $userId, 'username' => (string) ($user->username ?? ''),
                'name' => trim((string) ($user->firstname ?? '') . ' ' . (string) ($user->lastname ?? '')), 'last_at' => null,
                'conversations' => 0, 'messages' => 0, 'persona' => Personas::on() ? Personas::chosen($user) : null, 'personas' => []];
        }
        foreach ($this->conversations($order) as $conversation) {
            $one = &$out[$conversation['userid']];
            $key = self::personaOf($conversation['space']);
            $one['personas'][$key] ??= ['conversations' => 0, 'messages' => 0, 'last_at' => null];
            $one['personas'][$key]['conversations']++;
            $one['personas'][$key]['messages'] += $conversation['messages'];
            $one['personas'][$key]['last_at'] = max((string) $one['personas'][$key]['last_at'], $conversation['last_at']) ?: null;
            $one['conversations']++;
            $one['messages'] += $conversation['messages'];
            $one['last_at'] = max((string) $one['last_at'], $conversation['last_at']) ?: null;
            unset($one);
        }

        return array_values($out);
    }

    /**
     * One person, in full.
     *
     * @return array<string, mixed>|null Null for nobody who talked to the companion
     */
    public function person(int $userId): ?array
    {
        $conversations = $this->conversations([$userId]);
        $user          = new \Pramnos\User\User($userId);
        if ($conversations === [] && (int) ($user->userid ?? 0) !== $userId) {
            return null;
        }
        $organization = $conversations[0]['organization_id'] ?? null;
        $keys         = array_keys(Personas::all()) ?: [Scope::CHAT];
        foreach ($conversations as $conversation) {
            $keys[] = self::personaOf($conversation['space']);
        }
        $day      = (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
        $personas = [];
        foreach (array_unique($keys) as $key) {
            $persona = $key === Scope::CHAT || $key === Personas::default() ? null : $key;
            $scope   = new Scope($userId, $organization, Scope::CHAT, $persona);
            $mine    = array_values(array_filter($conversations, static fn (array $c): bool => $c['space'] === $scope->stored()));
            $pages   = Artifacts::available() ? (new Diary($scope))->pages($user, 500) : [];
            $personas[$key] = [
                'conversations' => array_map(static fn (array $c): array => array_diff_key($c, ['userid' => 1, 'space' => 1, 'organization_id' => 1]), $mine),
                'first_meeting' => $mine === [] ? null : self::iso((string) min(array_column($mine, 'created_at'))),
                'mood'          => (new Mood($scope))->now(),
                'memories'      => self::quietly(static fn (): array => (new Memory($scope))->all()),
                'diary'         => ['total' => count($pages), 'unread' => count(array_filter($pages, static fn (array $p): bool => $p['unread']))],
                'thought'       => $this->thought($scope, $day),
                'voice'         => \Pramnos\Llm\Speech\Speech::chosen($user, $persona),
                'style'         => Acts::style($user, $persona),
                'routines'      => array_map(static fn (array $r): array => ['routine_id' => $r['routine_id'], 'title' => $r['title'], 'kind' => $r['kind'],
                    'cadence' => $r['cadence'], 'next' => $r['next_run_local'], 'active' => $r['active']],
                    self::quietly(static fn (): array => (new Routines(new Conversations(new BaseAssistant())))->mine($scope))),
            ];
        }
        $reset = trim((string) ($user->{self::RESET_DETAIL} ?? ''));

        return [
            'userid'   => $userId,
            'username' => (string) ($user->username ?? ''),
            'persona'  => Personas::on() ? Personas::chosen($user) : null,
            'profile'  => Profile::available() ? (new Profile(new Scope($userId, $organization)))->entries() : [],
            'personas' => $personas,
            'push'     => array_map(static fn (array $s): array => ['app' => $s['app'] ?? null, 'client' => $s['client'] ?? null,
                'user_agent' => (string) ($s['user_agent'] ?? ''), 'created_at' => $s['created_at'] ?? null, 'failure_count' => (int) ($s['failure_count'] ?? 0)],
                \Pramnos\Push\Subscriptions::forUser($userId, \Pramnos\Push\Subscriptions::EVERY_APP)),
            'reset_at' => $reset === '' ? null : $reset,
        ];
    }

    /**
     * A companion conversation's messages, as they were, for an administrator: with the tools
     * called, the mood, act and the rest of `extra`, and the cost. Read straight from the table, so
     * nothing the person has read changes and nothing is learned.
     *
     * @return array{conversation: array<string, mixed>, messages: list<array<string, mixed>>}|null Null for one not the companion's
     */
    public function messages(int $conversationId): ?array
    {
        $found = $this->table('assist.chat_conversations')->where('conversation_id', $conversationId)->get();
        $row   = $found ? $found->fetch() : null;
        if (!is_array($row) || !self::companion((string) $row['space'])) {
            return null;
        }
        $out    = [];
        $result = $this->table('assist.chat_messages')->where('conversation_id', $conversationId)->orderBy('message_id')->get();
        while ($result && ($m = $result->fetch()) !== null) {
            $out[] = ['message_id' => (int) $m['message_id'], 'role' => (string) $m['role'], 'content' => (string) $m['content'],
                'tools' => json_decode((string) ($m['tools'] ?? ''), true), 'extra' => json_decode((string) ($m['extra'] ?? ''), true),
                'cost' => $m['cost'] === null ? null : (float) $m['cost'], 'created_at' => (string) $m['created_at']];
        }

        return ['conversation' => ['conversation_id' => $conversationId, 'userid' => (int) $row['userid'], 'persona' => self::personaOf((string) $row['space']),
            'title' => (string) $row['title'], 'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at']], 'messages' => $out];
    }

    /**
     * Start a person over with the companion: their conversations and messages, queued questions,
     * memories, profile, diary, thoughts and every other artifact of its spaces, routines and
     * reminders go — the mood and first meetings with the conversations — their persona, voice,
     * style and diary marks are the defaults again, and `reset_at` is now. The Ask's conversations
     * and the person's calendar stay. One `llm.companion.reset` line in the audit log.
     *
     * @param int $by Who did it
     * @return array<string, int> How many of each went
     */
    public function reset(int $userId, int $by): array
    {
        $spaces = [];
        foreach (self::SPACED as $table) {
            if ($this->db()->schema()->hasTable($table)) {
                $result = $this->table($table)->select(['space'])->distinct()->where('userid', $userId)->whereLike('space', Scope::CHAT . '%')->get();
                while ($result && ($row = $result->fetch()) !== null) {
                    $spaces[] = (string) $row['space'];
                }
            }
        }
        $spaces = array_values(array_unique(array_filter($spaces, self::companion(...))));
        $counts = ['conversations' => 0, 'messages' => 0, 'asks' => 0, 'memories' => 0, 'artifacts' => 0, 'routines' => 0, 'profile' => 0];
        if ($spaces !== []) {
            $ids = $this->ids('assist.chat_conversations', 'conversation_id', $userId, $spaces);
            if ($ids !== []) {
                $counts['messages'] = $this->table('assist.chat_messages')->whereIn('conversation_id', $ids)->count();
                $this->table('assist.chat_messages')->whereIn('conversation_id', $ids)->delete();
                $this->unshare(Shares::CONVERSATION, $ids);
            }
            $artifacts = $this->ids('assist.chat_artifacts', 'artifact_id', $userId, $spaces);
            $this->unshare(Shares::ARTIFACT, $artifacts);
            $routines = $this->ids('pramnos.ai_routines', 'routine_id', $userId, $spaces);
            foreach (['pramnos.ai_task_runs', 'pramnos.ai_task_subscriptions'] as $table) {
                if ($routines !== [] && $this->db()->schema()->hasTable($table)) {
                    $this->table($table)->whereIn('routine_id', $routines)->delete();
                }
            }
            $counts['conversations'] = count($ids);
            $counts['artifacts']     = count($artifacts);
            $counts['routines']      = count($routines);
            foreach (['assist.chat_asks' => 'asks', 'assist.chat_memory' => 'memories', 'assist.chat_artifacts' => null,
                'pramnos.ai_routines' => null, 'assist.chat_conversations' => null] as $table => $counted) {
                if ($this->db()->schema()->hasTable($table)) {
                    if ($counted !== null) {
                        $counts[$counted] = $this->table($table)->where('userid', $userId)->whereIn('space', $spaces)->count();
                    }
                    $this->table($table)->where('userid', $userId)->whereIn('space', $spaces)->delete();
                }
            }
        }
        $counts['profile'] = Profile::erase('userid', $userId, $this->db());
        $details = $this->table(defined('DB_USERDETAILSTABLE') ? DB_USERDETAILSTABLE : '#PREFIX#userdetails')->where('userid', $userId)->where(
            static function (\Pramnos\Database\QueryBuilder $q): void {
                foreach (self::CHOICES as $i => $prefix) {
                    $i === 0 ? $q->whereLike('fieldname', $prefix . '%') : $q->orWhereLike('fieldname', $prefix . '%');
                }
            }
        );
        $details->delete();
        $user = new \Pramnos\User\User($userId);
        $user->{self::RESET_DETAIL} = gmdate('c');
        $user->save();
        \Pramnos\Auth\AuditLog::record('llm.companion.reset', $by, $userId, $counts);

        return $counts;
    }

    /**
     * The first meeting with each persona, by key — null before any — for `GET {chat}/me`.
     *
     * @return array<string, ?string>
     */
    public function firstMeetings(int $userId): array
    {
        $out = [];
        foreach (array_keys(Personas::all()) ?: [Scope::CHAT] as $key) {
            $out[$key] = null;
        }
        foreach ($this->conversations([$userId]) as $conversation) {
            $key       = self::personaOf($conversation['space']);
            $first     = self::iso($conversation['created_at']);
            $out[$key] = $out[$key] === null || ($first !== null && $first < $out[$key]) ? $first : $out[$key];
        }

        return $out;
    }

    /** When a person was last started over (ISO 8601), or null. */
    public static function resetAt(object $user): ?string
    {
        $at = trim((string) ($user->{self::RESET_DETAIL} ?? ''));

        return $at === '' ? null : $at;
    }

    /**
     * Some people's companion conversations, each with how many messages it has.
     *
     * @param list<int> $userIds
     * @return list<array{conversation_id: int, userid: int, organization_id: ?int, space: string, title: string, messages: int, created_at: string, last_at: string}>
     */
    private function conversations(array $userIds): array
    {
        $out    = [];
        $result = $this->table('assist.chat_conversations')->select(['conversation_id', 'userid', 'organization_id', 'space', 'title', 'created_at', 'updated_at'])
            ->whereIn('userid', $userIds)->whereLike('space', Scope::CHAT . '%')->orderBy('conversation_id', 'DESC')->get();
        while ($result && ($row = $result->fetch()) !== null) {
            if (self::companion((string) $row['space'])) {
                $out[(int) $row['conversation_id']] = ['conversation_id' => (int) $row['conversation_id'], 'userid' => (int) $row['userid'],
                    'organization_id' => $row['organization_id'] === null ? null : (int) $row['organization_id'], 'space' => (string) $row['space'],
                    'title' => (string) $row['title'], 'messages' => 0, 'created_at' => (string) $row['created_at'], 'last_at' => (string) $row['updated_at']];
            }
        }
        if ($out !== []) {
            $counts = $this->table('assist.chat_messages')->select(['conversation_id', $this->db()->queryBuilder()->raw('COUNT(*) AS n')])
                ->whereIn('conversation_id', array_keys($out))->groupBy('conversation_id')->get();
            while ($counts && ($row = $counts->fetch()) !== null) {
                $out[(int) $row['conversation_id']]['messages'] = (int) $row['n'];
            }
        }

        return array_values($out);
    }

    /** Who a search finds: by username, email or name, the first 500. @return list<int> */
    private function matching(string $search): array
    {
        $like   = '%' . $search . '%';
        $result = $this->table('#PREFIX#users')->select(['userid'])->where(static function (\Pramnos\Database\QueryBuilder $q) use ($like): void {
            $q->whereLike('username', $like)->orWhereLike('email', $like)->orWhereLike('firstname', $like)->orWhereLike('lastname', $like);
        })->limit(500)->get();
        $out = [];
        while ($result && ($row = $result->fetch()) !== null) {
            $out[] = (int) $row['userid'];
        }

        return $out;
    }

    /** The ids of a person's rows of a table in some spaces. @return list<int> */
    private function ids(string $table, string $column, int $userId, array $spaces): array
    {
        if (!$this->db()->schema()->hasTable($table)) {
            return [];
        }
        $out    = [];
        $result = $this->table($table)->select([$column])->where('userid', $userId)->whereIn('space', $spaces)->get();
        while ($result && ($row = $result->fetch()) !== null) {
            $out[] = (int) $row[$column];
        }

        return $out;
    }

    /** What was shared of some conversations or artifacts, unshared. */
    private function unshare(string $kind, array $ids): void
    {
        if ($ids !== [] && Shares::available()) {
            $this->table('assist.chat_shares')->where('kind', $kind)->whereIn('target_id', $ids)->delete();
        }
    }

    /** Today's thought of a persona, if it was written. */
    private function thought(Scope $scope, string $day): ?string
    {
        if (!Artifacts::available()) {
            return null;
        }
        $artifacts = new Artifacts($scope);
        foreach ($artifacts->search(['kind' => 'text', 'query' => Today::THOUGHT . ' ' . $day]) as $found) {
            if ($found['title'] === Today::THOUGHT . ' ' . $day) {
                return (string) ($artifacts->find((int) $found['artifact_id'])['content']['text'] ?? '') ?: null;
            }
        }

        return null;
    }

    /** What a call answers, or nothing where its table is not there. */
    private static function quietly(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable) {
            return [];
        }
    }

    /** A stored moment as ISO 8601, or null. */
    private static function iso(string $stored): ?string
    {
        $at = Routines::utc($stored);

        return $at === null ? null : gmdate('c', $at);
    }

    private function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }

    private function table(string $name): \Pramnos\Database\QueryBuilder
    {
        return $this->db()->queryBuilder()->table($name);
    }
}
