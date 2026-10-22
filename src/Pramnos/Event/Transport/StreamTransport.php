<?php

declare(strict_types=1);

namespace Pramnos\Event\Transport;

use Pramnos\Event\Event;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;

/**
 * The stream: an event is written **once** to `event_log`, with the consumer groups it is for —
 * each queued listener set to `stream` is a group — and every group reads the log from its own
 * position ({@see StreamConsumer}, `bin/pramnos events:consume`).
 *
 * The entry is written on the application's connection, in its transaction, as a queue task is: a
 * rollback takes it with the change. A group that has never been written for starts just before
 * its first entry, so nothing fired for it before its consumer first runs is missed.
 */
final class StreamTransport implements EventTransport
{
    public const NAME = 'stream';

    /** The log. */
    public const LOG_TABLE = 'event_log';

    /** Each group's position. */
    public const CONSUMERS_TABLE = 'event_consumers';

    /** What failed, to be tried again or dead. */
    public const DELIVERIES_TABLE = 'event_deliveries';

    /** @var array<string, bool> Whether the tables are here, asked once per process and database */
    private static array $available = [];

    /** @var array{0: string, 1: int|null}|null The last firing written, and its entry */
    private static ?array $written = null;

    public function name(): string
    {
        return self::NAME;
    }

    public function available(): bool
    {
        try {
            $db  = self::db();
            $key = $db->type . '|' . $db->server . '|' . $db->database . '|' . $db->prefix;
            if (!isset(self::$available[$key])) {
                $schema                = $db->schema();
                self::$available[$key] = $schema->hasTable(self::LOG_TABLE) && $schema->hasTable(self::CONSUMERS_TABLE) && $schema->hasTable(self::DELIVERIES_TABLE);
            }

            return self::$available[$key];
        } catch (\Throwable) {
            return false;
        }
    }

    public function deliver(Envelope $envelope, QueuedListener|string $listener): int|string|null
    {
        // One firing is one entry, whichever of its listeners asks first.
        if (self::$written !== null && self::$written[0] === $envelope->id() && $envelope->id() !== '') {
            return self::$written[1];
        }
        $groups  = self::groupsOf($envelope->event);
        $payload = QueuedEvents::payload($groups[0] ?? (is_string($listener) ? $listener : $listener::class), $envelope->event, $envelope->args, $envelope->meta);
        $entry   = null;
        if ($payload !== null) {
            $db = self::db();
            $db->queryBuilder()->table('#PREFIX#' . self::LOG_TABLE)->insert([
                'event'     => mb_substr($envelope->event, 0, 150),
                'eventid'   => mb_substr($envelope->id(), 0, 64),
                'payload'   => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'groups'    => (string) json_encode($groups, JSON_UNESCAPED_SLASHES),
                'createdat' => time(),
            ]);
            $entry = (int) $db->getInsertId();
            foreach ($groups as $group) {
                $db->queryBuilder()->table('#PREFIX#' . self::CONSUMERS_TABLE)
                    ->insertOrIgnore(['groupname' => $group, 'position' => $entry - 1, 'updatedat' => time()]);
            }
        }
        self::$written = [$envelope->id(), $entry];

        return $entry;
    }

    /**
     * The groups an event is for: its queued listeners whose events go by the stream.
     *
     * @return list<string>
     */
    public static function groupsOf(string $event): array
    {
        $groups = [];
        foreach (Event::getListeners($event) as $listener) {
            if (($listener instanceof QueuedListener || (is_string($listener) && is_a($listener, QueuedListener::class, true)))
                && Transports::for($listener)->name() === self::NAME) {
                $groups[] = is_string($listener) ? $listener : $listener::class;
            }
        }

        return array_values(array_unique($groups));
    }

    /** Forget what this process knows — for tests, and after a migration in the same process. */
    public static function reset(): void
    {
        self::$available = [];
        self::$written   = null;
    }

    /** The application's connection. */
    public static function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
