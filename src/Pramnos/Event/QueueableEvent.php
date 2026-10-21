<?php

declare(strict_types=1);

namespace Pramnos\Event;

/**
 * An event object that can reach a {@see QueuedListener}: it goes into the task as plain data and
 * the worker builds it again.
 *
 * ```php
 * final class JobPostCreated implements QueueableEvent
 * {
 *     public const EVENT = 'jobpost.created';
 *
 *     public function __construct(public readonly JobPost $post) {}
 *
 *     public function jsonSerialize(): array
 *     {
 *         return ['id' => (int) $this->post->id];
 *     }
 *
 *     public static function fromQueue(array $data): static
 *     {
 *         return new self(new JobPost((int) $data['id']));   // the row as it is now
 *     }
 * }
 * ```
 *
 * {@see jsonSerialize()} writes ids and plain values, never the objects themselves; {@see fromQueue()}
 * loads what it needs again, so the listener in the worker reads the current state, not the one
 * the request had.
 */
interface QueueableEvent extends \JsonSerializable
{
    /**
     * What the task carries: ids and plain values.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array;

    /**
     * The event again, in the worker, from what {@see jsonSerialize()} wrote.
     *
     * @param array<string, mixed> $data
     */
    public static function fromQueue(array $data): static;
}
