<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

use Pramnos\Broadcasting\BroadcastEventStore;
use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * Database-backed polling backplane driver.
 *
 * For deployments without Redis (typical shared hosting), events are appended to
 * a durable {@see BroadcastEventStore} and consumers poll it for new rows. This
 * gives the same publish/subscribe contract as the Redis driver — an SSE stream
 * or the WebSocket server can consume from it identically — at the cost of poll
 * latency instead of instant push.
 *
 * The poll cadence is {@see SubscriptionOptions::$readTimeout} seconds; every
 * poll that returns nothing fires the idle tick (keep-alive ping / liveness /
 * runtime check), mirroring the Redis driver's read-timeout behaviour.
 *
 * Because the store is durable, this driver can **replay**: pass
 * {@see SubscriptionOptions::$sinceId} and the loop resumes after that row
 * instead of from "now". That is what closes the gap an SSE reconnect opens —
 * and the events were always there; the loop simply used to start at the end of
 * the table.
 */
class DatabaseDriver implements SubscribableDriverInterface
{
    /** @var callable(int):void Sleeps for the given number of milliseconds. */
    private $sleeper;

    /**
     * @param BroadcastEventStore $store   Durable event store to append to / poll.
     * @param callable|null       $sleeper Injectable sleep (milliseconds) for tests;
     *                                     defaults to usleep().
     */
    public function __construct(
        private readonly BroadcastEventStore $store,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $ms): int => usleep($ms * 1000) ?: 0;
    }

    public function name(): string
    {
        return 'database';
    }

    public function broadcast(string $channel, string $event, array $payload): void
    {
        $this->store->append($channel, $event, $payload);
    }

    public function subscribe(array $channels, callable $onEvent, ?SubscriptionOptions $options = null): void
    {
        if ($channels === []) {
            throw new \InvalidArgumentException('subscribe() requires at least one channel.');
        }
        $options ??= new SubscriptionOptions();

        $channels = array_values($channels);
        $deadline = $options->maxRuntime !== null ? time() + $options->maxRuntime : null;

        // Where to resume from. Without a cursor this starts at "now", which is
        // the reconnect gap in one line of code: the events are durable and sat
        // in the table the whole time, and the loop skipped over them because
        // nobody said where to begin. A client that hands back the last id it saw
        // gets everything published while it was away.
        $lastId = $options->sinceId !== null
            ? max(0, (int) $options->sinceId)
            : $this->store->latestId();

        while (true) {
            if ($deadline !== null && time() >= $deadline) {
                break;
            }

            $rows = $this->store->fetchSince($lastId, $channels);

            if ($rows === []) {
                if (!$options->fireIdle()) {
                    break;
                }
            } else {
                foreach ($rows as $row) {
                    $lastId = max($lastId, (int) $row['id']);
                    // The id travels with the event so the consumer can put it on
                    // the wire — an SSE `id:` frame — and be resumed from it.
                    $delivered = $onEvent(
                        (string) $row['channel'],
                        (string) $row['event'],
                        (array) $row['payload'],
                        (string) $row['id'],
                    );
                    if ($delivered === false) {
                        return;
                    }
                    if ($deadline !== null && time() >= $deadline) {
                        return;
                    }
                }
            }

            if ($deadline !== null && time() >= $deadline) {
                break;
            }
            ($this->sleeper)($options->readTimeout * 1000);
        }
    }
}
