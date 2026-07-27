<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * Persistence seam for the polling {@see \Pramnos\Broadcasting\Drivers\DatabaseDriver}.
 *
 * The database backplane works by appending every broadcast to a durable store
 * and having consumers poll for rows newer than the last id they saw. Isolating
 * that behind this interface keeps the driver's poll loop unit-testable with an
 * in-memory store and lets the storage backend vary (a SQL table today, anything
 * append+range-scan tomorrow).
 */
interface BroadcastEventStore
{
    /**
     * Append one event to the store.
     *
     * @param array<string,mixed> $payload
     */
    public function append(string $channel, string $event, array $payload): void;

    /**
     * The id of the most recently stored event (0 when the store is empty).
     * A fresh subscription starts from here so it only receives NEW events.
     */
    public function latestId(): int;

    /**
     * Fetch stored events with an id greater than $lastId on any of $channels,
     * ordered by ascending id.
     *
     * @param string[] $channels
     * @return list<array{id:int,channel:string,event:string,payload:array<string,mixed>}>
     */
    public function fetchSince(int $lastId, array $channels): array;
}
