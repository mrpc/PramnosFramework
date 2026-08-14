<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * A non-blocking Redis ingest for the WebSocket server.
 *
 * The server runs a single-threaded `stream_select()` loop, so it cannot use
 * phpredis's blocking calls: it needs a raw socket it can add to its own select
 * set, and a `drain()` it can call whenever that socket is readable. Two
 * implementations satisfy that, and which one is right follows from **which
 * driver publishes**:
 *
 * | Publisher                | Ingest                        | Redis command |
 * | ------------------------ | ----------------------------- | ------------- |
 * | {@see Drivers\RedisDriver}       | {@see RedisSubscriberSocket} | `SUBSCRIBE`   |
 * | {@see Drivers\RedisStreamDriver} | {@see RedisStreamSocket}     | `XREAD`       |
 *
 * Pairing them the other way round produces **silence, not an error**:
 * `SUBSCRIBE` on a key that only ever receives `XADD` is a perfectly healthy
 * subscription that is never delivered anything. That cost one application a
 * duplicate write on every event — publishing twice, once for each transport —
 * because there was no stream ingest to choose.
 *
 * This interface exists so the choice of ingest can follow the choice of driver
 * instead of being independent of it.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
interface RedisIngestInterface
{
    /**
     * Open the socket and issue whatever command starts the flow of events.
     *
     * @return void
     * @throws \RuntimeException When Redis cannot be reached.
     */
    public function connect(): void;

    /**
     * The socket, for the server's `stream_select()` set.
     *
     * @return resource|null Null before {@see connect()} and after {@see close()}.
     */
    public function getStream();

    /**
     * Read what is available — without blocking — and return complete messages.
     *
     * Each message carries the stream **entry id** as well as its channel and payload, so a
     * consumer can tell when the event was published. An implementation that has no notion of
     * one — a pub/sub bridge, for instance — may return an empty string, and a router written
     * against it keeps working because the id arrives as a defaulted argument.
     *
     * @return list<array{channel: string, message: string, id?: string}>
     */
    public function drain(): array;

    /**
     * Close the socket and forget any partial data.
     *
     * @return void
     */
    public function close(): void;
}
