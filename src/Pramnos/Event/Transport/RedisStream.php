<?php

declare(strict_types=1);

namespace Pramnos\Event\Transport;

use Pramnos\Event\QueuedListener;

/**
 * The stream on Redis Streams, for `'events' => ['stream' => 'redis']`: the database keeps writing
 * each event to `event_log` in the caller's transaction — the outbox — and a relay appends what
 * is committed to one Redis stream, in order; each consumer group reads it with `XREADGROUP`.
 *
 * ```php
 * 'events' => [
 *     'transport'  => 'stream',
 *     'stream'     => 'redis',
 *     'redis'      => ['host' => 'events-redis', 'port' => 6379, 'database' => 0],  // its own instance
 *     'max_length' => 100000,                                                        // XADD MAXLEN ~
 * ],
 * ```
 *
 * - **Nothing is lost between the commit and Redis.** The relay keeps its own position in
 *   `event_consumers` (`__relay__`) and appends whatever is after it — at once when the event was
 *   fired outside a transaction, and on every pass of `events:consume` — so a process that dies in
 *   between leaves the entry for the next pass.
 * - **A Redis that evicts is refused.** A stream has no TTL, but `allkeys-*` evicts keys whatever
 *   they hold: on such an instance (a page cache's, typically) the stream is passed over for the
 *   queue, and the log says why. Give the events an instance of their own with `noeviction`.
 * - **Retries** are the group's pending entries: one that failed is claimed again once it has been
 *   idle for the wait its deliveries have earned (30 s, 1, 2, 4 … minutes); past the listener's
 *   attempts it is acknowledged and written to `event_deliveries` as dead, where the Queue screen
 *   shows it and *Try the dead again* puts it back.
 */
final class RedisStream
{
    /** The relay's own row in `event_consumers`. */
    public const RELAY = '__relay__';

    /** The longest a stream keeps, by default. */
    public const MAX_LENGTH = 100000;

    /** @var (callable(): object)|null A connection for tests */
    public static $connect = null;

    /** @var array<string, string> Why a Redis was refused, by endpoint, asked once per process */
    private static array $refused = [];

    /** The stream's key. */
    public static function key(): string
    {
        $settings = self::settings();

        return (string) ($settings['redis']['prefix'] ?? '') . 'pramnos:events';
    }

    /** Whether the installation's stream is on Redis. */
    public static function enabled(): bool
    {
        return (self::settings()['stream'] ?? 'database') === 'redis';
    }

    /**
     * Why this Redis cannot carry the stream — its eviction policy — or null where it can.
     */
    public static function refusal(): ?string
    {
        $redis    = self::redis();
        $endpoint = spl_object_hash($redis);
        if (!isset(self::$refused[$endpoint])) {
            $policy = '';
            try {
                $policy = (string) (((array) $redis->config('GET', 'maxmemory-policy'))['maxmemory-policy'] ?? '');
            } catch (\Throwable) {
                // CONFIG is often disabled on a managed Redis: INFO says the same.
            }
            if ($policy === '') {
                $policy = (string) (((array) $redis->info('memory'))['maxmemory_policy'] ?? '');
            }
            self::$refused[$endpoint] = str_starts_with($policy, 'allkeys')
                ? 'Redis evicts any key under memory pressure (maxmemory-policy ' . $policy . '): events would be lost. Point events.redis at an instance with noeviction.'
                : '';
        }

        return self::$refused[$endpoint] === '' ? null : self::$refused[$endpoint];
    }

    /**
     * Append the committed entries after the relay's position to the stream, in order.
     *
     * @return int How many were appended
     */
    public static function relay(int $limit = 500): int
    {
        $db = StreamTransport::db();
        if ($db->inTransaction()) {
            return 0;   // what this transaction wrote is not committed yet
        }
        $consumers = '#PREFIX#' . StreamTransport::CONSUMERS_TABLE;
        $row       = $db->queryBuilder()->table($consumers)->where('groupname', self::RELAY)->limit(1)->getAllOrFail()[0] ?? null;
        if ($row === null) {
            // From what the groups had not read yet: a switch from the database loses nothing.
            $floor = $db->queryBuilder()->table($consumers)->select([new \Pramnos\Database\Expression('MIN(position) AS p')])->getAllOrFail()[0]['p'] ?? null;
            $db->queryBuilder()->table($consumers)->insertOrIgnore(['groupname' => self::RELAY, 'position' => (int) ($floor ?? 0), 'updatedat' => time()]);
            $row = ['position' => (int) ($floor ?? 0)];
        }
        $entries = $db->queryBuilder()->table('#PREFIX#' . StreamTransport::LOG_TABLE)->where('entryid', '>', (int) $row['position'])
            ->orderBy('entryid')->limit($limit)->getAllOrFail();
        $redis = self::redis();
        $max   = (int) (self::settings()['max_length'] ?? self::MAX_LENGTH);
        foreach ($entries as $entry) {
            $redis->xAdd(self::key(), '*', ['entry' => (string) $entry['entryid'], 'groups' => (string) $entry['groups']], $max, true);
            $db->queryBuilder()->table($consumers)->where('groupname', self::RELAY)->update(['position' => (int) $entry['entryid'], 'updatedat' => time()]);
        }

        return count($entries);
    }

    /**
     * One pass for a group: claim what is due again, then read what is new.
     *
     * @param callable(array<string, mixed>): ?string $run Runs an `event_log` entry; why it failed, or null
     * @return array{held: bool, ran: int, failed: int, retried: int, dead: int}
     */
    public static function consume(string $group, string $consumer, int $limit, int $block, callable $run): array
    {
        $tally = ['held' => false, 'ran' => 0, 'failed' => 0, 'retried' => 0, 'dead' => 0];
        $redis = self::redis();
        $key   = self::key();
        try {
            $redis->xGroup('CREATE', $key, $group, '0', true);
        } catch (\Throwable) {
            // BUSYGROUP: it is there already.
        }
        $attempts = max(1, (new $group())->maxAttempts());

        // What failed and has waited long enough: claimed by this consumer, run again.
        $due = [];
        foreach ((array) $redis->xPending($key, $group, '-', '+', $limit) as $pending) {
            [$id, , $idle, $count] = array_values((array) $pending);
            if ((int) $idle >= StreamConsumer::backoff((int) $count) * 1000) {
                $due[(string) $id] = (int) $count + 1;   // claiming it is its next delivery
            }
        }
        if ($due !== []) {
            foreach ((array) $redis->xClaim($key, $group, $consumer, 0, array_keys($due)) as $id => $fields) {
                $tally['retried']++;
                self::handle($group, (string) $id, (array) $fields, $due[(string) $id], $attempts, $run, $tally);
            }
        }

        // What is new.
        $read = $redis->xReadGroup($group, $consumer, [$key => '>'], $limit, $block > 0 ? $block : null);
        foreach ((array) ($read[$key] ?? []) as $id => $fields) {
            self::handle($group, (string) $id, (array) $fields, 1, $attempts, $run, $tally);
        }

        return $tally;
    }

    /**
     * How the group is doing on Redis: what waits to be read, and what waits to be tried again.
     *
     * @return array{lag: int, pending: int}
     */
    public static function groupStatus(string $group): array
    {
        try {
            foreach ((array) self::redis()->xInfo('GROUPS', self::key()) as $info) {
                if (($info['name'] ?? null) === $group) {
                    return ['lag' => (int) ($info['lag'] ?? 0), 'pending' => (int) ($info['pending'] ?? 0)];
                }
            }
        } catch (\Throwable) {
            // No stream yet.
        }

        return ['lag' => 0, 'pending' => 0];
    }

    /** Forget the connection's verdicts and the injected connection — for tests. */
    public static function reset(): void
    {
        self::$refused = [];
        self::$connect = null;
    }

    /**
     * One message: run its entry if it is for this group, acknowledge it when done or dead.
     *
     * @param array<string, string>                   $fields
     * @param callable(array<string, mixed>): ?string $run
     * @param array<string, mixed>                    $tally
     */
    private static function handle(string $group, string $id, array $fields, int $delivery, int $attempts, callable $run, array &$tally): void
    {
        $redis = self::redis();
        if (!in_array($group, (array) json_decode((string) ($fields['groups'] ?? '[]'), true), true)) {
            $redis->xAck(self::key(), $group, [$id]);

            return;
        }
        $entry = StreamTransport::db()->queryBuilder()->table('#PREFIX#' . StreamTransport::LOG_TABLE)
            ->where('entryid', (int) ($fields['entry'] ?? 0))->limit(1)->getAllOrFail()[0] ?? null;
        $error = $entry === null ? 'The entry is no longer in the log.' : $run($entry);
        if ($error === null) {
            $redis->xAck(self::key(), $group, [$id]);
            $tally['ran']++;

            return;
        }
        if ($delivery < $attempts && $entry !== null) {
            $tally['failed']++;

            return;   // pending: claimed again when it has waited long enough
        }
        $tally['dead']++;
        $redis->xAck(self::key(), $group, [$id]);
        StreamTransport::db()->queryBuilder()->table('#PREFIX#' . StreamTransport::DELIVERIES_TABLE)->insertOrIgnore([
            'groupname' => $group, 'entryid' => (int) ($fields['entry'] ?? 0), 'attempts' => $delivery, 'status' => 'dead',
            'nextat' => 0, 'error' => mb_substr($error, 0, 2000), 'createdat' => time(),
        ]);
    }

    /** The events' Redis: `events.redis`, or the framework's. */
    public static function redis(): object
    {
        if (self::$connect !== null) {
            return (self::$connect)();
        }
        $config = self::settings()['redis'] ?? null;

        return is_array($config) ? \Pramnos\Redis\ConnectionManager::forConfig($config)->connection()
            : \Pramnos\Redis\ConnectionManager::getInstance()->connection();
    }

    /** @return array<string, mixed> The application's `events` settings */
    private static function settings(): array
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return is_object($app) && is_array($app->applicationInfo['events'] ?? null) ? $app->applicationInfo['events'] : [];
    }
}
