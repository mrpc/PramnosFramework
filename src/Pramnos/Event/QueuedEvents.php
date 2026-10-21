<?php

declare(strict_types=1);

namespace Pramnos\Event;

use Pramnos\Queue\QueueManager;

/**
 * The queue side of {@see Event}: what `fire()` writes for a {@see QueuedListener}, and what the
 * worker does with it ({@see EventListenerTask}).
 *
 * Every task carries the event that brought it — `meta` in the payload:
 *
 * | key        | what                                                                  |
 * |------------|-----------------------------------------------------------------------|
 * | `eventId`  | this firing's id                                                      |
 * | `event`    | its name                                                              |
 * | `firedAt`  | Unix time                                                             |
 * | `userid`   | who: the signed-in account, the one {@see Event::fireAs()} names, or null — the system |
 * | `ip`       | the client's address, for an event fired by a web request             |
 * | `source`   | `web`, `cli`, or `queue` — fired while a worker ran a task            |
 * | `causedBy` | the event whose queued listener fired this one; null for a first event |
 * | `rootId`   | the first event of the chain                                          |
 * | `depth`    | how far down the chain; past {@see MAX_DEPTH} nothing more is queued   |
 *
 * **The task is written on the application's own connection, as part of whatever transaction is
 * open on it**: a rollback takes the task with the change, and without a transaction it is
 * written at once. A transaction on another connection — a second handle an application keeps
 * for older code — does not cover it.
 */
final class QueuedEvents
{
    /** How deep a chain of events may go before the next is refused: a rule that loops. */
    public const MAX_DEPTH = 10;

    /** The keys of work already done. */
    public const IDEMPOTENCY_TABLE = 'queue_idempotency';

    /** The log a refused event is written to. */
    public const LOG = 'events';

    /**
     * The queue tasks are written to; null for the application's.
     *
     * A seam for tests, and for an application whose queue manager is its own subclass.
     */
    public static ?QueueManager $queue = null;

    /**
     * Whether an argument that cannot travel as JSON throws; null for "in development or under
     * test". In production it is logged and that one task skipped, so an event fired in a
     * payment's path never loses the payment over a listener.
     */
    public static ?bool $strict = null;

    /** @var array<string, mixed>|null The event whose queued listener this process is running */
    private static ?array $current = null;

    /** Whether a worker is running a task in this process. */
    private static bool $working = false;

    /** @var array<string, bool> Which "not migrated yet" warnings were logged already */
    private static array $warned = [];

    /** @var bool|null Whether the idempotency table exists, asked once per process */
    private static ?bool $hasIdempotency = null;

    /**
     * The `meta` of one firing.
     *
     * @param array{userid: int|null}|null $actor Who {@see Event::fireAs()} names; null to ask
     *                                            the session, then the parent event
     * @return array<string, mixed>
     */
    public static function meta(string $event, ?array $actor = null): array
    {
        $parent = self::$current;
        $id     = bin2hex(random_bytes(16));
        $source = self::source();

        $userId = $actor !== null
            ? $actor['userid']
            : (\Pramnos\Changelog\ChangelogWriter::currentUserId() ?? ($parent['userid'] ?? null));

        return [
            'eventId'  => $id,
            'event'    => $event,
            'firedAt'  => time(),
            'userid'   => $userId,
            'ip'       => $source === 'web' ? (\Pramnos\Http\Request::clientIp() ?: null) : null,
            'source'   => $source,
            'causedBy' => $parent['eventId'] ?? null,
            'rootId'   => $parent['rootId'] ?? $id,
            'depth'    => $parent !== null ? (int) $parent['depth'] + 1 : 0,
        ];
    }

    /** Where this is running: `queue` while a worker runs a task, `cli`, or `web`. */
    public static function source(): string
    {
        if (self::$working || self::$current !== null) {
            return 'queue';
        }

        return PHP_SAPI === 'cli' ? 'cli' : 'web';
    }

    /** The worker says it is running a task — or no longer. */
    public static function working(bool $working): void
    {
        self::$working = $working;
    }

    /**
     * Write the task for one queued listener of one firing.
     *
     * @param QueuedListener|class-string<QueuedListener> $listener
     * @param list<mixed>                                  $args
     * @param array<string, mixed>                         $meta
     * @return int|null The task id; null when nothing was queued — the key was used, the chain
     *                  went too deep, or (in production) an argument could not travel
     */
    public static function enqueue(QueuedListener|string $listener, string $event, array $args, array $meta): ?int
    {
        $class    = is_string($listener) ? $listener : $listener::class;
        $instance = is_string($listener) ? new $listener() : $listener;

        if ((int) $meta['depth'] > self::MAX_DEPTH) {
            \Pramnos\Logs\Logger::log(
                'Event ' . $event . ' not queued for ' . $class . ': the chain from ' . $meta['rootId']
                . ' is ' . $meta['depth'] . ' events deep (most ' . self::MAX_DEPTH . ') — a loop?',
                self::LOG
            );

            return null;
        }

        $plain = self::plain($args, $class, $event);
        if ($plain === null) {
            return null;
        }

        $queue = self::queue();
        $key   = $instance->idempotencyKey(...$args);
        if ($key !== null && $key !== '' && self::used($queue, $key)) {
            return null;
        }

        $fold = $instance->debounceKey(...$args);
        if ($fold !== null && $fold !== '' && !$queue->hasEventColumns()) {
            self::warnOnce('columns', 'Queued listeners are not folded by key: queueitems has no dedupekey — run migrate (add_event_columns_to_queueitems).');
        }

        $payload = ['listener' => $class, 'event' => $event, 'args' => $plain, 'meta' => $meta];
        if ($key !== null && $key !== '') {
            $payload['idempotency'] = ['key' => $key, 'ttl' => $instance->idempotencyTtl()];
        }

        $folded = $fold !== null && $fold !== '';

        return $queue->addKeyed(
            EventListenerTask::TYPE,
            $payload,
            $folded ? $fold : null,
            $folded ? $instance->debounceWindow() : 0,
            $folded ? $instance->debounceCeiling() : 0,
            $instance->priority(),
            $instance->maxAttempts()
        );
    }

    /**
     * Run one task's listener, in its event's context: events it fires are caused by this one.
     *
     * @param array<string, mixed> $job The task's payload
     * @return string What the queue records as the task's message
     */
    public static function run(array $job, int $taskId): string
    {
        $class = (string) ($job['listener'] ?? '');
        if (!is_a($class, QueuedListener::class, true)) {
            throw new \RuntimeException('Not a queued listener: ' . $class);
        }

        $key = (string) ($job['idempotency']['key'] ?? '');
        if ($key !== '' && !self::claim($key, (int) ($job['idempotency']['ttl'] ?? 0), $taskId)) {
            return 'Skipped: already done (' . $key . ')';
        }

        $previous      = self::$current;
        self::$current = (array) ($job['meta'] ?? []) + ['eventId' => null, 'rootId' => null, 'depth' => 0, 'userid' => null];
        try {
            (new $class())->handle(...array_values((array) ($job['args'] ?? [])));
        } catch (\Throwable $exception) {
            // Not done: the retry may take the key again.
            if ($key !== '') {
                self::release($key, $taskId);
            }
            throw $exception;
        } finally {
            self::$current = $previous;
        }

        return 'Ran ' . $class . ' for ' . ($job['event'] ?? '?') . (isset($job['meta']['eventId']) ? ' (' . $job['meta']['eventId'] . ')' : '');
    }

    /** Forget everything this process remembered — for tests. */
    public static function reset(): void
    {
        self::$queue          = null;
        self::$strict         = null;
        self::$current        = null;
        self::$working        = false;
        self::$warned         = [];
        self::$hasIdempotency = null;
    }

    /**
     * The arguments as they will reach the worker, or null where they cannot travel.
     *
     * An object is refused unless it is JsonSerializable: `json_encode()` would write its public
     * properties and the listener would receive an array where it expected a model.
     *
     * @param list<mixed> $args
     * @return list<mixed>|null
     */
    private static function plain(array $args, string $class, string $event): ?array
    {
        $problem = null;
        array_walk_recursive($args, static function ($value) use (&$problem): void {
            if ($problem === null && is_object($value) && !$value instanceof \JsonSerializable) {
                $problem = 'an object of ' . $value::class . ' — pass its id';
            }
        });

        if ($problem === null) {
            try {
                return (array) json_decode(json_encode($args, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $problem = $exception->getMessage();
            }
        }

        $message = 'Event ' . $event . ' cannot be queued for ' . $class . ': its arguments do not travel as JSON (' . $problem . ').';
        if (self::$strict ?? ((defined('DEVELOPMENT') && DEVELOPMENT) || defined('PRAMNOS_TESTING'))) {
            throw new \InvalidArgumentException($message);
        }
        \Pramnos\Logs\Logger::log($message, self::LOG);

        return null;
    }

    /** The queue tasks are written to. */
    private static function queue(): QueueManager
    {
        return self::$queue ?? new QueueManager(
            QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')
        );
    }

    /** Whether work with this key was done, and its key has not expired. */
    private static function used(QueueManager $queue, string $key): bool
    {
        if (!self::hasIdempotency()) {
            return false;
        }

        return self::db()->queryBuilder()->table(self::IDEMPOTENCY_TABLE)
            ->where('idempotencykey', $key)
            ->where('expiresat', '>', date('Y-m-d H:i:s'))
            ->count() > 0;
    }

    /**
     * Take the key for this task. The insert is the whole of the exclusion: a key already there
     * — done, or being done by another worker — is refused by the primary key.
     */
    private static function claim(string $key, int $ttl, int $taskId): bool
    {
        if (!self::hasIdempotency()) {
            return true;
        }

        $now = time();
        // An expired key may be taken again.
        self::db()->queryBuilder()->table(self::IDEMPOTENCY_TABLE)
            ->where('idempotencykey', $key)
            ->where('expiresat', '<=', date('Y-m-d H:i:s', $now))
            ->delete();

        try {
            self::db()->queryBuilder()->table(self::IDEMPOTENCY_TABLE)->insert([
                'idempotencykey' => $key,
                'taskid'         => $taskId,
                'createdat'      => date('Y-m-d H:i:s', $now),
                'expiresat'      => date('Y-m-d H:i:s', $now + max(1, $ttl)),
            ]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** Give a key back after a failed run. */
    private static function release(string $key, int $taskId): void
    {
        try {
            self::db()->queryBuilder()->table(self::IDEMPOTENCY_TABLE)
                ->where('idempotencykey', $key)
                ->where('taskid', $taskId)
                ->delete();
        } catch (\Throwable) {
            // The key stays until it expires; the failure itself is what the queue reports.
        }
    }

    /** Whether the idempotency table exists; warned about once when it does not. */
    private static function hasIdempotency(): bool
    {
        if (self::$hasIdempotency === null) {
            try {
                self::$hasIdempotency = self::db()->schema()->hasTable(self::IDEMPOTENCY_TABLE);
            } catch (\Throwable) {
                self::$hasIdempotency = false;
            }
        }
        if (!self::$hasIdempotency) {
            self::warnOnce('idempotency', 'Queued listeners run without idempotency keys: there is no queue_idempotency table — run migrate (create_queue_idempotency_table).');
        }

        return self::$hasIdempotency;
    }

    /** The connection the queue writes on. */
    private static function db(): \Pramnos\Database\Database
    {
        return self::queue()->database();
    }

    /** Log a "not migrated yet" warning once per process. */
    private static function warnOnce(string $what, string $message): void
    {
        if (!isset(self::$warned[$what])) {
            self::$warned[$what] = true;
            \Pramnos\Logs\Logger::log($message, self::LOG);
        }
    }
}
