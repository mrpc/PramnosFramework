<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * The people of an account who talk to the companion under it — a family's children, the other
 * parent — each with their own conversations, memories, diary, photos, plan and reminders.
 *
 * On with `'llm' => ['chat' => ['profiles' => true]]` (or `['limit' => fn (object $user): int]`,
 * how many the account may have — the application's, by its subscription). A profile is kept in
 * {@see TABLE}; what it says is kept where everything is, under its own space (`chat@<profile>`,
 * {@see Scope::$profile}), so nothing else changes shape.
 *
 * - **Its kind** — `parent`, `child`, or an audience the application declares — is whom its
 *   answers are for ({@see Audience}); its age is from its year of birth.
 * - **Which profile speaks** is the request's `X-Profile` header, among the account's own; none is
 *   the account's holder.
 * - **A device held to a child**: a session locked to a profile ({@see lock()}) is that profile,
 *   whatever the request says, until it is unlocked with the account's PIN ({@see unlock()}).
 *   Without a PIN set, no lock is made.
 * - **Another profile's conversations**, read by a parent's ({@see conversationsOf()}), with a
 *   summary of them; and after each answer of a profile, the application's own hook
 *   (`chat.profiles.after`), which is where it decides whether to tell anybody.
 */
final class Profiles
{
    /** The profiles, by account. */
    public const TABLE = 'assist.chat_profiles';

    /** The sessions held to a profile. */
    public const LOCKS = 'assist.chat_profile_locks';

    /** The user detail the account's PIN is kept in, hashed. */
    public const PIN = 'askpin';

    /** How many wrong PINs an account may give in {@see PIN_WINDOW} seconds. */
    public const PIN_TRIES = 5;

    public const PIN_WINDOW = 900;

    /** How many profiles an account may have unless the application says. */
    public const LIMIT = 6;

    /** The header naming the profile that speaks. */
    public const HEADER = 'HTTP_X_PROFILE';

    /** Whether the application turned profiles on and their table is there. */
    public static function on(): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);
        if (!is_array($chat) || empty($chat['profiles'])) {
            return false;
        }
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /** How many profiles this account may have: the application's word, else {@see LIMIT}. */
    public static function limit(object $user): int
    {
        $given = ((array) (((array) \Pramnos\Llm\Llm::config('chat', []))['profiles'] ?? []))['limit'] ?? null;

        return is_callable($given) ? max(0, (int) $given($user)) : self::LIMIT;
    }

    /**
     * An account's profiles, as made.
     *
     * @return list<array{profile_id: int, name: string, kind: string, age: ?int, birth_year: ?int, image: ?string}>
     */
    public static function of(int $userId): array
    {
        if (!self::on()) {
            return [];
        }

        return array_map(self::shape(...), self::table()->where('userid', $userId)->orderBy('profile_id')->getAllOrFail());
    }

    /** One of an account's profiles, or null. @return array<string, mixed>|null */
    public static function get(int $userId, int $profileId): ?array
    {
        if (!self::on() || $profileId <= 0) {
            return null;
        }
        $row = self::table()->where('userid', $userId)->where('profile_id', $profileId)->first();

        return $row && (int) $row->numRows > 0 ? self::shape($row->fields) : null;
    }

    /**
     * A profile made: `name`, `kind` (`parent`, `child` or an audience the application declares),
     * `birth_year`, `image`. Refused past the account's limit.
     *
     * @param array<string, mixed> $given
     * @return array<string, mixed>|string
     */
    public static function create(object $user, array $given): array|string
    {
        if (!self::on()) {
            return 'Profiles are not on here.';
        }
        $userId = (int) ($user->userid ?? 0);
        if (count(self::of($userId)) >= self::limit($user)) {
            return 'This account has as many profiles as it may.';
        }
        $row = self::fields($given, true);
        if (is_string($row)) {
            return $row;
        }
        $stamp = \Pramnos\Llm\CallLog::instant(time());
        self::table()->insert($row + ['userid' => $userId, 'created_at' => $stamp, 'updated_at' => $stamp]);

        return self::get($userId, (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId()) ?? 'The profile could not be kept.';
    }

    /**
     * The profiles the application gives, made and kept up to date: its hook
     * `chat.profiles.from` — `fn (int $userId): array` answering `[source => {name, kind,
     * birth_year, image}]`, a child it already keeps by its own key — makes the missing ones and
     * changes those whose name, year or image changed. One it no longer gives is marked detached,
     * never deleted: what it said stays, for the account holder to keep as a profile of its own or
     * to delete. A profile with no source is never touched. Answers how many were made.
     */
    public static function sync(int $userId): int
    {
        $hook = ((array) (((array) \Pramnos\Llm\Llm::config('chat', []))['profiles'] ?? []))['from'] ?? null;
        if (!self::on() || !is_callable($hook) || $userId <= 0) {
            return 0;
        }
        try {
            $given = (array) $hook($userId);
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('llm profiles from-hook: ' . $why->getMessage(), 'llm');

            return 0;
        }
        $mine  = array_column(array_filter(self::of($userId), static fn (array $p): bool => $p['source'] !== null), null, 'source');
        $stamp = \Pramnos\Llm\CallLog::instant(time());
        $made  = 0;
        foreach ($given as $source => $one) {
            $source = mb_substr(trim((string) $source), 0, 64);
            $fields = is_array($one) && $source !== '' ? self::fields(array_intersect_key($one, array_flip(['name', 'kind', 'birth_year', 'image'])), true) : 'x';
            if (is_string($fields)) {
                continue;
            }
            $have = $mine[$source] ?? null;
            if ($have === null) {
                // The application's to give: it says how many, so its own are not held to the limit.
                self::table()->insert($fields + ['userid' => $userId, 'source' => $source, 'created_at' => $stamp, 'updated_at' => $stamp]);
                $made++;
                continue;
            }
            $changed = array_filter($fields, static fn ($v, string $k): bool => $k !== 'kind' && $have[$k] !== $v, ARRAY_FILTER_USE_BOTH);
            if ($changed !== [] || $have['detached']) {
                self::table()->where('profile_id', $have['profile_id'])->update($changed + ['detached_at' => null, 'updated_at' => $stamp]);
            }
        }
        foreach ($mine as $source => $have) {
            if (!array_key_exists($source, $given) && !$have['detached']) {
                self::table()->where('profile_id', $have['profile_id'])->update(['detached_at' => $stamp, 'updated_at' => $stamp]);
            }
        }

        return $made;
    }

    /**
     * A profile changed: any of its fields.
     *
     * @param array<string, mixed> $given
     * @return array<string, mixed>|string
     */
    public static function update(int $userId, int $profileId, array $given): array|string
    {
        if (self::get($userId, $profileId) === null) {
            return 'There is no such profile.';
        }
        $row = self::fields($given, false);
        if (is_string($row)) {
            return $row;
        }
        if ($row !== []) {
            self::table()->where('userid', $userId)->where('profile_id', $profileId)->update($row + ['updated_at' => \Pramnos\Llm\CallLog::instant(time())]);
        }

        return self::get($userId, $profileId) ?? 'There is no such profile.';
    }

    /** A profile deleted, with everything it said and kept, and any lock to it. */
    public static function delete(int $userId, int $profileId): bool
    {
        if (self::get($userId, $profileId) === null) {
            return false;
        }
        self::eraseData($userId, $profileId);
        self::table()->where('userid', $userId)->where('profile_id', $profileId)->delete();
        self::db()->queryBuilder()->table(self::LOCKS)->where('userid', $userId)->where('profile_id', $profileId)->delete();

        return true;
    }

    /**
     * What a profile said and kept, cleared — its conversations, memories, artifacts, reminders —
     * the profile itself kept: its experience started again. The choices kept with the account
     * (persona, voice, quiet hours) are the account's, every profile's alike.
     */
    public static function clear(int $userId, int $profileId, int $by): bool
    {
        if (self::get($userId, $profileId) === null) {
            return false;
        }
        self::eraseData($userId, $profileId);
        \Pramnos\Auth\AuditLog::record('llm.companion.profile_cleared', $by, $userId, ['profile' => $profileId]);

        return true;
    }

    /** Every profile of an account, and what each kept: the account's erasure. */
    public static function erase(int $userId): int
    {
        if (!self::on()) {
            return 0;
        }
        $all = self::of($userId);
        foreach ($all as $profile) {
            self::delete($userId, (int) $profile['profile_id']);
        }

        return count($all);
    }

    /** A profile's name by its id alone, for the administration's lists; null for none. */
    public static function nameOf(int $profileId): ?string
    {
        if (!self::on() || $profileId <= 0) {
            return null;
        }
        $name = self::table()->where('profile_id', $profileId)->value('name');

        return $name === null || $name === false ? null : (string) $name;
    }

    /** The kind of the profile a scope speaks as — null for the account holder. */
    public static function kindOf(?Scope $scope): ?string
    {
        if ($scope === null || $scope->profile === null) {
            return null;
        }

        return self::get($scope->userId, $scope->profile)['kind'] ?? null;
    }

    /** The age of the profile a scope speaks as, where its year of birth is known. */
    public static function ageOf(?Scope $scope): ?int
    {
        return $scope === null || $scope->profile === null ? null : (self::get($scope->userId, $scope->profile)['age'] ?? null);
    }

    /**
     * The scope a request speaks in: a session held to a profile is that profile, whatever it
     * says; else the profile its `X-Profile` names, when it is the account's; else the holder.
     */
    public static function forRequest(Scope $scope): Scope
    {
        if (!self::on()) {
            return $scope;
        }
        $locked = self::lockedTo($scope->userId);
        if ($locked !== null) {
            return $scope->withProfile($locked);
        }
        $asked = (int) ($_SERVER[self::HEADER] ?? 0);

        return $asked > 0 && self::get($scope->userId, $asked) !== null ? $scope->withProfile($asked) : $scope->withProfile(null);
    }

    /** The account's PIN set or changed: four to eight digits. */
    public static function setPin(object $user, string $pin): ?string
    {
        if (preg_match('/^\d{4,8}$/', $pin) !== 1) {
            return 'A PIN is four to eight digits.';
        }
        $user->{self::PIN} = password_hash($pin, PASSWORD_DEFAULT);
        $user->save();

        return null;
    }

    /** Whether the account has a PIN. */
    public static function hasPin(object $user): bool
    {
        return (string) ($user->{self::PIN} ?? '') !== '';
    }

    /**
     * This session held to one of the account's profiles: every request on it speaks as that
     * profile until it is unlocked. Needs the account's PIN set, so it can be undone.
     */
    public static function lock(object $user, int $profileId): ?string
    {
        $userId = (int) ($user->userid ?? 0);
        if (self::get($userId, $profileId) === null) {
            return 'There is no such profile.';
        }
        if (!self::hasPin($user)) {
            return 'Set a PIN first: it is what unlocks this device again.';
        }
        $key = self::sessionKey();
        if ($key === null) {
            return 'There is no session to lock.';
        }
        $locks = self::db()->queryBuilder()->table(self::LOCKS);
        $locks->where('key_hash', $key)->delete();
        $stamp = \Pramnos\Llm\CallLog::instant(time());
        self::db()->queryBuilder()->table(self::LOCKS)->insert(['key_hash' => $key, 'userid' => $userId, 'profile_id' => $profileId, 'created_at' => $stamp, 'updated_at' => $stamp]);

        return null;
    }

    /** This session let go of its profile, with the account's PIN; a few wrong ones, and it waits. */
    public static function unlock(object $user, string $pin): ?string
    {
        $userId = (int) ($user->userid ?? 0);
        $tries  = 'pf-profile-pin-' . $userId;
        $held   = json_decode((string) (self::cache()?->load($tries) ?? ''), true);
        $wrong  = is_array($held) && (int) ($held['until'] ?? 0) > time() ? (int) ($held['n'] ?? 0) : 0;
        if ($wrong >= self::PIN_TRIES) {
            return 'Too many wrong PINs: try again later.';
        }
        if (!self::hasPin($user) || !password_verify($pin, (string) $user->{self::PIN})) {
            self::cache()?->save((string) json_encode(['n' => $wrong + 1, 'until' => is_array($held) && $wrong > 0 ? (int) $held['until'] : time() + self::PIN_WINDOW]), $tries);

            return 'That is not the PIN.';
        }
        self::cache()?->delete($tries);
        $key = self::sessionKey();
        if ($key !== null) {
            self::db()->queryBuilder()->table(self::LOCKS)->where('key_hash', $key)->delete();
        }

        return null;
    }

    /** The profile this session is held to, or null. */
    public static function lockedTo(int $userId): ?int
    {
        $key = self::sessionKey();
        if ($key === null) {
            return null;
        }
        $row = self::db()->queryBuilder()->table(self::LOCKS)->where('key_hash', $key)->where('userid', $userId)->first();

        return $row && (int) $row->numRows > 0 ? (int) $row->fields['profile_id'] : null;
    }

    /**
     * Another profile's conversations, for a parent to read — the newest first, each with when and
     * how many messages — with a few lines of a summary: how many, over how long, the last mood.
     * Only for a scope that is not itself a child's, and only of its own account.
     *
     * @return array{summary: string, conversations: list<array<string, mixed>>}|string
     */
    public static function conversationsOf(Scope $reader, int $profileId, int $days = 7): array|string
    {
        if (self::kindOf($reader) === Audience::CHILD || self::get($reader->userId, $profileId) === null) {
            return 'There is no such profile.';
        }
        $theirs = $reader->withPersona(null)->withProfile($profileId);
        $rows   = self::db()->queryBuilder()->table('assist.chat_conversations')->select(['conversation_id', 'title', 'space', 'mood', 'created_at', 'updated_at'])
            ->where('userid', $reader->userId)->whereLike('space', '%@' . $profileId)
            ->where('updated_at', '>=', \Pramnos\Llm\CallLog::instant(time() - max(1, $days) * 86400))->orderBy('updated_at', 'desc')->limit(50)->getAllOrFail();
        $list   = [];
        foreach ($rows as $row) {
            $count  = self::db()->queryBuilder()->table('assist.chat_messages')->where('conversation_id', (int) $row['conversation_id'])->count();
            $list[] = ['conversation_id' => (int) $row['conversation_id'], 'title' => (string) $row['title'], 'messages' => $count,
                'mood' => $row['mood'] ?? null, 'from' => (string) $row['created_at'], 'to' => (string) $row['updated_at']];
        }
        $name    = (string) (self::get($reader->userId, $profileId)['name'] ?? '');
        $t       = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
        $summary = $list === [] ? sprintf($t('%s has not talked to the assistant in the last %d days.'), $name, $days)
            : sprintf($t('%s had %d conversations in the last %d days, %d messages in all.'), $name, count($list), $days, array_sum(array_column($list, 'messages')))
                . (($list[0]['mood'] ?? null) !== null ? ' ' . sprintf($t('The last one\'s mood: %s.'), (string) $list[0]['mood']) : '');
        unset($theirs);

        return ['summary' => $summary, 'conversations' => $list];
    }

    /**
     * One of another profile's conversations, whole, for a parent to read.
     *
     * @return array<string, mixed>|null
     */
    public static function conversationOf(Scope $reader, int $profileId, int $conversationId): ?array
    {
        if (self::kindOf($reader) === Audience::CHILD || self::get($reader->userId, $profileId) === null) {
            return null;
        }
        $row = self::db()->queryBuilder()->table('assist.chat_conversations')->select(['space'])->where('conversation_id', $conversationId)
            ->where('userid', $reader->userId)->whereLike('space', '%@' . $profileId)->first();
        if (!$row || (int) $row->numRows === 0) {
            return null;
        }

        return (new Conversations(new BaseAssistant()))->as(new Scope($reader->userId, $reader->organizationId, (string) $row->fields['space']))->find($conversationId);
    }

    /**
     * After a profile's answer, the application's hook: `chat.profiles.after` —
     * `fn (Scope $scope, array $profile, string $question, string $answer, int $conversationId): void`.
     * What counts as worth telling, and whom it tells, are the application's.
     */
    public static function after(Scope $scope, string $question, string $answer, int $conversationId): void
    {
        if ($scope->profile === null) {
            return;
        }
        $hook = ((array) (((array) \Pramnos\Llm\Llm::config('chat', []))['profiles'] ?? []))['after'] ?? null;
        $one  = is_callable($hook) ? self::get($scope->userId, $scope->profile) : null;
        if ($one === null) {
            return;
        }
        try {
            $hook($scope, $one, $question, $answer, $conversationId);
        } catch (\Throwable $why) {
            \Pramnos\Logs\Logger::log('llm profiles after-hook: ' . $why->getMessage(), 'llm');
        }
    }

    /**
     * The tool for a profile that is not a child's: how another of the account's profiles has
     * been — the summary of their conversations, and their plan's — on the person's asking.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tools(Scope $scope): array
    {
        if (!self::on() || self::kindOf($scope) === Audience::CHILD || $scope->space !== Scope::CHAT) {
            return [];
        }
        $others = array_values(array_filter(self::of($scope->userId), static fn (array $p): bool => (int) $p['profile_id'] !== (int) $scope->profile));
        if ($others === []) {
            return [];
        }

        return ['profile_activity' => [
            'description' => 'How one of the family\'s profiles has been lately — their conversations with you, in short, and their plan — '
                . 'when the person asks after them. Profiles: ' . implode(', ', array_column($others, 'name')) . '.',
            'parameters'  => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'enum' => array_values(array_column($others, 'name'))],
                'days' => ['type' => 'integer', 'description' => 'How far back, 1 to 30; 7 by default.'],
            ], 'required' => ['name']],
            'run'         => static function (array $a) use ($scope, $others): string {
                $found = array_values(array_filter($others, static fn (array $p): bool => mb_strtolower($p['name']) === mb_strtolower((string) ($a['name'] ?? ''))))[0] ?? null;
                if ($found === null) {
                    return 'Error: there is no such profile.';
                }
                $talk = self::conversationsOf($scope, (int) $found['profile_id'], max(1, min(30, (int) ($a['days'] ?? 7))));
                $plan = Plan::on($scope) ? (new Plan($scope->withProfile((int) $found['profile_id'])))->context() : '';

                return is_string($talk) ? 'Error: ' . $talk : $talk['summary'] . "\n" . implode("\n", array_map(
                    static fn (array $c): string => '- ' . substr($c['to'], 0, 16) . ' «' . $c['title'] . '», ' . $c['messages'] . ' messages', array_slice($talk['conversations'], 0, 10)
                )) . ($plan !== '' ? "\n\n" . $plan : '');
            },
            'tags'        => ['personal' => true],
        ]];
    }

    /**
     * A profile's fields, checked; on making one, its name is needed.
     *
     * @param array<string, mixed> $given
     * @return array<string, mixed>|string
     */
    private static function fields(array $given, bool $new): array|string
    {
        $row = [];
        if ($new || array_key_exists('name', $given)) {
            $name = mb_substr(trim(strip_tags((string) ($given['name'] ?? ''))), 0, 60);
            if ($name === '') {
                return 'A profile needs a name.';
            }
            $row['name'] = $name;
        }
        if ($new || array_key_exists('kind', $given)) {
            $kind = strtolower(trim((string) ($given['kind'] ?? Audience::CHILD)));
            if (preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $kind) !== 1) {
                return 'A profile\'s kind is a word: parent, child, or an audience the application declares.';
            }
            $row['kind'] = $kind;
        }
        if (array_key_exists('birth_year', $given)) {
            $year = $given['birth_year'] === null || $given['birth_year'] === '' ? null : (int) $given['birth_year'];
            if ($year !== null && ($year < 1900 || $year > (int) date('Y'))) {
                return 'A year of birth is between 1900 and this year.';
            }
            $row['birth_year'] = $year;
        }
        // Kept as a profile of its own, no more the application's: its source let go of.
        if (!$new && array_key_exists('source', $given) && $given['source'] === null) {
            $row['source']      = null;
            $row['detached_at'] = null;
        }
        if (array_key_exists('image', $given)) {
            $row['image'] = $given['image'] === null || $given['image'] === '' ? null : mb_substr((string) $given['image'], 0, 255);
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shape(array $row): array
    {
        $year = $row['birth_year'] === null ? null : (int) $row['birth_year'];

        return ['profile_id' => (int) $row['profile_id'], 'name' => (string) $row['name'], 'kind' => (string) $row['kind'],
            'birth_year' => $year, 'age' => $year === null ? null : (int) date('Y') - $year, 'image' => $row['image'] === null ? null : (string) $row['image'],
            // Given by the application (sync()): its key there, and whether it gives it no more.
            'source' => ($row['source'] ?? null) === null ? null : (string) $row['source'], 'detached' => ($row['detached_at'] ?? null) !== null];
    }

    /** What a profile said and kept, wherever it is kept under its space. */
    private static function eraseData(int $userId, int $profileId): void
    {
        $db       = self::db();
        $like     = '%@' . $profileId;
        $schema   = $db->schema();
        $ids      = array_map('intval', array_column($db->queryBuilder()->table('assist.chat_conversations')->select(['conversation_id'])
            ->where('userid', $userId)->whereLike('space', $like)->getAllOrFail(), 'conversation_id'));
        if ($ids !== []) {
            $db->queryBuilder()->table('assist.chat_messages')->whereIn('conversation_id', $ids)->delete();
        }
        foreach (['assist.chat_conversations', 'assist.chat_memory', 'assist.chat_asks', 'assist.chat_artifacts', 'assist.chat_calendar', 'assist.chat_dossier', 'assist.chat_shares', 'pramnos.ai_routines'] as $table) {
            if ($schema->hasTable($table)) {
                $db->queryBuilder()->table($table)->where('userid', $userId)->whereLike('space', $like)->delete();
            }
        }
    }

    /** A hash of this request's session or access token, or null where it has neither. */
    private static function sessionKey(): ?string
    {
        $token = \Pramnos\Http\Request::accessToken();
        if ($token !== null && $token !== '') {
            return hash('sha256', 'token:' . $token);
        }
        $id = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';

        return $id !== '' ? hash('sha256', 'session:' . $id) : null;
    }

    // ponytail: wrong PINs counted in the cache; without one, no limit — a store of its own if a host has no cache.
    private static function cache(): ?\Pramnos\Cache\Cache
    {
        try {
            return \Pramnos\Cache\Cache::getInstance('llm');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return self::db()->queryBuilder()->table(self::TABLE);
    }

    private static function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
