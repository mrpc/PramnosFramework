<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * Contract for all queue task handlers.
 *
 * Implement this interface (or extend AbstractTask) to register a handler
 * for a specific task type. The Worker dispatches queue items to the
 * appropriate handler based on the task type string.
 *
 */
interface TaskInterface
{
    /**
     * Execute the task.
     *
     * Return values:
     *   true                         — simple success
     *   false                        — failure (Worker marks as failed)
     *   ['message' => '...']         — success with human-readable message
     *   ['warning' => '...']         — completed with a non-fatal warning
     *
     * @param  QueueItem $queueItem
     * @return bool|array<string,string>
     * @throws \Throwable  Unrecoverable errors — Worker catches and marks as failed
     */
    public function execute(QueueItem $queueItem): mixed;

    /**
     * Return a human-readable description of what this item does.
     *
     * Used in logs and admin dashboards.
     *
     * @param  QueueItem $queueItem
     * @return string
     */
    public function getDescription(QueueItem $queueItem): string;

    /**
     * Validate the queue item before execution begins.
     *
     * Return false to immediately mark the task as failed without incrementing
     * the attempt counter (the item had invalid data from the start).
     *
     * @param  QueueItem $queueItem
     * @return bool
     */
    public function validate(QueueItem $queueItem): bool;

    /**
     * Handle a failure that occurred inside execute().
     *
     * Return true to allow the Worker to retry the task; false to mark it as
     * permanently failed regardless of remaining attempts.
     *
     * @param  QueueItem  $queueItem
     * @param  \Throwable $exception  The exception that caused the failure
     * @return bool
     */
    public function handleFailure(QueueItem $queueItem, \Throwable $exception): bool;
}
