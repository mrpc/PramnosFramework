<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * The priority of the task a worker is running right now, in this process — read by
 * {@see QueueManager::addTask()}/`addKeyed()` so a task that spawns a child task hands it down a
 * priority of its own without being told to, where the application turns inheritance on
 * (`'queue' => ['priority_inheritance' => true]`).
 *
 * Capped, not copied: a child is given `min(parent + 1, 10)` — one step below its parent, never
 * past the ordinary default (10, the lowest). A flat inheritance would let an innocuous-looking
 * child of a priority-1 task also run at priority 1, racing ahead of everything else in the
 * queue; the cap means a child runs ahead of ordinary-priority work without ever outranking its
 * own parent, or anything as urgent. {@see Worker::processNextTask()} sets this around a task's
 * own `execute()` and clears it after, success or failure alike.
 */
final class AmbientPriority
{
    private static ?int $current = null;

    /** The worker says which task it is running now — or none. */
    public static function set(?int $priority): void
    {
        self::$current = $priority;
    }

    /** The priority of the task running in this process right now; null outside one. */
    public static function current(): ?int
    {
        return self::$current;
    }

    /** Forget it — for tests. */
    public static function reset(): void
    {
        self::$current = null;
    }
}
