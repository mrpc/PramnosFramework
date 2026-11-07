<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Beacon;

use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\People;

/**
 * The primary's side of the beacon: telling it the team every minute, taking back what was written
 * there while the primary was down, and a drill.
 *
 * Each request is signed with the shared `secret`, the drill with the watcher's; a beacon that does
 * not answer is a failure said, never an exception in a scheduled command.
 */
final class Primary
{
    /** The beacon's system messages are signed so. */
    public const FROM = 'Beacon';

    /** How far back a conversation's last message may be for the beacon to have it, in days: `beacon.conversation_days`. */
    public const CONVERSATION_DAYS = 60;

    /** @var (callable(int): list<array<string, mixed>>)|null A person's push subscriptions, where not the framework's table */
    private static $subscriptions = null;

    /**
     * Where a person's push subscriptions come from — a test's — or, with null, `Push\Subscriptions`.
     *
     * @param (callable(int): list<array<string, mixed>>)|null $subscriptions
     */
    public static function subscriptionsFrom(?callable $subscriptions): void
    {
        self::$subscriptions = $subscriptions;
    }

    /**
     * Tell the beacon who may come: the active team, their push subscriptions — and the team's
     * conversations that moved lately ({@see conversations()}), so they go on writing there.
     *
     * @return array{ok: bool, people: int, why?: string}
     */
    public static function sync(): array
    {
        $people = array_map(static fn (array $p): array => ['id' => $p['id'], 'name' => $p['name']], People::search('', 1000));
        $push   = [];
        foreach ($people as $person) {
            try {
                foreach (self::$subscriptions !== null ? (self::$subscriptions)($person['id']) : \Pramnos\Push\Subscriptions::forUser($person['id']) as $s) {
                    $push[] = ['user' => $person['id'], 'endpoint' => (string) ($s['endpoint'] ?? ''), 'p256dh' => (string) ($s['p256dh'] ?? ''),
                        'auth_secret' => (string) ($s['auth_secret'] ?? ''), 'content_encoding' => (string) ($s['content_encoding'] ?? 'aes128gcm')];
                }
            } catch (\Throwable) {
                // An installation without push: the team without it.
            }
        }
        $answer = self::send('POST', 'sync', ['people' => $people, 'revoked' => [], 'push' => $push,
            'conversations' => self::conversations(array_column($people, 'id'))]);

        return $answer === null ? ['ok' => false, 'people' => 0, 'why' => 'the beacon did not answer'] : ['ok' => true, 'people' => (int) ($answer['people'] ?? 0)];
    }

    /**
     * Take back what was written at the beacon: the emergency channel into its channel here, the
     * direct messages into the two people's own — each once, by the beacon's id — then tell the
     * beacon to let them go.
     *
     * @return array{ok: bool, imported: int, why?: string}
     */
    public static function import(): array
    {
        $imported = 0;
        $cursor   = '';
        do {
            $answer = self::send('GET', 'export', [], ['after' => $cursor]);
            if ($answer === null) {
                return ['ok' => false, 'imported' => $imported, 'why' => 'the beacon did not answer'];
            }
            $batch = (array) ($answer['messages'] ?? []);
            foreach ($batch as $message) {
                if (self::take((array) $message)) {
                    $imported++;
                }
                $cursor = (string) $message['id'];
            }
            if ($batch !== []) {
                self::send('POST', 'ack', ['cursor' => $cursor]);
            }
        } while (count($batch) >= 500);

        return ['ok' => true, 'imported' => $imported];
    }

    /**
     * The monthly drill: the watcher is asked to go through the whole path, Cloudflare included,
     * and a person's browser is sent to the beacon for a few minutes.
     *
     * @return array{watcher: ?int, told: bool}
     */
    public static function drill(int $person): array
    {
        $status = null;
        $url    = (string) Config::value('drill_url', '');
        if ($url !== '') {
            $body     = (string) json_encode(['person' => $person, 'at' => time()]);
            $response = \Pramnos\Http\Client::post($url)->headers(Signature::headers((string) Config::value('watcher_secret', ''), $body))
                ->body($body, 'application/json')->timeout(15)->send();
            $status = $response->status();
        }
        $told = false;
        $manager = \Pramnos\Broadcasting\BroadcastingManager::currentInstance();
        if ($manager !== null) {
            $app = \Pramnos\Application\Application::currentInstance();
            $manager->broadcast(\Pramnos\Broadcasting\RealtimeConfig::userChannel($person,
                is_object($app) && is_array($app->applicationInfo['broadcasting'] ?? null) ? $app->applicationInfo['broadcasting'] : []), 'conversation.drill', ['minutes' => 5]);
            $told = true;
        }

        return ['watcher' => $status, 'told' => $told];
    }

    /**
     * The conversations the beacon keeps for the team: channels, private and system ones and group
     * conversations whose last message is within `beacon.conversation_days` (60) — not a direct
     * one, which the beacon names by its two people, nor Ask's — each with its members on the
     * team, and a title: its name, or a group's people.
     *
     * @param list<int> $team
     * @return list<array{id: int, kind: string, title: string, members: list<int>}>
     */
    public static function conversations(array $team): array
    {
        $since = time() - 86400 * max(1, (int) Config::value('conversation_days', self::CONVERSATION_DAYS));
        $rows  = Conversations::db()->queryBuilder()->table('#PREFIX#conversations')->select(['conversationid', 'kind', 'name'])
            ->whereIn('kind', ['channel', 'private', 'system', 'group'])->whereNull('archivedat')->where('lastmessageat', '>=', $since)
            ->orderBy('conversationid')->limit(1000)->getAllOrFail();
        $out = [];
        foreach ($rows as $row) {
            $id      = (int) $row['conversationid'];
            $members = array_values(array_intersect(Conversations::members($id), $team));
            if ($members === []) {
                continue;
            }
            $title = (string) ($row['name'] ?? '');
            if ($title === '') {
                $title = implode(', ', array_values(People::names($members)));
            }
            $out[] = ['id' => $id, 'kind' => (string) $row['kind'], 'title' => $title, 'members' => $members];
        }

        return $out;
    }

    /** The emergency channel here, made the first time; its writer joins it. */
    private static function emergency(int $author): int
    {
        $channel      = Conversations::bySlug(Config::channel());
        $conversation = $channel['id'] ?? Conversations::create('system', Config::channel(), [], null);
        if ($author > 0) {
            Conversations::join($conversation, $author);
        }

        return (int) $conversation;
    }

    /**
     * One message from the beacon, into the conversations here; false when it was here already or
     * has nowhere to go. One written in a conversation of the primary's goes back there as its
     * writer — or, when they are no longer in it, to the emergency channel, saying where from.
     */
    private static function take(array $message): bool
    {
        $author = (int) ($message['author'] ?? 0);
        $where  = (string) ($message['conversation'] ?? '');
        $body   = (string) $message['body'];
        if ($where === Store::EMERGENCY) {
            $conversation = self::emergency($author);
        } elseif (preg_match('/^dm:(\d+)-(\d+)$/', $where, $m) === 1) {
            $conversation = Conversations::create('dm', null, [(int) $m[2]], (int) $m[1]);
        } elseif (preg_match('/^c:(\d+)$/', $where, $m) === 1 && $author > 0) {
            $conversation = (int) $m[1];
            $original     = Conversations::get($conversation);
            if ($original === null || Conversations::member($conversation, $author) === null) {
                $from         = $original === null ? '' : ($original['name'] ?? '');
                $body         = '(' . t('from %s', $from !== '' ? '#' . $from : t('a conversation')) . ') ' . $body;
                $conversation = self::emergency($author);
            }
        } else {
            return false;
        }
        $existing = Conversations::db()->queryBuilder()->table('#PREFIX#conversation_messages')->select(['messageid'])
            ->where('source', 'beacon')->where('externalid', (string) $message['id'])->limit(1)->getAllOrFail();
        if ($existing !== []) {
            return false;
        }
        $id = Messages::post($conversation, $author > 0 ? $author : null, $body, array_filter([
            'source' => 'beacon', 'externalid' => (string) $message['id'], 'at' => (int) ($message['at'] ?? time()),
            'card'   => $author > 0 ? null : ['from' => t(self::FROM)],
        ], static fn ($v): bool => $v !== null));
        foreach ((array) ($message['reactions'] ?? []) as $emoji => $people) {
            foreach ((array) $people as $person) {
                // Who reacted in the emergency channel was there: they join it, if they may.
                if ($where === Store::EMERGENCY && Conversations::member($conversation, (int) $person) === null && self::mayUse((int) $person)) {
                    Conversations::join($conversation, (int) $person);
                }
                if (Conversations::member($conversation, (int) $person) !== null) {
                    Messages::react($id, (int) $person, (string) $emoji);
                }
            }
        }

        return true;
    }

    /** Whether an account may use the conversations. */
    private static function mayUse(int $person): bool
    {
        $row = Conversations::db()->queryBuilder()->table(\Pramnos\Audience\Attributes::FROM)
            ->select(['users.userid', 'users.usertype', 'users.active'])->where('users.userid', $person)->limit(1)->getAllOrFail()[0] ?? null;

        return $row !== null && \Pramnos\Conversations\Access::canUse((object) $row);
    }

    /**
     * A signed request to the beacon: its answer, or null when it did not give one.
     *
     * @param array<string, mixed> $json
     * @param array<string, string> $query
     * @return array<string, mixed>|null
     */
    private static function send(string $method, string $path, array $json = [], array $query = []): ?array
    {
        $base = rtrim((string) Config::value('beacon_url', ''), '/');
        if ($base === '') {
            return null;
        }
        $body    = $method === 'GET' ? '' : (string) json_encode($json, JSON_UNESCAPED_UNICODE);
        $url     = $base . '/beacon/' . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = (new \Pramnos\Http\Client())->make($method, $url)->headers(Signature::headers((string) Config::value('secret', ''), $body))->timeout(20);
        if ($body !== '') {
            $request->body($body, 'application/json');
        }
        try {
            $response = $request->send();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Beacon: ' . $path . ' failed: ' . $e->getMessage(), 'beacon');

            return null;
        }
        if (!$response->successful()) {
            \Pramnos\Logs\Logger::log('Beacon: ' . $path . ' answered ' . $response->status(), 'beacon');

            return null;
        }

        return (array) $response->json();
    }
}
