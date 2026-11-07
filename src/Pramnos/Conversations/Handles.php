<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * The handles that mention many people at once, as Slack's:
 *
 * | handle                | who is told                                                                       |
 * |-----------------------|-----------------------------------------------------------------------------------|
 * | `@here` (`@online`)   | the conversation's members connected now, but those whose notifications are paused |
 * | `@channel` (`@all`)   | all its members, connected or not                                                  |
 * | `@everyone`           | in the general conversation, everyone who may use the conversations; elsewhere `@channel` |
 * | `@<group>`            | the conversation's members in one of the application's groups                      |
 *
 * Never the writer, nor somebody inactive ({@see People::inactive()}) — named with `<@id>`, they are
 * still told. Who is connected is asked of the broadcasting server's presence channel
 * ({@see Realtime::PRESENCE}, through {@see \Pramnos\Broadcasting\Http\ApiClient}); where it cannot be
 * asked, `@here` tells the members as `@channel` does but for the paused.
 *
 * ```php
 * 'conversations' => [
 *     'everyone_channel' => 'general',                                   // its slug, or its id
 *     'groups'           => ['sales' => fn (): array => Staff::ids('sales'), 'admins' => [2, 7]],
 *     'quiet'            => fn (array $ids): array => Status::paused($ids), // of these, who is not told now
 * ],
 * ```
 */
final class Handles
{
    /** The handles for the members connected now, the first the one a composer offers. */
    public const HERE = ['here', 'online'];

    /** The handles for all the members, the first the one a composer offers. */
    public const CHANNEL = ['channel', 'all'];

    /** The handle for everyone, in the general conversation. */
    public const EVERYONE = 'everyone';

    /**
     * The handles a body uses: `here`, `channel`, `everyone`, and `group:<name>` for each of the
     * application's groups — each once.
     *
     * @return list<string>
     */
    public static function used(string $body): array
    {
        preg_match_all('/(?<![\w@<])@([\w.-]*\w)/u', $body, $m);
        $groups = self::groups();
        $used   = [];
        foreach (array_unique(array_map('mb_strtolower', $m[1])) as $word) {
            $handle = match (true) {
                in_array($word, self::HERE, true)    => 'here',
                in_array($word, self::CHANNEL, true) => 'channel',
                $word === self::EVERYONE             => self::EVERYONE,
                isset($groups[$word])                => 'group:' . $word,
                default                              => null,
            };
            if ($handle !== null && !in_array($handle, $used, true)) {
                $used[] = $handle;
            }
        }

        return $used;
    }

    /**
     * Who a body's handles tell in a conversation, each with the handle that told them first: `here`,
     * `all` (`@channel`, and `@everyone` outside the general conversation), `everyone`, `group`.
     *
     * @return array<int, string>
     */
    public static function audience(int $conversationId, ?int $author, string $body): array
    {
        $used = self::used($body);
        if ($used === []) {
            return [];
        }
        $members = Conversations::members($conversationId);
        $general = self::isGeneral($conversationId);
        $people  = [];
        foreach ($used as $handle) {
            // `all` for every member, as `@all` always was: what an event's listener already knows.
            $kind = ($handle === self::EVERYONE && !$general) || $handle === 'channel' ? 'all' : (str_starts_with($handle, 'group:') ? 'group' : $handle);
            $ids  = match (true) {
                $handle === 'here'                              => self::here($members),
                $handle === self::EVERYONE && $general          => self::everyone(),
                str_starts_with($handle, 'group:')              => array_values(array_intersect($members, self::group(substr($handle, 6)))),
                default                                         => $members,
            };
            foreach ($ids as $person) {
                if ($person !== $author) {
                    $people[$person] ??= $kind;
                }
            }
        }
        $asleep = People::inactive(array_keys($people));

        return array_diff_key($people, $asleep);
    }

    /** How many people a body's handles would tell — for a composer to ask before it sends to many. */
    public static function reach(int $conversationId, ?int $author, string $body): int
    {
        return count(self::audience($conversationId, $author, $body));
    }

    /**
     * The handles a composer offers in a conversation: `here`, `channel`, `everyone` in the general
     * one, and the application's groups.
     *
     * @return list<string>
     */
    public static function offered(int $conversationId): array
    {
        return array_merge([self::HERE[0], self::CHANNEL[0]], self::isGeneral($conversationId) ? [self::EVERYONE] : [], array_keys(self::groups()));
    }

    /** Whether a conversation is the general one ({@see generalId()}). */
    public static function isGeneral(int $conversationId): bool
    {
        return $conversationId > 0 && self::generalId() === $conversationId;
    }

    /** The general conversation, `conversations.everyone_channel` — its slug or its id — or null when none is set. */
    public static function generalId(): ?int
    {
        $set = self::config('everyone_channel');
        if ($set === null || $set === '') {
            return null;
        }

        if (is_numeric($set)) {
            return (int) $set;
        }
        $general = Conversations::bySlug((string) $set);

        return $general === null ? null : (int) $general['id'];
    }

    /**
     * The application's groups, by handle: `conversations.groups`, each a list of ids or what returns
     * one. A name the handles already have is not a group.
     *
     * @return array<string, callable|array<int|string>>
     */
    public static function groups(): array
    {
        $groups = [];
        foreach ((array) (self::config('groups') ?? []) as $name => $people) {
            $name = mb_strtolower(trim((string) $name));
            if (preg_match('/^[\w.-]*\w$/u', $name) === 1 && !in_array($name, [...self::HERE, ...self::CHANNEL, self::EVERYONE], true)) {
                $groups[$name] = $people;
            }
        }

        return $groups;
    }

    /** @return list<int> The people of one of the application's groups */
    private static function group(string $name): array
    {
        $people = self::groups()[$name] ?? [];

        return array_values(array_map('intval', (array) (is_callable($people) ? $people() : $people)));
    }

    /**
     * Of these members, those connected now and not paused; where who is connected cannot be asked,
     * all of them but the paused.
     *
     * @param list<int> $members
     * @return list<int>
     */
    private static function here(array $members): array
    {
        $connected = self::connected();
        $here      = $connected === null ? $members : array_values(array_intersect($members, $connected));

        return array_values(array_diff($here, self::paused($here)));
    }

    /**
     * Of these people, those whose notifications are paused now, as the application's `quiet` hook
     * says — none without one.
     *
     * @param list<int> $people
     * @return list<int>
     */
    public static function paused(array $people): array
    {
        $quiet = self::config('quiet');

        return is_callable($quiet) && $people !== [] ? array_values(array_intersect($people, array_map('intval', (array) $quiet($people)))) : [];
    }

    /** @return list<int>|null Who is connected to the conversations now, or null when it cannot be asked */
    private static function connected(): ?array
    {
        $config = \Pramnos\Application\Application::currentInstance()?->applicationInfo['broadcasting'] ?? null;
        $users  = is_array($config) ? \Pramnos\Broadcasting\Http\ApiClient::fromConfig($config)?->presenceUsers(Realtime::PRESENCE) : null;

        return $users === null ? null : array_map('intval', $users);
    }

    /**
     * Everyone who may use the conversations, active.
     *
     * @return list<int>
     */
    private static function everyone(): array
    {
        $query = Conversations::db()->queryBuilder()->table(\Pramnos\Audience\Attributes::FROM)
            ->select(['users.userid', 'users.usertype', 'users.active'])->where('users.active', 1)->where('users.userid', '>', 1);
        if (!\Pramnos\Auth\AdminAccess::usesPermissions()) {
            $query->where('users.usertype', '>=', Access::floor());
        }
        $people = [];
        // ponytail: asks Access of every active account in permissions mode; a permission index if that grows large.
        foreach ($query->getAllOrFail() as $row) {
            if (Access::canUse((object) $row)) {
                $people[] = (int) $row['userid'];
            }
        }

        return $people;
    }

    /** A `conversations` setting from app.php. */
    private static function config(string $name): mixed
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return is_object($app) ? ($app->applicationInfo['conversations'][$name] ?? null) : null;
    }
}
