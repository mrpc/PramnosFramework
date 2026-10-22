<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Beacon;

/**
 * What the beacon keeps, in Redis alone: who may come (the active team, from the primary every
 * minute), their push subscriptions, what is written while the primary is down — the emergency
 * channel and direct messages, text and reactions — and whether the primary is down, since when.
 * Nothing of the application's customers is here.
 *
 * Every message goes to its conversation's stream, for reading, and to one log, for the primary
 * to import once it is back; the log is trimmed as the primary acknowledges what it took.
 */
final class Store
{
    /** The emergency channel's conversation. */
    public const EMERGENCY = 'emergency';

    /** Who writes the beacon's own messages: "the primary is down", "back". */
    public const SYSTEM = 0;

    public function __construct(private readonly \Redis $redis, private readonly string $prefix = 'beacon:')
    {
    }

    /** The beacon's store on the configured Redis. */
    public static function connect(): self
    {
        $config = (array) Config::value('redis', []);

        return new self(\Pramnos\Redis\ConnectionManager::forConfig($config)->connection());
    }

    /**
     * What the primary sends every minute: the active team, their push subscriptions, the keys
     * revoked. The team is replaced whole, so somebody deactivated is gone.
     *
     * @param list<array{id: int, name: string}> $people
     * @param list<string> $revoked
     * @param list<array{user: int, endpoint: string, p256dh: string, auth: string}> $push
     */
    public function sync(array $people, array $revoked, array $push, int $now): void
    {
        $this->redis->multi();
        $this->redis->del($this->prefix . 'people', $this->prefix . 'push');
        foreach ($people as $person) {
            $this->redis->hSet($this->prefix . 'people', (string) (int) $person['id'], (string) $person['name']);
        }
        foreach ($revoked as $jti) {
            $this->redis->sAdd($this->prefix . 'revoked', (string) $jti);
        }
        foreach ($push as $subscription) {
            $this->redis->rPush($this->prefix . 'push', (string) json_encode($subscription));
        }
        $this->redis->set($this->prefix . 'synced', (string) $now);
        $this->redis->exec();
    }

    /**
     * The person a key is, when they are on the team now and the key is not revoked.
     *
     * @param array{u: int, jti: string} $claims
     */
    public function admits(array $claims): bool
    {
        return $this->redis->hExists($this->prefix . 'people', (string) $claims['u'])
            && !$this->redis->sIsMember($this->prefix . 'revoked', $claims['jti']);
    }

    /** @return array<int, string> The team, by id */
    public function people(): array
    {
        $people = [];
        foreach ((array) $this->redis->hGetAll($this->prefix . 'people') as $id => $name) {
            $people[(int) $id] = (string) $name;
        }
        ksort($people);

        return $people;
    }

    /** @return list<array<string, mixed>> The push subscriptions, as the primary sent them */
    public function subscriptions(): array
    {
        return array_values(array_filter(array_map(static fn ($s): mixed => json_decode((string) $s, true), (array) $this->redis->lRange($this->prefix . 'push', 0, -1)), 'is_array'));
    }

    /** When the primary last synced, or null. */
    public function syncedAt(): ?int
    {
        $at = $this->redis->get($this->prefix . 'synced');

        return $at === false || $at === null ? null : (int) $at;
    }

    /**
     * A direct conversation's name: its two people, lower id first.
     */
    public static function dm(int $a, int $b): string
    {
        return 'dm:' . min($a, $b) . '-' . max($a, $b);
    }

    /** Whether a person may read and write in a conversation: the emergency channel, or a dm of theirs. */
    public static function mayUse(string $conversation, int $person): bool
    {
        if ($conversation === self::EMERGENCY) {
            return true;
        }

        return preg_match('/^dm:(\d+)-(\d+)$/', $conversation, $m) === 1 && in_array($person, [(int) $m[1], (int) $m[2]], true) && $m[1] !== $m[2];
    }

    /**
     * Write a message: once per `client_id`, however often a device retries.
     *
     * @return string The message's id
     */
    public function write(string $conversation, int $author, string $body, string $clientId, int $now): string
    {
        $seen = $this->prefix . 'client:' . $clientId;
        if ($clientId !== '' && ($existing = $this->redis->get($seen)) !== false && $existing !== null) {
            return (string) $existing;
        }
        $fields = ['c' => $conversation, 'a' => (string) $author, 'b' => $body, 'at' => (string) $now];
        $id     = (string) $this->redis->xAdd($this->prefix . 'log', '*', $fields);
        $this->redis->xAdd($this->prefix . 'c:' . $conversation, $id, $fields);
        if ($clientId !== '') {
            $this->redis->set($seen, $id, ['ex' => 86400]);
        }

        return $id;
    }

    /**
     * A conversation's messages after one — the newest hundred when none — with their reactions.
     *
     * @return list<array{id: string, author: int, body: string, at: int, reactions: array<string, list<int>>}>
     */
    public function read(string $conversation, string $after = ''): array
    {
        $key  = $this->prefix . 'c:' . $conversation;
        $rows = $after === '' ? array_reverse((array) $this->redis->xRevRange($key, '+', '-', 100), true)
            : (array) $this->redis->xRange($key, '(' . $after, '+', 500);
        $out = [];
        foreach ($rows as $id => $fields) {
            $reactions = [];
            foreach ((array) $this->redis->hGetAll($this->prefix . 'r:' . $id) as $emoji => $people) {
                $reactions[(string) $emoji] = array_map('intval', (array) json_decode((string) $people, true));
            }
            $out[] = ['id' => (string) $id, 'author' => (int) $fields['a'], 'body' => (string) $fields['b'], 'at' => (int) $fields['at'], 'reactions' => $reactions];
        }

        return $out;
    }

    /** React to a message, or take it back. */
    public function react(string $id, int $person, string $emoji, bool $on): void
    {
        $key    = $this->prefix . 'r:' . $id;
        $people = array_map('intval', (array) json_decode((string) ($this->redis->hGet($key, $emoji) ?: '[]'), true));
        $people = $on ? array_values(array_unique([...$people, $person])) : array_values(array_diff($people, [$person]));
        $people === [] ? $this->redis->hDel($key, $emoji) : $this->redis->hSet($key, $emoji, (string) json_encode($people));
    }

    /**
     * What was written, after a cursor, for the primary to import.
     *
     * @return list<array{id: string, conversation: string, author: int, body: string, at: int, reactions: array<string, list<int>>}>
     */
    public function export(string $after = '', int $limit = 500): array
    {
        $out = [];
        foreach ((array) $this->redis->xRange($this->prefix . 'log', $after === '' ? '-' : '(' . $after, '+', $limit) as $id => $fields) {
            $reactions = [];
            foreach ((array) $this->redis->hGetAll($this->prefix . 'r:' . $id) as $emoji => $people) {
                $reactions[(string) $emoji] = array_map('intval', (array) json_decode((string) $people, true));
            }
            $out[] = ['id' => (string) $id, 'conversation' => (string) $fields['c'], 'author' => (int) $fields['a'], 'body' => (string) $fields['b'],
                'at' => (int) $fields['at'], 'reactions' => $reactions];
        }

        return $out;
    }

    /** How many messages wait for the primary. */
    public function pending(): int
    {
        return (int) $this->redis->xLen($this->prefix . 'log');
    }

    /** The primary took everything up to a message: it leaves the log. The conversations keep it for a month. */
    public function acknowledge(string $upTo): int
    {
        $taken = 0;
        foreach (array_keys((array) $this->redis->xRange($this->prefix . 'log', '-', $upTo)) as $id) {
            $taken += (int) $this->redis->xDel($this->prefix . 'log', [(string) $id]);
        }

        return $taken;
    }

    /**
     * Whether the primary is down, and since when.
     *
     * @return array{state: string, since: ?int}
     */
    public function primary(): array
    {
        $state = (array) $this->redis->hGetAll($this->prefix . 'primary');

        return ['state' => (string) ($state['state'] ?? 'unknown'), 'since' => isset($state['since']) ? (int) $state['since'] : null];
    }

    /**
     * Mark the primary down or up — answering whether that is a change, so the alarm and the
     * all-clear are each given once.
     */
    public function mark(string $state, int $since): bool
    {
        $before = $this->primary();
        if ($before['state'] === $state) {
            return false;
        }
        $this->redis->hMSet($this->prefix . 'primary', ['state' => $state, 'since' => (string) $since]);

        return true;
    }
}
