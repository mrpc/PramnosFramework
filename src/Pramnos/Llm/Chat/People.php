<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\CallLog;

/**
 * What the companion knows of a person, and the way to start them over — over the companion's
 * spaces only: `chat` and each persona's `chat:<persona>`, never the Ask's or another space's.
 * The conversations themselves are read through {@see \Pramnos\Llm\Analyst\Review}, every
 * channel's alike; this is the panel beside one of the companion's.
 *
 * - {@see person()}: one person in full — conversations, mood, profile, memories, diary, today's
 *   thought, their choices, routines and push subscriptions;
 * - {@see reset()}: their experience reset — their choices back to the defaults and `reset_at`
 *   set, so each persona meets them again — with their history kept;
 * - {@see erase()}: their history with the companion gone as well; no screen offers it.
 *
 * Who may do which is the application's: the class checks nobody.
 */
final class People
{
    /** When the person was last started over, kept with their account (ISO 8601). */
    public const RESET_DETAIL = 'askreset';

    /** The person's companion choices, by the start of their detail's name: each persona's too. */
    public const CHOICES = [Personas::USER_DETAIL, \Pramnos\Llm\Speech\Speech::VOICE_DETAIL, Acts::STYLE_DETAIL, Settings::DETAIL];

    /** The tables a companion space's rows live in, beside the conversations' messages. */
    private const SPACED = ['assist.chat_conversations', 'assist.chat_asks', 'assist.chat_memory', 'assist.chat_artifacts', 'pramnos.ai_routines'];

    /** Each person's last reset, read once: userid => ISO 8601, '' for none. @var array<int, string> */
    private array $resets = [];

    /** Whether a stored space is the companion's: `chat` or `chat:<persona>`. */
    public static function companion(string $space): bool
    {
        // A persona's, and a partition's under it (`chat:tesla@12`).
        return preg_match('/^' . Scope::CHAT . '(:[A-Za-z0-9_-]+)?(@\d+)?$/', $space) === 1;
    }

    /** The persona a stored space is, by key: the default's for plain `chat` (`chat` without personas). */
    public static function personaOf(string $space): string
    {
        $space = (string) strtok($space, '@');

        return str_contains($space, ':') ? substr($space, (int) strpos($space, ':') + 1) : (Personas::default() ?? Scope::CHAT);
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
                'first_meeting' => array_reduce($mine, fn (?string $first, array $c): ?string => self::earlier($first, $this->since($userId, $c['created_at'])), null),
                'mood'          => (new Mood($scope))->now(),
                'memories'      => self::quietly(static fn (): array => (new Memory($scope))->all()),
                'diary'         => ['total' => count($pages), 'unread' => count(array_filter($pages, static fn (array $p): bool => $p['unread']))],
                'thought'       => $this->thought($scope, $day),
                'settings'      => Settings::of($user, $persona),
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
     * Reset a person's experience of the companion — nothing of the history goes: their persona,
     * voice and style (each persona's) are the defaults again, and `reset_at` is now, so each
     * persona meets them again for the first time ({@see firstMeetings()} counts from it). Their
     * conversations, memories, profile, mood, diary and routines stay. One `llm.companion.reset`
     * line in the audit log.
     *
     * @param int $by Who did it
     * @return string When, ISO 8601
     */
    public function reset(int $userId, int $by): string
    {
        $this->table(defined('DB_USERDETAILSTABLE') ? DB_USERDETAILSTABLE : '#PREFIX#userdetails')->where('userid', $userId)->where(
            static function (\Pramnos\Database\QueryBuilder $q): void {
                foreach (self::CHOICES as $i => $prefix) {
                    $i === 0 ? $q->whereLike('fieldname', $prefix . '%') : $q->orWhereLike('fieldname', $prefix . '%');
                }
            }
        )->delete();
        // The details went around the account's caches: read it afresh, and let the next reader too.
        self::forget();
        $at   = gmdate('c');
        $user = new \Pramnos\User\User($userId);
        $user->{self::RESET_DETAIL} = $at;
        $user->save();
        self::forget();
        $this->resets[$userId] = $at;
        \Pramnos\Auth\AuditLog::record('llm.companion.reset', $by, $userId, ['reset_at' => $at]);

        return $at;
    }

    /**
     * Erase a person's history with the companion, and reset their experience ({@see reset()}):
     * their conversations and messages, queued questions, memories, profile, diary, thoughts and
     * every other artifact of its spaces, routines and reminders go, and their diary marks. The
     * Ask's conversations and the person's calendar stay. One `llm.companion.erased` line in the
     * audit log. No screen offers it: it is the application's to call, where it decides to.
     *
     * @param int $by Who did it
     * @return array<string, int> How many of each went
     */
    public function erase(int $userId, int $by): array
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
        $this->table(defined('DB_USERDETAILSTABLE') ? DB_USERDETAILSTABLE : '#PREFIX#userdetails')->where('userid', $userId)
            ->whereLike('fieldname', Diary::READ_DETAIL . '%')->delete();
        self::forget();
        \Pramnos\Auth\AuditLog::record('llm.companion.erased', $by, $userId, $counts);
        $this->reset($userId, $by);

        return $counts;
    }

    /**
     * The first meeting with each persona, by key, for `GET {chat}/me`: the start of the first
     * conversation with it since the person's experience was last reset — null before any.
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
            $out[$key] = self::earlier($out[$key] ?? null, $this->since($userId, $conversation['created_at']));
        }

        return $out;
    }

    /** A conversation's start as ISO 8601, if it is after the person's last reset; else null. */
    private function since(int $userId, string $created): ?string
    {
        $this->resets[$userId] ??= (string) (self::resetAt(new \Pramnos\User\User($userId)) ?? '');
        $at    = Routines::utc($created);
        $reset = $this->resets[$userId] === '' ? null : strtotime($this->resets[$userId]);

        // ponytail: to the second — a conversation begun in the very second of a reset counts as before it.
        return $at === null || ($reset !== null && $reset !== false && $at <= $reset) ? null : gmdate('c', $at);
    }

    /** The earlier of two ISO moments, either of which may be none. */
    private static function earlier(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
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

    /** Forget the accounts read in this request, and their cached rows. */
    private static function forget(): void
    {
        \Pramnos\Framework\Factory::getDatabase()->cacheflush('userlist');
        \Pramnos\User\User::clearUserCache();
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
